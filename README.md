## Hacks of Core needed:

  *  includes/bootstrap.inc

     - function drupal_settings_initialize(). UNL change: include a "global" settings file that applies to all sites.

     - function conf_path(). UNL change: Add $default_domains array support for sites.php to list which domains are ok to use with 'unl.edu.*' site_dirs.
       If no $default_domains array is defined in sites.php, this code will do nothing.
       
  *  sites/sites.php

     Added support for $default_domains array. See conf_path() in includes/bootstrap.inc
     
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
