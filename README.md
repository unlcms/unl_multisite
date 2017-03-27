## Hacks of Core needed:

### includes/bootstrap.inc

- function drupal_settings_initialize(). UNL change: include a "global" settings file that applies to all sites.

```
function drupal_settings_initialize() {
  global $base_url, $base_path, $base_root;

  // Export these settings.php variables to the global namespace.
  global $databases, $cookie_domain, $conf, $installed_profile, $update_free_access, $db_url, $db_prefix, $drupal_hash_salt, $is_https, $base_secure_url, $base_insecure_url;
  $conf = array();

  // UNL change: include a "global" settings file that applies to all sites.
  if (file_exists(DRUPAL_ROOT . '/sites/all/settings.php')) {
    include_once DRUPAL_ROOT . '/sites/all/settings.php';
  }
  // End UNL change.
  
  if (file_exists(DRUPAL_ROOT . '/' . conf_path() . '/settings.php')) {
    include_once DRUPAL_ROOT . '/' . conf_path() . '/settings.php';
  }
```

- function conf_path(). UNL change: Add $default_domains array support for sites.php to list which domains are ok to use with 'unl.edu.*' site_dirs.
       If no $default_domains array is defined in sites.php, this code will do nothing.
       
```
function conf_path($require_settings = TRUE, $reset = FALSE) {
  $conf = &drupal_static(__FUNCTION__, '');

  if ($conf && !$reset) {
    return $conf;
  }

  $confdir = 'sites';

  $sites = array();
  // UNL Change
  $default_domains = array();
  // End UNL Change
  if (file_exists(DRUPAL_ROOT . '/' . $confdir . '/sites.php')) {
    // This will overwrite $sites with the desired mappings.
    include(DRUPAL_ROOT . '/' . $confdir . '/sites.php');
  }

  $uri = explode('/', $_SERVER['SCRIPT_NAME'] ? $_SERVER['SCRIPT_NAME'] : $_SERVER['SCRIPT_FILENAME']);
  $server = explode('.', implode('.', array_reverse(explode(':', rtrim($_SERVER['HTTP_HOST'], '.')))));
  for ($i = count($uri) - 1; $i > 0; $i--) {
    for ($j = count($server); $j > 0; $j--) {
      $dir = implode('.', array_slice($server, -$j)) . implode('.', array_slice($uri, 0, $i));
      // UNL Change
      // Since we're truncating site_dir domains to just unl.edu, we need to skip any site_dir that
      // Starts with "unl.edu" unless we're on the default site's domain (ie: unlcms.unl.edu)
      if (substr($dir, 0, 7) == 'unl.edu' && count($default_domains) > 0) {
        $is_primary_domain = FALSE;
        foreach ($default_domains as $default_domain) {
          if (substr($_SERVER['HTTP_HOST'], 0, strlen($default_domain)) == $default_domain) {
            $is_primary_domain = TRUE;
          }
        }
        if (!$is_primary_domain) {
          continue;
        }
      }
      // End UNL Change
      if (isset($sites[$dir]) && file_exists(DRUPAL_ROOT . '/' . $confdir . '/' . $sites[$dir])) {
        $dir = $sites[$dir];
      }
      if (file_exists(DRUPAL_ROOT . '/' . $confdir . '/' . $dir . '/settings.php') || (!$require_settings && file_exists(DRUPAL_ROOT . '/' . $confdir . '/' . $dir))) {
        $conf = "$confdir/$dir";
        return $conf;
      }
    }
  }
  $conf = "$confdir/default";
  return $conf;
}
```
       
### sites/sites.php

Add support for $default_domains array. See conf_path() in includes/bootstrap.inc
     
```
/**
 * Default domains
 * 
 * Used to specify which domains are allowed to use "universal" site dirs
 * (starting with unl.edu).  The purpose is to prevent sites like
 * unlcms.unl.edu/ncard from showing up at bike.unl.edu/ncard.
 */
# $default_domains = array('example.unl.edu', 'example-test.unl.edu');

/**
 * Stub for UNL Site creation tool.
 * 
 * The following comments are needed for the UNL Site creation tool to 
 * create site aliases.  Make sure to include them in your sites.php!
 */
# THIS SECTION IS AUTOMATICALY GENERATED.
# DO NOT EDIT!!!!

# %UNL_CREATION_TOOL_STUB%

# END AUTOMATICALLY GENERATED AREA.
```
