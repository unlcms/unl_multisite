Insert the following line into index.php after $request is initialized:
require_once './modules/contrib/unl_multisite/bootstrap.inc';


Your index.php file should look like:

$request = Request::createFromGlobals();
require_once './modules/contrib/unl_multisite/bootstrap.inc';
$response = $kernel->handle($request);
