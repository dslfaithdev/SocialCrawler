<?php
// Set up the Facebook application
set_time_limit(120);

define('APPID',"");
define('APPSEC',"");
define('nl', "<br>");

require_once "./facebook-php/src/facebook.php";

$facebook = new Facebook(array(
			       'appId' => APPID,
			       'secret' => APPSEC,
			       'cookie' => true,
			       'status' => true,
			       'xfbml' => true,
			       ));

$user = $facebook->getUser();

?>
