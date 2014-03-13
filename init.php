<?php defined('SYSPATH') or die('No direct script access.');

// Set version
define('PAJAS_MINIFY_VERSION', '1.0');

// Compare kohana version, must be 3.2.x to be compatible
if (
	version_compare(Kohana::VERSION, '3.2', '<')
	|| version_compare(Kohana::VERSION, '3.3', '>=')
)
	throw new Kohana_Exception('Kohana version 3.2.x required, current version is :kohana_version',
		array(':kohana_version', Kohana::VERSION));