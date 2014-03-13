<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * JSMin.php - modified PHP implementation of Douglas Crockford's JSMin.
 *
 * <code>
 * $minified_js = Jsmin::factory($js)->min();
 * </code>
 *
 * This is a modified port of jsmin.c. Improvements:
 *
 * Does not choke on some regexp literals containing quote characters. E.g. /'/
 *
 * Spaces are preserved after some add/sub operators, so they are not mistakenly
 * converted to post-inc/dec. E.g. a + ++b -> a+ ++b
 *
 * Preserves multi-line comments that begin with /*!
 *
 * PHP 5 or higher is required.
 *
 * Permission is hereby granted to use this version of the library under the
 * same terms as jsmin.c, which has the following license:
 *
 * --
 * Copyright (c) 2002 Douglas Crockford  (www.crockford.com)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * The Software shall be used for Good, not Evil.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * --
 *
 * @package JSMin
 * @author Ryan Grove <ryan@wonko.com> (PHP port)
 * @author Steve Clay <steve@mrclay.org> (modifications + cleanup)
 * @author Andrea Giammarchi <http://www.3site.eu> (spaceBeforeRegExp)
 * @author Mikael Lilleman Göransson <http://larvit.se> (Adaption to Kohana and Pajas) 2014
 * @copyright 2002 Douglas Crockford <douglas@crockford.com> (jsmin.c)
 * @copyright 2008 Ryan Grove <ryan@wonko.com> (PHP port)
 * @license http://opensource.org/licenses/mit-license.php MIT License
 * @link http://code.google.com/p/jsmin-php/
 */

class Jsmin
{
	const ORD_LF             = 10;
	const ORD_SPACE          = 32;
	const ACTION_KEEP_A      = 1;
	const ACTION_DELETE_A    = 2;
	const ACTION_DELETE_A_B  = 3;

	protected $a             = "\n";
	protected $b             = '';
	protected $input         = '';
	protected $input_index   = 0;
	protected $input_length  = 0;
	protected $look_ahead    = NULL;
	protected $output        = '';
	protected $last_byte_out = '';
	protected $kept_comment  = '';

	/**
	 * Minify Javascript.
	 *
	 * @param string $js Javascript to be minified
	 * @return obj of self - run ->min(); go get the result
	 */
	public static function factory($js)
	{
		return new self($js);
	}

	/**
	 * @param string $input
	 */
	public function __construct($input)
	{
		$this->input = $input;
	}

	/**
	 * Perform minification, return result
	 *
	 * @return string
	 */
	public function min()
	{
		if ($this->output !== '')
			return $this->output; // min already ran

		$mb_int_enc = NULL;
		if (function_exists('mb_strlen') && ((int)ini_get('mbstring.func_overload') & 2))
		{
			$mb_int_enc = mb_internal_encoding();
			mb_internal_encoding('8bit');
		}
		$this->input        = str_replace("\r\n", "\n", $this->input);
		$this->input_length = strlen($this->input);

		$this->action(self::ACTION_DELETE_A_B);

		while ($this->a !== NULL)
		{

			// Determine next command
			$command = self::ACTION_KEEP_A; // default
			if ($this->a === ' ')
			{
				if (($this->last_byte_out === '+' || $this->last_byte_out === '-') && ($this->b === $this->last_byte_out))
				{
					// Don't delete this space. If we do, the addition/subtraction
					// could be parsed as a post-increment
				}
				elseif ( ! $this->is_alpha_num($this->b))
				{
					$command = self::ACTION_DELETE_A;
				}
			}
			elseif ($this->a === "\n")
			{
				if ($this->b === ' ')
				{
					$command = self::ACTION_DELETE_A_B;

					// in case of mbstring.func_overload & 2, must check for NULL b,
					// otherwise mb_strpos will give WARNING
				}
				elseif ($this->b === NULL || (FALSE === strpos('{[(+-!~', $this->b) && ! $this->is_alpha_num($this->b)))
				{
					$command = self::ACTION_DELETE_A;
				}
			}
			elseif ( ! $this->is_alpha_num($this->a))
			{
				if ($this->b === ' ' || ($this->b === "\n" && (FALSE === strpos('}])+-"\'', $this->a))))
					$command = self::ACTION_DELETE_A_B;
			}

			$this->action($command);
		}

		$this->output = trim($this->output);

		if ($mb_int_enc !== NULL)
			mb_internal_encoding($mb_int_enc);

		return $this->output;
	}

	/**
	 * ACTION_KEEP_A = Output A. Copy B to A. Get the next B.
	 * ACTION_DELETE_A = Copy B to A. Get the next B.
	 * ACTION_DELETE_A_B = Get the next B.
	 *
	 * @param int $command
	 * @throws Jsmin_UnterminatedRegExpException|Jsmin_UnterminatedStringException
	 */
	protected function action($command)
	{
		// make sure we don't compress "a + ++b" to "a+++b", etc.
		if ($command === self::ACTION_DELETE_A_B && $this->b === ' ' && ($this->a === '+' || $this->a === '-'))
		{
			// Note: we're at an addition/substraction operator; the input_index
			// will certainly be a valid index
			if ($this->input[$this->input_index] === $this->a)
			{
				// This is "+ +" or "- -". Don't delete the space.
				$command = self::ACTION_KEEP_A;
			}
		}

		switch ($command)
		{
			case self::ACTION_KEEP_A: // 1
				$this->output .= $this->a;

				if ($this->kept_comment)
				{
					$this->output = rtrim($this->output, "\n");
					$this->output .= $this->kept_comment;
					$this->kept_comment = '';
				}

				$this->last_byte_out = $this->a;

			// Fallthrough intentional
			case self::ACTION_DELETE_A: // 2
				$this->a = $this->b;
				if ($this->a === "'" || $this->a === '"')
				{
					// String literal
					$str = $this->a; // In case needed for exception
					for(;;)
					{
						$this->output .= $this->a;
						$this->last_byte_out = $this->a;

						$this->a = $this->get();
						if ($this->a === $this->b) // End quote
							break;

						if ($this->is_EOF($this->a))
						{
							$byte = $this->input_index - 1;
							throw new Jsmin_UnterminatedStringException('JSMin: Unterminated String at byte '.$byte.': '.$str);
						}

						$str .= $this->a;
						if ($this->a === '\\')
						{
							$this->output .= $this->a;
							$this->last_byte_out = $this->a;

							$this->a = $this->get();
							$str .= $this->a;
						}
					}
				}

			// Fallthrough intentional
			case self::ACTION_DELETE_A_B: // 3
				$this->b = $this->next();
				if ($this->b === '/' && $this->is_regexp_literal())
				{
					$this->output .= $this->a . $this->b;
					$pattern = '/'; // Keep entire pattern in case we need to report it in the exception
					for(;;)
					{
						$this->a = $this->get();
						$pattern .= $this->a;
						if ($this->a === '[')
						{
							for(;;)
							{
								$this->output .= $this->a;
								$this->a = $this->get();
								$pattern .= $this->a;
								if ($this->a === ']')
									break;

								if ($this->a === '\\')
								{
									$this->output .= $this->a;
									$this->a = $this->get();
									$pattern .= $this->a;
								}

								if ($this->is_EOF($this->a))
									throw new Jsmin_UnterminatedRegExpException('JSMin: Unterminated set in RegExp at byte '.$this->input_index.': '.$pattern);

							}
						}

						if ($this->a === '/')
						{ // End pattern
							break; // while (TRUE)
						}
						elseif ($this->a === '\\')
						{
							$this->output .= $this->a;
							$this->a = $this->get();
							$pattern .= $this->a;
						}
						elseif ($this->is_EOF($this->a))
						{
							$byte = $this->input_index - 1;
							throw new Jsmin_UnterminatedRegExpException('JSMin: Unterminated RegExp at byte '.$byte.': '.$pattern);
						}
						$this->output .= $this->a;
						$this->last_byte_out = $this->a;
					}

					$this->b = $this->next();
				}
			// End case ACTION_DELETE_A_B
		}
	}

	/**
	 * @return bool
	 */
	protected function is_regexp_literal()
	{
		if (FALSE !== strpos("(,=:[!&|?+-~*{;", $this->a))
		{
			// We obviously aren't dividing
			return TRUE;
		}

		// We have to check for a preceding keyword, and we don't need to pattern
		// match over the whole output.
		$recentOutput = substr($this->output, -10);

		// Check if return/typeof directly precede a pattern without a space
		foreach (array('return', 'typeof') as $keyword)
		{
			if ($this->a !== substr($keyword, -1))
			{
				// Certainly wasn't keyword

				continue;
			}

			if (preg_match("~(^|[\\s\\S])" . substr($keyword, 0, -1) . "$~", $recentOutput, $m))
				if ($m[1] === '' || !$this->is_alpha_num($m[1]))
					return TRUE;
		}

		// Check all keywords
		if ($this->a === ' ' || $this->a === "\n")
			if (preg_match('~(^|[\\s\\S])(?:case|else|in|return|typeof)$~', $recentOutput, $m))
				if ($m[1] === '' || !$this->is_alpha_num($m[1]))
					return TRUE;

		return FALSE;
	}

	/**
	 * Return the next character from stdin. Watch out for lookahead. If the character is a control character,
	 * translate it to a space or linefeed.
	 *
	 * @return string
	 */
	protected function get()
	{
		$c = $this->look_ahead;
		$this->look_ahead = NULL;
		if ($c === NULL)
		{
			// getc(stdin)
			if ($this->input_index < $this->input_length)
			{
				$c = $this->input[$this->input_index];
				$this->input_index += 1;
			}
			else
			{
				$c = NULL;
			}
		}

		if (ord($c) >= self::ORD_SPACE || $c === "\n" || $c === NULL)
			return $c;

		if ($c === "\r")
			return "\n";

		return ' ';
	}

	/**
	 * Does $a indicate end of input?
	 *
	 * @param string $a
	 * @return bool
	 */
	protected function is_EOF($a)
	{
		return ord($a) <= self::ORD_LF;
	}

	/**
	 * Get next char (without getting it). If is ctrl character, translate to a space or newline.
	 *
	 * @return string
	 */
	protected function peek()
	{
		$this->look_ahead = $this->get();

		return $this->look_ahead;
	}

	/**
	 * Return TRUE if the character is a letter, digit, underscore, dollar sign, or non-ASCII character.
	 *
	 * @param string $c
	 *
	 * @return bool
	 */
	protected function is_alpha_num($c)
	{
		return (preg_match('/^[a-z0-9A-Z_\\$\\\\]$/', $c) || ord($c) > 126);
	}

	/**
	 * Consume a single line comment from input (possibly retaining it)
	 */
	protected function consume_single_line_comment()
	{
		$comment = '';
		while (TRUE)
		{
			$get = $this->get();
			$comment .= $get;
			if (ord($get) <= self::ORD_LF) // End of line reached
			{
				// if IE conditional comment
				if (preg_match('/^\\/@(?:cc_on|if|elif|else|end)\\b/', $comment))
					$this->kept_comment .= "/{$comment}";

				return;
			}
		}
	}

	/**
	 * Consume a multiple line comment from input (possibly retaining it)
	 *
	 * @throws Jsmin_UnterminatedCommentException
	 */
	protected function consume_multiple_line_comment()
	{
		$this->get();
		$comment = '';
		for(;;)
		{
			$get = $this->get();
			if ($get === '*')
			{
				if ($this->peek() === '/') // End of comment reached
				{
					$this->get();
					if (0 === strpos($comment, '!'))
					{
						// preserved by YUI Compressor
						if ( ! $this->kept_comment)
						{
							// Don't prepend a newline if two comments right after one another
							$this->kept_comment = "\n";
						}
						$this->kept_comment .= "/*!" . substr($comment, 1) . "*/\n";
					}
					elseif (preg_match('/^@(?:cc_on|if|elif|else|end)\\b/', $comment))
					{
						// IE conditional
						$this->kept_comment .= "/*{$comment}*/";
					}

					return;
				}
			}
			elseif ($get === NULL)
			{
				throw new Jsmin_UnterminatedCommentException('JSMin: Unterminated comment at byte '.$this->input_index.': /*'.$comment);
			}
			$comment .= $get;
		}
	}

	/**
	 * Get the next character, skipping over comments. Some comments may be preserved.
	 *
	 * @return string
	 */
	protected function next()
	{
		$get = $this->get();
		if ($get === '/')
		{
			switch ($this->peek())
			{
				case '/':
					$this->consume_single_line_comment();
					$get = "\n";
					break;
				case '*':
					$this->consume_multiple_line_comment();
					$get = ' ';
					break;
			}
		}

		return $get;
	}

}

class Jsmin_UnterminatedStringException  extends Exception {}
class Jsmin_UnterminatedCommentException extends Exception {}
class Jsmin_UnterminatedRegExpException  extends Exception {}