<?php

set_time_limit(120);
ini_set('display_errors',0);

echo "small_SPC_inside";

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

$cfname = sprintf("config/page_%s.txt", $user);
if (!($configFilePtr = fopen($cfname, "r")))
  {
    $pages = 0;
  }
 else
   {
     fscanf($configFilePtr, "%d", $pages);
   }
if ($configFilePtr) fclose($configFilePtr);

if(!($configFilePtr = fopen($cfname, "w")))
  {
    return;
  }
fprintf($configFilePtr, "%d", $pages);
fflush($configFilePtr);
fclose($configFilePtr);

if (!($fbIDFilePtr = fopen('config/fbid.txt', 'r')))
  {
    echo "No Valid FB page identifier\n";
    return;
  }
fscanf($fbIDFilePtr, "%s\n", $fbGroupID);
fscanf($fbIDFilePtr, "%s\n", $fbGroupName);
fclose($fbIDFilePtr);

echo "I am crawling [ ", $fbGroupID, " ] ", $fbGroupName;
echo " from ", $pages;

fprintf(errFilePtr, "I am crawling [ %s, %s ] from page %d\n", $fbGroupID, $fbGroupName, $pages);
fflush(errFilePtr);

if( $user )
  {
    $esname = sprintf("outputs/%05d_%s.log", $pages, $user);
    if (!($errFilePtr = fopen($esname, "w"))) {
      return;
    }

    $groups_feed = $facebook->api('/' . $fbGroupID . "/feed");

    $next_page = 1;
    $count = 0;
    $group_feed = 0;

    // skipping a few pages ...
    for ($i = 0; $i < $pages; $i += 1)
      {
	$next_page = $groups_feed['paging']['next'];
	$groups_feed = $facebook->api(substr($next_page, 26));
	fprintf($errFilePtr, "page %d --- \n%s\n", $count, $next_page);
	$count += 1;
	if (($i % 400) == 0) sleep(2);
      }
    flush();
    fflush($errFilePtr);

    $sname = sprintf("config/posts_%s.txt", $user);

    if (!($myFilePtr = fopen($sname, "a")))
      {
	echo "I am OUT";
	return;
      }

    while($next_page)
      {
	foreach($groups_feed['data'] as $curr_feed)
	  {
	    fprintf($myFilePtr, "%s\n", $curr_feed['created_time']);
	    fprintf($myFilePtr, "%s\n", $curr_feed['id']);
	    fflush($myFilePtr);
	  }
	$count += 1;
	$next_page = $groups_feed['paging']['next'];
	if ($next_page)
	  {
	    $groups_feed = $facebook->api(substr($next_page, 26));
	  }

	fprintf($errFilePtr, "page %d/%d done\n", $count, $pages);
	if(!($configFilePtr = fopen($cfname, "w")))
	  {
	    return;
	  }
	fprintf($configFilePtr, "%d", $count);
	fflush($configFilePtr);
	fclose($configFilePtr);

	if (($count % 100) == 0) sleep(2);
      }
    fprintf($errFilePtr, "ALL Posts collected %d\n", $count);
    echo "ALL Posts collected ", $count, "!!!!!";
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

