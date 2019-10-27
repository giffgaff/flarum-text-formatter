<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2019 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\Collections;
class TagFilterChain extends FilterChain
{
	public function getFilterClassName()
	{
		return 's9e\\TextFormatter\\Configurator\\Items\\TagFilter';
	}
}