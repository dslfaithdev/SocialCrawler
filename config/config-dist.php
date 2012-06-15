<?php
set_time_limit(240);

// Set up the Facebook application
define('APPID',"");
define('APPSEC',"");
define('nl', "<br>");
define('URL', "");

require_once "./facebook-php/src/facebook.php";
$facebook = new Facebook(array(
    'appId' => APPID,
    'secret' => APPSEC,
    'cookie' => true,
    'status' => true,
    'xfbml' => true,
));
?>
