<?php
require_once "./config/config.php";
require_once "./include/outputHandler.php";
if(php_sapi_name() === 'cli') //We are running from cli, set exec-time to 0.
  set_time_limit(0);

//Parse command line arguments as GET variables
parse_str(implode('&', array_slice($argv, 1)), $_GET);
if(!isset($_GET['token']))
  die("You must provide me with an access token (visit: https://developers.facebook.com/tools/explorer/".APPID." and generate a token)\n");
$token['access_token'] = $_GET['token'];
renewAccessToken();
# Registering shutdown function to redirect users.
register_shutdown_function('fatalErrorHandler');

# Save the output buffer to file AND print to screen.
ob_implicit_flush(true);
//ob_end_flush();
$obfw = new OB_FileWriter('log/session.log');
$obfw->start();

while(true) {
  $out = array();
  #Fetch new id's
  get_execution_time(true);
  try {
    $result = trim(curl_get(URL, array("action" => "pull","count" => 5)));
  } catch (Exception $e) { sleep(30); continue; }
  $posts = explode('&',$result);
  print "\n+++ Pulled " .count($posts)." post(s) ".get_execution_time(1)."\n";
  flush();ob_flush();
  if(count($posts) < 1 || empty($posts[0])) {
    print "Did not receive any new posts :/.\nWill take a nap and try again.\n"; ob_flush();flush();
    sleep(1800); //30 min.
    continue;
  }
  foreach($posts as $currentPost) {
    //Test if the user is still there..
    if(connection_aborted()) {
      print "connection lost\n";
      return;
    }
    //Verify the access token's lifetime
    if(($token['expire_time']-(2*60*60)) < time()) #Renew accessToken
      renewAccessToken();
    $start_time = microtime(true);
    try {
      if(strpos($currentPost, '_') === false)
        $data = fb_page_extract($currentPost, $facebook);
      else
        $data = crawl($currentPost, $facebook);
    } catch(Exception $e) { continue; }
    $out[$currentPost]['exec_time'] = microtime(true)-$start_time;
#    file_put_contents('outputs/'.$currentPost, $data);
    $data = base64_encode(gzencode($data));
    $out[$currentPost]['data'] = $data;
#    file_put_contents('outputs/'.$currentPost.'gz', $data);
  }
  //Push changes
  for($i=0; $i<10; $i++) {
  get_execution_time(1);
    $curl_result = curl_post(URL.'?action=push', $out);
    print "--- ".trim($curl_result) ." ".get_execution_time(1)."\n";
    flush();ob_flush();
    if($curl_result === "Pushed to db.\n")
      break;
    sleep(10);
  }
  //break;
}

function fb_page_extract($page, $facebook) {
  get_execution_time(true);
  print  $page; flush();ob_flush();
  $out="";
  $page='https://graph.facebook.com/'.$page.'/feed?fields=id,created_time';
  while(1) {
    $fb_data = facebook_api_wrapper($facebook, substr($page, 26));
    print "."; flush(); ob_flush();
    foreach($fb_data['data'] as $curr_feed)
      $out .= sprintf("%s\n%s\n", $curr_feed['id'], $curr_feed['created_time']);
    if (!isset($fb_data['paging'],$fb_data['paging']['next']))
      break;
    $page = $fb_data['paging']['next'];
  }
  print " ". get_execution_time(true) . "<br/>\n";flush(); ob_flush();
  return $out;
}

function crawl($currentPost, $facebook) {
  get_execution_time(true);
  print  $currentPost;
  flush();ob_flush();
  $curr_feed = facebook_api_wrapper($facebook, '/' . $currentPost);
  print "."; flush(); ob_flush();

  #      fprintf($outFilePtr, "%s\n", json_encode($curr_feed));
  #      fprintf($outFilePtr, "\n");
  $out = sprintf("%s\n\n", json_encode($curr_feed));
  // el_likes handling --
  $ep_likes_page = 1;
  $ep_likes = facebook_api_wrapper($facebook, '/' . $currentPost . "/likes");
  print "L"; flush(); ob_flush();
  while($ep_likes_page)
  {
    if ($ep_likes)
    {
      #          fprintf($outFilePtr, "{\"ep_likes\":%s}\n",
      #            json_encode($ep_likes));
      #          fprintf($outFilePtr, "\n");
      $out .= sprintf("{\"ep_likes\":%s}\n\n", json_encode($ep_likes));

      $ep_likes_page = 0;
      if (isset($ep_likes['paging']) && isset($ep_likes['paging']['next']))
        $ep_likes_page = $ep_likes['paging']['next'];
      if ($ep_likes_page)
      {
        $ep_likes = facebook_api_wrapper($facebook, substr($ep_likes_page, 26));
        print "L"; flush(); ob_flush();
      }
    }
    else
    {
      $ep_likes_page = NULL;
    }
  } // done with el_likes!

  // ec_comments handling --
  $ec_comments_page = 1;
  $ec_comments = facebook_api_wrapper($facebook, '/' . $currentPost . "/comments");
  print "C"; flush(); ob_flush();
  while($ec_comments_page) {
    if ($ec_comments) {
      #          fprintf($outFilePtr, "{\"ec_comments\":%s}\n",
      #            json_encode($ec_comments));
      #          fprintf($outFilePtr, "\n");
      $out .= sprintf("{\"ec_comments\":%s}\n\n", json_encode($ec_comments));

      foreach ($ec_comments['data'] as $ec_comment) {
        $ec_likes_page = 1;
        if(!isset($ec_comment['like_count']) || $ec_comment['like_count'] == 0) {
          #              fprintf($outFilePtr, "{\"ec_likes\":{\"data\":[]}}\n\n");
          $out .= "{\"ec_likes\":{\"data\":[]}}\n\n";
          continue;
        }

        $ec_likes = facebook_api_wrapper($facebook, '/' . $ec_comment['id'] . "/likes");
        print "l"; flush(); ob_flush();
        while($ec_likes_page) {
          if ($ec_likes) {
            #                fprintf($outFilePtr, "{\"ec_likes\":%s}\n",
            #                  json_encode($ec_likes));
            #                fprintf($outFilePtr, "\n");
            $out .= sprintf("{\"ec_likes\":%s}\n\n", json_encode($ec_likes));
            $ec_likes_page = 0;
            if (isset($ec_likes['paging']) && isset($ec_likes['paging']['next']))
              $ec_likes_page = $ec_likes['paging']['next'];
            if ($ec_likes_page) {
              $ec_likes = facebook_api_wrapper($facebook, substr($ec_likes_page, 26));
              print "l"; flush(); ob_flush();
            }
          }
          else {
            $ec_likes_page = NULL;
          }
        } // ec_likes_page
      } // for each ec_comment

      $ec_comments_page = 0;
      if (isset($ec_comments['paging']) && isset($ec_comments['paging']['next']))
        $ec_comments_page = $ec_comments['paging']['next'];
      if ($ec_comments_page) {
        $ec_comments = facebook_api_wrapper($facebook, substr($ec_comments_page, 26));
        print "C"; flush(); ob_flush();
      }
    }
    else {
      $ec_comments_page = NULL;
    }
  }

  print " ". get_execution_time(true) . "<br/>\n";flush(); ob_flush();
  // At this point, we are done with ONE post.
  return $out;
}

  /*
    if ((($postsCount+$offset) % (100*$chunk)) == 0)
    {
      print " ".get_execution_time(true)."<br/>\nEven hundred count, extend Access_Token"; ob_flush();
      $facebook->api('/oauth/access_token', 'GET',
        array(
          'client_id' => $facebook->getAppId(),
          'client_secret' => $facebook->getApiSecret(),
          'grant_type' => 'fb_exchange_token',
          'fb_exchange_token' => $facebook->getAccessToken()
        )
      );
    }
    print " " . get_execution_time(true) . "<br/>\n" . $id_file. "--" . "All Posts DONE! ";
    print microtime(true) . "<br/>\n";
    if (!($lastCountFilePtr = fopen($lastCountFName, "w"))) {
      print "error opening the file: $lastCountFName for writing";
      return;
    }
    else {
      fprintf($lastCountFilePtr, "%d", $postsCount);
      fclose($lastCountFilePtr);
    }
  }
  sleep(60);
  }*/
?>

<?php
function renewAccessToken() {
  GLOBAL $facebook, $token;
  #Renew the accessToken
  $url='https://graph.facebook.com/oauth/access_token?client_id='.APPID.
    '&client_secret='.APPSEC.
    '&grant_type=fb_exchange_token&fb_exchange_token='.$token['access_token'];
  $ret = curl_get($url, array());
  if(strpos($ret, '"type":"OAuthException"') !== false)
    die("Old access token (visit: https://developers.facebook.com/tools/explorer/".APPID." and generate a token)\n");
  parse_str($ret, $token);

  $facebook->setAccessToken($token['access_token']);
  $token['expire_time'] = $token['expires']+time();
  print "New token: " . $token['access_token'];
  print "\nToken expires in ". ($token['expire_time'] - time()) ." secs <br/>\n\n";
}

/**
 * get execution time in seconds at current point of call in seconds
 * @return float Execution time at this point of call
 */
function get_execution_time($delta = false)
{
  static $microtime_start = null;
  static $microtime_delta = null;
  if($microtime_start === null)
  {
    $microtime_start = microtime(true);
    $microtime_delta = $microtime_start;
    return 0.0;
  }
  if($delta) {
    $delta = microtime(true) - $microtime_delta;
    $microtime_delta = microtime(true);
    return $delta;
  }
  $microtime_delta = microtime(true);
  return microtime(true) - $microtime_start;
}

function postTime() {
  static $postTime = null;
  if($postTime === null) {
    $postTime = microtime(true);
  }
  $delta = microtime(true) - $postTime;
  $postTime = microtime(true);
  return $delta;
}

function facebook_api_wrapper($facebook, $url) {
  $error = 0;
  global $start_time;
  while (1) {
    try {
      $data = $facebook->api($url, 'GET', array('limit' => 200));
      return $data;
    } catch (Exception $e) {
      $t = time(1);
      error_log(microtime(1) . ";". $e->getCode() .";[".get_class($e)."]".$e->getMessage().";$url\n",3,dirname($_SERVER['SCRIPT_FILENAME']) . "/error.log" );
      sleep(10);
      print "#"; flush(); ob_flush();
      if (strpos($e->getMessage(), "Unsupported get request") !== false)
        return "$e-getMessage()";
      if (strpos($e->getMessage(), "(#803)") !== false) //We got a error 803 "Some of the aliases you requested do not exist"
        return "$e-getMessage()";
      if (strpos($e->getMessage(), "(#613)") !== false) //We got a error 613 "Calls to stream have exceeded the rate of 600 calls per 600 seconds."
        sleep(rand(60,240));
      if (strpos($e->getMessage(), "(#4)") !== false) //We got a error 4 "User request limit reached"
        sleep(rand(60,240));
      if ($error > 10) {
        sleep(600);
        throw $e;
        # trigger_error($e->getMessage(), E_USER_WARNING);
        # die($e->getMessage()."<br/>\n".get_execution_time()."<br/>\n<script> top.location = \"".selfURL()."\"</script>\n");
      }
      $start_time += (time(1)-$t);
      $error++;
    }
  }
}

function fatalErrorHandler()
{
  # Getting last error
  $error = error_get_last();

  error_log(microtime(1) . ";".$error['type'].";".$error['message'].";\n",3,dirname($_SERVER['SCRIPT_FILENAME']) . "/error.log" );
  # Checking if last error is a fatal error
  if(($error['type'] === E_ERROR) || ($error['type'] === E_USER_ERROR))
  {
    # Here we handle the error, displaying HTML, logging, ...
    echo 'Sorry, a serious error has occured but don\'t worry, I\'ll redirect the user<br/>\n';
    echo "<br/>\n".get_execution_time()."<br/>\n\n<script> top.location = \"".selfURL()."\"</script>\n";
    print microtime(true) . "<br/>\n";
  }
}

function selfURL()
{
  //isset($_SERVER["HTTPS"]) ? 'https' : 'http';
  //$protocol = strleft(strtolower($_SERVER["SERVER_PROTOCOL"]), "/").$s;
  if(!isset($_SERVER['SERVER_NAME'], $_SERVER['REQUEST_URI']))
    return $_SERVER['PHP_SELF'];
  return (isset($_SERVER["HTTPS"]) ? 'https' : 'http') ."://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
}

#print microtime(true) . "<br/>\n";
#print "Script ended gracefully.\n\nALL OK!\n";

$obfw->end();
?>

<?php

/**
 * Send a POST requst using cURL
 * @param string $url to request
 * @param array $post values to send
 * @param array $options for cURL
 * @return string
 */
function curl_post($url, array $post = NULL, array $options = array())
{
  $defaults = array(
    CURLOPT_POST => 1,
    CURLOPT_HEADER => 0,
    CURLOPT_URL => $url,
    CURLOPT_FRESH_CONNECT => 1,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_FORBID_REUSE => 1,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_POSTFIELDS => http_build_query($post)
  );

  $ch = curl_init();
  curl_setopt_array($ch, ($options + $defaults));
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
  if(($result = curl_exec($ch)) === false)
  {
    trigger_error(curl_error($ch) . "\n $url");
  }
  if(curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
    trigger_error("Curl error: ". curl_getinfo($ch, CURLINFO_HTTP_CODE) ."\n".$result . "\n");
  }
  curl_close($ch);
  return $result;
}

/**
 * Send a GET requst using cURL
 * @param string $url to request
 * @param array $get values to send
 * @param array $options for cURL
 * @return string
 */
function curl_get($url, array $get = NULL, array $options = array())
{
  $defaults = array(
    CURLOPT_URL => $url. (strpos($url, '?') === FALSE ? '?' : ''). http_build_query($get),
    CURLOPT_HEADER => 0,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_TIMEOUT => 30
  );

  $ch = curl_init();
  curl_setopt_array($ch, ($options + $defaults));
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
  if(($result = curl_exec($ch)) === false)
  {
    trigger_error(curl_error($ch) . "\n $url");
  }
  if(curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
    trigger_error("Curl error: ". curl_getinfo($ch, CURLINFO_HTTP_CODE) ."\n".$result . "\n");
  }
  curl_close($ch);
  return $result;
}
?>
