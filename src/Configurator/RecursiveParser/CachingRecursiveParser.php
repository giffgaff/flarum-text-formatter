<?php declare(strict_types=1);
/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2019 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\RecursiveParser;
use s9e\TextFormatter\Configurator\RecursiveParser;
class CachingRecursiveParser extends RecursiveParser
{
	protected $cache;
	public function parse(string $str, string $restrict = '')
	{
		if (!isset($this->cache[$restrict][$str]))
			$this->cache[$restrict][$str] = parent::parse($str, $restrict);
		return $this->cache[$restrict][$str];
	}
	public function setMatchers(array $matchers): void
	{
		$this->cache = array();
		parent::setMatchers($matchers);
	}
}