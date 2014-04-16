<?php defined('SYSPATH') OR die('No direct access allowed.');

class Controller_Minify extends Controller
{

	protected $mime;
	protected $extension;
	protected $base;
	protected $minified        = '';
	protected $last_changetime = 0;
	protected $cache_max_age   = 3600;

	public function action_index()
	{
		if ( ! isset($_GET['f']))
			throw new Kohana_Exception('f parameter missing');

		if ( ! isset($_GET['b']))
			$this->base = '';
		else
			$this->base = $_GET['b'];

		$this->base = rtrim($this->base, '/');

		foreach (explode(',', $_GET['f']) as $filename)
		{
			$pathinfo = pathinfo($filename);

			if ( ! $this->mime)
			{
				$this->extension = strtolower($pathinfo['extension']);
				$this->mime      = File::mime_by_ext($this->extension);
			}
			elseif ($this->mime != File::mime_by_ext(strtolower($pathinfo['extension'])))
			{
				throw new Kohana_Exception('All files must be of the same mime type');
			}

			if ($pathinfo['dirname'] != '')
				$pathinfo['dirname'] .= '/';

			// Returns an absolute path to the file based on kohana cascading filesystem
			$real_filename = Kohana::find_file($this->base, $pathinfo['dirname'].$pathinfo['filename'], $pathinfo['extension']);

			if ( ! $real_filename)
				throw new Kohana_Exception('File '.$this->base.$filename.' is not found');

			$last_changetime = filemtime($real_filename);
			if ($last_changetime > $this->last_changetime)
				$this->last_changetime = $last_changetime;

			$cache_file = APPPATH.'cache/minify_'.str_replace('/', '_', $real_filename).'_'.$last_changetime;

			if (file_exists($cache_file))
			{
				$minified = file_get_contents($cache_file);
			}
			elseif ($this->mime == 'text/css')
			{
				$options = array(
					'current_dir'           => URL::base().$this->base,
					'prepend_relative_path' => URL::base().$this->base.'/',
				);

				$minified = Cssmin::factory(file_get_contents($real_filename), $options)->min();
				file_put_contents($cache_file, $minified);
			}
			elseif ($this->mime == 'application/javascript')
			{
				$minified = Jsmin::factory(file_get_contents($real_filename))->min();
				file_put_contents($cache_file, $minified);
			}
			else // We cannot minify anything but css and js atm
			{
				$minified = file_get_contents($real_filename);
			}

			$this->minified .= $minified;
		}

		$this->response->headers('Last-Modified', gmdate('D, d M Y H:i:s', $this->last_changetime).' GMT');
		$this->response->headers('Cache-Control', 'public, must-revalidate, max-age='.$this->cache_max_age);
		$this->response->headers('Content-Type', $this->mime);
		$this->response->body($this->minified);
	}

}