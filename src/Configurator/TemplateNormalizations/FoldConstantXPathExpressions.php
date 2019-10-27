<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2019 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\TemplateNormalizations;
use Exception;
use s9e\TextFormatter\Utils\XPath;
class FoldConstantXPathExpressions extends AbstractConstantFolding
{
	protected $supportedFunctions = array(
		'boolean',
		'ceiling',
		'concat',
		'contains',
		'floor',
		'normalize-space',
		'not',
		'number',
		'round',
		'starts-with',
		'string',
		'string-length',
		'substring',
		'substring-after',
		'substring-before',
		'translate'
	);
	protected function getOptimizationPasses()
	{
		return array(
			'(^(?:"[^"]*"|\'[^\']*\'|\\.[0-9]|[^"$&\'./:@[\\]])++$)' => 'foldConstantXPathExpression'
		);
	}
	protected function evaluate($expr)
	{
		$useErrors = \libxml_use_internal_errors(\true);
		$result    = $this->xpath->evaluate($expr);
		\libxml_use_internal_errors($useErrors);
		return $result;
	}
	protected function foldConstantXPathExpression(array $m)
	{
		$expr = $m[0];
		if ($this->isConstantExpression($expr))
		{
			try
			{
				$result     = $this->evaluate($expr);
				$foldedExpr = XPath::export($result);
				$expr       = $this->selectReplacement($expr, $foldedExpr);
			}
			catch (Exception $e)
			{
				}
		}
		return $expr;
	}
	protected function isConstantExpression($expr)
	{
		$expr = \preg_replace('("[^"]*"|\'[^\']*\')', '0', $expr);
		\preg_match_all('(\\w[-\\w]+(?=\\())', $expr, $m);
		if (\count(\array_diff($m[0], $this->supportedFunctions)) > 0)
			return \false;
		return !\preg_match('([^\\s!\\-0-9<=>a-z\\(-.]|\\.(?![0-9])|\\b[-a-z](?![-\\w]+\\()|\\(\\s*\\))i', $expr);
	}
	protected function selectReplacement($expr, $foldedExpr)
	{
		if (\strlen($foldedExpr) < \strlen($expr) || $foldedExpr === 'false()' || $foldedExpr === 'true()')
			return $foldedExpr;
		return $expr;
	}
}