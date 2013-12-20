<?php
// Get test users' access tokens of the app
require_once "./config/config.php";
require_once "./facebook-php/src/facebook.php";

$facebook = new Facebook(array(
    'appId' => APPID,
    'secret' => APPSEC,
));

$access_token = $facebook->getAccessToken();
$accounts = $facebook->api("/".APPID."/accounts/test-users?access_token=$access_token");

$id=1;
foreach($accounts['data'] as $account) {
  $filename="TOKEN.$id";
  print "Write access token of test user #$id into $filename\n";
  file_put_contents($filename, $account['access_token']);
  $id++;
}

?>
