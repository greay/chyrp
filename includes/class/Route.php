<?php
	/**
	 * Class: Route
	 * Holds information for URLs, redirecting, etc.
	 */
	class Route {
		# Array: $code
		# The translation array of the post URL setting to regular expressions.
		# Passed through the route_code filter.
		public $code = array('(year)'     => '([0-9]{4})',
		                     '(month)'    => '([0-9]{1,2})',
		                     '(day)'      => '([0-9]{1,2})',
		                     '(hour)'     => '([0-9]{1,2})',
		                     '(minute)'   => '([0-9]{1,2})',
		                     '(second)'   => '([0-9]{1,2})',
		                     '(id)'       => '([0-9]+)',
		                     '(author)'   => '([^\/]+)',
		                     '(clean)'    => '([^\/]+)',
		                     '(url)'      => '([^\/]+)',
		                     '(feather)'  => '([^\/]+)',
		                     '(feathers)' => '([^\/]+)');

		# Function: $urls
		# An array of clean URL => dirty URL translations.
		public $urls = array('/\/id\/([0-9]+)\//'                => '/?action=view&amp;id=$1',
		                     '/\/page\/(([^\/]+)\/)+/'           => '/?action=page&amp;url=$2',
		                     '/\/search\//'                      => '/?action=search',
		                     '/\/search\/([^\/]+)\//'            => '/?action=search&amp;query=$1',
		                     '/\/archive\/([^\/]+)\/([^\/]+)\//' => '/?action=archive&amp;year=$1&amp;month=$2',
		                     '/\/theme_preview\/([^\/]+)\//'     => '/?action=theme_preview&amp;theme=$1',
		                     '/\/([^\/]+)\/feed\/([^\/]+)\//'    => '/?action=$1&amp;feed&amp;title=$2',
		                     '/\/([^\/]+)\/feed\//'              => '/?action=$1&amp;feed');

		# String: $action
		# The current action.
		public $action = "";

		# Boolean: $ajax
		# Shortcut to the AJAX constant (useful for Twig).
		public $ajax = AJAX;

		# Array: $post_url_attrs
		# Contains an associative array of URL key to value arguments if we're viewing a post.
		public $post_url_attrs = array();

		/**
		 * Function: __construct
		 * Filters the key => val code so that modules may extend it.
		 */
		private function __construct() {}

		/**
		 * Function: url
		 * Attempts to change the specified clean URL to a dirty URL if clean URLs is disabled.
		 * Use this for linking to things. The applicable URL conversions are passed through the
		 * parse_urls trigger.
		 *
		 * Parameters:
		 *     $url - The clean URL.
		 *
		 * Returns:
		 *     Clean URL - if $config->clean_urls is set to *true*.
		 *     Dirty URL - if $config->clean_urls is set to *false*.
		 */
		public function url($url) {
			$config = Config::current();
			if ($config->clean_urls) { # If their post URL doesn't have a trailing slash, remove it from these as well.
				if (substr($url, 0, 5) == "page/") # Different URL for viewing a page
					$url = substr($url, 5);

				return (substr($config->post_url, -1) == "/" or $url == "search/") ?
				       $config->url."/".$url :
				       $config->url."/".rtrim($url, "/") ;
			}

			$urls = $this->urls;
			Trigger::current()->filter($urls, "parse_urls");

			foreach (array_diff_assoc($urls, $this->urls) as $key => $value)
				$urls[substr($key, 0, -1)."feed\//"] = "/".$value."&amp;feed";

			$urls["/\/(.*?)\/$/"] = "/?action=$1";

			return $config->url.preg_replace(
			       array_keys($urls),
			       array_values($urls),
			       "/".$url, 1);
		}

		/**
		 * Function: key_regexp
		 * Converts the values in $config->post_url to regular expressions.
		 *
		 * Parameters:
		 *     $key - Input URL with the keys from <Routes->$code>.
		 *
		 * Returns:
		 *     $regexp - $key values replaced with their regular expressions from <Routes->$code>.
		 */
		private function key_regexp($key) {
			Trigger::current()->filter($this->code, "url_code");

			$replace = str_replace("/", "\\/", $key);
			$replace = str_replace(array_keys($this->code), array_values($this->code), $replace);

			return $replace;
		}

		/**
		 * Function: determine_action
		 * This meaty function determines what exactly to do with the URL.
		 */
		public function determine_action() {
			global $pluralizations, $page;
			$config = Config::current();

			if (!$config->clean_urls)
				fallback($_GET['action'], "index");

			$this->action =& $_GET['action'];

			# Correctly translate viewing feathers with dirty URLs on.
			if (!$config->clean_urls and in_array($this->action, array_values($pluralizations["feathers"]))) {
				$_GET['feather'] = $this->action;
				$this->action = "feather";
			}

			# Parse the current URL and extract information.
			$parse = parse_url($config->url);
			fallback($parse["path"]);

			$this->safe_path = str_replace("/", "\\/", $parse["path"]);
			$this->request = preg_replace("/".$this->safe_path."/", "", $_SERVER['REQUEST_URI'], 1);
			$this->arg = explode("/", trim($this->request, "/"));

			if (ADMIN or JAVASCRIPT or AJAX or XML_RPC or !$config->clean_urls)
				return;

			if (empty($this->arg[0])) return $this->action = "index"; # If they're just at /, don't bother with all this.

			# Viewing a post by its ID
			if ($this->arg[0] == "id") {
				$_GET['id'] = $this->arg[1];
				return $this->action = "id";
			}

			# Paginator
			if (preg_match_all("/\/((([^_\/]+)_)?page)\/([0-9]+)/", $this->request, $page_matches)) {
				foreach ($page_matches[1] as $key => $page_var) {
					$index = array_search($page_var, $this->arg);
					$_GET[$page_var] = $this->arg[$index + 1];
				}

				if ($index == 0) # Don't set $this->action to "page" (bottom of this function).
					return $this->action = "index";
			}

			# Feed
			if (preg_match("/\/feed\/?$/", $this->request)) {
				$_GET['feed'] = "true";

				if ($this->arg[0] == "feed") # Don't set $this->action to "feed" (bottom of this function).
					return $this->action = "index";
			}

			# Feed with a title parameter
			if (preg_match("/\/feed\/([^\/]+)\/?$/", $this->request, $title)) {
				$_GET['feed'] = "true";
				$_GET['title'] = $title[1];

				if ($this->arg[0] == "feed") # Don't set $this->action to "feed" (bottom of this function).
					return $this->action = "index";
			}

			# Archive
			if ($this->arg[0] == "archive") {
				# Make sure they're numeric; there might be a /page/ in there.
				if (isset($this->arg[1]) and is_numeric($this->arg[1]))
					$_GET['year'] = $this->arg[1];
				if (isset($this->arg[2]) and is_numeric($this->arg[2]))
					$_GET['month'] = $this->arg[2];
				if (isset($this->arg[3]) and is_numeric($this->arg[3]))
					$_GET['day'] = $this->arg[3];

				return $this->action = "archive";
			}

			# Searching
			if ($this->arg[0] == "search") {
				if (isset($this->arg[1]) and strpos($this->request, "?action=search&query="))
					redirect(str_replace("?action=search&query=", "", $this->request));

				if (isset($this->arg[1]))
					$_GET['query'] = $this->arg[1];

				return $this->action = "search";
			}

			# Theme Previewing
			if ($this->arg[0] == "theme_preview" and !empty($this->arg[1])) {
				$_GET['theme'] = $this->arg[1];
				return $this->action = "theme_preview";
			}

			# Bookmarklet
			if ($this->arg[0] == "bookmarklet") {
				$_GET['status'] = $this->arg[1];
				return $this->action = "bookmarklet";
			}

			# Viewing Feathers
			if (in_array($this->arg[0], array_values($pluralizations["feathers"])) and (empty($this->arg[1]) or $this->arg[1] == "feed" or $this->arg[1] == "page")) {
				$_GET['feather'] = $this->arg[0];
				return $this->action = "feather";
			}

			# Custom pages added by Modules, Feathers, Themes, etc.
			foreach ($config->routes as $route)
				if (preg_match_all("/\(([^\)]+)\)/", $route, $matches)) {
					if (substr($config->post_url, -1) != "/")
						$route = rtrim($route, "/");

					$fix_slashes = str_replace("/", "\\/", $route);
					$to_regexp = preg_replace("/\(([^\)]+)\)/", "([^\/]+)", $fix_slashes);

					if (preg_match("/".$to_regexp."/", $this->request, $url_matches)) {
						array_shift($url_matches);

						foreach ($matches[1] as $index => $parameter)
							$_GET[$parameter] = $url_matches[$index];

						return $this->action = $this->arg[0];
					}
				}

			# Check for a page
			$page = new Page(null, array("where" => "__pages.url = :url",
			                             "params" => array(":url" => end($this->arg))));
			if (!$page->no_results)
				return list($_GET['url'], $this->action) = array(end($this->arg), "page");
		}

		public function check_viewing_post() {
			global $post, $page, $main;
			$config = Config::current();
			if (ADMIN or JAVASCRIPT or AJAX or XML_RPC or !$config->clean_urls or isset($this->action))
				return;

			if (count($this->arg) == 1 and method_exists($main, $this->arg[0]))
				return $this->action = $this->arg[0];

			$post_url = $this->key_regexp(rtrim($config->post_url, "/"));
			preg_match_all("/([^\/]+)(\/|$)/", $config->post_url, $parameters);
			if (preg_match("/".$post_url."/", rtrim($this->request, "/"), $matches)) {
				array_shift($matches);

				foreach ($parameters[1] as $index => $parameter)
					if ($parameters[1][$index][0] == "(")
						$this->post_url_attrs[rtrim(ltrim($parameter, "("), ")")] = urldecode($this->arg[$index]);

				return $this->action = "view";
			}

			return $this->action = (empty($this->arg[0])) ? "index" : $this->arg[0] ;
		}

		/**
		 * Function: add
		 * Adds a route to Chyrp. Only needed for actions that have more than one parameter.
		 * For example, for /tags/ you won't need to do this, but you will for /tag/tag-name/.
		 *
		 * Parameters:
		 *     $path - The path to add. Wrap variables with parentheses, e.g. "/tag/(name)/".
		 *
		 * See Also:
		 *     <remove_route>
		 */
		public function add($path) {
			$config = Config::current();
			$new_routes = $config->routes;
			$new_routes[] = $path;
			$config->set("routes", $new_routes);
		}

		/**
		 * Function: remove_route
		 * Removes a route from the install's .htaccess file.
		 *
		 * Parameters:
		 *     $path - The path to remove. Same as <add>.
		 *
		 * See Also:
		 *     <add_route>
		 */
		public function remove($path) {
			$new_routes = array();
			$config = Config::current();

			foreach ($config->routes as $route) {
				if ($route == $path) continue;
				$new_routes[] = $route;
			}

			$config->set("routes", $new_routes);
		}

		/**
		 * Function: current
		 * Returns a singleton reference to the current class.
		 */
		public static function & current() {
			static $instance = null;
			return $instance = (empty($instance)) ? new self() : $instance ;
		}
	}
	$route = Route::current();
