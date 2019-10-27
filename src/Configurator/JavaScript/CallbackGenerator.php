<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2019 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\JavaScript;
class CallbackGenerator
{
	public $callbacks = array(
		'tags.*.attributes.*.filterChain.*' => array(
			'attrValue' => '*',
			'attrName'  => 'string'
		),
		'tags.*.filterChain.*' => array(
			'tag'       => '!Tag',
			'tagConfig' => '!Object'
		)
	);
	protected $encoder;
	public function __construct()
	{
		$this->encoder = new Encoder;
	}
	public function replaceCallbacks(array $config)
	{
		foreach ($this->callbacks as $path => $params)
			$config = $this->mapArray($config, \explode('.', $path), $params);
		return $config;
	}
	protected function buildCallbackArguments(array $params, array $localVars)
	{
		unset($params['parser']);
		$available  = \array_combine(\array_keys($localVars), \array_keys($localVars));
		$available += array(
			'innerText'      => '(tag.getEndTag() ? text.substr(tag.getPos() + tag.getLen(), tag.getEndTag().getPos() - tag.getPos() - tag.getLen()) : "")',
			'logger'         => 'logger',
			'openTags'       => 'openTags',
			'outerText'      => 'text.substr(tag.getPos(), (tag.getEndTag() ? tag.getEndTag().getPos() + tag.getEndTag().getLen() - tag.getPos() : tag.getLen()))',
			'registeredVars' => 'registeredVars',
			'tagText'        => 'text.substr(tag.getPos(), tag.getLen())',
			'text'           => 'text'
		);
		$args = array();
		foreach ($params as $k => $v)
			if (isset($v))
				$args[] = $this->encoder->encode($v);
			elseif (isset($available[$k]))
				$args[] = $available[$k];
			else
				$args[] = 'registeredVars[' . \json_encode($k) . ']';
		return \implode(',', $args);
	}
	protected function generateFunction(array $config, array $params)
	{
		if ($config['js'] == 'returnFalse' || $config['js'] == 'returnTrue')
			return new Code((string) $config['js']);
		$config += array('params' => array());
		$src  = $this->getHeader($params);
		$src .= 'function(' . \implode(',', \array_keys($params)) . '){';
		$src .= 'return ' . $this->parenthesizeCallback($config['js']);
		$src .= '(' . $this->buildCallbackArguments($config['params'], $params) . ');}';
		return new Code($src);
	}
	protected function getHeader(array $params)
	{
		$header = "/**\n";
		foreach ($params as $paramName => $paramType)
			$header .= '* @param {' . $paramType . '} ' . $paramName . "\n";
		$header .= "* @return {*}\n";
		$header .= "*/\n";
		return $header;
	}
	protected function mapArray(array $array, array $path, array $params)
	{
		$key  = \array_shift($path);
		$keys = ($key === '*') ? \array_keys($array) : array($key);
		foreach ($keys as $key)
		{
			if (!isset($array[$key]))
				continue;
			$array[$key] = (empty($path)) ? $this->generateFunction($array[$key], $params) : $this->mapArray($array[$key], $path, $params);
		}
		return $array;
	}
	protected function parenthesizeCallback($callback)
	{
		return (\preg_match('(^[.\\w]+$)D', $callback)) ? $callback : '(' . $callback  . ')';
	}
}