<?php

/**
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2012 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter;

use RuntimeException;

class RegexpMaster
{
	/**
	* Create a regexp pattern that matches a list of words
	*
	* @param  array  $words   Words to sort (must be UTF-8)
	* @param  array  $options
	* @return string
	*/
	public function buildRegexpFromList(array $words, array $options = array())
	{
		$options += array(
			'specialChars' => array(),
			'useLookahead' => false
		);

		// Sort the words in order to produce the same regexp regardless of the words' order
		sort($words);

		/**
		* Used to store the first character of each word so that we can generate the lookahead
		* assertion
		*/
		$initials = array();

		/**
		* Used to store the escaped representation of each character, e.g. "a"=>"a", "."=>"\\."
		* Also used to give a special meaning to some characters, e.g. "*" => ".*?"
		*/
		$esc = $options['specialChars'];

		/**
		* preg_quote() errs on the safe side when escaping characters that could have a special
		* meaning in some situations. Since we're building the regexp in a controlled environment,
		* we don't have to escape those characters.
		*/
		$esc += array(
			'!' => '!',
			'-' => '-',
			':' => ':',
			'<' => '<',
			'=' => '=',
			'>' => '>',
			'}' => '}'
		);

		/**
		* List of words, split by character
		*/
		$splitWords = array();

		foreach ($words as $word)
		{
			if (preg_match_all('#.#us', $word, $matches) === false)
			{
				throw new RuntimeException("Invalid UTF-8 string '" . $word . "'");
			}

			$splitWord = array();
			foreach ($matches[0] as $pos => $c)
			{
				if (!isset($esc[$c]))
				{
					$esc[$c] = preg_quote($c, '#');
				}

				if ($pos === 0)
				{
					// Store the initial for later
					$initials[$esc[$c]] = true;
				}

				$splitWord[] = $esc[$c];
			}

			$splitWords[] = $splitWord;
		}

		$regexp = $this->assemble(array($this->mergeChains($splitWords)));

		if ($options['useLookahead']
		 && count($initials) > 1
		 && $regexp[0] !== '[')
		{
			$useLookahead = true;

			foreach ($initials as $initial => $void)
			{
				if (!$this->canBeUsedInCharacterClass($initial))
				{
					$useLookahead = false;
					break;
				}
			}

			if ($useLookahead)
			{
				$regexp = '(?=' . $this->generateCharacterClass(array_keys($initials)) . ')' . $regexp;
			}
		}

		return $regexp;
	}

	/**
	* Merge a 2D array of split words into a 1D array of expressions
	*
	* Each element in the passed array is called a "chain". It starts as an array where each element
	* is a character (a sort of UTF-8 aware str_split()) but successive iterations replace
	* individual characters with an equivalent expression.
	*
	* How it works:
	*
	* 1. Remove the longest prefix shared by all the chains
	* 2. Remove the longest suffix shared by all the chains
	* 3. Group each chain by their first element, e.g. all the chains that start with "a" (or in 	*    some cases, "[xy]") are grouped together
	* 4. If no group has more than 1 chain, we assemble them in a regexp, such as (aa|bb). If any
	*    group has more than 1 chain, for each group we merge the chains from that group together so
	*    that no group has more than 1 chain. When we're done, we remerge all the chains together.
	*
	* @param  array $chains
	* @return array
	*/
	protected function mergeChains(array $chains)
	{
		// If there's only one chain, there's nothing to merge
		if (!isset($chains[1]))
		{
			return $chains[0];
		}

		// The merged chain starts with the chains' common prefix
		$mergedChain = $this->removeLongestCommonPrefix($chains);

		if (!isset($chains[0][0])
		 && !array_filter($chains))
		{
			// The chains are empty, either they were already empty or they were identical and their
			// content was removed as their prefix. Nothing left to merge
			return $mergedChain;
		}

		// Remove the longest common suffix and save it for later
		$suffix = $this->removeLongestCommonSuffix($chains);

		// Whether one of the chain has been completely optimized away by prefix/suffix removal.
		// Signals that the middle part of the regexp is optional, e.g. (prefix)(foo)?(suffix)
		$endOfChain = false;

		// Whether these chains need to be remerged
		$remerge = false;

		// Here we group chains by their first atom (head of chain)
		$groups = array();
		foreach ($chains as $chain)
		{
			if (!isset($chain[0]))
			{
				$endOfChain = true;
				continue;
			}

			$head = $chain[0];

			if (isset($groups[$head]))
			{
				// More than one chain in a group means that we need to remerge
				$remerge = true;
			}

			$groups[$head][] = $chain;
		}

		// See if we can replace single characters with a character class
		$characterClass = array();
		foreach ($groups as $head => $groupChains)
		{
			if ($groupChains === array(array($head))
			 && $this->canBeUsedInCharacterClass($head))
			{
				$characterClass[$head] = $head;
			}
		}

		// Sort the characters and reset their keys
		sort($characterClass);

		if (isset($characterClass[1]))
		{
			foreach ($characterClass as $char)
			{
				unset($groups[$char]);
			}

			$head = $this->generateCharacterClass($characterClass);
			$groups[$head][] = array($head);

			// Ensure that the character class is at first in the alternation. Not only it looks
			// nice and might be more performant, it's also how assemble() does it, so normalizing
			// it might help with generating identical regexps (or subpatterns that would then be
			// optimized away as a prefix/suffix)
			$groups = array($head => $groups[$head])
			        + $groups;
		}

		if ($remerge)
		{
			// Merge all chains sharing the same head together
			$mergedChains = array();
			foreach ($groups as $head => $groupChains)
			{
				$mergedChains[] = $this->mergeChains($groupChains);
			}

			// Merge the tails of all chains if applicable. Helps with [ab][xy] (two chains with
			// identical tails)
			$this->mergeTails($mergedChains);

			// Now merge all chains together and append it to our merged chain
			$regexp = implode('', $this->mergeChains($mergedChains));

			if ($endOfChain)
			{
				$regexp = $this->makeRegexpOptional($regexp);
			}

			$mergedChain[] = $regexp;

		}
		else
		{
			$mergedChain[] = $this->assemble($chains);
		}

		// Add the common suffix
		foreach ($suffix as $atom)
		{
			$mergedChain[] = $atom;
		}

		return $mergedChain;
	}

	/**
	* Merge the tails of an array of chains wherever applicable
	*
	* This method optimizes (a[xy]|b[xy]|c) into ([ab][xy]|c). The expression [xy] is not a suffix
	* to every branch of the alternation (common suffix), so it is not automatically remove. What we
	* do here is group chains by their last element (their tail) and then try to merge them together
	* group by group. This method should only be called AFTER chains have been group-merged by head.
	*
	* NOTE: will only merge tails if their heads can become a character class, in order to avoid
	*       creating a non-capturing subpattern, e.g. (?:c|a[xy]|bb[xy]) does not become
	*       (?:c|(?:a|bb)[xy]) but (?:c|a[xy]|bb[xy]|d[xy]) does become (?:c|bb[xy]|[ad][xy])
	*
	* @param array &$chains
	*/
	protected function mergeTails(array &$chains)
	{
		$candidateChains = array();

		foreach ($chains as $k => $chain)
		{
			if (isset($chain[1])
			 && !isset($chain[2])
			 && $this->canBeUsedInCharacterClass($chain[0]))
			{
				$candidateChains[$chain[1]][$k] = $chain;
			}
		}

		foreach ($candidateChains as $tail => $groupChains)
		{
			if (count($groupChains) < 2)
			{
				// Only 1 element, skip this group
				continue;
			}

			// Remove this group's chains from the original list
			$chains = array_diff_key($chains, $groupChains);

			// Merge this group's chains and add the result to the list
			$chains[] = $this->mergeChains(array_values($groupChains));
		}

		// Don't forget to reset the keys
		$chains = array_values($chains);
	}

	/**
	* Remove the longest common prefix from an array of chains
	*
	* @param  array &$chains
	* @return array          Removed elements
	*/
	protected function removeLongestCommonPrefix(array &$chains)
	{
		// Length of longest common prefix
		$pLen = 0;

		while (1)
		{
			// $c will be used to store the character we're matching against
			unset($c);

			foreach ($chains as $chain)
			{
				if (!isset($chain[$pLen]))
				{
					// Reached the end of a word
					break 2;
				}

				if (!isset($c))
				{
					$c = $chain[$pLen];
					continue;
				}

				if ($chain[$pLen] !== $c)
				{
					// Does not match -- don't increment sLen and break out of the loop
					break 2;
				}
			}

			// We have confirmed that all the words share a same prefix of at least ($pLen + 1)
			++$pLen;
		}

		if (!$pLen)
		{
			return array();
		}

		// Store prefix
		$prefix = array_slice($chains[0], 0, $pLen);

		// Remove prefix from each word
		foreach ($chains as &$chain)
		{
			$chain = array_slice($chain, $pLen);
		}
		unset($chain);

		return $prefix;
	}

	/**
	* Remove the longest common suffix from an array of chains
	*
	* NOTE: this method is meant to be called after removeLongestCommonPrefix(). If it's not, then
	*       the longest match return may be off by 1.
	*
	* @param  array &$chains
	* @return array          Removed elements
	*/
	protected function removeLongestCommonSuffix(array &$chains)
	{
		// Cache the length of every word
		$chainsLen = array_map('count', $chains);

		// Length of the longest possible suffix
		$maxLen = min($chainsLen);

		// If all the words are the same length, the longest suffix is 1 less than the length of the
		// words because we've already extracted the longest prefix
		if (max($chainsLen) === $maxLen)
		{
			--$maxLen;
		}

		// Length of longest common suffix
		$sLen = 0;

		// Try to find the longest common suffix
		while ($sLen < $maxLen)
		{
			// $c will be used to store the character we're matching against
			unset($c);

			foreach ($chains as $k => $chain)
			{
				$pos = $chainsLen[$k] - ($sLen + 1);

				if (!isset($c))
				{
					$c = $chain[$pos];
					continue;
				}

				if ($chain[$pos] !== $c)
				{
					// Does not match -- don't increment sLen and break out of the loop
					break 2;
				}
			}

			// We have confirmed that all the words share a same suffix of at least ($sLen + 1)
			++$sLen;
		}

		if (!$sLen)
		{
			return array();
		}

		// Store suffix
		$suffix = array_slice($chains[0], -$sLen);

		// Remove suffix from each word
		foreach ($chains as &$chain)
		{
			$chain = array_slice($chain, 0, -$sLen);
		}
		unset($chain);

		return $suffix;
	}

	/**
	* Assemble an array of chains into one expression
	*
	* @param  array  $chain
	* @return string
	*/
	protected function assemble(array $chains)
	{
		$endOfChain = false;

		$regexps        = array();
		$characterClass = array();

		foreach ($chains as $chain)
		{
			if (empty($chain))
			{
				$endOfChain = true;
				continue;
			}

			if (!isset($chain[1])
			 && $this->canBeUsedInCharacterClass($chain[0]))
			{
				$characterClass[$chain[0]] = $chain[0];
			}
			else
			{
				$regexps[] = implode('', $chain);
			}
		}

		if (!empty($characterClass))
		{
			// Sort the characters and reset their keys
			sort($characterClass);

			// Use a character class if there are more than 1 characters in it
			$regexp = (isset($characterClass[1]))
					? $this->generateCharacterClass($characterClass)
					: $characterClass[0];

			// Prepend the character class to the list of regexps
			array_unshift($regexps, $regexp);
		}

		if (empty($regexps))
		{
			return '';
		}

		if (isset($regexps[1]))
		{
			// There are several branches
			$regexp = '(?:' . implode('|', $regexps) . ')';
		}
		else
		{
			$regexp = $regexps[0];
		}

		// If we've reached the end of a chain, it means that the branches are optional
		if ($endOfChain)
		{
			$regexp = $this->makeRegexpOptional($regexp);
		}

		return $regexp;
	}

	/**
	* Make an entire regexp optional through the use of the ? quantifier
	*
	* @param  string $regexp
	* @return string
	*/
	protected function makeRegexpOptional($regexp)
	{
		// One single character, optionally escaped
		if (preg_match('#^\\\\?.$#Dus', $regexp))
		{
			$isAtomic = true;
		}
		// At least two characters, but it's not a subpattern or a character class
		elseif (preg_match('#^[^[(].#s', $regexp))
		{
			$isAtomic = false;
		}
		else
		{
			$def    = $this->parseRegexp('#' . $regexp . '#');
			$tokens = $def['tokens'];

			switch (count($tokens))
			{
				// One character class
				case 1:
					$startPos = $tokens[0]['pos'];
					$len      = $tokens[0]['len'];

					$isAtomic = (bool) ($startPos === 0 && $len === strlen($regexp));
					break;

				// One subpattern covering the entire regexp
				case 2:
					if ($tokens[0]['type'] === 'nonCapturingSubpatternStart'
					 && $tokens[1]['type'] === 'nonCapturingSubpatternEnd')
					{
						$startPos = $tokens[0]['pos'];
						$len      = $tokens[1]['pos'] + $tokens[1]['len'];

						$isAtomic = (bool) ($startPos === 0 && $len === strlen($regexp));

						// If the tokens are not a non-capturing subpattern, we let it fall through
						break;
					}
					// no break; here

				default:
					$isAtomic = false;
			}
		}

		if (!$isAtomic)
		{
			$regexp = '(?:' . $regexp . ')';
		}

		$regexp .= '?';

		return $regexp;
	}

	/**
	* Generate a character class from an array of characters
	*
	* @param  array  $chars
	* @return string
	*/
	protected function generateCharacterClass(array $chars)
	{
		$chars = array_flip($chars);

		// "-" should be the first character of the class to avoid ambiguity
		if (isset($chars['-']))
		{
			$chars = array('-' => 1) + $chars;
		}

		// Those characters do not need to be escaped inside of a character class.
		// Also, we ensure that ^ is at the end of the class to prevent it from negating the class
		$unescape = str_split('$()*+.?[{|^', 1);

		foreach ($unescape as $c)
		{
			if (isset($chars['\\' . $c]))
			{
				unset($chars['\\' . $c]);
				$chars[$c] = 1;
			}
		}

		return '[' . implode('', array_keys($chars)) . ']';
	}

	/**
	* Test whether a given expression (usually one character) can be used in a character class
	*
	* @param  string $char
	* @return bool
	*/
	protected function canBeUsedInCharacterClass($char)
	{
		// More than 1 character => cannnot be used in a character class
		if (!preg_match('#^\\\\?.$#Dus', $char))
		{
			return false;
		}

		// Unescaped dots shouldn't be used in a character class
		if (preg_match('/(?<!\\\\)\\./', $char))
		{
			return false;
		}

		return true;
	}

	/**
	* @param  string $regexp
	* @return array
	*/
	public function parseRegexp($regexp)
	{
		if (!preg_match('#^(.)(.*?)\\1([a-zA-Z]*)$#D', $regexp, $m))
		{
			throw new RuntimeException('Could not parse regexp delimiters');
		}

		$ret = array(
			'delimiter' => $m[1],
			'modifiers' => $m[3],
			'regexp'    => $m[2],
			'tokens'    => array()
		);

		$regexp = $m[2];

		$openSubpatterns = array();

		$pos = 0;
		$regexpLen = strlen($regexp);

		while ($pos < $regexpLen)
		{
			switch ($regexp[$pos])
			{
				case '\\':
					// skip next character
					$pos += 2;
					break;

				case '[':
					if (!preg_match('#\\[(.*?(?<!\\\\)(?:\\\\\\\\)*)\\]((?:[\\+\\*]\\+?)?)#', $regexp, $m, 0, $pos))
					{
						throw new RuntimeException('Could not find matching bracket from pos ' . $pos);
					}

					$ret['tokens'][] = array(
						'pos'         => $pos,
						'len'         => strlen($m[0]),
						'type'        => 'characterClass',
						'content'     => $m[1],
						'quantifiers' => $m[2]
					);

					$pos += strlen($m[0]);
					break;

				case '(';
					if (preg_match('#\\(\\?([a-z]*)\\)#i', $regexp, $m, 0, $pos))
					{
						/**
						* This is an option (?i) so we skip past the right parenthesis
						*/
						$ret['tokens'][] = array(
							'pos'     => $pos,
							'len'     => strlen($m[0]),
							'type'    => 'option',
							'options' => $m[1]
						);

						$pos += strlen($m[0]);
						break;
					}

					/**
					* This should be a subpattern, we just have to sniff which kind
					*/
					if (preg_match("#(?J)\\(\\?(?:P?<(?<name>[a-z]+)>|'(?<name>[a-z]+)')#A", $regexp, $m, \PREG_OFFSET_CAPTURE, $pos))
					{
						/**
						* This is a named capture
						*/
						$tok = array(
							'pos'  => $pos,
							'len'  => strlen($m[0][0]),
							'type' => 'capturingSubpatternStart',
							'name' => $m['name'][0]
						);

						$pos += strlen($m[0][0]);
					}
					elseif (preg_match('#\\(\\?([a-z]*):#iA', $regexp, $m, 0, $pos))
					{
						/**
						* This is a non-capturing subpattern (?:xxx)
						*/
						$tok = array(
							'pos'     => $pos,
							'len'     => strlen($m[0]),
							'type'    => 'nonCapturingSubpatternStart',
							'options' => $m[1]
						);

						$pos += strlen($m[0]);
					}
					elseif (preg_match('#\\(\\?>#iA', $regexp, $m, 0, $pos))
					{
						/**
						* This is a non-capturing subpattern with atomic grouping (?>x+)
						*/
						$tok = array(
							'pos'     => $pos,
							'len'     => strlen($m[0]),
							'type'    => 'nonCapturingSubpatternStart',
							'subtype' => 'atomic'
						);

						$pos += strlen($m[0]);
					}
					elseif (preg_match('#\\(\\?(<?[!=])#A', $regexp, $m, 0, $pos))
					{
						/**
						* This is an assertion
						*/
						$assertions = array(
							'='  => 'lookahead',
							'<=' => 'lookbehind',
							'!'  => 'negativeLookahead',
							'<!' => 'negativeLookbehind'
						);

						$tok = array(
							'pos'     => $pos,
							'len'     => strlen($m[0]),
							'type'    => $assertions[$m[1]] . 'AssertionStart'
						);

						$pos += strlen($m[0]);
					}
					elseif (preg_match('#\\(\\?#A', $regexp, $m, 0, $pos))
					{
						throw new RuntimeException('Unsupported subpattern type at pos ' . $pos);
					}
					else
					{
						/**
						* This should be a normal capture
						*/
						$tok = array(
							'pos'  => $pos,
							'len'  => 1,
							'type' => 'capturingSubpatternStart'
						);

						++$pos;
					}

					$openSubpatterns[] = count($ret['tokens']);
					$ret['tokens'][] = $tok;
					break;

				case ')':
					if (empty($openSubpatterns))
					{
						throw new RuntimeException('Could not find matching pattern start for right parenthesis at pos ' . $pos);
					}

					$k = array_pop($openSubpatterns);
					$ret['tokens'][$k]['endToken'] = count($ret['tokens']);


					/**
					* Look for quantifiers after the subpattern, e.g. (?:ab)++
					*/
					$spn = strspn($regexp, '+*', 1 + $pos);
					$quantifiers = substr($regexp, 1 + $pos, $spn);

					$ret['tokens'][] = array(
						'pos'  => $pos,
						'len'  => 1 + $spn,
						'type' => substr($ret['tokens'][$k]['type'], 0, -5) . 'End',
						'quantifiers' => $quantifiers
					);

					$pos += 1 + $spn;
					break;

				default:
					++$pos;
			}
		}

		if (!empty($openSubpatterns))
		{
			throw new RuntimeException('Could not find matching pattern end for left parenthesis at pos ' . $ret['tokens'][$openSubpatterns[0]]['pos']);
		}

		return $ret;
	}

	/*
	* Convert a PCRE regexp to a Javascript regexp
	*
	* @param  string  $regexp    PCRE regexp
	* @param  array  &$regexpMap Will be replaced with an array mapping named capture to their index
	* @return string
	*/
	public function pcreToJs($regexp, &$regexpMap = null)
	{
		$regexpInfo = $this->parseRegexp($regexp);

		$dotAll = (strpos($regexpInfo['modifiers'], 's') !== false);

		$regexp = '';
		$pos = 0;

		$captureIndex = 0;
		$regexpMap    = array();

		foreach ($regexpInfo['tokens'] as $tok)
		{
			$regexp .= $this->unfoldUnicodeProperties(
				substr($regexpInfo['regexp'], $pos, $tok['pos'] - $pos),
				false,
				$dotAll
			);

			switch ($tok['type'])
			{
				case 'option':
					throw new RuntimeException('Regexp options are not supported');

				case 'capturingSubpatternStart':
					$regexp .= '(';

					++$captureIndex;

					if (isset($tok['name']))
					{
						$regexpMap[$tok['name']] = $captureIndex;
					}
					break;

				case 'nonCapturingSubpatternStart':
					if ($tok['options'])
					{
						throw new RuntimeException('Subpattern options are not supported');
					}

					$regexp .= '(?:';
					break;

				case 'capturingSubpatternEnd':
				case 'nonCapturingSubpatternEnd':
					$regexp .= ')' . substr($tok['quantifiers'], 0, 1);
					break;

				case 'characterClass':
					$regexp .= '[';
					$regexp .= $this->unfoldUnicodeProperties(
						$tok['content'],
						true,
						false
					);
					$regexp .= ']' . substr($tok['quantifiers'], 0, 1);
					break;

				case 'lookaheadAssertionStart':
					$regexp .= '(?=';
					break;

				case 'negativeLookaheadAssertionStart':
					$regexp .= '(?!';
					break;

				case 'lookbehindAssertionStart':
					throw new RuntimeException('Lookbehind assertions are not supported');

				case 'negativeLookbehindAssertionStart':
					throw new RuntimeException('Negative lookbehind assertions are not supported');

				case 'lookaheadAssertionEnd':
				case 'negativeLookaheadAssertionEnd':
					$regexp .= ')';
					break;

				// @codeCoverageIgnoreStart
				default:
					throw new RuntimeException("Unknown token type '" . $tok['type'] . "' encountered while parsing regexp");
				// @codeCoverageIgnoreEnd
			}

			$pos = $tok['pos'] + $tok['len'];
		}

		$regexp .= $this->unfoldUnicodeProperties(
			substr($regexpInfo['regexp'], $pos),
			false,
			$dotAll
		);

		if ($regexpInfo['delimiter'] !== '/')
		{
			$regexp = preg_replace('#(?<!\\\\)((?:\\\\\\\\)*)/#', '$1\\/', $regexp);
		}

		$modifiers = preg_replace('#[DSsu]#', '', $regexpInfo['modifiers']);
		$regexp = '/' . $regexp . '/' . $modifiers;

		return $regexp;
	}

	protected function unfoldUnicodeProperties($str, $inCharacterClass, $dotAll)
	{
		$unicodeProps = self::$unicodeProps;

		$propNames = array();
		foreach (array_keys($unicodeProps) as $propName)
		{
			$propNames[] = $propName;
			$propNames[] = preg_replace('#(.)(.+)#', '$1\\{$2\\}', $propName);
			$propNames[] = preg_replace('#(.)(.+)#', '$1\\{\\^$2\\}', $propName);
		}

		$str = preg_replace_callback(
			'#(?<!\\\\)((?:\\\\\\\\)*)\\\\(' . implode('|', $propNames) . ')#',
			function ($m) use ($inCharacterClass, $unicodeProps)
			{
				$propName = preg_replace('#[\\{\\}]#', '', $m[2]);

				if ($propName[1] === '^')
				{
					/**
					* Replace p^L with PL
					*/
					$propName = (($propName[0] === 'p') ? 'P' : 'p') . substr($propName, 2);
				}

				return (($inCharacterClass) ? '' : '[')
				     . $unicodeProps[$propName]
				     . (($inCharacterClass) ? '' : ']');
			},
			$str
		);

		if ($dotAll)
		{
			$str = preg_replace(
				'#(?<!\\\\)((?:\\\\\\\\)*)\\.#',
				'$1[\\s\\S]',
				$str
			);
		}

		return $str;
	}

	/**
	* Ranges to be used in Javascript regexps in place of PCRE's Unicode properties
	*/
	static protected $unicodeProps = array(
		'PL' => 'A-Za-z\\u00C0-\\u02C1\\u02C6-\\u02D1\\u02E0-\\u02E4\\u02EC-\\u02EE\\u0370-\\u0377\\u037A-\\u037D\\u0386-\\u0481\\u048A-\\u0527\\u0531-\\u0556\\u0561-\\u0587\\u05D0-\\u05EA\\u05F0-\\u05F2\\u0620-\\u064A\\u066E-\\u06D5\\u06E5\\u06E6\\u06EE\\u06EF\\u06FA-\\u06FC\\u0710-\\u072F\\u074D-\\u07A5\\u07CA-\\u07EA\\u07F4\\u07F5\\u0800-\\u0815\\u0840-\\u0858\\u0904-\\u0939\\u0958-\\u0961\\u0971-\\u097F\\u0985-\\u098C\\u098F\\u0990\\u0993-\\u09B2\\u09B6-\\u09B9\\u09DC-\\u09E1\\u09F0\\u09F1\\u0A05-\\u0A0A\\u0A0F\\u0A10\\u0A13-\\u0A39\\u0A59-\\u0A5E\\u0A72-\\u0A74\\u0A85-\\u0AB9\\u0AE0\\u0AE1\\u0B05-\\u0B0C\\u0B0F\\u0B10\\u0B13-\\u0B39\\u0B5C-\\u0B61\\u0B83-\\u0B8A\\u0B8E-\\u0B95\\u0B99-\\u0B9F\\u0BA3\\u0BA4\\u0BA8-\\u0BAA\\u0BAE-\\u0BB9\\u0C05-\\u0C39\\u0C58\\u0C59\\u0C60\\u0C61\\u0C85-\\u0CB9\\u0CDE-\\u0CE1\\u0CF1\\u0CF2\\u0D05-\\u0D3A\\u0D60\\u0D61\\u0D7A-\\u0D7F\\u0D85-\\u0D96\\u0D9A-\\u0DBD\\u0DC0-\\u0DC6\\u0E01-\\u0E33\\u0E40-\\u0E46\\u0E81-\\u0E84\\u0E87-\\u0E8A\\u0E94-\\u0EA7\\u0EAA-\\u0EB3\\u0EC0-\\u0EC6\\u0EDC\\u0EDD\\u0F40-\\u0F6C\\u0F88-\\u0F8C\\u1000-\\u102A\\u1050-\\u1055\\u105A-\\u105D\\u1065\\u1066\\u106E-\\u1070\\u1075-\\u1081\\u10A0-\\u10C5\\u10D0-\\u10FC\\u1100-\\u124D\\u1250-\\u125D\\u1260-\\u128D\\u1290-\\u12B5\\u12B8-\\u12C5\\u12C8-\\u1315\\u1318-\\u135A\\u1380-\\u138F\\u13A0-\\u13F4\\u1401-\\u166C\\u166F-\\u169A\\u16A0-\\u16EA\\u1700-\\u1711\\u1720-\\u1731\\u1740-\\u1751\\u1760-\\u1770\\u1780-\\u17B3\\u1820-\\u1877\\u1880-\\u18AA\\u18B0-\\u18F5\\u1900-\\u191C\\u1950-\\u196D\\u1970-\\u1974\\u1980-\\u19AB\\u19C1-\\u19C7\\u1A00-\\u1A16\\u1A20-\\u1A54\\u1B05-\\u1B33\\u1B45-\\u1B4B\\u1B83-\\u1BA0\\u1BAE\\u1BAF\\u1BC0-\\u1BE5\\u1C00-\\u1C23\\u1C4D-\\u1C4F\\u1C5A-\\u1C7D\\u1CE9-\\u1CF1\\u1D00-\\u1DBF\\u1E00-\\u1F15\\u1F18-\\u1F1D\\u1F20-\\u1F45\\u1F48-\\u1F4D\\u1F50-\\u1F7D\\u1F80-\\u1FBE\\u1FC2-\\u1FCC\\u1FD0-\\u1FD3\\u1FD6-\\u1FDB\\u1FE0-\\u1FEC\\u1FF2-\\u1FFC\\u2090-\\u209C\\u210A-\\u2115\\u2119-\\u211D\\u2124-\\u2139\\u213C-\\u213F\\u2145-\\u2149\\u2183\\u2184\\u2C00-\\u2CE4\\u2CEB-\\u2CEE\\u2D00-\\u2D25\\u2D30-\\u2D65\\u2D80-\\u2D96\\u2DA0-\\u2DDE\\u3005\\u3006\\u3031-\\u3035\\u303B\\u303C\\u3041-\\u3096\\u309D-\\u30FF\\u3105-\\u312D\\u3131-\\u318E\\u31A0-\\u31BA\\u31F0-\\u31FF\\u3400-\\u4DB5\\u4E00-\\u9FCB\\uA000-\\uA48C\\uA4D0-\\uA4FD\\uA500-\\uA60C\\uA610-\\uA61F\\uA62A\\uA62B\\uA640-\\uA66E\\uA67F-\\uA697\\uA6A0-\\uA6E5\\uA717-\\uA71F\\uA722-\\uA788\\uA78B-\\uA791\\uA7A0-\\uA7A9\\uA7FA-\\uA822\\uA840-\\uA873\\uA882-\\uA8B3\\uA8F2-\\uA8F7\\uA90A-\\uA925\\uA930-\\uA946\\uA960-\\uA97C\\uA984-\\uA9B2\\uAA00-\\uAA28\\uAA40-\\uAA4B\\uAA60-\\uAA76\\uAA80-\\uAAB1\\uAAB5\\uAAB6\\uAAB9-\\uAABD\\uAAC0-\\uAAC2\\uAADB-\\uAADD\\uAB01-\\uAB06\\uAB09-\\uAB0E\\uAB11-\\uAB16\\uAB20-\\uAB2E\\uABC0-\\uABE2\\uAC00-\\uD7A3\\uD7B0-\\uD7C6\\uD7CB-\\uD7FB\\uF900-\\uFA2D\\uFA30-\\uFA6D\\uFA70-\\uFAD9\\uFB00-\\uFB06\\uFB13-\\uFB17\\uFB1D-\\uFBB1\\uFBD3-\\uFD3D\\uFD50-\\uFD8F\\uFD92-\\uFDC7\\uFDF0-\\uFDFB\\uFE70-\\uFEFC\\uFF21-\\uFF3A\\uFF41-\\uFF5A\\uFF66-\\uFFBE\\uFFC2-\\uFFC7\\uFFCA-\\uFFCF\\uFFD2-\\uFFD7\\uFFDA-\\uFFDC',
		'PLm' => '\\u02B0-\\u02C1\\u02C6-\\u02D1\\u02E0-\\u02E4\\u02EC-\\u02EE\\u06E5\\u06E6\\u07F4\\u07F5\\u1C78-\\u1C7D\\u1D2C-\\u1D61\\u1D9B-\\u1DBF\\u2090-\\u209C\\u3031-\\u3035\\u309D\\u309E\\u30FC-\\u30FE\\uA4F8-\\uA4FD\\uA717-\\uA71F\\uFF9E\\uFF9F',
		'PLo' => '\\u01C0-\\u01C3\\u05D0-\\u05EA\\u05F0-\\u05F2\\u0620-\\u064A\\u066E-\\u06D5\\u06EE\\u06EF\\u06FA-\\u06FC\\u0710-\\u072F\\u074D-\\u07A5\\u07CA-\\u07EA\\u0800-\\u0815\\u0840-\\u0858\\u0904-\\u0939\\u0958-\\u0961\\u0972-\\u097F\\u0985-\\u098C\\u098F\\u0990\\u0993-\\u09B2\\u09B6-\\u09B9\\u09DC-\\u09E1\\u09F0\\u09F1\\u0A05-\\u0A0A\\u0A0F\\u0A10\\u0A13-\\u0A39\\u0A59-\\u0A5E\\u0A72-\\u0A74\\u0A85-\\u0AB9\\u0AE0\\u0AE1\\u0B05-\\u0B0C\\u0B0F\\u0B10\\u0B13-\\u0B39\\u0B5C-\\u0B61\\u0B83-\\u0B8A\\u0B8E-\\u0B95\\u0B99-\\u0B9F\\u0BA3\\u0BA4\\u0BA8-\\u0BAA\\u0BAE-\\u0BB9\\u0C05-\\u0C39\\u0C58\\u0C59\\u0C60\\u0C61\\u0C85-\\u0CB9\\u0CDE-\\u0CE1\\u0CF1\\u0CF2\\u0D05-\\u0D3A\\u0D60\\u0D61\\u0D7A-\\u0D7F\\u0D85-\\u0D96\\u0D9A-\\u0DBD\\u0DC0-\\u0DC6\\u0E01-\\u0E33\\u0E40-\\u0E45\\u0E81-\\u0E84\\u0E87-\\u0E8A\\u0E94-\\u0EA7\\u0EAA-\\u0EB3\\u0EC0-\\u0EC4\\u0EDC\\u0EDD\\u0F40-\\u0F6C\\u0F88-\\u0F8C\\u1000-\\u102A\\u1050-\\u1055\\u105A-\\u105D\\u1065\\u1066\\u106E-\\u1070\\u1075-\\u1081\\u10D0-\\u10FA\\u1100-\\u124D\\u1250-\\u125D\\u1260-\\u128D\\u1290-\\u12B5\\u12B8-\\u12C5\\u12C8-\\u1315\\u1318-\\u135A\\u1380-\\u138F\\u13A0-\\u13F4\\u1401-\\u166C\\u166F-\\u169A\\u16A0-\\u16EA\\u1700-\\u1711\\u1720-\\u1731\\u1740-\\u1751\\u1760-\\u1770\\u1780-\\u17B3\\u1820-\\u1877\\u1880-\\u18AA\\u18B0-\\u18F5\\u1900-\\u191C\\u1950-\\u196D\\u1970-\\u1974\\u1980-\\u19AB\\u19C1-\\u19C7\\u1A00-\\u1A16\\u1A20-\\u1A54\\u1B05-\\u1B33\\u1B45-\\u1B4B\\u1B83-\\u1BA0\\u1BAE\\u1BAF\\u1BC0-\\u1BE5\\u1C00-\\u1C23\\u1C4D-\\u1C4F\\u1C5A-\\u1C77\\u1CE9-\\u1CF1\\u2135-\\u2138\\u2D30-\\u2D65\\u2D80-\\u2D96\\u2DA0-\\u2DDE\\u3041-\\u3096\\u309F-\\u30FA\\u3105-\\u312D\\u3131-\\u318E\\u31A0-\\u31BA\\u31F0-\\u31FF\\u3400-\\u4DB5\\u4E00-\\u9FCB\\uA000-\\uA48C\\uA4D0-\\uA4F7\\uA500-\\uA60B\\uA610-\\uA61F\\uA62A\\uA62B\\uA6A0-\\uA6E5\\uA7FB-\\uA822\\uA840-\\uA873\\uA882-\\uA8B3\\uA8F2-\\uA8F7\\uA90A-\\uA925\\uA930-\\uA946\\uA960-\\uA97C\\uA984-\\uA9B2\\uAA00-\\uAA28\\uAA40-\\uAA4B\\uAA60-\\uAA76\\uAA80-\\uAAB1\\uAAB5\\uAAB6\\uAAB9-\\uAABD\\uAAC0-\\uAAC2\\uAADB\\uAADC\\uAB01-\\uAB06\\uAB09-\\uAB0E\\uAB11-\\uAB16\\uAB20-\\uAB2E\\uABC0-\\uABE2\\uAC00-\\uD7A3\\uD7B0-\\uD7C6\\uD7CB-\\uD7FB\\uF900-\\uFA2D\\uFA30-\\uFA6D\\uFA70-\\uFAD9\\uFB1D-\\uFBB1\\uFBD3-\\uFD3D\\uFD50-\\uFD8F\\uFD92-\\uFDC7\\uFDF0-\\uFDFB\\uFE70-\\uFEFC\\uFF66-\\uFF9D\\uFFA0-\\uFFBE\\uFFC2-\\uFFC7\\uFFCA-\\uFFCF\\uFFD2-\\uFFD7\\uFFDA-\\uFFDC',
		'PN' => '0-9\\u00B2\\u00B3\\u00BC-\\u00BE\\u0660-\\u0669\\u06F0-\\u06F9\\u07C0-\\u07C9\\u0966-\\u096F\\u09E6-\\u09EF\\u09F4-\\u09F9\\u0A66-\\u0A6F\\u0AE6-\\u0AEF\\u0B66-\\u0B6F\\u0B72-\\u0B77\\u0BE6-\\u0BF2\\u0C66-\\u0C6F\\u0C78-\\u0C7E\\u0CE6-\\u0CEF\\u0D66-\\u0D75\\u0E50-\\u0E59\\u0ED0-\\u0ED9\\u0F20-\\u0F33\\u1040-\\u1049\\u1090-\\u1099\\u1369-\\u137C\\u16EE-\\u16F0\\u17E0-\\u17E9\\u17F0-\\u17F9\\u1810-\\u1819\\u1946-\\u194F\\u19D0-\\u19DA\\u1A80-\\u1A89\\u1A90-\\u1A99\\u1B50-\\u1B59\\u1BB0-\\u1BB9\\u1C40-\\u1C49\\u1C50-\\u1C59\\u2074-\\u2079\\u2080-\\u2089\\u2150-\\u2182\\u2185-\\u2189\\u2460-\\u249B\\u24EA-\\u24FF\\u2776-\\u2793\\u3021-\\u3029\\u3038-\\u303A\\u3192-\\u3195\\u3220-\\u3229\\u3251-\\u325F\\u3280-\\u3289\\u32B1-\\u32BF\\uA620-\\uA629\\uA6E6-\\uA6EF\\uA830-\\uA835\\uA8D0-\\uA8D9\\uA900-\\uA909\\uA9D0-\\uA9D9\\uAA50-\\uAA59\\uABF0-\\uABF9\\uFF10-\\uFF19',
		'PNd' => '0-9\\u0660-\\u0669\\u06F0-\\u06F9\\u07C0-\\u07C9\\u0966-\\u096F\\u09E6-\\u09EF\\u0A66-\\u0A6F\\u0AE6-\\u0AEF\\u0B66-\\u0B6F\\u0BE6-\\u0BEF\\u0C66-\\u0C6F\\u0CE6-\\u0CEF\\u0D66-\\u0D6F\\u0E50-\\u0E59\\u0ED0-\\u0ED9\\u0F20-\\u0F29\\u1040-\\u1049\\u1090-\\u1099\\u17E0-\\u17E9\\u1810-\\u1819\\u1946-\\u194F\\u19D0-\\u19D9\\u1A80-\\u1A89\\u1A90-\\u1A99\\u1B50-\\u1B59\\u1BB0-\\u1BB9\\u1C40-\\u1C49\\u1C50-\\u1C59\\uA620-\\uA629\\uA8D0-\\uA8D9\\uA900-\\uA909\\uA9D0-\\uA9D9\\uAA50-\\uAA59\\uABF0-\\uABF9\\uFF10-\\uFF19',
		'PNl' => '\\u16EE-\\u16F0\\u2160-\\u2182\\u2185-\\u2188\\u3021-\\u3029\\u3038-\\u303A\\uA6E6-\\uA6EF',
		'PNo' => '\\u00B2\\u00B3\\u00BC-\\u00BE\\u09F4-\\u09F9\\u0B72-\\u0B77\\u0BF0-\\u0BF2\\u0C78-\\u0C7E\\u0D70-\\u0D75\\u0F2A-\\u0F33\\u1369-\\u137C\\u17F0-\\u17F9\\u2074-\\u2079\\u2080-\\u2089\\u2150-\\u215F\\u2460-\\u249B\\u24EA-\\u24FF\\u2776-\\u2793\\u3192-\\u3195\\u3220-\\u3229\\u3251-\\u325F\\u3280-\\u3289\\u32B1-\\u32BF\\uA830-\\uA835',
		'PP' => '\\!-/\\:;\\?@\\[-_\\{-\\}\\u055A-\\u055F\\u0589\\u058A\\u05BE-\\u05C0\\u05F3\\u05F4\\u0609-\\u060D\\u061E\\u061F\\u066A-\\u066D\\u0700-\\u070D\\u07F7-\\u07F9\\u0830-\\u083E\\u0964\\u0965\\u0E5A\\u0E5B\\u0F04-\\u0F12\\u0F3A-\\u0F3D\\u0FD0-\\u0FD4\\u0FD9\\u0FDA\\u104A-\\u104F\\u1361-\\u1368\\u166D\\u166E\\u169B\\u169C\\u16EB-\\u16ED\\u1735\\u1736\\u17D4-\\u17DA\\u1800-\\u180A\\u1944\\u1945\\u1A1E\\u1A1F\\u1AA0-\\u1AAD\\u1B5A-\\u1B60\\u1BFC-\\u1BFF\\u1C3B-\\u1C3F\\u1C7E\\u1C7F\\u2010-\\u2027\\u2030-\\u205E\\u207D\\u207E\\u208D\\u208E\\u2329\\u232A\\u2768-\\u2775\\u27C5\\u27C6\\u27E6-\\u27EF\\u2983-\\u2998\\u29D8-\\u29DB\\u29FC\\u29FD\\u2CF9-\\u2CFF\\u2E00-\\u2E31\\u3001-\\u3003\\u3008-\\u3011\\u3014-\\u301F\\uA4FE\\uA4FF\\uA60D-\\uA60F\\uA6F2-\\uA6F7\\uA874-\\uA877\\uA8CE\\uA8CF\\uA8F8-\\uA8FA\\uA92E\\uA92F\\uA9C1-\\uA9CD\\uA9DE\\uA9DF\\uAA5C-\\uAA5F\\uAADE\\uAADF\\uFD3E\\uFD3F\\uFE10-\\uFE19\\uFE30-\\uFE63\\uFE68-\\uFE6B\\uFF01-\\uFF0F\\uFF1A\\uFF1B\\uFF1F\\uFF20\\uFF3B-\\uFF3F\\uFF5B-\\uFF65',
		'PPc' => '\\u203F\\u2040\\uFE33\\uFE34\\uFE4D-\\uFE4F',
		'PPd' => '\\u2010-\\u2015\\uFE31\\uFE32',
		'PPe' => '\\u0F3B-\\u0F3D\\u2769-\\u2775\\u27E7-\\u27EF\\u2984-\\u2998\\u29D9-\\u29DB\\u2E23-\\u2E29\\u3009-\\u3011\\u3015-\\u301B\\u301E\\u301F\\uFE36-\\uFE44\\uFE5A-\\uFE5E',
		'PPf' => '\\u2E03-\\u2E05',
		'PPi' => '\\u201B\\u201C\\u2E02-\\u2E04',
		'PPo' => '\\!-\'\\*-/\\:;\\?@\\u055A-\\u055F\\u05F3\\u05F4\\u0609-\\u060D\\u061E\\u061F\\u066A-\\u066D\\u0700-\\u070D\\u07F7-\\u07F9\\u0830-\\u083E\\u0964\\u0965\\u0E5A\\u0E5B\\u0F04-\\u0F12\\u0FD0-\\u0FD4\\u0FD9\\u0FDA\\u104A-\\u104F\\u1361-\\u1368\\u166D\\u166E\\u16EB-\\u16ED\\u1735\\u1736\\u17D4-\\u17DA\\u1800-\\u180A\\u1944\\u1945\\u1A1E\\u1A1F\\u1AA0-\\u1AAD\\u1B5A-\\u1B60\\u1BFC-\\u1BFF\\u1C3B-\\u1C3F\\u1C7E\\u1C7F\\u2016\\u2017\\u2020-\\u2027\\u2030-\\u2038\\u203B-\\u203E\\u2041-\\u2043\\u2047-\\u205E\\u2CF9-\\u2CFF\\u2E00\\u2E01\\u2E06-\\u2E08\\u2E0E-\\u2E1B\\u2E1E\\u2E1F\\u2E2A-\\u2E31\\u3001-\\u3003\\uA4FE\\uA4FF\\uA60D-\\uA60F\\uA6F2-\\uA6F7\\uA874-\\uA877\\uA8CE\\uA8CF\\uA8F8-\\uA8FA\\uA92E\\uA92F\\uA9C1-\\uA9CD\\uA9DE\\uA9DF\\uAA5C-\\uAA5F\\uAADE\\uAADF\\uFE10-\\uFE16\\uFE45\\uFE46\\uFE49-\\uFE4C\\uFE50-\\uFE57\\uFE5F-\\uFE61\\uFE68-\\uFE6B\\uFF01-\\uFF07\\uFF0A-\\uFF0F\\uFF1A\\uFF1B\\uFF1F\\uFF20\\uFF64\\uFF65',
		'PPs' => '\\u0F3A-\\u0F3C\\u2768-\\u2774\\u27E6-\\u27EE\\u2983-\\u2997\\u29D8-\\u29DA\\u2E22-\\u2E28\\u3008-\\u3010\\u3014-\\u301A\\uFE35-\\uFE43\\uFE59-\\uFE5D',
		'PS' => '\\<-\\>\\^-`\\|-~\\u00A2-\\u00A9\\u00AC-\\u00B1\\u00B4-\\u00B8\\u02C2-\\u02C5\\u02D2-\\u02DF\\u02E5-\\u02FF\\u0384\\u0385\\u0606-\\u0608\\u060E\\u060F\\u06FD\\u06FE\\u09F2\\u09F3\\u09FA\\u09FB\\u0BF3-\\u0BFA\\u0F01-\\u0F03\\u0F13-\\u0F17\\u0F1A-\\u0F1F\\u0F34-\\u0F38\\u0FBE-\\u0FCF\\u0FD5-\\u0FD8\\u109E\\u109F\\u1390-\\u1399\\u19DE-\\u19FF\\u1B61-\\u1B6A\\u1B74-\\u1B7C\\u1FBD-\\u1FC1\\u1FCD-\\u1FCF\\u1FDD-\\u1FDF\\u1FED-\\u1FEF\\u1FFD\\u1FFE\\u207A-\\u207C\\u208A-\\u208C\\u20A0-\\u20B9\\u2100-\\u2109\\u2114-\\u2118\\u211E-\\u2129\\u213A\\u213B\\u2140-\\u2144\\u214A-\\u214F\\u2190-\\u2328\\u232B-\\u23F3\\u2400-\\u2426\\u2440-\\u244A\\u249C-\\u24E9\\u2500-\\u2767\\u2794-\\u27C4\\u27C7-\\u27E5\\u27F0-\\u2982\\u2999-\\u29D7\\u29DC-\\u29FB\\u29FE-\\u2B4C\\u2B50-\\u2B59\\u2CE5-\\u2CEA\\u2E80-\\u2EF3\\u2F00-\\u2FD5\\u2FF0-\\u2FFB\\u3012\\u3013\\u3036\\u3037\\u303E\\u303F\\u309B\\u309C\\u3190\\u3191\\u3196-\\u319F\\u31C0-\\u31E3\\u3200-\\u321E\\u322A-\\u3250\\u3260-\\u327F\\u328A-\\u32B0\\u32C0-\\u33FF\\u4DC0-\\u4DFF\\uA490-\\uA4C6\\uA700-\\uA716\\uA720\\uA721\\uA789\\uA78A\\uA828-\\uA82B\\uA836-\\uA839\\uAA77-\\uAA79\\uFBB2-\\uFBC1\\uFDFC\\uFDFD\\uFE62-\\uFE66\\uFF1C-\\uFF1E\\uFF3E-\\uFF40\\uFF5C-\\uFF5E\\uFFE0-\\uFFEE\\uFFFC\\uFFFD',
		'PSc' => '\\u00A2-\\u00A5\\u09F2\\u09F3\\u20A0-\\u20B9\\uFFE0\\uFFE1\\uFFE5\\uFFE6',
		'PSk' => '\\^-`\\u02C2-\\u02C5\\u02D2-\\u02DF\\u02E5-\\u02FF\\u0384\\u0385\\u1FBD-\\u1FC1\\u1FCD-\\u1FCF\\u1FDD-\\u1FDF\\u1FED-\\u1FEF\\u1FFD\\u1FFE\\u309B\\u309C\\uA700-\\uA716\\uA720\\uA721\\uA789\\uA78A\\uFBB2-\\uFBC1\\uFF3E-\\uFF40',
		'PSm' => '\\<-\\>\\|-~\\u0606-\\u0608\\u207A-\\u207C\\u208A-\\u208C\\u2140-\\u2144\\u2190-\\u2194\\u219A\\u219B\\u21CE\\u21CF\\u21D2-\\u21D4\\u21F4-\\u22FF\\u2308-\\u230B\\u2320\\u2321\\u239B-\\u23B3\\u23DC-\\u23E1\\u25F8-\\u25FF\\u27C0-\\u27C4\\u27C7-\\u27E5\\u27F0-\\u27FF\\u2900-\\u2982\\u2999-\\u29D7\\u29DC-\\u29FB\\u29FE-\\u2AFF\\u2B30-\\u2B44\\u2B47-\\u2B4C\\uFE62-\\uFE66\\uFF1C-\\uFF1E\\uFF5C-\\uFF5E\\uFFE9-\\uFFEC',
		'PSo' => '\\u00A6-\\u00A9\\u00AE-\\u00B0\\u060E\\u060F\\u06FD\\u06FE\\u0BF3-\\u0BFA\\u0F01-\\u0F03\\u0F13-\\u0F17\\u0F1A-\\u0F1F\\u0F34-\\u0F38\\u0FBE-\\u0FCF\\u0FD5-\\u0FD8\\u109E\\u109F\\u1390-\\u1399\\u19DE-\\u19FF\\u1B61-\\u1B6A\\u1B74-\\u1B7C\\u2100-\\u2109\\u2114-\\u2117\\u211E-\\u2129\\u213A\\u213B\\u214A-\\u214F\\u2195-\\u2199\\u219C-\\u21CD\\u21D0-\\u21F3\\u2300-\\u2307\\u230C-\\u231F\\u2322-\\u2328\\u232B-\\u239A\\u23B4-\\u23DB\\u23E2-\\u23F3\\u2400-\\u2426\\u2440-\\u244A\\u249C-\\u24E9\\u2500-\\u25F7\\u2600-\\u2767\\u2794-\\u27BF\\u2800-\\u28FF\\u2B00-\\u2B2F\\u2B45\\u2B46\\u2B50-\\u2B59\\u2CE5-\\u2CEA\\u2E80-\\u2EF3\\u2F00-\\u2FD5\\u2FF0-\\u2FFB\\u3012\\u3013\\u3036\\u3037\\u303E\\u303F\\u3190\\u3191\\u3196-\\u319F\\u31C0-\\u31E3\\u3200-\\u321E\\u322A-\\u3250\\u3260-\\u327F\\u328A-\\u32B0\\u32C0-\\u33FF\\u4DC0-\\u4DFF\\uA490-\\uA4C6\\uA828-\\uA82B\\uA836-\\uA839\\uAA77-\\uAA79\\uFFED\\uFFEE\\uFFFC\\uFFFD',
		'PZ' => '\\u2000-\\u200A\\u2028\\u2029',
		'PZl' => '',
		'PZp' => '',
		'PZs' => '\\u2000-\\u200A',
		'pL' => 'A-Za-z\\u00AA\\u00B5\\u00BA\\u00C0-\\u00D6\\u00D8-\\u00F6\\u00F8-\\u02C1\\u02C6-\\u02D1\\u02E0-\\u02E4\\u02EC\\u02EE\\u0370-\\u0374\\u0376\\u0377\\u037A-\\u037D\\u0386\\u0388-\\u038A\\u038C\\u038E-\\u03A1\\u03A3-\\u03F5\\u03F7-\\u0481\\u048A-\\u0527\\u0531-\\u0556\\u0559\\u0561-\\u0587\\u05D0-\\u05EA\\u05F0-\\u05F2\\u0620-\\u064A\\u066E\\u066F\\u0671-\\u06D3\\u06D5\\u06E5\\u06E6\\u06EE\\u06EF\\u06FA-\\u06FC\\u06FF\\u0710\\u0712-\\u072F\\u074D-\\u07A5\\u07B1\\u07CA-\\u07EA\\u07F4\\u07F5\\u07FA\\u0800-\\u0815\\u081A\\u0824\\u0828\\u0840-\\u0858\\u0904-\\u0939\\u093D\\u0950\\u0958-\\u0961\\u0971-\\u0977\\u0979-\\u097F\\u0985-\\u098C\\u098F\\u0990\\u0993-\\u09A8\\u09AA-\\u09B0\\u09B2\\u09B6-\\u09B9\\u09BD\\u09CE\\u09DC\\u09DD\\u09DF-\\u09E1\\u09F0\\u09F1\\u0A05-\\u0A0A\\u0A0F\\u0A10\\u0A13-\\u0A28\\u0A2A-\\u0A30\\u0A32\\u0A33\\u0A35\\u0A36\\u0A38\\u0A39\\u0A59-\\u0A5C\\u0A5E\\u0A72-\\u0A74\\u0A85-\\u0A8D\\u0A8F-\\u0A91\\u0A93-\\u0AA8\\u0AAA-\\u0AB0\\u0AB2\\u0AB3\\u0AB5-\\u0AB9\\u0ABD\\u0AD0\\u0AE0\\u0AE1\\u0B05-\\u0B0C\\u0B0F\\u0B10\\u0B13-\\u0B28\\u0B2A-\\u0B30\\u0B32\\u0B33\\u0B35-\\u0B39\\u0B3D\\u0B5C\\u0B5D\\u0B5F-\\u0B61\\u0B71\\u0B83\\u0B85-\\u0B8A\\u0B8E-\\u0B90\\u0B92-\\u0B95\\u0B99\\u0B9A\\u0B9C\\u0B9E\\u0B9F\\u0BA3\\u0BA4\\u0BA8-\\u0BAA\\u0BAE-\\u0BB9\\u0BD0\\u0C05-\\u0C0C\\u0C0E-\\u0C10\\u0C12-\\u0C28\\u0C2A-\\u0C33\\u0C35-\\u0C39\\u0C3D\\u0C58\\u0C59\\u0C60\\u0C61\\u0C85-\\u0C8C\\u0C8E-\\u0C90\\u0C92-\\u0CA8\\u0CAA-\\u0CB3\\u0CB5-\\u0CB9\\u0CBD\\u0CDE\\u0CE0\\u0CE1\\u0CF1\\u0CF2\\u0D05-\\u0D0C\\u0D0E-\\u0D10\\u0D12-\\u0D3A\\u0D3D\\u0D4E\\u0D60\\u0D61\\u0D7A-\\u0D7F\\u0D85-\\u0D96\\u0D9A-\\u0DB1\\u0DB3-\\u0DBB\\u0DBD\\u0DC0-\\u0DC6\\u0E01-\\u0E30\\u0E32\\u0E33\\u0E40-\\u0E46\\u0E81\\u0E82\\u0E84\\u0E87\\u0E88\\u0E8A\\u0E8D\\u0E94-\\u0E97\\u0E99-\\u0E9F\\u0EA1-\\u0EA3\\u0EA5\\u0EA7\\u0EAA\\u0EAB\\u0EAD-\\u0EB0\\u0EB2\\u0EB3\\u0EBD\\u0EC0-\\u0EC4\\u0EC6\\u0EDC\\u0EDD\\u0F00\\u0F40-\\u0F47\\u0F49-\\u0F6C\\u0F88-\\u0F8C\\u1000-\\u102A\\u103F\\u1050-\\u1055\\u105A-\\u105D\\u1061\\u1065\\u1066\\u106E-\\u1070\\u1075-\\u1081\\u108E\\u10A0-\\u10C5\\u10D0-\\u10FA\\u10FC\\u1100-\\u1248\\u124A-\\u124D\\u1250-\\u1256\\u1258\\u125A-\\u125D\\u1260-\\u1288\\u128A-\\u128D\\u1290-\\u12B0\\u12B2-\\u12B5\\u12B8-\\u12BE\\u12C0\\u12C2-\\u12C5\\u12C8-\\u12D6\\u12D8-\\u1310\\u1312-\\u1315\\u1318-\\u135A\\u1380-\\u138F\\u13A0-\\u13F4\\u1401-\\u166C\\u166F-\\u167F\\u1681-\\u169A\\u16A0-\\u16EA\\u1700-\\u170C\\u170E-\\u1711\\u1720-\\u1731\\u1740-\\u1751\\u1760-\\u176C\\u176E-\\u1770\\u1780-\\u17B3\\u17D7\\u17DC\\u1820-\\u1877\\u1880-\\u18A8\\u18AA\\u18B0-\\u18F5\\u1900-\\u191C\\u1950-\\u196D\\u1970-\\u1974\\u1980-\\u19AB\\u19C1-\\u19C7\\u1A00-\\u1A16\\u1A20-\\u1A54\\u1AA7\\u1B05-\\u1B33\\u1B45-\\u1B4B\\u1B83-\\u1BA0\\u1BAE\\u1BAF\\u1BC0-\\u1BE5\\u1C00-\\u1C23\\u1C4D-\\u1C4F\\u1C5A-\\u1C7D\\u1CE9-\\u1CEC\\u1CEE-\\u1CF1\\u1D00-\\u1DBF\\u1E00-\\u1F15\\u1F18-\\u1F1D\\u1F20-\\u1F45\\u1F48-\\u1F4D\\u1F50-\\u1F57\\u1F59\\u1F5B\\u1F5D\\u1F5F-\\u1F7D\\u1F80-\\u1FB4\\u1FB6-\\u1FBC\\u1FBE\\u1FC2-\\u1FC4\\u1FC6-\\u1FCC\\u1FD0-\\u1FD3\\u1FD6-\\u1FDB\\u1FE0-\\u1FEC\\u1FF2-\\u1FF4\\u1FF6-\\u1FFC\\u2071\\u207F\\u2090-\\u209C\\u2102\\u2107\\u210A-\\u2113\\u2115\\u2119-\\u211D\\u2124\\u2126\\u2128\\u212A-\\u212D\\u212F-\\u2139\\u213C-\\u213F\\u2145-\\u2149\\u214E\\u2183\\u2184\\u2C00-\\u2C2E\\u2C30-\\u2C5E\\u2C60-\\u2CE4\\u2CEB-\\u2CEE\\u2D00-\\u2D25\\u2D30-\\u2D65\\u2D6F\\u2D80-\\u2D96\\u2DA0-\\u2DA6\\u2DA8-\\u2DAE\\u2DB0-\\u2DB6\\u2DB8-\\u2DBE\\u2DC0-\\u2DC6\\u2DC8-\\u2DCE\\u2DD0-\\u2DD6\\u2DD8-\\u2DDE\\u2E2F\\u3005\\u3006\\u3031-\\u3035\\u303B\\u303C\\u3041-\\u3096\\u309D-\\u309F\\u30A1-\\u30FA\\u30FC-\\u30FF\\u3105-\\u312D\\u3131-\\u318E\\u31A0-\\u31BA\\u31F0-\\u31FF\\u3400-\\u4DB5\\u4E00-\\u9FCB\\uA000-\\uA48C\\uA4D0-\\uA4FD\\uA500-\\uA60C\\uA610-\\uA61F\\uA62A\\uA62B\\uA640-\\uA66E\\uA67F-\\uA697\\uA6A0-\\uA6E5\\uA717-\\uA71F\\uA722-\\uA788\\uA78B-\\uA78E\\uA790\\uA791\\uA7A0-\\uA7A9\\uA7FA-\\uA801\\uA803-\\uA805\\uA807-\\uA80A\\uA80C-\\uA822\\uA840-\\uA873\\uA882-\\uA8B3\\uA8F2-\\uA8F7\\uA8FB\\uA90A-\\uA925\\uA930-\\uA946\\uA960-\\uA97C\\uA984-\\uA9B2\\uA9CF\\uAA00-\\uAA28\\uAA40-\\uAA42\\uAA44-\\uAA4B\\uAA60-\\uAA76\\uAA7A\\uAA80-\\uAAAF\\uAAB1\\uAAB5\\uAAB6\\uAAB9-\\uAABD\\uAAC0\\uAAC2\\uAADB-\\uAADD\\uAB01-\\uAB06\\uAB09-\\uAB0E\\uAB11-\\uAB16\\uAB20-\\uAB26\\uAB28-\\uAB2E\\uABC0-\\uABE2\\uAC00-\\uD7A3\\uD7B0-\\uD7C6\\uD7CB-\\uD7FB\\uF900-\\uFA2D\\uFA30-\\uFA6D\\uFA70-\\uFAD9\\uFB00-\\uFB06\\uFB13-\\uFB17\\uFB1D\\uFB1F-\\uFB28\\uFB2A-\\uFB36\\uFB38-\\uFB3C\\uFB3E\\uFB40\\uFB41\\uFB43\\uFB44\\uFB46-\\uFBB1\\uFBD3-\\uFD3D\\uFD50-\\uFD8F\\uFD92-\\uFDC7\\uFDF0-\\uFDFB\\uFE70-\\uFE74\\uFE76-\\uFEFC\\uFF21-\\uFF3A\\uFF41-\\uFF5A\\uFF66-\\uFFBE\\uFFC2-\\uFFC7\\uFFCA-\\uFFCF\\uFFD2-\\uFFD7\\uFFDA-\\uFFDC',
		'pLm' => '\\u02B0-\\u02C1\\u02C6-\\u02D1\\u02E0-\\u02E4\\u02EC\\u02EE\\u0374\\u037A\\u0559\\u0640\\u06E5\\u06E6\\u07F4\\u07F5\\u07FA\\u081A\\u0824\\u0828\\u0971\\u0E46\\u0EC6\\u10FC\\u17D7\\u1843\\u1AA7\\u1C78-\\u1C7D\\u1D2C-\\u1D61\\u1D78\\u1D9B-\\u1DBF\\u2071\\u207F\\u2090-\\u209C\\u2C7D\\u2D6F\\u2E2F\\u3005\\u3031-\\u3035\\u303B\\u309D\\u309E\\u30FC-\\u30FE\\uA015\\uA4F8-\\uA4FD\\uA60C\\uA67F\\uA717-\\uA71F\\uA770\\uA788\\uA9CF\\uAA70\\uAADD\\uFF70\\uFF9E\\uFF9F',
		'pLo' => '\\u01BB\\u01C0-\\u01C3\\u0294\\u05D0-\\u05EA\\u05F0-\\u05F2\\u0620-\\u063F\\u0641-\\u064A\\u066E\\u066F\\u0671-\\u06D3\\u06D5\\u06EE\\u06EF\\u06FA-\\u06FC\\u06FF\\u0710\\u0712-\\u072F\\u074D-\\u07A5\\u07B1\\u07CA-\\u07EA\\u0800-\\u0815\\u0840-\\u0858\\u0904-\\u0939\\u093D\\u0950\\u0958-\\u0961\\u0972-\\u0977\\u0979-\\u097F\\u0985-\\u098C\\u098F\\u0990\\u0993-\\u09A8\\u09AA-\\u09B0\\u09B2\\u09B6-\\u09B9\\u09BD\\u09CE\\u09DC\\u09DD\\u09DF-\\u09E1\\u09F0\\u09F1\\u0A05-\\u0A0A\\u0A0F\\u0A10\\u0A13-\\u0A28\\u0A2A-\\u0A30\\u0A32\\u0A33\\u0A35\\u0A36\\u0A38\\u0A39\\u0A59-\\u0A5C\\u0A5E\\u0A72-\\u0A74\\u0A85-\\u0A8D\\u0A8F-\\u0A91\\u0A93-\\u0AA8\\u0AAA-\\u0AB0\\u0AB2\\u0AB3\\u0AB5-\\u0AB9\\u0ABD\\u0AD0\\u0AE0\\u0AE1\\u0B05-\\u0B0C\\u0B0F\\u0B10\\u0B13-\\u0B28\\u0B2A-\\u0B30\\u0B32\\u0B33\\u0B35-\\u0B39\\u0B3D\\u0B5C\\u0B5D\\u0B5F-\\u0B61\\u0B71\\u0B83\\u0B85-\\u0B8A\\u0B8E-\\u0B90\\u0B92-\\u0B95\\u0B99\\u0B9A\\u0B9C\\u0B9E\\u0B9F\\u0BA3\\u0BA4\\u0BA8-\\u0BAA\\u0BAE-\\u0BB9\\u0BD0\\u0C05-\\u0C0C\\u0C0E-\\u0C10\\u0C12-\\u0C28\\u0C2A-\\u0C33\\u0C35-\\u0C39\\u0C3D\\u0C58\\u0C59\\u0C60\\u0C61\\u0C85-\\u0C8C\\u0C8E-\\u0C90\\u0C92-\\u0CA8\\u0CAA-\\u0CB3\\u0CB5-\\u0CB9\\u0CBD\\u0CDE\\u0CE0\\u0CE1\\u0CF1\\u0CF2\\u0D05-\\u0D0C\\u0D0E-\\u0D10\\u0D12-\\u0D3A\\u0D3D\\u0D4E\\u0D60\\u0D61\\u0D7A-\\u0D7F\\u0D85-\\u0D96\\u0D9A-\\u0DB1\\u0DB3-\\u0DBB\\u0DBD\\u0DC0-\\u0DC6\\u0E01-\\u0E30\\u0E32\\u0E33\\u0E40-\\u0E45\\u0E81\\u0E82\\u0E84\\u0E87\\u0E88\\u0E8A\\u0E8D\\u0E94-\\u0E97\\u0E99-\\u0E9F\\u0EA1-\\u0EA3\\u0EA5\\u0EA7\\u0EAA\\u0EAB\\u0EAD-\\u0EB0\\u0EB2\\u0EB3\\u0EBD\\u0EC0-\\u0EC4\\u0EDC\\u0EDD\\u0F00\\u0F40-\\u0F47\\u0F49-\\u0F6C\\u0F88-\\u0F8C\\u1000-\\u102A\\u103F\\u1050-\\u1055\\u105A-\\u105D\\u1061\\u1065\\u1066\\u106E-\\u1070\\u1075-\\u1081\\u108E\\u10D0-\\u10FA\\u1100-\\u1248\\u124A-\\u124D\\u1250-\\u1256\\u1258\\u125A-\\u125D\\u1260-\\u1288\\u128A-\\u128D\\u1290-\\u12B0\\u12B2-\\u12B5\\u12B8-\\u12BE\\u12C0\\u12C2-\\u12C5\\u12C8-\\u12D6\\u12D8-\\u1310\\u1312-\\u1315\\u1318-\\u135A\\u1380-\\u138F\\u13A0-\\u13F4\\u1401-\\u166C\\u166F-\\u167F\\u1681-\\u169A\\u16A0-\\u16EA\\u1700-\\u170C\\u170E-\\u1711\\u1720-\\u1731\\u1740-\\u1751\\u1760-\\u176C\\u176E-\\u1770\\u1780-\\u17B3\\u17DC\\u1820-\\u1842\\u1844-\\u1877\\u1880-\\u18A8\\u18AA\\u18B0-\\u18F5\\u1900-\\u191C\\u1950-\\u196D\\u1970-\\u1974\\u1980-\\u19AB\\u19C1-\\u19C7\\u1A00-\\u1A16\\u1A20-\\u1A54\\u1B05-\\u1B33\\u1B45-\\u1B4B\\u1B83-\\u1BA0\\u1BAE\\u1BAF\\u1BC0-\\u1BE5\\u1C00-\\u1C23\\u1C4D-\\u1C4F\\u1C5A-\\u1C77\\u1CE9-\\u1CEC\\u1CEE-\\u1CF1\\u2135-\\u2138\\u2D30-\\u2D65\\u2D80-\\u2D96\\u2DA0-\\u2DA6\\u2DA8-\\u2DAE\\u2DB0-\\u2DB6\\u2DB8-\\u2DBE\\u2DC0-\\u2DC6\\u2DC8-\\u2DCE\\u2DD0-\\u2DD6\\u2DD8-\\u2DDE\\u3006\\u303C\\u3041-\\u3096\\u309F\\u30A1-\\u30FA\\u30FF\\u3105-\\u312D\\u3131-\\u318E\\u31A0-\\u31BA\\u31F0-\\u31FF\\u3400-\\u4DB5\\u4E00-\\u9FCB\\uA000-\\uA014\\uA016-\\uA48C\\uA4D0-\\uA4F7\\uA500-\\uA60B\\uA610-\\uA61F\\uA62A\\uA62B\\uA66E\\uA6A0-\\uA6E5\\uA7FB-\\uA801\\uA803-\\uA805\\uA807-\\uA80A\\uA80C-\\uA822\\uA840-\\uA873\\uA882-\\uA8B3\\uA8F2-\\uA8F7\\uA8FB\\uA90A-\\uA925\\uA930-\\uA946\\uA960-\\uA97C\\uA984-\\uA9B2\\uAA00-\\uAA28\\uAA40-\\uAA42\\uAA44-\\uAA4B\\uAA60-\\uAA6F\\uAA71-\\uAA76\\uAA7A\\uAA80-\\uAAAF\\uAAB1\\uAAB5\\uAAB6\\uAAB9-\\uAABD\\uAAC0\\uAAC2\\uAADB\\uAADC\\uAB01-\\uAB06\\uAB09-\\uAB0E\\uAB11-\\uAB16\\uAB20-\\uAB26\\uAB28-\\uAB2E\\uABC0-\\uABE2\\uAC00-\\uD7A3\\uD7B0-\\uD7C6\\uD7CB-\\uD7FB\\uF900-\\uFA2D\\uFA30-\\uFA6D\\uFA70-\\uFAD9\\uFB1D\\uFB1F-\\uFB28\\uFB2A-\\uFB36\\uFB38-\\uFB3C\\uFB3E\\uFB40\\uFB41\\uFB43\\uFB44\\uFB46-\\uFBB1\\uFBD3-\\uFD3D\\uFD50-\\uFD8F\\uFD92-\\uFDC7\\uFDF0-\\uFDFB\\uFE70-\\uFE74\\uFE76-\\uFEFC\\uFF66-\\uFF6F\\uFF71-\\uFF9D\\uFFA0-\\uFFBE\\uFFC2-\\uFFC7\\uFFCA-\\uFFCF\\uFFD2-\\uFFD7\\uFFDA-\\uFFDC',
		'pN' => '0-9\\u00B2\\u00B3\\u00B9\\u00BC-\\u00BE\\u0660-\\u0669\\u06F0-\\u06F9\\u07C0-\\u07C9\\u0966-\\u096F\\u09E6-\\u09EF\\u09F4-\\u09F9\\u0A66-\\u0A6F\\u0AE6-\\u0AEF\\u0B66-\\u0B6F\\u0B72-\\u0B77\\u0BE6-\\u0BF2\\u0C66-\\u0C6F\\u0C78-\\u0C7E\\u0CE6-\\u0CEF\\u0D66-\\u0D75\\u0E50-\\u0E59\\u0ED0-\\u0ED9\\u0F20-\\u0F33\\u1040-\\u1049\\u1090-\\u1099\\u1369-\\u137C\\u16EE-\\u16F0\\u17E0-\\u17E9\\u17F0-\\u17F9\\u1810-\\u1819\\u1946-\\u194F\\u19D0-\\u19DA\\u1A80-\\u1A89\\u1A90-\\u1A99\\u1B50-\\u1B59\\u1BB0-\\u1BB9\\u1C40-\\u1C49\\u1C50-\\u1C59\\u2070\\u2074-\\u2079\\u2080-\\u2089\\u2150-\\u2182\\u2185-\\u2189\\u2460-\\u249B\\u24EA-\\u24FF\\u2776-\\u2793\\u2CFD\\u3007\\u3021-\\u3029\\u3038-\\u303A\\u3192-\\u3195\\u3220-\\u3229\\u3251-\\u325F\\u3280-\\u3289\\u32B1-\\u32BF\\uA620-\\uA629\\uA6E6-\\uA6EF\\uA830-\\uA835\\uA8D0-\\uA8D9\\uA900-\\uA909\\uA9D0-\\uA9D9\\uAA50-\\uAA59\\uABF0-\\uABF9\\uFF10-\\uFF19',
		'pNd' => '0-9\\u0660-\\u0669\\u06F0-\\u06F9\\u07C0-\\u07C9\\u0966-\\u096F\\u09E6-\\u09EF\\u0A66-\\u0A6F\\u0AE6-\\u0AEF\\u0B66-\\u0B6F\\u0BE6-\\u0BEF\\u0C66-\\u0C6F\\u0CE6-\\u0CEF\\u0D66-\\u0D6F\\u0E50-\\u0E59\\u0ED0-\\u0ED9\\u0F20-\\u0F29\\u1040-\\u1049\\u1090-\\u1099\\u17E0-\\u17E9\\u1810-\\u1819\\u1946-\\u194F\\u19D0-\\u19D9\\u1A80-\\u1A89\\u1A90-\\u1A99\\u1B50-\\u1B59\\u1BB0-\\u1BB9\\u1C40-\\u1C49\\u1C50-\\u1C59\\uA620-\\uA629\\uA8D0-\\uA8D9\\uA900-\\uA909\\uA9D0-\\uA9D9\\uAA50-\\uAA59\\uABF0-\\uABF9\\uFF10-\\uFF19',
		'pNl' => '\\u16EE-\\u16F0\\u2160-\\u2182\\u2185-\\u2188\\u3007\\u3021-\\u3029\\u3038-\\u303A\\uA6E6-\\uA6EF',
		'pNo' => '\\u00B2\\u00B3\\u00B9\\u00BC-\\u00BE\\u09F4-\\u09F9\\u0B72-\\u0B77\\u0BF0-\\u0BF2\\u0C78-\\u0C7E\\u0D70-\\u0D75\\u0F2A-\\u0F33\\u1369-\\u137C\\u17F0-\\u17F9\\u19DA\\u2070\\u2074-\\u2079\\u2080-\\u2089\\u2150-\\u215F\\u2189\\u2460-\\u249B\\u24EA-\\u24FF\\u2776-\\u2793\\u2CFD\\u3192-\\u3195\\u3220-\\u3229\\u3251-\\u325F\\u3280-\\u3289\\u32B1-\\u32BF\\uA830-\\uA835',
		'pP' => '\\!-#%-\\*,-/\\:;\\?@\\[-\\]_\\{\\}\\u00A1\\u00AB\\u00B7\\u00BB\\u00BF\\u037E\\u0387\\u055A-\\u055F\\u0589\\u058A\\u05BE\\u05C0\\u05C3\\u05C6\\u05F3\\u05F4\\u0609\\u060A\\u060C\\u060D\\u061B\\u061E\\u061F\\u066A-\\u066D\\u06D4\\u0700-\\u070D\\u07F7-\\u07F9\\u0830-\\u083E\\u085E\\u0964\\u0965\\u0970\\u0DF4\\u0E4F\\u0E5A\\u0E5B\\u0F04-\\u0F12\\u0F3A-\\u0F3D\\u0F85\\u0FD0-\\u0FD4\\u0FD9\\u0FDA\\u104A-\\u104F\\u10FB\\u1361-\\u1368\\u1400\\u166D\\u166E\\u169B\\u169C\\u16EB-\\u16ED\\u1735\\u1736\\u17D4-\\u17D6\\u17D8-\\u17DA\\u1800-\\u180A\\u1944\\u1945\\u1A1E\\u1A1F\\u1AA0-\\u1AA6\\u1AA8-\\u1AAD\\u1B5A-\\u1B60\\u1BFC-\\u1BFF\\u1C3B-\\u1C3F\\u1C7E\\u1C7F\\u1CD3\\u2010-\\u2027\\u2030-\\u2043\\u2045-\\u2051\\u2053-\\u205E\\u207D\\u207E\\u208D\\u208E\\u2329\\u232A\\u2768-\\u2775\\u27C5\\u27C6\\u27E6-\\u27EF\\u2983-\\u2998\\u29D8-\\u29DB\\u29FC\\u29FD\\u2CF9-\\u2CFC\\u2CFE\\u2CFF\\u2D70\\u2E00-\\u2E2E\\u2E30\\u2E31\\u3001-\\u3003\\u3008-\\u3011\\u3014-\\u301F\\u3030\\u303D\\u30A0\\u30FB\\uA4FE\\uA4FF\\uA60D-\\uA60F\\uA673\\uA67E\\uA6F2-\\uA6F7\\uA874-\\uA877\\uA8CE\\uA8CF\\uA8F8-\\uA8FA\\uA92E\\uA92F\\uA95F\\uA9C1-\\uA9CD\\uA9DE\\uA9DF\\uAA5C-\\uAA5F\\uAADE\\uAADF\\uABEB\\uFD3E\\uFD3F\\uFE10-\\uFE19\\uFE30-\\uFE52\\uFE54-\\uFE61\\uFE63\\uFE68\\uFE6A\\uFE6B\\uFF01-\\uFF03\\uFF05-\\uFF0A\\uFF0C-\\uFF0F\\uFF1A\\uFF1B\\uFF1F\\uFF20\\uFF3B-\\uFF3D\\uFF3F\\uFF5B\\uFF5D\\uFF5F-\\uFF65',
		'pPc' => '_\\u203F\\u2040\\u2054\\uFE33\\uFE34\\uFE4D-\\uFE4F\\uFF3F',
		'pPd' => '\\-\\u058A\\u05BE\\u1400\\u1806\\u2010-\\u2015\\u2E17\\u2E1A\\u301C\\u3030\\u30A0\\uFE31\\uFE32\\uFE58\\uFE63\\uFF0D',
		'pPe' => '\\)\\]\\}\\u0F3B\\u0F3D\\u169C\\u2046\\u207E\\u208E\\u232A\\u2769\\u276B\\u276D\\u276F\\u2771\\u2773\\u2775\\u27C6\\u27E7\\u27E9\\u27EB\\u27ED\\u27EF\\u2984\\u2986\\u2988\\u298A\\u298C\\u298E\\u2990\\u2992\\u2994\\u2996\\u2998\\u29D9\\u29DB\\u29FD\\u2E23\\u2E25\\u2E27\\u2E29\\u3009\\u300B\\u300D\\u300F\\u3011\\u3015\\u3017\\u3019\\u301B\\u301E\\u301F\\uFD3F\\uFE18\\uFE36\\uFE38\\uFE3A\\uFE3C\\uFE3E\\uFE40\\uFE42\\uFE44\\uFE48\\uFE5A\\uFE5C\\uFE5E\\uFF09\\uFF3D\\uFF5D\\uFF60\\uFF63',
		'pPf' => '\\u00BB\\u2019\\u201D\\u203A\\u2E03\\u2E05\\u2E0A\\u2E0D\\u2E1D\\u2E21',
		'pPi' => '\\u00AB\\u2018\\u201B\\u201C\\u201F\\u2039\\u2E02\\u2E04\\u2E09\\u2E0C\\u2E1C\\u2E20',
		'pPo' => '\\!-#%-\'\\*,\\./\\:;\\?@\\\\\\u00A1\\u00B7\\u00BF\\u037E\\u0387\\u055A-\\u055F\\u0589\\u05C0\\u05C3\\u05C6\\u05F3\\u05F4\\u0609\\u060A\\u060C\\u060D\\u061B\\u061E\\u061F\\u066A-\\u066D\\u06D4\\u0700-\\u070D\\u07F7-\\u07F9\\u0830-\\u083E\\u085E\\u0964\\u0965\\u0970\\u0DF4\\u0E4F\\u0E5A\\u0E5B\\u0F04-\\u0F12\\u0F85\\u0FD0-\\u0FD4\\u0FD9\\u0FDA\\u104A-\\u104F\\u10FB\\u1361-\\u1368\\u166D\\u166E\\u16EB-\\u16ED\\u1735\\u1736\\u17D4-\\u17D6\\u17D8-\\u17DA\\u1800-\\u1805\\u1807-\\u180A\\u1944\\u1945\\u1A1E\\u1A1F\\u1AA0-\\u1AA6\\u1AA8-\\u1AAD\\u1B5A-\\u1B60\\u1BFC-\\u1BFF\\u1C3B-\\u1C3F\\u1C7E\\u1C7F\\u1CD3\\u2016\\u2017\\u2020-\\u2027\\u2030-\\u2038\\u203B-\\u203E\\u2041-\\u2043\\u2047-\\u2051\\u2053\\u2055-\\u205E\\u2CF9-\\u2CFC\\u2CFE\\u2CFF\\u2D70\\u2E00\\u2E01\\u2E06-\\u2E08\\u2E0B\\u2E0E-\\u2E16\\u2E18\\u2E19\\u2E1B\\u2E1E\\u2E1F\\u2E2A-\\u2E2E\\u2E30\\u2E31\\u3001-\\u3003\\u303D\\u30FB\\uA4FE\\uA4FF\\uA60D-\\uA60F\\uA673\\uA67E\\uA6F2-\\uA6F7\\uA874-\\uA877\\uA8CE\\uA8CF\\uA8F8-\\uA8FA\\uA92E\\uA92F\\uA95F\\uA9C1-\\uA9CD\\uA9DE\\uA9DF\\uAA5C-\\uAA5F\\uAADE\\uAADF\\uABEB\\uFE10-\\uFE16\\uFE19\\uFE30\\uFE45\\uFE46\\uFE49-\\uFE4C\\uFE50-\\uFE52\\uFE54-\\uFE57\\uFE5F-\\uFE61\\uFE68\\uFE6A\\uFE6B\\uFF01-\\uFF03\\uFF05-\\uFF07\\uFF0A\\uFF0C\\uFF0E\\uFF0F\\uFF1A\\uFF1B\\uFF1F\\uFF20\\uFF3C\\uFF61\\uFF64\\uFF65',
		'pPs' => '\\(\\[\\{\\u0F3A\\u0F3C\\u169B\\u201A\\u201E\\u2045\\u207D\\u208D\\u2329\\u2768\\u276A\\u276C\\u276E\\u2770\\u2772\\u2774\\u27C5\\u27E6\\u27E8\\u27EA\\u27EC\\u27EE\\u2983\\u2985\\u2987\\u2989\\u298B\\u298D\\u298F\\u2991\\u2993\\u2995\\u2997\\u29D8\\u29DA\\u29FC\\u2E22\\u2E24\\u2E26\\u2E28\\u3008\\u300A\\u300C\\u300E\\u3010\\u3014\\u3016\\u3018\\u301A\\u301D\\uFD3E\\uFE17\\uFE35\\uFE37\\uFE39\\uFE3B\\uFE3D\\uFE3F\\uFE41\\uFE43\\uFE47\\uFE59\\uFE5B\\uFE5D\\uFF08\\uFF3B\\uFF5B\\uFF5F\\uFF62',
		'pS' => '\\$\\+\\<-\\>\\^`\\|~\\u00A2-\\u00A9\\u00AC\\u00AE-\\u00B1\\u00B4\\u00B6\\u00B8\\u00D7\\u00F7\\u02C2-\\u02C5\\u02D2-\\u02DF\\u02E5-\\u02EB\\u02ED\\u02EF-\\u02FF\\u0375\\u0384\\u0385\\u03F6\\u0482\\u0606-\\u0608\\u060B\\u060E\\u060F\\u06DE\\u06E9\\u06FD\\u06FE\\u07F6\\u09F2\\u09F3\\u09FA\\u09FB\\u0AF1\\u0B70\\u0BF3-\\u0BFA\\u0C7F\\u0D79\\u0E3F\\u0F01-\\u0F03\\u0F13-\\u0F17\\u0F1A-\\u0F1F\\u0F34\\u0F36\\u0F38\\u0FBE-\\u0FC5\\u0FC7-\\u0FCC\\u0FCE\\u0FCF\\u0FD5-\\u0FD8\\u109E\\u109F\\u1360\\u1390-\\u1399\\u17DB\\u1940\\u19DE-\\u19FF\\u1B61-\\u1B6A\\u1B74-\\u1B7C\\u1FBD\\u1FBF-\\u1FC1\\u1FCD-\\u1FCF\\u1FDD-\\u1FDF\\u1FED-\\u1FEF\\u1FFD\\u1FFE\\u2044\\u2052\\u207A-\\u207C\\u208A-\\u208C\\u20A0-\\u20B9\\u2100\\u2101\\u2103-\\u2106\\u2108\\u2109\\u2114\\u2116-\\u2118\\u211E-\\u2123\\u2125\\u2127\\u2129\\u212E\\u213A\\u213B\\u2140-\\u2144\\u214A-\\u214D\\u214F\\u2190-\\u2328\\u232B-\\u23F3\\u2400-\\u2426\\u2440-\\u244A\\u249C-\\u24E9\\u2500-\\u26FF\\u2701-\\u2767\\u2794-\\u27C4\\u27C7-\\u27CA\\u27CC\\u27CE-\\u27E5\\u27F0-\\u2982\\u2999-\\u29D7\\u29DC-\\u29FB\\u29FE-\\u2B4C\\u2B50-\\u2B59\\u2CE5-\\u2CEA\\u2E80-\\u2E99\\u2E9B-\\u2EF3\\u2F00-\\u2FD5\\u2FF0-\\u2FFB\\u3004\\u3012\\u3013\\u3020\\u3036\\u3037\\u303E\\u303F\\u309B\\u309C\\u3190\\u3191\\u3196-\\u319F\\u31C0-\\u31E3\\u3200-\\u321E\\u322A-\\u3250\\u3260-\\u327F\\u328A-\\u32B0\\u32C0-\\u32FE\\u3300-\\u33FF\\u4DC0-\\u4DFF\\uA490-\\uA4C6\\uA700-\\uA716\\uA720\\uA721\\uA789\\uA78A\\uA828-\\uA82B\\uA836-\\uA839\\uAA77-\\uAA79\\uFB29\\uFBB2-\\uFBC1\\uFDFC\\uFDFD\\uFE62\\uFE64-\\uFE66\\uFE69\\uFF04\\uFF0B\\uFF1C-\\uFF1E\\uFF3E\\uFF40\\uFF5C\\uFF5E\\uFFE0-\\uFFE6\\uFFE8-\\uFFEE\\uFFFC\\uFFFD',
		'pSc' => '\\$\\u00A2-\\u00A5\\u060B\\u09F2\\u09F3\\u09FB\\u0AF1\\u0BF9\\u0E3F\\u17DB\\u20A0-\\u20B9\\uA838\\uFDFC\\uFE69\\uFF04\\uFFE0\\uFFE1\\uFFE5\\uFFE6',
		'pSk' => '\\^`\\u00A8\\u00AF\\u00B4\\u00B8\\u02C2-\\u02C5\\u02D2-\\u02DF\\u02E5-\\u02EB\\u02ED\\u02EF-\\u02FF\\u0375\\u0384\\u0385\\u1FBD\\u1FBF-\\u1FC1\\u1FCD-\\u1FCF\\u1FDD-\\u1FDF\\u1FED-\\u1FEF\\u1FFD\\u1FFE\\u309B\\u309C\\uA700-\\uA716\\uA720\\uA721\\uA789\\uA78A\\uFBB2-\\uFBC1\\uFF3E\\uFF40\\uFFE3',
		'pSm' => '\\+\\<-\\>\\|~\\u00AC\\u00B1\\u00D7\\u00F7\\u03F6\\u0606-\\u0608\\u2044\\u2052\\u207A-\\u207C\\u208A-\\u208C\\u2118\\u2140-\\u2144\\u214B\\u2190-\\u2194\\u219A\\u219B\\u21A0\\u21A3\\u21A6\\u21AE\\u21CE\\u21CF\\u21D2\\u21D4\\u21F4-\\u22FF\\u2308-\\u230B\\u2320\\u2321\\u237C\\u239B-\\u23B3\\u23DC-\\u23E1\\u25B7\\u25C1\\u25F8-\\u25FF\\u266F\\u27C0-\\u27C4\\u27C7-\\u27CA\\u27CC\\u27CE-\\u27E5\\u27F0-\\u27FF\\u2900-\\u2982\\u2999-\\u29D7\\u29DC-\\u29FB\\u29FE-\\u2AFF\\u2B30-\\u2B44\\u2B47-\\u2B4C\\uFB29\\uFE62\\uFE64-\\uFE66\\uFF0B\\uFF1C-\\uFF1E\\uFF5C\\uFF5E\\uFFE2\\uFFE9-\\uFFEC',
		'pSo' => '\\u00A6\\u00A7\\u00A9\\u00AE\\u00B0\\u00B6\\u0482\\u060E\\u060F\\u06DE\\u06E9\\u06FD\\u06FE\\u07F6\\u09FA\\u0B70\\u0BF3-\\u0BF8\\u0BFA\\u0C7F\\u0D79\\u0F01-\\u0F03\\u0F13-\\u0F17\\u0F1A-\\u0F1F\\u0F34\\u0F36\\u0F38\\u0FBE-\\u0FC5\\u0FC7-\\u0FCC\\u0FCE\\u0FCF\\u0FD5-\\u0FD8\\u109E\\u109F\\u1360\\u1390-\\u1399\\u1940\\u19DE-\\u19FF\\u1B61-\\u1B6A\\u1B74-\\u1B7C\\u2100\\u2101\\u2103-\\u2106\\u2108\\u2109\\u2114\\u2116\\u2117\\u211E-\\u2123\\u2125\\u2127\\u2129\\u212E\\u213A\\u213B\\u214A\\u214C\\u214D\\u214F\\u2195-\\u2199\\u219C-\\u219F\\u21A1\\u21A2\\u21A4\\u21A5\\u21A7-\\u21AD\\u21AF-\\u21CD\\u21D0\\u21D1\\u21D3\\u21D5-\\u21F3\\u2300-\\u2307\\u230C-\\u231F\\u2322-\\u2328\\u232B-\\u237B\\u237D-\\u239A\\u23B4-\\u23DB\\u23E2-\\u23F3\\u2400-\\u2426\\u2440-\\u244A\\u249C-\\u24E9\\u2500-\\u25B6\\u25B8-\\u25C0\\u25C2-\\u25F7\\u2600-\\u266E\\u2670-\\u26FF\\u2701-\\u2767\\u2794-\\u27BF\\u2800-\\u28FF\\u2B00-\\u2B2F\\u2B45\\u2B46\\u2B50-\\u2B59\\u2CE5-\\u2CEA\\u2E80-\\u2E99\\u2E9B-\\u2EF3\\u2F00-\\u2FD5\\u2FF0-\\u2FFB\\u3004\\u3012\\u3013\\u3020\\u3036\\u3037\\u303E\\u303F\\u3190\\u3191\\u3196-\\u319F\\u31C0-\\u31E3\\u3200-\\u321E\\u322A-\\u3250\\u3260-\\u327F\\u328A-\\u32B0\\u32C0-\\u32FE\\u3300-\\u33FF\\u4DC0-\\u4DFF\\uA490-\\uA4C6\\uA828-\\uA82B\\uA836\\uA837\\uA839\\uAA77-\\uAA79\\uFDFD\\uFFE4\\uFFE8\\uFFED\\uFFEE\\uFFFC\\uFFFD',
		'pZ' => ' \\u00A0\\u1680\\u180E\\u2000-\\u200A\\u2028\\u2029\\u202F\\u205F\\u3000',
		'pZl' => '\\u2028',
		'pZp' => '\\u2029',
		'pZs' => ' \\u00A0\\u1680\\u180E\\u2000-\\u200A\\u202F\\u205F\\u3000'
	);
}