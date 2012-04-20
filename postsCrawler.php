<?php

// Set up the Facebook application
set_time_limit(120);
ini_set('display_errors',0);
echo "Hello, Crawler!!\n";

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

// First, let us try to figure out how many already done?
$lastCountFName = sprintf("config/lastCount_%s.txt", $user);
if (!($lastCountFilePtr = fopen($lastCountFName, "r")))
  {
    $lastCount = 0;
  }
 else
   {
     fscanf($lastCountFilePtr, "%d", $lastCount);
   }
if ($lastCountFilePtr) fclose($lastCountFilePtr);

// Second, fast-forward to the post ID entry we should continue this time.
$postsCount = -1;
$currentPost = 0;
$postsFName = sprintf("config/posts.txt", $user);
if (!($postsFilePtr = fopen($postsFName, "r")))
  {
    echo "No Posts File available\n";
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

// strictly speaking, we might not need the following --
if (!($fbIDFilePtr = fopen('config/fbid.txt', 'r')))
  {
    echo "No Valid FB page identifier\n";
    return;
  }
fscanf($fbIDFilePtr, "%s\n", $fbGroupID);
fscanf($fbIDFilePtr, "%s\n", $fbGroupName);
fclose($fbIDFilePtr);
flush();
echo "I am crawling [ ", $fbGroupID, " ] ", $fbGroupName;
echo " from ", $currentPost, " index = ", $postsCount;

// Now, we can continue...
if( $user )
  {
    while(!feof($postsFilePtr))
      {
        fscanf($postsFilePtr, "%s\n", $currentPost);
        $postsCount += 1;

	sscanf($currentTime, "%13s", $timePrefix);
        $outFName = sprintf("outputs/%08d_%13s_%s.json",
	                    $postsCount, $timePrefix, $currentPost);
        if (!($outFilePtr = fopen($outFName, "w")))
          {
            return;
          }

	$curr_feed = $facebook->api('/' . $currentPost);

	fprintf($outFilePtr, "%s\n", json_encode($curr_feed));
        fprintf($outFilePtr, "\n");
	fflush($outFilePtr);

	// el_likes handling --
	$ep_likes_page = 1;
	$ep_likes = $facebook->api('/' . $currentPost . "/likes");
	while($ep_likes_page)
	  {
	    if ($ep_likes)
	      {
		fprintf($outFilePtr, "{\"ep_likes\":%s}\n",
			json_encode($ep_likes));
		fprintf($outFilePtr, "\n");
		fflush($outFilePtr);

		$ep_likes_page = $ep_likes['paging']['next'];
		if ($ep_likes_page)
		  {
		    $ep_likes = $facebook->api(substr($ep_likes_page, 26));
		  }
	      }
	    else
	      {
		$ep_likes_page = NULL;
	      }
	  } // done with el_likes!

	// ec_comments handling --
	$ec_comments_page = 1;
	$ec_comments = $facebook->api('/' . $currentPost . "/comments");
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
		    $ec_likes = $facebook->api
		      ('/' . $ec_comment['id'] . "/likes");
		    while($ec_likes_page)
		      {
			if ($ec_likes)
			  {
			    fprintf($outFilePtr, "{\"ec_likes\":%s}\n",
				    json_encode($ec_likes));
			    fprintf($outFilePtr, "\n");
			    fflush($outFilePtr);

			    $ec_likes_page = $ec_likes['paging']['next'];
			    if ($ec_likes_page)
			      {
				$ec_likes = $facebook->api
				  (substr($ec_likes_page, 26));
			      }
			  }
			else
			  {
			    $ec_likes_page = NULL;
			  }
		      } // ec_likes_page
		  } // for each ec_comment

		$ec_comments_page = $ec_comments['paging']['next'];
		if ($ec_comments_page)
		  {
		    $ec_comments = $facebook->api
		      (substr($ec_comments_page, 26));
		  }
	      }
	    else
	      {
		$ec_comments_page = NULL;
	      }
	  }

	// At this point, we are done with ONE post.
	// so, let us close/record it!

	fflush(outFilePtr);
	fclose(outFilePtr);

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
	    sleep(2);
	  }
      }
    echo "All Posts DONE!!!\n";
  }
 else
   {
     try
       {
	 $params = array
	   ('scope' => "email, sms, user_groups, friends_groups, read_stream",
	    //  'redirect_uri' => "http://apps.facebook.com/spring_demo",
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
   }
?>

