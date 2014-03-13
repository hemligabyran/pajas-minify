<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * Class Cssmin_Compressor
 * @package Minify
 */

/**
 * Compress CSS
 *
 * This is a heavy regex-based removal of whitespace, unnecessary
 * comments and tokens, and some CSS value minimization, where practical.
 * Many steps have been taken to avoid breaking comment-based hacks,
 * including the ie5/mac filter (and its inversion), but expect tricky
 * hacks involving comment tokens in 'content' value strings to break
 * minimization badly. A test suite is available.
 *
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 * @author http://code.google.com/u/1stvamp/ (Issue 64 patch)
 */
class Cssmin_Compressor
{

	/**
	 * Minify a CSS string
	 *
	 * @param string $css
	 * @param array $options (currently ignored)
	 * @return string
	 */
	public static function process($css, $options = array())
	{
		$obj = new Cssmin_Compressor($options);

		return $obj->_process($css);
	}

	/**
	 * @var array
	 */
	protected $_options = NULL;

	/**
	 * Are we "in" a hack? I.e. are some browsers targetted until the next comment?
	 *
	 * @var bool
	 */
	protected $_in_hack = FALSE;

	/**
	 * Constructor
	 *
	 * @param array $options (currently ignored)
	 */
	private function __construct($options)
	{
		$this->_options = $options;
	}

	/**
	 * Minify a CSS string
	 *
	 * @param string $css
	 *
	 * @return string
	 */
	protected function _process($css)
	{
		$css = str_replace("\r\n", "\n", $css);

		// Preserve empty comment after '>'
		// http://www.webdevout.net/css-hacks#in_css-selectors
		$css = preg_replace('@>/\\*\\s*\\*/@', '>/*keep*/', $css);

		// Preserve empty comment between property and value
		// http://css-discuss.incutio.com/?page=BoxModelHack
		$css = preg_replace('@/\\*\\s*\\*/\\s*:@', '/*keep*/:', $css);
		$css = preg_replace('@:\\s*/\\*\\s*\\*/@', ':/*keep*/', $css);

		// Apply callback to all valid comments (and strip out surrounding ws
		$css = preg_replace_callback('@\\s*/\\*([\\s\\S]*?)\\*/\\s*@', array($this, '_comment_cb'), $css);

		// Remove ws around { } and last semicolon in declaration block
		$css = preg_replace('/\\s*{\\s*/', '{', $css);
		$css = preg_replace('/;?\\s*}\\s*/', '}', $css);

		// Remove ws surrounding semicolons
		$css = preg_replace('/\\s*;\\s*/', ';', $css);

		// Remove ws around urls
		$css = preg_replace('/
			url\\(      # url(
			\\s*
			([^\\)]+?)  # 1 = the URL (really just a bunch of non right parenthesis)
			\\s*
			\\)         # )
			/x', 'url($1)', $css);

		// Remove ws between rules and colons
		$css = preg_replace('/
			\\s*
			([{;])              # 1 = beginning of block or rule separator
			\\s*
			([\\*_]?[\\w\\-]+)  # 2 = property (and maybe IE filter)
			\\s*
			:
			\\s*
			(\\b|[#\'"-])        # 3 = first character of a value
			/x', '$1$2:$3', $css);

		// Remove ws in selectors
		$css = preg_replace_callback('/
			(?:              # non-capture
			\\s*
			[^~>+,\\s]+      # selector part
			\\s*
			[,>+~]           # combinators
			)+
			\\s*
			[^~>+,\\s]+      # selector part
			{                # open declaration block
			/x'
			,array($this, '_selectors_cb'), $css);

		// Minimize hex colors
		$css = preg_replace('/([^=])#([a-f\\d])\\2([a-f\\d])\\3([a-f\\d])\\4([\\s;\\}])/i', '$1#$2$3$4$5', $css);

		// Remove spaces between font families
		$css = preg_replace_callback('/font-family:([^;}]+)([;}])/', array($this, '_font_family_cb'), $css);

		$css = preg_replace('/@import\\s+url/', '@import url', $css);

		// Replace any ws involving newlines with a single newline
		$css = preg_replace('/[ \\t]*\\n+\\s*/', "\n", $css);

		// Separate common descendent selectors w/ newlines (to limit line lengths)
		$css = preg_replace('/([\\w#\\.\\*]+)\\s+([\\w#\\.\\*]+){/', "$1\n$2{", $css);

		// Use newline after 1st numeric value (to limit line lengths).
		$css = preg_replace('/
			((?:padding|margin|border|outline):\\d+(?:px|em)?) # 1 = prop : 1st numeric value
			\\s+
			/x'
			, "$1\n", $css);

		// Prevent triggering IE6 bug: http://www.crankygeek.com/ie6pebug/
		$css = preg_replace('/:first-l(etter|ine)\\{/', ':first-l$1 {', $css);

		return trim($css);
	}

	/**
	 * Replace what looks like a set of selectors
	 *
	 * @param array $m regex matches
	 * @return string
	 */
	protected function _selectors_cb($m)
	{
		// Remove ws around the combinators
		return preg_replace('/\\s*([,>+~])\\s*/', '$1', $m[0]);
	}

	/**
	 * Process a comment and return a replacement
	 *
	 * @param array $m regex matches
	 * @return string
	 */
	protected function _comment_cb($m)
	{
		$has_surrounding_ws = (trim($m[0]) !== $m[1]);
		$m                  = $m[1];

		// $m is the comment content w/o the surrounding tokens,
		// but the return value will replace the entire comment.
		if ($m === 'keep')
			return '/**/';

		// Component of http://tantek.com/CSS/Examples/midpass.html
		if ($m === '" "')
			return '/*" "*/';

		// Component of http://tantek.com/CSS/Examples/midpass.html
		if (preg_match('@";\\}\\s*\\}/\\*\\s+@', $m))
			return '/*";}}/* */';

		if ($this->_in_hack) {
			// inversion: feeding only to one browser
			if (preg_match('@
				^/               # comment started like /*/
				\\s*
				(\\S[\\s\\S]+?)  # has at least some non-ws content
				\\s*
				/\\*             # ends like /*/ or /**/
				@x', $m, $n)
			)
			{
				// End hack mode after this comment, but preserve the hack and comment content
				$this->_in_hack = FALSE;

				return "/*/{$n[1]}/**/";
			}
		}

		// Comment ends like \*/
		if (substr($m, -1) === '\\')
		{
			// Begin hack mode and preserve hack
			$this->_in_hack = TRUE;

			return '/*\\*/';
		}

		// Comment looks like /*/ foo */
		if ($m !== '' && $m[0] === '/')
		{
			// Begin hack mode and preserve hack
			$this->_in_hack = TRUE;

			return '/*/*/';
		}

		if ($this->_in_hack)
		{
			// A regular comment ends hack mode but should be preserved
			$this->_in_hack = FALSE;

			return '/**/';
		}

		// Issue 107: if there's any surrounding whitespace, it may be important, so
		// replace the comment with a single space

		// Remove all other comments
		return $has_surrounding_ws ? ' ' : '';
	}

	/**
	 * Process a font-family listing and return a replacement
	 *
	 * @param array $m regex matches
	 * @return string
	 */
	protected function _font_family_cb($m)
	{
		// Issue 210: must not eliminate WS between words in unquoted families
		$pieces = preg_split('/(\'[^\']+\'|"[^"]+")/', $m[1], NULL, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		$out    = 'font-family:';

		while (NULL !== ($piece = array_shift($pieces)))
		{
			if ($piece[0] !== '"' && $piece[0] !== "'")
			{
				$piece = preg_replace('/\\s+/',      ' ', $piece);
				$piece = preg_replace('/\\s?,\\s?/', ',', $piece);
			}

			$out .= $piece;
		}

		return $out . $m[2];
	}

}