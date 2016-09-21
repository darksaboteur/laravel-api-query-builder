<?php

namespace Unlu\Laravel\Api;

use Exception;
use Illuminate\Http\Request;
use Unlu\Laravel\Api\UriParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Unlu\Laravel\Api\Exceptions\UnknownColumnException;
use Unlu\Laravel\Api\Exceptions\UnknownRelationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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

        if ($this->hasWheres() || $this->hasIncludes()) {
            $this->applyNestedWheres($this->processed_wheres, $this->query, $this->model);
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
      $constantParameters = $this->uriParser->constantParameters();

      array_map([$this, 'prepareConstant'], $constantParameters);

  		$raw_wheres = $this->uriParser->whereParameters();
      $this->setWheres($raw_wheres);

      $with = [];

      foreach ($this->columns as $column) {
        $tables = explode('.', $column);
        $column = array_pop($tables);
        $with_ptr = &$with;
        foreach ($tables as $table) {
          $with_ptr = &$with_ptr['children'][$table];
        }
        $with_ptr['select'][] = $column;
      }

      foreach ($this->includesDeleted as $include) {     //check the includes map to a valid relation
        //special case for top level
        if ($include == 'true') {
          $with['include_deleted'] = true;
          continue;
        }
        $tables = explode('.', $include);
        $with_ptr = &$with;
        foreach ($tables as $table) {
          $with_ptr = &$with_ptr['children'][$table];
        }
        $with_ptr['include_deleted'] = true;
      }

      foreach ($this->includes as $include) {     //check the includes map to a valid relation
        $tables = explode('.', $include);

        $with_ptr = &$with;
        foreach ($tables as $table) {
          $with_ptr = &$with_ptr['children'][$table];
          $with_ptr['include'] = true;
        }
      }

  		foreach ($raw_wheres as $raw_where) {
        $tables = explode('.', $raw_where['key']);
  			$raw_where['key'] = array_pop($tables);

        $with_ptr = &$with;
        foreach ($tables as $table) {
          $with_ptr = &$with_ptr['children'][$table];
        }
        $with_ptr['wheres'][] = $raw_where;
  		}

      $this->determineRestrictive($with);

      $this->setProcessedWheres($with);

      if ($this->hasIncludes() && $this->hasRelationColumns()) {
          //$this->fixRelationColumns();
      }

      return $this;
    }

    private function determineRestrictive(&$wheres) {
      $status = -1;

      if (isset($wheres['children'])) {
        foreach ($wheres['children'] as $i => $where) {
          $restrictive = $this->determineRestrictive($wheres['children'][$i]);
          if ($status === -1) {
            $status = $restrictive;
          }
          elseif ($status === $restrictive) {
            //they match, do nothing
          }
          else {
            $status = null;
          }
        }
      }

      if (isset($wheres['wheres'])) {
        foreach ($wheres['wheres'] as $where) {
          if ($status === -1) {
            $status = $where['restrictive'];
          }
          elseif ($status === $where['restrictive']) {
            //they match, do nothing
          }
          else {
            $status = null;
          }
        }
      }

      $status = ($status === -1 ? false : $status);
      $wheres['restrictive'] = $status;
      return $status;
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
        $this->columns = array_filter(explode(',', $columns));
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

    private function applyWhere($where, $query, $model) {
      $column = $where['key'];

      if (!$this->hasTableColumn($column, $model)) {
        throw new UnknownColumnException("Unknown column '".$where['key']."'");
      }

      $column = $model->getTable().'.'.$column;
      if ($where['operator'] == 'in') {
        $query->where(function($query) use ($where, $column) {
          if (count($where['value']) > 1) {
            $query->whereIn($column, $where['value']);
          }
          else {
            $query->where($column, '=', $where['value']);
          }

          $null_key = array_search('null', $where['value']);
          if ($null_key !== false) {
            $query->orWhereIsNull($column);
            unset($where['value'][$null_key]);
          }
        });
      }
      else {
        if (is_null($where['value'])) {
          $query->whereNull($column);
        }
        else {
          $query->where($column, $where['operator'], $where['value']);
        }
      }
      return $query;
    }

    private function applyNestedWheres($wheres, $query, $model, $restrictive = null) {
      //include soft deleted items (todo: check model has soft deleted trait?)
      if (isset($wheres['include_deleted']) && $wheres['include_deleted']) {
        $query->withTrashed();
      }

      //get the current column select criteria (null means it will not be applied)
      $select = (isset($wheres['select']) && $wheres['select'] ? $wheres['select'] : null);

      //handle the nested includes / wheres
      if (isset($wheres['children'])) {
        foreach ($wheres['children'] as $table => $where_child) {
          //check relation validity
          $relationship = $this->getRelationship($table, $model);
          $child_model = $relationship->getRelated();

          //the following automatically adds foriegn and primary keys needed by relations when columns are in use
          $isHasOneOrMany = ($relationship instanceof HasOneOrMany);
          $isHasManyThrough = ($relationship instanceof HasManyThrough);

          if ($select) {
            $key = null;
            if (!$isHasManyThrough && method_exists($relationship, 'getQualifiedParentKeyName')) {
              $key = $relationship->getQualifiedParentKeyName();
            }
            elseif ($isHasManyThrough && method_exists($relationship, 'getHasCompareKey')) {
              $key = $relationship->getHasCompareKey();
            }
            elseif (!$isHasOneOrMany && !$isHasManyThrough && method_exists($relationship, 'getForeignKey')) {
              $key = $relationship->getForeignKey();
            }

            if ($key) {
              if (!in_array('*', $select) && !in_array($key, $select)) {
                $select[] = $key;
              }
            }
          }

          if (isset($where_child['select']) && $where_child['select']) {
            $key = null;

            //belongsTo
            if (method_exists($relationship, 'getOtherKey')) {
              $key = $relationship->getOtherKey();
            }
            elseif (($isHasOneOrMany || $isHasManyThrough) && method_exists($relationship, 'getForeignKey')) {
              $key = $relationship->getForeignKey();
            }
            if ($key) {
              if (!in_array('*', $where_child['select']) && !in_array($key, $where_child['select'])) {
                $where_child['select'][] = $key;
              }
            }
          }

          //include the relations results
          if (isset($where_child['include']) && $where_child['include']) {
            $query->with([$table => function($sub_query) use ($where_child, $child_model) {
              $this->applyNestedWheres($where_child, $sub_query, $child_model);
            }]);
          }
          //limit the parent models results if restrictive is not strict false
          if ($where_child['restrictive'] || is_null($where_child['restrictive'])) {
            $query->whereHas($table, function($sub_query) use ($where_child, $child_model) {
              $this->applyNestedWheres($where_child, $sub_query, $child_model, true);
            });
          }
        }
      }
      //apply the column filters
      if ($select) {
        $query->select(array_map(function($val) use ($model) {
          //qualify with the model table if its not supplied
          return (count(explode('.', $val)) == 1 ? $model->getTable().'.' : '').$val;
        }, $select));
      }

      //apply the where clauses to the query
      if (isset($wheres['wheres'])) {
        $wheres['wheres'] = $this->determineIn($wheres['wheres']);

        foreach ($wheres['wheres'] as $where) {
          if ($model == $this->model) {	//only check on the top level for excluded params
             if ($this->isExcludedParameter($where['key'])) {
              continue;
            }

            if ($this->hasCustomFilter($where['key'])) {
              $this->applyCustomFilter($where['key'], $where['operator'], $where['value']);
            }
          }

          if (is_null($restrictive) || ($restrictive && $where['restrictive'])) {
            $query = $this->applyWhere($where, $query, $model);
          }
        }
      }
      return $query;
    }

	private function determineIn($wheres) {
		$ors = $others = [];
		foreach ($wheres as $i => $where) {
			if ($where['operator'] == '=') {
				if (!isset($ors[$where['key']])) {
					$ors[$where['key']] = [];
				}
				$ors[$where['key']][] = $where;
			}
      else {
        $others[] = $where;
      }
		}

		foreach ($ors as $key => $or) {
			if (sizeof($or) == 1) {
        $ors[$key] = $or[0];
			}
      else {
        $values = [];
        foreach ($or as $item) {
          $values[] = $item['value'];
        }
        $ors[$key] = $or[0];
        $ors[$key]['operator'] = 'in';
        $ors[$key]['value'] = $values;
      }
		}
		return array_merge(array_values($ors), $others);
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

    private function getRelatedModel($tables, $model = null) {
      $model = (!is_null($model) ? $model : $this->model);
      while (sizeof($tables) > 0) {
          $method = array_shift($tables);
          $relationship = $this->getRelationship($method, $model);
          $model = $relationship->getRelated();
      }
      return $model;
    }

    private function getRelationship($relation, $model) {
      try {
        if (!method_exists($model, $relation)) {
          throw new Exception('Relationship does not exist');
        }
        $relationship = $model->$relation();
        if (!$relationship instanceof Relation) {
          throw new Exception('Relationship method exists but is not an instance of Relation');
        }
      }
      catch (Exception $ex) {
          throw new UnknownRelationException("Unknown relation '".$relation."' on ".$this->get_class_name($model));
      }
      return $relationship;
    }

    private function get_class_name($classname) {
      $classname = (is_object($classname) ? get_class($classname) : $classname);
      if ($pos = strrpos($classname, '\\')) {
        return substr($classname, $pos + 1);
      }
      return $pos;
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
