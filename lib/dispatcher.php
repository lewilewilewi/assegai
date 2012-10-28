<?php

namespace assegai;

/**
 * Request dispatcher.
 *
 * This is the main class of Assegai and a routing wrapper around
 * Atlatl. The principle is simple; the request is routed to the
 * correct Atlatl appliction, then the output is processed by the
 * global settings.
 *
 * This file is part of Assegai
 *
 * Assegai is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * Assegai is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Assegai.  If not, see <http://www.gnu.org/licenses/>.
 */
class Dispatcher
{
    protected $root_path;
	protected $apps_path;
    protected $modules_path;
    protected $custom_modules_path;
	protected $apps;

    protected $main_conf;
	protected $apps_conf;

	protected $current_app;
	protected $prefix;

	protected $apps_routes;
	protected $routes;

    function __construct($root, $conf = false)
    {
        $this->root_path = $root;
        $this->conf_path = ($conf? $conf : $this->getPath('conf.php'));
        $this->parseconf();
    }

    /**
     * Parses the global configuration file and that of each
     * application.
     */
    protected function parseconf()
    {
		// Loading the main configuration file first so we can get the paths.
		$conf = array(
			'prefix' => '',
			'apps_path' => 'apps',
            'modules_path' => 'lib/modules',
			'apps' => array(),
			);

		require($this->conf_path);
        $this->main_conf = Config::fromArray($conf);

		$this->apps_path = $this->getPath($this->main_conf->get('apps_path'));
        $this->modules_path = $this->getPath($this->main_conf->get('modules_path'));
        $this->custom_modules_path = $this->main_conf->get('user_modules');
		$this->apps = $this->main_conf->get('apps');
		$this->prefix = $this->main_conf->get('prefix');

		// Alright. Now let's load the apps config.
		$this->routes = array();
		$this->app_routes = array();
		foreach($this->apps as $appname) {
			$path = $this->apps_path . '/' . $appname;
			if(!file_exists($path) || !is_dir($path)) {
				continue;
			}
			$app = array();
			@include($path . '/conf.php');
			$this->apps_conf[$appname] = Config::fromArray($app);
			foreach($app['route'] as $route => $callback) {
				$this->app_routes[$route] = $appname;
			}
		}

		krsort($this->app_routes);
    }

    /**
     * Returns an absolute path.
     */
    protected function getPath($relpath)
    {
		// Is this a relative path?
		if($relpath[0] == '/'
           || preg_match('/^[a-z]:/i', $relpath)) {
            return $relpath;
        } else {
			return $this->root_path . '/' . $relpath;
		}
    }

	/**
	 * Autoloader for controllers etc.
	 */
	public function autoload($classname)
	{
        $first_split = strpos($classname, '_');
        if($first_split) {
            $token = substr($classname, 0, $first_split);

            $filename = "";

            if($token == 'Module') {
                $class = substr($classname, strlen($token) + 1);

                // Trying user modules.
                $filename = '';
                if($this->custom_modules_path) {
                    $filename = $this->custom_modules_path . '/' . strtolower($class) . '/' .
                        strtolower($class) . '.php';
                }

                // Falling back on default module path.
                if(!file_exists($filename)) {
                    $filename = $this->modules_path . '/' . strtolower($class) . '/' .
                        strtolower($class) . '.php';
                }
            }
            else if(substr_count($classname, '_') >= 2) {
                $app_splitter = strpos($classname, '_');
                $type_splitter = strpos($classname, '_', $app_splitter + 1);

                $app = substr($classname, 0, $app_splitter);
                $type = substr($classname, $app_splitter + 1,
                               $type_splitter - $app_splitter - 1);
                $class = substr($classname, $type_splitter + 1);

                $paths = array('Controller' => 'controllers',
                               'Exception' => 'exceptions',
                               'Model' => 'models',
                               'View' => 'views');
                $filename = $this->apps_path . '/' . strtolower($app) . '/'
                    . $paths[$type] . '/' . strtolower($class) . '.php';
            }

            include($filename);
		}
	}

	/**
	 * Serves requests
	 */
	public function serve()
	{
		$server = new Server($_SERVER, $this->prefix);
        $runner = new \atlatl\Core($this->prefix, $server);
		$route_to_app = "";
        $app = null;

        /* Dealing with the error handlers.*/
        if($this->main_conf->get('handler40x')) {
            $handler = $this->main_conf->get('handler40x');
            $runner->register40x(function($e) use($handler) {
                    list($class, $method) = explode('::', $handler);
                    $controller = new $class();
                    $class->$method();
                });
        }
        if($this->main_conf->get('handler50x')) {
            $handler = $this->main_conf->get('handler50x');
            $runner->register50x(function($e) use($handler) {
                    list($class, $method) = explode('::', $handler);
                    $controller = new $class();
                    $class->$method();
                });
        }

		$method_routes = preg_grep('%^' . $server->getMethod() . ':%',
								   $this->app_routes);

		foreach($this->app_routes as $route => $app) {
			if(preg_match('%^'. $route .'%i', $server->getMethod() . ':' . $server->getRoute())) {
				$route_to_app = $app;
				break;
			}
		}

		// Trying generic.
		if(!$route_to_app) {
			foreach($this->app_routes as $route => $app) {
				if(preg_match('%^' . $route . '%i', $server->getRoute())) {
					$route_to_app = $app;
					break;
				}
			}
		}

		if(!$route_to_app) {
			throw new \Exception('Not found');
		}

		$this->current_app = $route_to_app;

        $server->setMainConf($this->main_conf);
        $server->setAppConf($this->apps_conf[$this->current_app]);

		// We register the dispatcher's autoloader
		spl_autoload_register(array($this, 'autoload'));

        $server->setAppPath($this->apps_path . '/' . $this->current_app);

		// Let's load the app's modules
		$container = new ModuleContainer($server);
		if($this->apps_conf[$this->current_app]->get('modules')
		   && is_array($this->apps_conf[$this->current_app]->get('modules'))) {
			foreach($this->apps_conf[$this->current_app]->get('modules') as $module) {
				$opts = NULL;
				if($this->apps_conf[$this->current_app]->get($module)) {
					$opts = $this->apps_conf[$this->current_app]->get($module);
				}
				$container->addModule('Module_' . $module, $opts);
			}
		}

		$runner->setModules($container);
		$runner->serve($this->apps_conf[$this->current_app]->get('route'));
	}
}

?>