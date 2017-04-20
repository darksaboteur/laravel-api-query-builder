<?php

namespace Unlu\Laravel\Api;

use Illuminate\Http\Request;

class UriParser  {
    protected $request;

    protected $pattern = '/!=|=|<=|<|>=|>/';

    protected $constantParameters = [
        'order_by',
        'group_by',
        'limit',
        'page',
        'columns',
        'includes',
        'includes_deleted',
    ];

    protected $uri;

    protected $queryUri;

    protected $queryParameters = [];

    public function __construct(Request $request) {
        $this->request = $request;

        $this->uri = $request->getRequestUri();

        $this->setQueryUri($this->uri);

        if ($this->hasQueryUri()) {
            $this->setQueryParameters($this->queryUri);
        }
    }

    public function queryParameter($key) {
        $keys = array_pluck($this->queryParameters, 'key');

        $queryParameters = array_combine($keys, $this->queryParameters);

        return $queryParameters[$key];
    }

    public function constantParameters() {
        return $this->constantParameters;
    }

    public function whereParameters() {
        return array_filter(
            $this->queryParameters,
            function($queryParameter) {
                $key = $queryParameter['key'];
                return (! in_array($key, $this->constantParameters));
            }
        );
    }

    private function setQueryUri($uri) {
        $explode = explode('?', $uri);

        $this->queryUri = (isset($explode[1])) ? $explode[1] : null;
    }

    private function setQueryParameters($queryUri) {
		$explode = explode('?', $queryUri);

        $queryParameters = array_filter(explode('&', $queryUri));

		foreach ($queryParameters as $i => $queryParameter) {
			$queryParameters[$i] = rawurldecode($queryParameter);
		}

        array_map([$this, 'appendQueryParameter'], $queryParameters);
    }

    private function appendQueryParameter($parameter) {
        preg_match($this->pattern, $parameter, $matches);
        $restrictive = true;
        $unmatched = false;
        if (!$matches) {
          $key = $parameter;
          $operator = 'has';
          $value = true;
          if (substr($key, 0, 1) == '!') {
            $key = substr($key, 1);
            $value = false;
          }
        }
        else {
          $operator = $matches[0];

          list($key, $value) = explode($operator, $parameter);

          if (substr($key, 0, 1) == '~') {
            $restrictive = false;
            $key = substr($key, 1);
          }

          if (substr($key, 0, 1) == '^') {
            $restrictive = true;
            $unmatched = true;
            $key = substr($key, 1);
          }

          if (!$this->isConstantParameter($key) && $this->isLikeQuery($value)) {
              $operator = 'like';
              $value = str_replace('*', '%', $value);
          }
        }

        $this->queryParameters[] = [
            'key' => $key,
            'operator' => $operator,
            'restrictive' => $restrictive,
            'unmatched' => $unmatched,
            'value' => $value
        ];
    }

    public function hasQueryUri() {
        return ($this->queryUri);
    }

    public function hasQueryParameters() {
        return (count($this->queryParameters) > 0);
    }

    public function hasQueryParameter($key) {
        $keys = array_pluck($this->queryParameters, 'key');

        return (in_array($key, $keys));
    }

    private function isLikeQuery($query) {
        $pattern = "/^\*|\*$/";

        return (preg_match($pattern, $query, $matches));
    }

    private function isConstantParameter($key) {
        return (in_array($key, $this->constantParameters));
    }
}
