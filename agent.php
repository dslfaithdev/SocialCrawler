<?php
define("VERSION", 3.2);
define("API_VERSION", 2.8);

ini_set('memory_limit', -1);
require_once "./config/config.php";
require_once "./include/outputHandler.php";
require_once "./facebook-php/src/facebook.php";

if(php_sapi_name() === 'cli') {
  //We are running from cli, set exec-time to 0.
  set_time_limit(0);
  if(defined('SESSION_PATH'))
     session_save_path(SESSION_PATH);

  //Parse command line arguments as GET variables
  parse_str(implode('&', array_slice($argv, 1)), $_GET);
}
else
  set_time_limit(240);

$facebook = new Facebook(array(
    'appId' => APPID,
    'secret' => APPSEC,
    'default_graph_version' => API_VERSION,
));

# Registering shutdown function to redirect users.
register_shutdown_function('fatalErrorHandler');

# Save the output buffer to file AND print to screen.
ob_implicit_flush(true);
//ob_end_flush();
$obfw = new OB_FileWriter(dirname($_SERVER['SCRIPT_FILENAME']) .'/log/session-'.getmypid().'.log');
$obfw->start();

if(isset($_GET['posts'])) {
  foreach(explode(',', $_GET['posts']) as $post) {
    if (strpos($post, '_') !== false) {
      file_put_contents($post.'.json', json_encode(crawl($post, $facebook)).PHP_EOL.PHP_EOL);
    } else {
      file_put_contents($post.'.json', json_encode(fb_page_extract($post, $facebook)).PHP_EOL.PHP_EOL);
    }
  }
  die();
}

if(!isset($_GET['token']))
  print "No token provided, will try to read from the file: ".dirname($_SERVER['SCRIPT_FILENAME']) . "/TOKEN instead.".PHP_EOL;
else {
  $token['access_token'] = $_GET['token'];
  file_put_contents("./TOKEN", $token['access_token'].PHP_EOL);
}
renewAccessToken();

while(true) {
  $out = array();
  #Fetch new id's
  get_execution_time(true);
  try {
    $r = curl_get(URL, array("action" => "pull","count" => 5,"version" => VERSION));
    $result = json_decode($r,true);
    if($result === NULL)
      throw new Exception("Json decode error of: ". $r);
  } catch (Exception $e) { print "Error fetching new posts, will sleep 5s and try again.".$e->getMessage().PHP_EOL; sleep(5); continue; }
  //Verify that we have the latest version.
  if($result['version']%10 > VERSION)
    die("You have an old version, please upgrade.".PHP_EOL);
  if($result['status'] != "ok" || count($result['posts']) == 0) {
    print "Did not receive any new posts :/.\nWill take a nap and try again.\n"; ob_flush();flush();
    sleep(1800); //30 min.
    continue;
  }
  $posts=$result['posts'];
  print "\n+++ Pulled " .count($posts)." post(s) ".get_execution_time(1)."\n";
  flush();ob_flush();
  $start_crawl_time = microtime(true);
  foreach($posts as $currentPost) {
    if($currentPost == "0")
      continue;
    //Test if the user is still there..
    if(connection_aborted()) {
      print "connection lost\n";
      return;
    }
    //Verify the access token's lifetime
    if(($token['expire_time']-(2*60*60)) < time()) #Renew accessToken
      renewAccessToken();
    $out[$currentPost['id']]['id'] = $currentPost['id'];
    $out[$currentPost['id']]['status'] = "done";
    $out[$currentPost['id']]['type'] = $currentPost['type'];
    $data=array();
    $start_time = microtime(true);
    try {
      if($currentPost['type'] == "page") {
        if(isset($currentPost['data']))
          $data = $currentPost['data'];
        $data = fb_page_extract($currentPost['id'], $facebook, $data);
      }
      else
        $data = crawl($currentPost['id'], $facebook);
    } catch(Exception $e) {
      print "-- Interrupted @ ". get_execution_time(true) . "<br/>\n";flush(); ob_flush();
      error_log(microtime(1) . ";". $e->getCode() .";[".get_class($e)."]".$e->getMessage().";".$currentPost['id']."\n",3,dirname($_SERVER['SCRIPT_FILENAME']) . "/log/error.log" );
      $out[$currentPost['id']]['status'] = "error";
      $out[$currentPost['id']]['error_msg'] = $e->getMessage();
    }
    $out[$currentPost['id']]['exec_time'] = microtime(true)-$start_time;
    $out[$currentPost['id']]['data'] = $data;
    //file_put_contents('outputs/'.$currentPost['id'], json_encode($out[$currentPost['id']]));
    if(microtime(true)-$start_crawl_time > 60) {
      pushData($out);
      $out = array();
      $start_crawl_time = microtime(true);
    }
  }
  pushData($out);
}

  //Push changes
function pushData(&$out) {
  if(count($out) == 0)
    return;
  for($i=0; $i<10; $i++) {
    get_execution_time(1);
    try {
      //$JSONout = json_encode(array('version'=> VERSION, 'd'=>$out));
      //$GZout = gzencode($JSONout);
      $curl_result = curl_post(URL.'?action=push', NULL,
        array(CURLOPT_HTTPHEADER => array("Content-Encoding: gzip", 'Content-Type: application/json'),
          CURLOPT_POSTFIELDS => gzencode(json_encode(array('version'=> VERSION, 'd'=>$out)))
        ));
    } catch(Exception $e) { print "Exception catched trying to push " . $e->getMessage(); unset($e); get_execution_time(1); continue; }

    print "-PUSH- ".trim($curl_result) ." ".get_execution_time(1)."\n";
    flush();ob_flush();
    if($curl_result === "Pushed to db.\n")
      return;
    sleep(3);
  }
  // Could not push data, save locally.
  foreach( $out as &$post ){
    file_put_contents('outputs/'. $post['id'] . '#'. microtime(true) . '.raw.json', json_encode($post));
  }
}

function fb_page_extract($page, $facebook, array &$out = array()) {
  $stime=time();
  get_execution_time(true);
  print  $page; flush();ob_flush();
  $page='https://graph.facebook.com/v'.API_VERSION.'/'.$page.'/feed?fields=id,created_time,updated_time,from{id},' .
    'likes.limit(1).summary(total_count){id},' .
    'comments.limit(1).summary(total_count){id}';
  $out=$out+array('seq'=>0,  'done'=>false, 'until'=>time(), 'feed'=>array());
  //Get only new ones..
  if(isset($out['since']) && $out['since'] !=0) {
    $page.='&since='.$out['since'];
  }
  //Stop at given position. (for whatever reason)
  if(isset($out['until']) && $out['until'] != 0) {
      $page.='&until='.$out['until'];
      $end=$out['until'];
      $stopAtPosition = true;
  } else {
    $stopAtPosition = false;
  }

  while((time()-$stime) < (3600*2)) { //Just run for 2h and then commit.
    $fb_data = facebook_api_wrapper($facebook, substr($page, strlen(API_VERSION) + 28));
    if(!isset($fb_data['data'])) {
      print "_"; flush(); ob_flush();
      continue;
    }
    print "."; flush(); ob_flush();
    foreach($fb_data['data'] as $curr_feed) {
      isset($curr_feed['likes']) ? $likes=$curr_feed['likes']['summary']['total_count'] : $likes=0;
      isset($curr_feed['comments']) ? $comments=$curr_feed['comments']['summary']['total_count'] : $comments=0;
      $out['feed'][$out['seq']++] = array(substr(strstr($curr_feed['id'],'_'),1), strtotime($curr_feed['created_time']), $curr_feed['from']['id'], $likes, $comments);
      //Store the newest created_time as epoc in until (so we can resume from that stage).
      if($out['until'] < strtotime($curr_feed['created_time'])-1)
        $out['until'] = strtotime($curr_feed['created_time'])-1;
    }
#    $out['seq'] = $out['seq']+count($fb_data['data']);
    if (!isset($fb_data['paging'],$fb_data['paging']['next'])) {
      $out['done'] = true;
      break;
    }
    if ($stopAtPosition && $out['until'] > $end) {
      break;
    }
    $page = $fb_data['paging']['next'];
  }
  print " ". get_execution_time(true) . "<br/>\n";flush(); ob_flush();
  return $out;
}

function crawl($currentPost, $facebook) {
  get_execution_time(true);
  print  $currentPost;
  flush();ob_flush();
  $fields = '?fields=message,type,picture,story,link,name,description,caption,icon,' .
    'created_time,updated_time,shares,' .
    'object_id,status_type,source,application,place,' .
    'likes.limit(1).summary(total_count){id},'.
    'comments.limit(1).summary(total_count){id}';
  $curr_feed = facebook_api_wrapper($facebook, '/' . $currentPost . $fields);
  print "."; flush(); ob_flush();
  $out = sprintf("%s\n\n", json_encode($curr_feed));
  // el_likes handling --
  $ep_likes_page = 1;
  $ep_likes = facebook_api_wrapper($facebook, '/' . $currentPost .
    "/reactions?summary=total_count&fields=id,type,name,profile_type");
  print "L"; flush(); ob_flush();
  while($ep_likes_page) {
    if ($ep_likes) {
      $out .= sprintf("{\"ep_likes\":%s}\n\n", json_encode($ep_likes));
      $ep_likes_page = 0;
      if (isset($ep_likes['paging']) && isset($ep_likes['paging']['next']))
        $ep_likes_page = $ep_likes['paging']['next'];
      if ($ep_likes_page) {
        $ep_likes = facebook_api_wrapper($facebook, substr($ep_likes_page, strlen(API_VERSION) + 28));
        print "L"; flush(); ob_flush();
      }
    }
    else
      $ep_likes_page = NULL;
  } // done with el_likes!

  // ep_shares
  if(isset($curr_feed['shares'],$curr_feed['shares']['count']) && $curr_feed['shares']['count'] != 0) {
    $page = $currentPost . '/sharedposts?fields=from,updated_time,created_time,to';
    while($page) {
      try {
        $fb_data = facebook_api_wrapper($facebook, $page);
      } catch (Exception $e) {
        $out .= "{\"ep_shares\":{\"data\":[]}}\n\n";
        break;
      }
      print "S"; flush(); ob_flush();
      $out .= sprintf("{\"ep_shares\":%s}\n\n", json_encode($fb_data));
      if (isset($fb_data['paging'],$fb_data['paging']['next']))
        $page = substr($fb_data['paging']['next'], strlen(API_VERSION) + 28);
      else
        $page = NULL;
    }
  } else {
    $out .= "{\"ep_shares\":{\"data\":[]}}\n\n";
  }// done with ep_shares

  // ec_comments handling --
  $ec_comments_page = 1;
  $ec_comments = facebook_api_wrapper($facebook, '/' . $currentPost .
    "/comments?fields=id,message,from,like_count,message_tags,created_time,parent{id}&filter=stream&summary=true");
  print "C"; flush(); ob_flush();
  while($ec_comments_page) {
    if ($ec_comments) {
      $out .= sprintf("{\"ec_comments\":%s}\n\n", json_encode($ec_comments));
      //Handle errors when the comment response is empty
      if(!isset($ec_comments['data'])) {
        print "_"; flush(); ob_flush();
        throw new Exception("Broken comment at: ".$ec_comments_page . ":". var_export($ec_comment,true));
      }
      foreach ($ec_comments['data'] as $ec_comment) {
        $ec_likes_page = 1;
        if(!isset($ec_comment['like_count']) || $ec_comment['like_count'] == 0) {
          $out .= "{\"ec_likes\":{\"data\":[]}}\n\n";
          continue;
        }
        $ec_likes = facebook_api_wrapper($facebook, '/' . $ec_comment['id'] .
          "/likes?summary=total_count&fields=name,profile_type");
        $old_url="";
        print "l"; flush(); ob_flush();
        while($ec_likes) {
            $out .= sprintf("{\"ec_likes\":%s,\"id\":\"%s\"}}\n\n",
              substr(json_encode($ec_likes),0,-1), //remove the last } to support adding a new field (id)
              $ec_comment['id']);
            if (isset($ec_likes['paging']) && isset($ec_likes['paging']['next'])) {
              $ec_likes_page = $ec_likes['paging']['next'];
              if($ec_likes_page == $old_url) {
                print "-"; flush(); ob_flush();
                break;
              }
              $old_url = $ec_likes_page;
              $ec_likes = facebook_api_wrapper($facebook, substr($ec_likes_page, strlen(API_VERSION) + 28));
              print "l"; flush(); ob_flush();
            }
            else
              break;
        } // ec_likes_page
      } // for each ec_comment
      $ec_comments_page = 0;
      if (isset($ec_comments['paging']) && isset($ec_comments['paging']['next']))
        $ec_comments_page = $ec_comments['paging']['next'];
      if ($ec_comments_page) {
        $ec_comments = facebook_api_wrapper($facebook, substr($ec_comments_page, strlen(API_VERSION) + 28));
        print "C"; flush(); ob_flush();
      }
    }
    else
      $ec_comments_page = NULL;
  }

  print " ". get_execution_time(true) . "<br/>\n";flush(); ob_flush();
  // At this point, we are done with ONE post.
  return $out;
}

function renewAccessToken() {
  GLOBAL $facebook, $token;
  $tokenFile = "./TOKEN";
  if(file_exists($tokenFile)) {
    $token['access_token'] = trim(file_get_contents($tokenFile));
  }
  #Renew the accessToken
  $url='https://graph.facebook.com/oauth/access_token?client_id='.APPID.
    '&client_secret='.APPSEC.
    '&grant_type=fb_exchange_token&fb_exchange_token='.$token['access_token'];
  try {
    $ret = curl_get($url, array());
  } catch (Exception $e) {
    die("Old access token (visit: https://developers.facebook.com/tools/explorer/".APPID." and generate a token)\n");
  }
  if(strpos($ret, '"type":"OAuthException"') !== false)
    die("Old access token (visit: https://developers.facebook.com/tools/explorer/".APPID." and generate a token)\n");
  parse_str($ret, $token);

  $facebook->setAccessToken($token['access_token']);
  $token['expire_time'] = $token['expires']+time();
  print "New token: " . $token['access_token'];
  print "\nToken expires in ". ($token['expire_time'] - time()) ." secs <br/>\n\n";
  flush();
  file_put_contents("./TOKEN", $token['access_token'].PHP_EOL);
}

/**
 * get execution time in seconds at current point of call in seconds
 * @return float Execution time at this point of call
 */
function get_execution_time($delta = false) {
  static $microtime_start = null;
  static $microtime_delta = null;
  if($microtime_start === null) {
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
  if($postTime === null)
    $postTime = microtime(true);
  $delta = microtime(true) - $postTime;
  $postTime = microtime(true);
  return $delta;
}

function facebook_api_wrapper($facebook, $url) {
  $error = 0;
  global $start_time;
  while (1) {
    try {
      $data = $facebook->api('/v' . API_VERSION . '/' . $url, 'GET', array('limit' => 100/($error+1)));
      return $data;
    } catch (Exception $e) {
      $t = time(1);
      error_log(microtime(1) . ";". $e->getCode() .";[".get_class($e)."]".$e->getMessage().";$url\n",3,dirname($_SERVER['SCRIPT_FILENAME']) . "/log/error.log" );
      print "#"; flush(); ob_flush();
      /* Try to handle strange errors with huge amounts of comments */
      if (strpos($e->getMessage(), "Operation timed out after") !== false)
        /* It seems like it might be possible to retrieve if one first gets only the id. */
        try { $facebook->api($url, 'GET', array('limit' => 200, 'fields' => 'id')); } catch ( Exception $ex ) { unset($ex); }
      if (strpos($e->getMessage(), "An unknown error has occurred.") !== false)
        throw $e;
      if (strpos($e->getMessage(), "Unsupported get request") !== false)
      //  return "Error: Unsupported get request";
        throw $e;
      if (strpos($e->getMessage(), "(#21)") !== false) //We got a error 21 "Page ID <id> was migrated to page ID <id>."
        throw $e;
      if (strpos($e->getMessage(), "(#803)") !== false) //We got a error 803 "Some of the aliases you requested do not exist"
        throw $e;
      if (strpos($e->getMessage(), "(#613)") !== false) //We got a error 613 "Calls to stream have exceeded the rate of 600 calls per 600 seconds."
        sleep(rand(60,900));
      if (strpos($e->getMessage(), "(#17)") !== false) //We got a error 17 "User request limit reached"
        sleep(rand(60,900));
      if (strpos($e->getMessage(), "(#4)") !== false) //We got a error 4 "User request limit reached"
        sleep(rand(60,900));
      if ($error > 32) {
        sleep(600);
        $start_time += (time(1)-$t);
        throw $e;
      }
      sleep(10);
      $start_time += (time(1)-$t);
      $error++;
    }
  }
}

function fatalErrorHandler() {
  # Getting last error
  $error = error_get_last();
  # Checking if last error is a fatal error
  if(($error['type'] === E_ERROR) || ($error['type'] === E_USER_ERROR)) {
    error_log(microtime(1) . ";".$error['type'].";"."DIED: ".$error['message'].";\n",3,dirname($_SERVER['SCRIPT_FILENAME']) . "/log/error.log" );
    print microtime(1) . ";".$error['type'].";".$error['message'].PHP_EOL;
    flush(); ob_flush();
    if(php_sapi_name() !== 'cli') {
      # Here we handle the error, displaying HTML, logging, ...
      print 'Sorry, a serious error has occured but don\'t worry, I\'ll redirect the user<br/>\n';
      print "<br/>\n".get_execution_time()."<br/>\n\n<script> top.location = \"".selfURL()."\"</script>\n";
    }
  } else
    error_log(microtime(1) . ";".$error['type'].";".$error['message'].";\n",3,dirname($_SERVER['SCRIPT_FILENAME']) . "/log/error.log" );
}

function selfURL() {
  if(!isset($_SERVER['SERVER_NAME'], $_SERVER['REQUEST_URI']))
    return $_SERVER['PHP_SELF'];
  return (isset($_SERVER["HTTPS"]) ? 'https' : 'http') ."://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
}

$obfw->end();

/**
 * Send a POST requst using cURL
 * @param string $url to request
 * @param array $post values to send
 * @param array $options for cURL
 * @return string
 */
function curl_post($url, array $post = NULL, array $options = array()) {
  $defaults = array(
    CURLOPT_POST => 1,
    CURLOPT_HEADER => 0,
    CURLOPT_URL => $url,
    CURLOPT_FRESH_CONNECT => 1,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_FORBID_REUSE => 1,
    CURLOPT_TIMEOUT => 600
  );
  if(!is_null($post))
    $defaults['CURLOPT_POSTFIELDS'] = http_build_query($post);

  $ch = curl_init();
  curl_setopt_array($ch, ($options + $defaults));
  try {
    $whoami = posix_getpwuid(posix_getuid())['name'] . "." . posix_getpid();
    if(isset($options[CURLOPT_HTTPHEADER])) {
      $options[CURLOPT_HTTPHEADER][] = "From: $whoami";
      curl_setopt($ch, CURLOPT_HTTPHEADER, $options[CURLOPT_HTTPHEADER]);
    } else
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('From:' . $whoami));
  } catch(Exception $e) {}
  curl_setopt($ch, CURLOPT_USERAGENT,'SocalCrawler/'.VERSION.' Agent/'.VERSION);
  if(($result = curl_exec($ch)) === false) {
    throw new Exception(curl_error($ch) . "\n $url");
  }
  if(curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
    throw new Exception("Curl error: ". curl_getinfo($ch, CURLINFO_HTTP_CODE) ."\n".$result . "\n");
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
function curl_get($url, array $get = NULL, array $options = array()) {
  $defaults = array(
    CURLOPT_URL => $url. (strpos($url, '?') === FALSE ? '?' : ''). http_build_query($get),
    CURLOPT_HEADER => 0,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_TIMEOUT => 30
  );

  $ch = curl_init();
  curl_setopt_array($ch, ($options + $defaults));
  try {
    $whoami = posix_getpwuid(posix_getuid())['name'] . "." . posix_getpid();
    if(isset($options[CURLOPT_HTTPHEADER])) {
      $options[CURLOPT_HTTPHEADER][] = "From: $whoami";
      curl_setopt($ch, CURLOPT_HTTPHEADER, $options[CURLOPT_HTTPHEADER]);
    } else
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('From:' . $whoami));
  } catch(Exception $e) {}
  curl_setopt($ch, CURLOPT_USERAGENT,'SocalCrawler/'.VERSION.' Agent/'.VERSION);
  if(($result = curl_exec($ch)) === false) {
    throw new Exception(curl_error($ch) . "\n $url");
  }
  if(curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
    throw new Exception("Curl error: ". curl_getinfo($ch, CURLINFO_HTTP_CODE) ."\n".$result . "\n");
  }
  curl_close($ch);
  return $result;
}
?>
