<?php
require_once "./config/config.php";

# Registering shutdown function to redirect users.
register_shutdown_function('fatalErrorHandler');

if( !$user )
   {
     try
       {
	 $params = array
	   ('scope' => "email, sms, user_groups, friends_groups, read_stream",
	    );
	 $redirect = $facebook->getLoginUrl($params);
	 ?>
	   <script>
	      top.location = "<?php echo $redirect; ?>";
	 </script>
	     <?php
	     }
     catch (FacebookApiException $e)
       {
	 print_r($e);
       }
     return;
   }

if(isset($_GET['file'])) {
  $file = $_GET['file'];
  $file = 'posts_files/posts.txt.'.$file;
  if (file_exists($file)){
    $files = array($file);
  }
}
// If we could not parse the get-var as a valid posts file just fall back to "all".
if (!isset($files)) {
  $files = glob('posts_files/posts.txt.*');
}

foreach($files as $file) {
  $id_file = substr($file, 22);

  print "starting child for: ".$id_file; flush();ob_flush();


// First, let us try to figure out how many already done?
$lastCountFName = sprintf("config/lastCount_%s_%s.txt", $id_file, $user);
if (file_exists($lastCountFName) && $lastCountFilePtr = fopen($lastCountFName, "r"))
  {
     fscanf($lastCountFilePtr, "%d", $lastCount);
   fclose($lastCountFilePtr);
}
 else
   {
    $lastCount = 0;
   }

// Second, fast-forward to the post ID entry we should continue this time.
$postsCount = 0;
$currentPost = 0;
$postsFName = $file;
if (!(file_exists($postsFName) && $postsFilePtr = fopen($postsFName, "r")))
  {
    echo "No Posts File available<br/>\n";
    flush();
    return;
  }
 else
   {
     for ($i = 0; $i < $lastCount; $i += 1)
       {
	 fscanf($postsFilePtr, "%s\n", $currentTime);
	 fscanf($postsFilePtr, "%s\n", $currentPost);
	 $postsCount += 1;
       }
   }
echo " from ", $currentPost, " index = ", $postsCount ."  "; flush(); ob_flush();
// Now, we can continue...
    while(!feof($postsFilePtr))
      {
        fscanf($postsFilePtr, "%s\n", $currentTime);
        fscanf($postsFilePtr, "%s\n", $currentPost);
        $postsCount += 1;

	sscanf($currentTime, "%13s", $timePrefix);
        $outFName = sprintf("outputs/%s_%08d_%13s_%s.json", $id_file,
	                    $postsCount, $timePrefix, $currentPost);
        print " " . get_execution_time(true) . "<br/>\n" . $outFName;
        flush();ob_flush();
        if (!($outFilePtr = fopen($outFName, "w")))
          {
            return;
          }

	$curr_feed = facebook_api_wrapper($facebook, '/' . $currentPost);

	fprintf($outFilePtr, "%s\n", json_encode($curr_feed));
        fprintf($outFilePtr, "\n");
	fflush($outFilePtr);

	// el_likes handling --
	$ep_likes_page = 1;
	$ep_likes = facebook_api_wrapper($facebook, '/' . $currentPost . "/likes");
	while($ep_likes_page)
	  {
	    if ($ep_likes)
	      {
		fprintf($outFilePtr, "{\"ep_likes\":%s}\n",
			json_encode($ep_likes));
		fprintf($outFilePtr, "\n");
		fflush($outFilePtr);

        $ep_likes_page = 0;
        if (isset($ep_likes['paging']) && isset($ep_likes['paging']['next']))
		$ep_likes_page = $ep_likes['paging']['next'];
		if ($ep_likes_page)
		  {
		    $ep_likes = facebook_api_wrapper($facebook, substr($ep_likes_page, 26));
        print "."; flush(); ob_flush();
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
  print "."; flush(); ob_flush();
	while($ec_comments_page)
	  {
	    if ($ec_comments)
	      {
		fprintf($outFilePtr, "{\"ec_comments\":%s}\n",
			json_encode($ec_comments));
		fprintf($outFilePtr, "\n");
		fflush($outFilePtr);

		foreach ($ec_comments['data'] as $ec_comment)
		  {
		    $ec_likes_page = 1;
		    $ec_likes = facebook_api_wrapper($facebook, '/' . $ec_comment['id'] . "/likes");
        print "."; flush(); ob_flush();
		    while($ec_likes_page)
		      {
			if ($ec_likes)
			  {
			    fprintf($outFilePtr, "{\"ec_likes\":%s}\n",
				    json_encode($ec_likes));
			    fprintf($outFilePtr, "\n");
			    fflush($outFilePtr);

                $ec_likes_page = 0;
                if (isset($ec_likes['paging']) && isset($ec_likes['paging']['next']))
			    $ec_likes_page = $ec_likes['paging']['next'];
			    if ($ec_likes_page)
			      {
				$ec_likes = facebook_api_wrapper($facebook, substr($ec_likes_page, 26));
        print "."; flush(); ob_flush();
			      }
			  }
			else
			  {
			    $ec_likes_page = NULL;
			  }
		      } // ec_likes_page
		  } // for each ec_comment

       $ec_comments_page = 0;
        if (isset($ec_comments['paging']) && isset($ec_comments['paging']['next']))
		$ec_comments_page = $ec_comments['paging']['next'];
		if ($ec_comments_page)
		  {
		    $ec_comments = facebook_api_wrapper($facebook, substr($ec_comments_page, 26));
        print "."; flush(); ob_flush();
		  }
	      }
	    else
	      {
		$ec_comments_page = NULL;
	      }
	  }

	// At this point, we are done with ONE post.
	// so, let us close/record it!

	fflush($outFilePtr);
	fclose($outFilePtr);

	if (!($lastCountFilePtr = fopen($lastCountFName, "w")))
	  {
	    return;
	  }
	else
	  {
	    fprintf($lastCountFilePtr, "%d", $postsCount);
	  }
	if ($lastCountFilePtr) fclose($lastCountFilePtr);

	if (($postsCount % 100) == 0)
	  {
        print " ".get_execution_time(true)."<br/>\nEven hundred count, extend Access_Token"; flush();
        $facebook->api('/oauth/access_token', 'GET',
          array(
            'client_id' => $facebook->getAppId(),
            'client_secret' => $facebook->getApiSecret(),
            'grant_type' => 'fb_exchange_token',
            'fb_exchange_token' => $facebook->getAccessToken()
          )
        );
	  }
      }
    echo "All Posts DONE!!!<br/>\n";
  }
?>

<?php
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
get_execution_time();

function facebook_api_wrapper($facebook, $url) {
  $error = 0;
  while (1) {
    try {
      $data = $facebook->api($url, 'GET', array('limit' => 500));
      return $data;
    } catch (Exception $e) {
      error_log(microtime(1) . ";". $e->getCode() .";[".get_class($e)."]".$e->getMessage().";$url\n",3,dirname($_SERVER['SCRIPT_FILENAME']) . "/error.log" );
      print "#"; flush(); ob_flush();
      if ($error > 10) {
        die($e->getMessage()."<br/>\n".get_execution_time()."<br/>\n<script> top.location = \"".$_SERVER['PHP_SELF']."\"</script>\n");
      }
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
    echo "<br/>\n".get_execution_time()."<br/>\n\n<script> top.location = \"".$_SERVER['PHP_SELF']."\"</script>\n";
  }
}

?>
