<?php

/**
* @package   s9e\Toolkit
* @copyright Copyright (c) 2010 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\Toolkit\TextFormatter\Plugins;

use s9e\Toolkit\TextFormatter\Parser,
    s9e\Toolkit\TextFormatter\PluginParser;

class WittyPantsParser extends PluginParser
{
	public function getTags($text, array $matches)
	{
		$tags = array();

		$tagName      = $this->config['tagName'];
		$attrName     = $this->config['attrName'];
		$replacements = $this->config['replacements'];

		foreach ($matches['singletons'] as $m)
		{
			$tags[] = array(
				'pos'   => $m[0][1],
				'type'  => Parser::SELF_CLOSING_TAG,
				'name'  => $tagName,
				'len'   => strlen($m[0][0]),
				'attrs' => array(
					$attrName => $replacements[$m[0][0]]
				)
			);
		}

		return $tags;
	}
}