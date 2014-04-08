<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * Class Cssmin
 * @package Minify
 */

/**
 * Minify CSS
 *
 * This class uses Cssmin_Compressor and Cssmin_Urirewriter to
 * minify CSS and rewrite relative URIs.
 *
 * @package Minify
 * @author Stephen Clay <steve@mrclay.org>
 * @author http://code.google.com/u/1stvamp/ (Issue 64 patch)
 */
class Cssmin
{

	public $options = array();
	public $input;

	/**
	 * Minify a CSS string
	 *
	 * @param string $css
	 *
	 * @param array $options available options:
	 *
	 * 'preserve_comments': (default TRUE) multi-line comments that begin
	 * with "/*!" will be preserved with newlines before and after to
	 * enhance readability.
	 *
	 * 'remove_charsets': (default TRUE) remove all @charset at-rules
	 *
	 * 'prepend_relative_path': (default NULL) if given, this string will be
	 * prepended to all relative URIs in import/url declarations
	 *
	 * 'current_dir': (default NULL) if given, this is assumed to be the
	 * directory of the current CSS file. Using this, minify will rewrite
	 * all relative URIs in import/url declarations to correctly point to
	 * the desired files. For this to work, the files *must* exist and be
	 * visible by the PHP process.
	 *
	 * 'symlinks': (default = array()) If the CSS file is stored in
	 * a symlink-ed directory, provide an array of link paths to
	 * target paths, where the link paths are within the document root. Because
	 * paths need to be normalized for this to work, use "//" to substitute
	 * the doc root in the link paths (the array keys). E.g.:
	 * <code>
	 * array('//symlink' => '/real/target/path') // unix
	 * array('//static' => 'D:\\staticStorage')  // Windows
	 * </code>
	 *
	 * 'doc_root': (default = $_SERVER['DOCUMENT_ROOT'])
	 * see Cssmin_Urirewriter::rewrite
	 *
	 * @return string
	 */
	public function __construct($css, $options = array())
	{
		$this->options = array_merge(array(
			'compress'              => TRUE,
			'remove_charsets'       => TRUE,
			'current_dir'           => NULL,
			'doc_root'              => $_SERVER['DOCUMENT_ROOT'],
			'prepend_relative_path' => NULL,
			'symlinks'              => array(),
		), $options);

		$this->input = $css;
	}

	public static function factory($css, $options)
	{
		return new self($css, $options);
	}

	public function min()
	{
		$css = $this->input;

		if ($this->options['remove_charsets'])
			$css = preg_replace('/@charset[^;]+;\\s*/', '', $css);

		if ($this->options['compress'])
			$css = Cssmin_Compressor::process($css, $this->options);

		if ($this->options['prepend_relative_path'])
		{
			$css = Cssmin_Urirewriter::prepend(
				$css,
				$this->options['prepend_relative_path']
			);
		}

		if ($this->options['current_dir'])
		{
			$css = Cssmin_Urirewriter::rewrite(
				$css,
				$this->options['current_dir'],
				$this->options['doc_root'],
				$this->options['symlinks']
			);
		}

		return $css;
	}

}