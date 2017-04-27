Copy .htaccess-subsite-map.txt to the Drupal root.

Insert the following line into index.php after $request is initialized:
require_once 'modules/unl_multisite/bootstrap.inc';

Add the following line to your httpd.conf:
RewriteMap drupal_multisite txt:<DRUPAL_ROOT>/.htaccess-subsite-map.txt
