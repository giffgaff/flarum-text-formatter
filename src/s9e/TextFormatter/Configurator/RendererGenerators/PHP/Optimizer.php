<?php

/**
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2013 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Configurator\RendererGenerators\PHP;

/**
* This class optimizes the code produced by the PHP rendere. It is not meant to be used on general
* purpose code
*/
class Optimizer
{
	/**
	* @var integer Maximum number iterations over the optimization passes
	*/
	public $maxLoops = 10;

	/**
	* Optimize the generated code
	*
	* @return string
	*/
	public function optimize($php)
	{
		$tokens = token_get_all('<?php ' . $php);
		$oldCnt = count($tokens);

		// Optimization passes, in order of execution
		$passes = [
			'optimizeOutConcatEqual',
			'optimizeConcatenations',
			'optimizeHtmlspecialchars'
		];

		// Limit the number of loops, in case something would make it loop indefinitely
		$remainingLoops = $this->maxLoops;
		do
		{
			$continue = false;

			foreach ($passes as $pass)
			{
				// Run the pass
				$this->$pass($tokens, $oldCnt);

				// If the array was modified, reset the keys and keep going
				$newCnt = count($tokens);
				if ($oldCnt !== $newCnt)
				{
					$tokens   = array_values($tokens);
					$oldCnt   = $newCnt;
					$continue = true;
				}
			}
		}
		while ($continue && --$remainingLoops);

		// Remove the first token, which should be T_OPEN_TAG, aka "<?php"
		unset($tokens[0]);

		// Rebuild the source
		$php = '';
		foreach ($tokens as $token)
		{
			$php .= (is_string($token)) ? $token : $token[1];
		}

		return $php;
	}

	/**
	* Optimize T_CONCAT_EQUAL assignments in an array of PHP tokens
	*
	* Will only optimize $this->out.= assignments
	*
	* @param  array   &$tokens PHP tokens from tokens_get_all()
	* @param  integer  $cnt    Number of tokens
	* @return void
	*/
	protected function optimizeOutConcatEqual(array &$tokens, $cnt)
	{
		// Start at offset 4 to skip the first four tokens: <?php $this->out.=
		// We adjust the max value to account for the number of tokens ahead of the .= necessary to
		// apply this optimization, which is 8 (therefore the offset is one less)
		// 'foo';$this->out.='bar';
		$i   = 3;
		$max = $cnt - 9;

		while (++$i <= $max)
		{
			if ($tokens[$i][0] !== T_CONCAT_EQUAL)
			{
				continue;
			}

			// Test whether this T_CONCAT_EQUAL is preceded with $this->out
			if ($tokens[$i - 1][0] !== T_STRING
			 || $tokens[$i - 1][1] !== 'out'
			 || $tokens[$i - 2][0] !== T_OBJECT_OPERATOR
			 || $tokens[$i - 3][0] !== T_VARIABLE
			 || $tokens[$i - 3][1] !== '$this')
			{
				 continue;
			}

			do
			{
				// Move the cursor to next semicolon
				while (++$i < $cnt && $tokens[$i] !== ';');

				// Move the cursor past the semicolon
				if (++$i >= $cnt)
				{
					return;
				}

				// Test whether the assignment is followed by another $this->out.= assignment
				if ($tokens[$i    ][0] !== T_VARIABLE
				 || $tokens[$i    ][1] !== '$this'
				 || $tokens[$i + 1][0] !== T_OBJECT_OPERATOR
				 || $tokens[$i + 2][0] !== T_STRING
				 || $tokens[$i + 2][1] !== 'out'
				 || $tokens[$i + 3][0] !== T_CONCAT_EQUAL)
				{
					 break;
				}

				// Replace the semicolon between assignments with a concatenation operator
				$tokens[$i - 1] = '.';

				// Remove the following $this->out.= assignment and move the cursor past it
				unset($tokens[$i]);
				unset($tokens[$i + 1]);
				unset($tokens[$i + 2]);
				unset($tokens[$i + 3]);
				$i += 3;
			}
			while ($i <= $max);
		}
	}

	/**
	* Optimize concatenations in an array of PHP tokens
	*
	* - Will precompute the result of the concatenation of constant strings
	* - Will replace the concatenation of two compatible htmlspecialchars() calls with one call to
	*   htmlspecialchars() on the concatenation of their first arguments
	*
	* @param  array   &$tokens PHP tokens from tokens_get_all()
	* @param  integer  $cnt    Number of tokens
	* @return void
	*/
	protected function optimizeConcatenations(array &$tokens, $cnt)
	{
		$i = 1;
		while (++$i < $cnt)
		{
			if ($tokens[$i] !== '.')
			{
				continue;
			}

			// Merge concatenated strings
			if ($tokens[$i - 1][0]    === T_CONSTANT_ENCAPSED_STRING
			 && $tokens[$i + 1][0]    === T_CONSTANT_ENCAPSED_STRING
			 && $tokens[$i - 1][1][0] === $tokens[$i + 1][1][0])
			{
				// Merge both strings into the right string
				$tokens[$i + 1][1] = substr($tokens[$i - 1][1], 0, -1)
				                   . substr($tokens[$i + 1][1], 1);

				// Unset the tokens that have been optimized away
				unset($tokens[$i - 1]);
				unset($tokens[$i]);

				// Advance the cursor
				++$i;

				continue;
			}

			// Merge htmlspecialchars() calls
			if ($tokens[$i + 1][0] === T_STRING
			 && $tokens[$i + 1][1] === 'htmlspecialchars'
			 && $tokens[$i + 2]    === '('
			 && $tokens[$i - 1]    === ')'
			 && $tokens[$i - 2][0] === T_LNUMBER
			 && $tokens[$i - 3]    === ',')
			{
				// Save the escape mode of the first call
				$escapeMode = $tokens[$i - 2][1];

				// Save the index of the comma that comes after the first argument of the first call
				$startIndex = $i - 3;

				// Save the index of the parenthesis that follows the second htmlspecialchars
				$endIndex = $i + 2;

				// Move the cursor to the first comma of the second call
				$i = $endIndex;
				$parens = 0;
				while (++$i < $cnt)
				{
					if ($tokens[$i] === ',' && !$parens)
					{
						break;
					}

					if ($tokens[$i] === '(')
					{
						++$parens;
					}
					elseif ($tokens[$i] === ')')
					{
						--$parens;
					}
				}

				if ($tokens[$i + 1][0] === T_LNUMBER
				 && $tokens[$i + 1][1] === $escapeMode)
				{
					// Replace the first comma of the first call with a concatenator operator
					$tokens[$startIndex] = '.';

					// Move the cursor back to the first comma then advance it and delete
					// everything up till the parenthesis of the second call, included
					$i = $startIndex;
					while (++$i <= $endIndex)
					{
						unset($tokens[$i]);
					}

					continue;
				}
			}
		}
	}

	/**
	* Optimize htmlspecialchars() calls
	*
	* - The result of htmlspecialchars() on literals is precomputed
	* - By default, the generator escapes all values, including variables that cannot contain
	*   special characters such as $node->localName. This pass removes those calls
	*
	* @param  array   &$tokens PHP tokens from tokens_get_all()
	* @param  integer  $cnt    Number of tokens
	* @return void
	*/
	protected function optimizeHtmlspecialchars(array &$tokens, $cnt)
	{
		$i   = 0;
		$max = $cnt - 7;

		while (++$i <= $max)
		{
			// Skip this token if it's not the first of the "htmlspecialchars(" sequence
			if ($tokens[$i    ][0] !== T_STRING
			 || $tokens[$i    ][1] !== 'htmlspecialchars'
			 || $tokens[$i + 1]    !== '(')
			{
				continue;
			}

			// Test whether a constant string is being escaped
			if ($tokens[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
			 && $tokens[$i + 3]    === ','
			 && $tokens[$i + 4][0] === T_LNUMBER
			 && $tokens[$i + 5]    === ')')
			{
				// Escape the content of the T_CONSTANT_ENCAPSED_STRING token
				$tokens[$i + 2][1] = var_export(
					htmlspecialchars(
						stripslashes(substr($tokens[$i + 2][1], 1, -1)),
						$tokens[$i + 4][1]
					),
					true
				);

				// Remove the htmlspecialchars() call, except for the T_CONSTANT_ENCAPSED_STRING
				// token
				unset($tokens[$i]);
				unset($tokens[$i + 1]);
				unset($tokens[$i + 3]);
				unset($tokens[$i + 4]);
				unset($tokens[$i + 5]);

				// Move the cursor past the call
				$i += 5;

				continue;
			}

			// Test whether a variable is being escaped
			if ($tokens[$i + 2][0] === T_VARIABLE
			 && $tokens[$i + 2][1]  === '$node'
			 && $tokens[$i + 3][0]  === T_OBJECT_OPERATOR
			 && $tokens[$i + 4][0]  === T_STRING
			 && ($tokens[$i + 4][1] === 'localName' || $tokens[$i + 4][1] === 'nodeName')
			 && $tokens[$i + 5]     === ','
			 && $tokens[$i + 6][0]  === T_LNUMBER
			 && $tokens[$i + 7]     === ')')
			{
				// Remove the htmlspecialchars() call, except for its first argument
				unset($tokens[$i]);
				unset($tokens[$i + 1]);
				unset($tokens[$i + 5]);
				unset($tokens[$i + 6]);
				unset($tokens[$i + 7]);

				// Move the cursor past the call
				$i += 7;

				continue;
			}
		}
	}
}