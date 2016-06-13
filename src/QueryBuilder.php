<?php

namespace Unlu\Laravel\Api;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unlu\Laravel\Api\Exceptions\UnknownColumnException;
use Unlu\Laravel\Api\Exceptions\UnknownRelationException;
use Unlu\Laravel\Api\UriParser;

class QueryBuilder {
    protected $model;

    protected $uriParser;

    protected $wheres = [];
    
    protected $processed_wheres = [];

    protected $orderBy = [];

    protected $limit;

    protected $page = 1;

    protected $offset = 0;

    protected $columns = ['*'];

    protected $relationColumns = [];

    protected $includes = [];

    protected $includesDeleted = [];

    protected $groupBy = [];

    protected $excludedParameters = [];

    protected $query;

    protected $result;

    protected $modelNamespace = '';

    public function __construct(Model $model, Request $request) {
        $this->orderBy = config('api-query-builder.orderBy');

        $this->limit = config('api-query-builder.limit');

        $this->modelNamespace = config('api-query-builder.modelNamespace');

        $this->excludedParameters = array_merge($this->excludedParameters, config('api-query-builder.excludedParameters'));

        $this->model = $model;

        $this->uriParser = new UriParser($request);

        $this->query = $this->model->newQuery();
    }

    public function build() {
        $this->prepare();

        if ($this->hasWheres()) {
            $this->addWheresToQuery($this->processed_wheres);
        }

        if ($this->hasGroupBy()) {
            $this->query->groupBy($this->groupBy);
        }

        if ($this->hasLimit()) {
            $this->query->take($this->limit);
        }

        if ($this->hasOffset()) {
            $this->query->skip($this->offset);
        }

        array_map([$this, 'addOrderByToQuery'], $this->orderBy);		

        if ($this->hasIncludes()) {
		  $missing_includes_deleted = $this->includesDeleted;
          foreach ($this->includes as $include) {     //check the includes map to a valid relation
            $tables = explode('.', $include);

            if (!($model = $this->getTableRelation($tables))) {
              throw new UnknownRelationException("Unknown relation '".$include."'");
            }

			  $hasIncludesDeleted = in_array($include, $this->includesDeleted);
			  if ($hasIncludesDeleted) {
				  unset($missing_includes_deleted[array_search($include, $missing_includes_deleted)]);
			  }
			  $this->query->with([$include => function($query) use ($include, $hasIncludesDeleted) {
				  foreach ($this->wheres as $where) {
					$tables = explode('.', $where['key']);
					$column = array_pop($tables);
					if (implode('.', $tables) == $include) {						
						$query->where($column, $where['operator'], $where['value']);
					}
				  }
				  $query->withTrashed();
			  }]);
			}
          //add any includes deleted that were missed
          /*foreach ($missing_includes_deleted as $missing_include_deleted) {
            $this->query->with([$missing_include_deleted => function($query) {
              $query->withTrashed();
            }]);
          }*/
        }

        $this->query->select($this->columns);

        return $this;
    }

    public function get() {
        return $this->query->get();
    }

    public function paginate() {
        if (! $this->hasLimit()) {
            throw new Exception("You can't use unlimited option for pagination", 1);
        }

        return $this->query->paginate($this->limit);
    }

    public function lists($value, $key) {
        return $this->query->lists($value, $key);
    }

    public function query() {
        return $this->query;
    }

    public function limit() {
        return $this->limit;
    }

    public function includes() {
        return $this->includes;
    }

    public function wheres() {
        return $this->wheres;
    }

    protected function prepare() {
		$raw_wheres = $this->uriParser->whereParameters();
		$this->setWheres($raw_wheres);
		
		$wheres = [];
		foreach ($raw_wheres as $raw_where) {
			$tables = explode('.', $raw_where['key']);
			$raw_where['key'] = array_pop($tables);
			$where_ptr = &$wheres;
			foreach ($tables as $table) {
				$where_ptr = &$where_ptr['children'][$table];
			}
			$where_ptr['wheres'][] = $raw_where;
		}
		
        $this->setProcessedWheres($wheres);

        $constantParameters = $this->uriParser->constantParameters();

        array_map([$this, 'prepareConstant'], $constantParameters);

        if ($this->hasIncludes() && $this->hasRelationColumns()) {
            $this->fixRelationColumns();
        }

        return $this;
    }

    private function prepareConstant($parameter) {
        if (! $this->uriParser->hasQueryParameter($parameter)) return;

        $callback = [$this, $this->setterMethodName($parameter)];

        $callbackParameter = $this->uriParser->queryParameter($parameter);

        call_user_func($callback, $callbackParameter['value']);
    }

    private function addInclude($include) {
      if (!in_array($include, $this->includes)) {
        $this->includes[] = $include;
      }
    }

    private function setIncludes($includes) {
        $this->includes = array_filter(explode(',', $includes));
    }

    private function setIncludesDeleted($includes) {
        $this->includesDeleted = array_filter(explode(',', $includes));
    }

    private function setPage($page) {
        $this->page = (int) $page;

        $this->offset = ($page - 1) * $this->limit;
    }

    private function setColumns($columns) {
        $columns = array_filter(explode(',', $columns));

        $this->columns = $this->relationColumns = [];

        array_map([$this, 'setColumn'], $columns);
    }

    private function setColumn($column) {
        if ($this->isRelationColumn($column)) {
            return $this->appendRelationColumn($column);
        }

        $this->columns[] = $column;
    }

    private function appendRelationColumn($keyAndColumn) {
        list($key, $column) = explode('.', $keyAndColumn);

        $this->relationColumns[$key][] = $column;
    }

    private function fixRelationColumns() {
        $keys = array_keys($this->relationColumns);

        $callback = [$this, 'fixRelationColumn'];

        array_map($callback, $keys, $this->relationColumns);
    }

    private function fixRelationColumn($key, $columns) {
        $index = array_search($key, $this->includes);

        unset($this->includes[$index]);

        $this->includes[$key] = $this->closureRelationColumns($columns);
    }

    private function closureRelationColumns($columns) {
        return function($q) use ($columns) {
            $q->select($columns);
        };
    }

    private function setOrderBy($order) {
        $this->orderBy = [];

        $orders = array_filter(explode('|', $order));

        array_map([$this, 'appendOrderBy'], $orders);
    }

    private function appendOrderBy($order) {
        if ($order == 'random') {
            $this->orderBy[] = 'random';
            return;
        }

        list($column, $direction) = explode(',', $order);

        $this->orderBy[] = [
            'column' => $column,
            'direction' => $direction
        ];
    }

    private function setGroupBy($groups) {
        $this->groupBy = array_filter(explode(',', $groups));
    }

    private function setLimit($limit) {
        $limit = ($limit == 'unlimited') ? null : (int) $limit;

        $this->limit = $limit;
    }

    private function setWheres($parameters) {
        $this->wheres = $parameters;
    }
    
    private function setProcessedWheres($parameters) {
        $this->processed_wheres = $parameters;
    }

    private function addWheresToQuery($wheres) {	
		return $this->applyNestedWheres($wheres, $this->query);
    }
    
    private function applyNestedWheres($wheres, $query, $parent_tables = null) {
		if (isset($wheres['children'])) {
			foreach ($wheres['children'] as $table => $where_child) {
				$query->whereHas($table, function($query) use ($where_child, $parent_tables, $table) {
					$parent_tables = ($parent_tables ? $parent_tables : []);
					$parent_tables[] = $table;
					$this->applyNestedWheres($where_child, $query, $parent_tables);
				});
			}
		}
		if (isset($wheres['wheres'])) {
			foreach ($wheres['wheres'] as $where) {
				$column = $where['key'];
				
				if (is_null($parent_tables)) {	//only check on the top level for excluded params
					 if ($this->isExcludedParameter($where['key'])) {
						continue;
					}

					if ($this->hasCustomFilter($where['key'])) {
						$this->applyCustomFilter($where['key'], $where['operator'], $where['value']);
					}
				}
				
				if (!($model = $this->getTableRelation($parent_tables))) {
					throw new UnknownRelationException("Unknown relation '".$where['key']."'");
				}
				
				if (!$this->hasTableColumn($column, $model)) {
					throw new UnknownColumnException("Unknown column '".$where['key']."'");
				}
				
				$column = $model->getTable().'.'.$column;
				$query->where($column, $where['operator'], $where['value']);
			}
		}
		return $query;
	}

    private function addOrderByToQuery($order) {
        if ($order == 'random') {
            return $this->query->orderBy(DB::raw('RAND()'));
        }

        extract($order);

        $this->query->orderBy($this->model->getTable().'.'.$column, $direction);
    }

    private function applyCustomFilter($key, $operator, $value) {
        $callback = [$this, $this->customFilterName($key)];

        $this->query = call_user_func($callback, $this->query, $value, $operator);
    }

    private function isRelationColumn($column) {
        return (count(explode('.', $column)) > 1);
    }

    private function isExcludedParameter($key) {
        return in_array($key, $this->excludedParameters);
    }

    private function hasWheres() {
        return (count($this->wheres) > 0);
    }

    private function hasIncludes() {
        return (count($this->includes) > 0);
    }

    private function hasGroupBy() {
        return (count($this->groupBy) > 0);
    }

    private function hasLimit() {
        return ($this->limit);
    }

    private function hasOffset() {
        return ($this->offset != 0);
    }

    private function hasRelationColumns() {
        return (count($this->relationColumns) > 0);
    }

    private function getTableRelation($tables) {
      $model = $this->model;
      try {
        while (sizeof($tables) > 0) {
            $method = array_shift($tables);
            $relationship = $model->$method();
            $model = $relationship->getRelated();
        }
      }
      catch (Exception $ex) {
        return false;
      }
      return $model;
    }

    private function hasTableColumn($column, $model = null) {
        $model = (!is_null($model) ? $model : $this->model);
        return (Schema::hasColumn($model->getTable(), $column));
    }

    private function hasCustomFilter($key) {
        $methodName = $this->customFilterName($key);

        return (method_exists($this, $methodName));
    }

    private function setterMethodName($key) {
        return 'set' . studly_case($key);
    }

    private function customFilterName($key) {
        return 'filterBy' . studly_case($key);
    }
}
