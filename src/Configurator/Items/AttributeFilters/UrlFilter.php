<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2019 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\Items\AttributeFilters;
use s9e\TextFormatter\Configurator\Items\AttributeFilter;
class UrlFilter extends AttributeFilter
{
	public function __construct()
	{
		parent::__construct('s9e\\TextFormatter\\Parser\\AttributeFilters\\UrlFilter::filter');
		$this->resetParameters();
		$this->addParameterByName('attrValue');
		$this->addParameterByName('urlConfig');
		$this->addParameterByName('logger');
		$this->setJS('UrlFilter.filter');
	}
	public function isSafeInCSS()
	{
		return \true;
	}
	public function isSafeInJS()
	{
		return \true;
	}
	public function isSafeAsURL()
	{
		return \true;
	}
}