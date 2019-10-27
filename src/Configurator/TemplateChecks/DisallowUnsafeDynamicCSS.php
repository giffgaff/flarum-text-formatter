<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2019 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\TemplateChecks;
use DOMElement;
use s9e\TextFormatter\Configurator\Helpers\NodeLocator;
use s9e\TextFormatter\Configurator\Helpers\XPathHelper;
use s9e\TextFormatter\Configurator\Items\Attribute;
class DisallowUnsafeDynamicCSS extends AbstractDynamicContentCheck
{
	protected function getNodes(DOMElement $template)
	{
		return NodeLocator::getCSSNodes($template->ownerDocument);
	}
	protected function isExpressionSafe($expr)
	{
		return XPathHelper::isExpressionNumeric($expr);
	}
	protected function isSafe(Attribute $attribute)
	{
		return $attribute->isSafeInCSS();
	}
}