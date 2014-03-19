<?php defined('SYSPATH') or die('No direct script access.');

return array(
	// Minify if we have another environmnent than DEVELOPMENT
	'minify' => (Kohana::$environment != Kohana::DEVELOPMENT),
);