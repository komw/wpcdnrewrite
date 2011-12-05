<?php
/*
Plugin Name: CDN Rewrite
Plugin URI: http://github.com/bluewormlabs
Version: 1.0
Description: Rewrites URLs to files matching user-specified rules. This allows, for example, static content (e.g., images) to be loaded from a CDN instead of the server running the WordPress install.
Author: Blue Worm Labs
Author URI: http://bluewormlabs.com
License: zlib

Copyright (c) 2011 Blue Worm Labs, LLC

This software is provided 'as-is', without any express or implied
warranty. In no event will the authors be held liable for any damages
arising from the use of this software.

Permission is granted to anyone to use this software for any purpose,
including commercial applications, and to alter it and redistribute it
freely, subject to the following restrictions:

   1. The origin of this software must not be misrepresented; you must not
   claim that you wrote the original software. If you use this software
   in a product, an acknowledgment in the product documentation would be
   appreciated but is not required.

   2. Altered source versions must be plainly marked as such, and must not be
   misrepresented as being the original software.

   3. This notice may not be removed or altered from any source
   distribution.
*/

// Load any external functions
require_once('functions.php');

class WP_CDN_Rewrite {
	const NAME = 'CDN Rewrite';
	const SLUG = 'wpcdnrewrite';
	const REQUIRED_CAPABILITY = 'manage_options';
    const VERSION = '1.0';
	const VERSION_KEY = 'wpcdnrewrite-version'; // WP options key for our version
	const RULES_KEY = 'wpcdnrewrite-rules'; // WP options key for rules
	const WHITELIST_KEY = 'wpcdnrewrite-whitelist'; // WP options key for domains to rewrite URLs for
	const REWRITE_TYPE_HOST_ONLY = 1; // rewrite only the host portion of the url
	const REWRITE_TYPE_FULL_URL = 2; // rewrite the full URL up to the file

	public function __construct() {
        //only register the admin call backs if we're in the admin
        if(is_admin()) {
            add_action('admin_menu', array($this, 'admin_menu'));
            add_action('admin_init', array($this, 'admin_init'));
            add_action('admin_enqueue_scripts', array($this, 'include_admin_javascript'));
        }

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_uninstall_hook(__FILE__, array('WP_CDN_Rewrite', 'uninstall'));
        
        // Add filters to run our rewrite code on
        if (function_exists('is_multisite') && is_multisite()) {
        	add_filter('muplugins_loaded', array($this, 'startup'), 5);
        }
        else {
        	add_filter('plugins_loaded', array($this, 'startup'), 5);
        }
        add_filter('shutdown', array($this, 'shutdown'), 20);
	}
	
	/**
	 * Filter to start buffering at the start of WordPress' work
	 */
	public function startup() {
		$ret = ob_start('wpcdn_rewrite_content');
	}
	
	/**
	 * Filter to end buffering/flush any remaining buffer at the end of WordPress' work
	 */
	public function shutdown() {
		@ob_end_flush();
	}

    /**
     * The admin_init hook runs as soon as the admin initializes and we use it
     * to add our settings to the whitelist of allowed options
     */
    public function admin_init() {
        register_setting('wpcdnrewrite', self::RULES_KEY, array($this, 'sanitize_rules'));
        register_setting('wpcdnrewrite', self::WHITELIST_KEY, array($this, 'sanitize_whitelist'));
    }
	
	/**
     * Adds a link to our settings page under the Settings menu
     */
    public function admin_menu() {
		add_options_page(self::NAME, self::NAME, self::REQUIRED_CAPABILITY, self::SLUG, array($this, 'show_config'));
	}

    /**
     * adds the necessary wordpress options for the plugin to use later. Only runs on activation
     *
     * @return void
     */
    public function activate() {
        $host = parse_url(network_site_url(), PHP_URL_HOST);
        //add_option only runs if the option doesn't exist
        add_option(self::VERSION_KEY, self::VERSION);
        add_option(self::RULES_KEY, array());
        add_option(self::WHITELIST_KEY, array($host));
    }

    /**
     * Adds admin.js to the <head>
     */
    public function include_admin_javascript() {
        wp_enqueue_script('admin.js', plugins_url('html/admin.js', __FILE__), array('jquery'));
    }
	
	/**
     * Shows the configuration page within the settings
     */
    public function show_config() {
		if (!current_user_can(self::REQUIRED_CAPABILITY)) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}

		require_once('html/config.php');
	}
	
	/**
     * Rewrites the specified content per specified rules
     *
     * @param string  $content The text to rewrite
     * @return string The new content with appropriate URLs rewritten
     */
    public function rewrite_content($content) {
    	// Grab the version number we're working with
		$version = get_option(self::VERSION_KEY);
		
		if (strcmp($version, '1.0') == 0) {
			// Pull the rules and whitelist arrays from the database
			$rules = get_option(self::RULES_KEY);
			$whitelist = get_option(self::WHITELIST_KEY);
			
			// Get a DOM object for this content that we can manipulate
			$dom = new DOMDocument();
			$dom->loadHTML($content);
			$dom->formatOutput = true;
			
			// Rewrite URLs
			$this->do_rewrite(&$dom, $rules, $whitelist, 'a', 'href');
			$this->do_rewrite(&$dom, $rules, $whitelist, 'img', 'src');
			
			// Grab the modified HTML
			$newContent = $dom->saveHTML();
			
			return $newContent;
		}
		
		return $content;
	}

    /**
     * Deletes all of the stuff we put into the database so that we don't leave anything behind to corrupt future installs
     *
     * @return void
     */
    public static function uninstall() {
        delete_option(self::VERSION_KEY);
        delete_option(self::RULES_KEY);
        delete_option(self::WHITELIST_KEY);
    }
    
    /**
     * Does the actual URL rewriting for a given DOMDocument object
     *
     * @param DOMDocument $dom       The DOM to rewrite URLs in
     * @param array       $rules     Rewrite rules
     * @param array       $whitelist Array of server names to rewrite links for
     * @param string      $tag       The tag type to rewrite for
     * @param string      $attribute The attribute to rewrite for on the specified tag
     */
    protected function do_rewrite($dom, $rules=array(), $whitelist=array(), $tag='a', $attribute='href') {
    	// Make sure we got a valid DOM
    	if (NULL == $dom) {
    		wp_die('Invalid DOM passed to WP CDN Rewrite\'s do_rewrite()');
    	}
    	
    	// Go through all of the tags of the type specified…
    	$tags = $dom->getElementsByTagName($tag);
		if (!is_null($tags)) {
			foreach ($tags as $tag) {
				// …and look for ones that have the requested attribute
				if ($tag->hasAttribute($attribute)) {
					$url = $tag->getAttribute($attribute);
					
					if ($this->startswith($url, '/')) {
						$base = network_site_url();
						if (!$this->startswith($base, '/')) {
							$base = $base . '/';
						}
						$url = $base . $url;
					}
					$parsed = parse_url($url);
					
					if (FALSE !== $parsed) {
						$host = $parsed['host'];
						if (in_array($host, $whitelist)) {
							// The target is on a whitelisted domain, so
							// we want to rewrite the url
							
							$matchedRule = NULL;
							foreach ($rules as $rule) {
								$path = $parsed['path'];
								
								if ($this->endswith($path, $rule['match'])) {
									// Found a rule to rewrite for
									$matchedRule = $rule;
									break;
								}
							}
							
							$tag->setAttribute($attribute, $this->rewrite_url($url, $matchedRule));
						}
					}
				}
			}
		}
    }
    
    /**
     * Rewrites one URL per the specified rule
     *
     * @param string  $url  The URL
     * @param array   $rule Rewrite rule
     * @return string The rewritten URL
     */
    protected function rewrite_url($url, $rule) {
    	if (NULL == $rule) {
    		return $url;
    	}
    	
    	$ret = $url;
    	
		if (self::REWRITE_TYPE_HOST_ONLY == $rule['type']) {
			$host = parse_url($ret, PHP_URL_HOST);
			
			// Set the scheme and host if we have an absolute path
			if (FALSE === $host) {
				$host = network_site_url();
			}
			
			// Find the stuff to the left and right of the host
			$oldHostLen = strlen($host);
			$leftLen = strpos($ret, $host);
			$rightLen = strlen($ret) - ($leftLen + $oldHostLen);
			
			$left = substr($ret, 0, $leftLen);
			$right = substr($ret, $leftLen + $oldHostLen);
			
			// Build a new URL with our replacement host
			$ret = $left . $rule['rule'] . $right;
			
		}
		else if (self::REWRITE_TYPE_FULL_URL == $rule['type']) {
			$filename = pathinfo(parse_url($ret, PHP_URL_PATH), PATHINFO_BASENAME);
			$ret = $rule['rule'];
			
			// Make sure we have a / on the end
			if (!$this->endswith($ret, '/')) {
				$ret = $ret . '/';
			}
			
			$ret = $ret . $filename;
			
			// Add in the scheme and host for an absolute path
			if ($this->startswith($ret, '/')) {
				$base = network_site_url();
				if (!$this->endswith($base, '/')) {
					$base = $base . '/';
				}
				
				$ret = $base . $ret;
			}
		}
		
		return $ret;
    }


    /**
     * Sanitize the array of rules
     *
     * @param array $ruleArray
     * @return array
     */
    public function sanitize_rules(array $ruleArray) {
        $allowedTypes = array(
            self::REWRITE_TYPE_FULL_URL,
            self::REWRITE_TYPE_HOST_ONLY,
        );

        foreach($ruleArray as $key => $rule) {
            if(! in_array($rule['type'], $allowedTypes)) {
                unset($ruleArray[$key]);
                add_settings_error(self::RULES_KEY, self::RULES_KEY, "Invalid rule type entered");
                continue;
            }

            $rule['match'] = preg_replace('/\W/', '', $rule['match']);
            if(trim($rule['match']) == '') {
                unset($ruleArray[$key]);
                continue;
            }

            $validRule = true;
            if($rule['type'] == self::REWRITE_TYPE_FULL_URL) {
                $rule['rule'] = filter_var($rule['rule'], FILTER_SANITIZE_URL);
                $validRule = filter_var($rule['rule'], FILTER_VALIDATE_URL);
            } elseif($rule['type'] == self::REWRITE_TYPE_HOST_ONLY) {
                $rule['rule'] = preg_replace("/[http|https]:\/\//", "", $rule['rule']);
                $validRule = self::validateDomainName($rule['rule']);
            }

            if(! $validRule) {
                unset($ruleArray[$key]);
                add_settings_error(self::RULES_KEY, self::RULES_KEY, "Invalid rewrite URL entered");
            } else {
                $ruleArray[$key] = $rule;
            }
        }

        //make sure all the indexes are contiguous
        $ruleArray = array_values($ruleArray);
        return $ruleArray;
    }

    /**
     * Sanitize the array of domains
     *
     * @param array $valueArray
     * @return array
     */
    public function sanitize_whitelist(array $valueArray) {
        foreach($valueArray as $key => $value) {
            $value = trim($value);

            if($value == '') {
                unset($valueArray[$key]);
            } else {
                //strip http, https, and ://
                $value = preg_replace("/[http|https]:\/\//", "", $value);

                $validDomain = self::validateDomainName($value);
                if(false == $validDomain) {
                    add_settings_error(self::WHITELIST_KEY, self::WHITELIST_KEY, "Invalid domain name \"{$value}\" entered");
                } else {
                    $valueArray[$key] = $value;
                }
            }
        }

        //make sure all the indexes are contiguous
        $valueArray = array_values($valueArray);
        return $valueArray;
    }

    /**
	 * Tests whether a text starts with the given string or not
	 *
	 * @param   string the text to search
	 * @param   string the string to look for
	 * @return  bool true if the text starts with the given string, else false
	 */
	protected function startswith($haystack, $needle) {
    	$needleLen = strlen($needle);
    	return substr($haystack, 0, $needleLen) === $needle;
    }
    
    /**
	 * Tests whether a text ends with the given string or not
	 *
	 * @param   string the text to search
	 * @param   string the string to look for
	 * @return  bool true if the text ends with the given string, else false
	 * @source  http://www.jonasjohn.de/snippets/php/ends-with.htm
	 */
	protected function endswith($haystack, $needle){
	    return strrpos($haystack, $needle) === strlen($haystack) - strlen($needle);
	}


    /**
     * Used to check and see if the domains that are posted are valid
     *
     * @param $domainName
     * @return bool
     */
    protected function validateDomainName($domainName) {
        $pieces = explode(".", $domainName);
        foreach($pieces as $piece) {
            if (!preg_match('/^[a-z\d][a-z\d-]{0,62}$/i', $piece) || preg_match('/-$/', $piece)) {
                return false;
            }
        }
        return true;
    }
}

new WP_CDN_Rewrite();