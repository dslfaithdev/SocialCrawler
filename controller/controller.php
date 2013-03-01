<?php
#$GLOBALS['maintenance']=TRUE;
/*
 * Don't forget to set the default values below.
 */
define('PDO_dsn','mysql:dbname=crawling;unix_socket=/tmp/mysql.sock');
define('PDO_username','root');
define('PDO_password', '');

include_once('parser.php');

error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set('error_log','logFile.log');
$action='';
if(isset($_GET['action']))
  $action = $_GET['action'];


switch ($action) {
case 'add':
  checkout();
  break;
case 'pull':
  pull_post();
  break;
case 'push':
  my_push();
  break;
case 'stageone':
  stageone();
  break;
default:
  my_list();
}

#Checkout file, add to db.
function checkout() {
  die("this is depricated..");
  set_time_limit(0);
  $count = 0;
  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  } catch (PDOException $e) {
    die("DB error, try again.");
  }
  //Read file for now.
  $files = glob('posts/posts.txt.*');
  //$postsFName = $file;
  foreach ($files as $postsFName){
    if (!(file_exists($postsFName) && $postsFilePtr = fopen($postsFName, "r")))
      return "error opening the file";
    $file = $db->quote(substr(strrchr($postsFName,"."),1));
    //Make sure the file does not exist.
    $result = $db->query("SELECT * FROM page WHERE name=$file");
    if(($row=$result->fetch())) {
      $result->closeCursor();
      continue;
    }
    $result->closeCursor();
    print "Adding $file <br/>\n"; flush(); ob_flush();
    //Create page row.
    $count++;
    $db->query("START TRANSACTION");
    $sql = "INSERT INTO page (name) VALUES (".$file.")";
    $db->query($sql);
    $insertId = $db->lastInsertId();
    $postsCount = 0;

    $sql = 'INSERT IGNORE INTO post (page_fb_id, time_stamp, status, seq, date, post_fb_id)'.
      ' VALUES ( '.$insertId.', UNIX_TIMESTAMP(), \'new\', ?, ?, ?)';
    $sth = $db->prepare($sql);
    while(!feof($postsFilePtr)){
      fscanf($postsFilePtr, "%s\n", $currentTime);
      fscanf($postsFilePtr, "%s\n", $currentPost);
      $postsCount++;
      $sth->execute(array($postsCount,$currentTime,$currentPost));
      $sth->closeCursor();
    }
    $db->query("COMMIT");
    print "\t+ $postsCount rows added.<br/>\n"; flush(); ob_flush();
  }
  print "$count pages added.<br/>\n";
}

/*
    Updates and adds new posts to crawl to the db.
    returns number of rows added/modified or false on error
 */
function update_posts($page, $exec_time, $posts){
  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  } catch (PDOException $e) {
    return false;
  }
  $db->query("START TRANSACTION");

  //Verify that the page exist in the page table.
  $sql = "INSERT INTO page (fb_id) VALUES (".$db->quote($page).") ON DUPLICATE KEY UPDATE `update`=NOW(); ";
  $sql .= "INSERT INTO post (page_fb_id, post_fb_id, status, who, time_stamp, time) VALUES (".
    $db->quote($page).",0,'done',".
    "INET_ATON(".$db->quote($_SERVER["REMOTE_ADDR"])."), UNIX_TIMESTAMP(), ".  $db->quote($exec_time).
    ") ON DUPLICATE KEY UPDATE status = 'done'".
    ", who = INET_ATON(".$db->quote($_SERVER["REMOTE_ADDR"]).") ".
    ", time_stamp = UNIX_TIMESTAMP()".
    ", time =".$db->quote($exec_time).";";
  //Set all "old" entries to seq -1 and status old.
  $sql .= "UPDATE post SET seq=-1 WHERE page_fb_id=".$db->quote($page)." AND seq!=0;";
  $sth = $db->exec($sql);
  $sql = "INSERT INTO post (time_stamp, status, seq, date, page_fb_id,post_fb_id)".
      " VALUES ( UNIX_TIMESTAMP(), 'new', :seq, :date, :page_fb_id, :post_fb_id)".
      " ON DUPLICATE KEY UPDATE time_stamp = UNIX_TIMESTAMP(), status=IF(`status`='done','updated','new'), date=:date, seq=:seq;";
  $sth = $db->prepare($sql);
  $seq=1;
  //A bit ugly but needed to split the post format (`date`\n`post`) into usable values
  $arr=explode("\n",trim($posts));
  reset($arr);
  while(list(,$date) = each($arr)){
    $sth->execute(array('seq'=> $seq++,'date'=>each($arr)[1], 'page_fb_id' => strstr($date,'_',true), 'post_fb_id' => substr(strstr($date,'_'),1)));
  }
    $db->query("COMMIT");
  return $seq;
}

function pull_post($count=3) {
  if(isset($GLOBALS['maintenance']))
    die("0&maintenance try later");
  if(isset($_GET['count']))
    $count = intval($_GET['count']);
  if($count < 1)
    $count = 3;
  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    $db->setAttribute(PDO::ATTR_TIMEOUT, "120");
  } catch (PDOException $e) {
    die("0&No new posts"); //only command understood by the agent.
  }
  //Make sure we are not already adding items to our helper table.
  for ($i = 0; $i < 20; $i++) {
      $result = $db->query("SELECT count(*) FROM pull_posts WHERE page_fb_id = 0");
      if ($result->fetchColumn() == 0)
          break;
      sleep(10);
  }
  $result = $db->query("SELECT count(*) FROM pull_posts WHERE page_fb_id != 0");
  if ($result->fetchColumn() <= $count + 10) {
      // Add posts to our helper..
      $sql = "set session binlog_format = 'MIXED'; INSERT IGNORE INTO pull_posts VALUES (0,0);";
      $sql .= "INSERT IGNORE INTO pull_posts ".
          "SELECT page_fb_id, post_fb_id FROM ".
          "(SELECT page_fb_id, post_fb_id, if(status='pulled', -1, time_stamp) as ts FROM ".
          "post /*FORCE INDEX (id_status_timestamp)*/ WHERE ".
          "((status='pulled' AND UNIX_TIMESTAMP()-IF(post_fb_id IS NULL, time_stamp+86400, time_stamp) >14400) OR status IN ('new', 'recrawl')) ".
          "ORDER BY ts) AS tmp LIMIT 500;"; #14400 == 4h.  86400 == 24h
      $sql .= "DELETE FROM pull_posts WHERE page_fb_id = 0;";
      $db->query($sql);
  }
  for($i=0;$i<5;$i++) { //This should be a mysql procedure.
    $db->query("set session binlog_format = 'MIXED'; START TRANSACTION");
  $sql = "SELECT page_fb_id, post_fb_id FROM pull_posts LIMIT ".intval($count);
  $result = $db->query($sql);
  if($result->rowCount() == 0)
    die("0&No new posts");
  $rows=$result->fetchAll();
  $id = array();
  $sql ="";
  foreach ($rows as $row){
      $sql .= "UPDATE post SET status = 'pulled', ".
        "time_stamp = UNIX_TIMESTAMP(), ".
        "who = INET_ATON(".$db->quote($_SERVER["REMOTE_ADDR"]). ") ".
        " WHERE page_fb_id = '".$row['page_fb_id']."' AND post_fb_id = '".$row['post_fb_id']."'; ".
        "DELETE FROM pull_posts WHERE post_fb_id =  ".$row['post_fb_id']." AND page_fb_id= ".$row['page_fb_id'].";";
      $result = $db->query("SELECT concat(page_fb_id,'_',post_fb_id) FROM post WHERE page_fb_id = ".
        $row['page_fb_id']." AND post_fb_id = ".$row['post_fb_id']);
      $id[]=$result->fetchColumn();
  }
  $result->closeCursor();
  try {
    $result = $db->exec($sql);
    $db->query("COMMIT");
  } catch (Exception $e) {
    die("0&No new posts"); //only command understood by the agent.
  }
  if ($result !== 0 && count($id) !== 0)
      break;
  }
  if(count($id) == 0)
    die("0&No new posts");
  if($result == 0) {
#    header('HTTP/1.1 501 Not Implemented');
    die("0&Error updating DB"); //\n$sql");
  }
  foreach ($id as &$value) {
    if(substr(strstr($value,'_'),1) == '0')
      $value = strstr($value,'_',true);
  }
  print implode('&',$id);

}

function my_push() {
  #file_put_contents('logFile.log', serialize($_POST));
  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  } catch (PDOException $e) {
    die("DB error, try again.");
  }
  foreach ($_POST as $post_id => $post) {
    if(!isset($post['data'], $post['exec_time']))
      die("Wrong parameters");
    if(!is_numeric($post['exec_time']))
      die("Wrong parameters");
    //Is it a stage one crawl.
    if(strpos($post_id, '_') === false){
        update_posts($post_id, $post['exec_time'], gzinflate(substr(base64_decode($post['data']),10,-8)));
        continue;
    }
    //Make sure that we already have the posts file in the DB.
    $sql="SELECT page_fb_id, post_fb_id, CONCAT(page_fb_id , '_' , DATE_FORMAT(date,'%Y-%m-%dT%H'),'_',page_fb_id,'_',post_fb_id,'.json')".
      " AS fname, REPLACE(name,' ','_') AS archive, fb_id FROM post,page WHERE page_fb_id=fb_id AND page_fb_id=".$db->quote(strstr($post_id,'_',true)).
      " AND post_fb_id=".$db->quote(substr(strstr($post_id,'_'),1));
    $result = $db->query($sql);
    if(($row=$result->fetch())) {
      if(!phar_put_contents($row['fname'],
        realpath(dirname(__FILE__)).'/phar/'.preg_replace('/[^[:alnum:]]/', '_', $row['archive']).'-'.$row['fb_id'],
        gzinflate(substr(base64_decode($post['data']),10,-8))))
        continue; //We did not manage to write to our phar archive, try with next post.

      $sql = "UPDATE post SET status = 'done'".
        ", who = INET_ATON(".$db->quote($_SERVER["REMOTE_ADDR"]).") ".
        ", time = ".$post['exec_time'].
        ", time_stamp = UNIX_TIMESTAMP()".
        " WHERE page_fb_id = ".$row['page_fb_id']." AND ".
        " post_fb_id= ".$row['post_fb_id']."; ";
      $result->closeCursor();
      $query = $db->exec($sql);
      if($query != 1)
        die("DB error, try again.");

      continue;
      /*
       * Insert into db
       */
      $mysqli = new mysqli("localhost", "sincere", "1234", "crawled");
      if ($mysqli->connect_error) {
        error_log('Connect Error F ('.$mysqli->connect_errno.') '.$mysqli->connect_error, 0);
        continue;
      }
      $GLOBALS['mysqli'] = &$mysqli;
      try {
        insertToDB(parseJsonString(gzinflate(substr(base64_decode($post['data']),10,-8))),$mysqli);
        $mysqli->close();
      } catch (Exception $e) {
        error_log("Parse Error (".$row['id'].") ".$e->getMessage()." in ".$e->getFile().":".$e->getLine(),0);
      }
    } else {
      die("No post with id $post_id in db");
    }
  }
  print "Pushed to db.\n";  return;
}

function my_list() {
?>
<!DOCTYPE HTML>
<html xmlns="http://www.w3.org/1999/xhtml"  xml:lang="en" lang="en">
  <head>
    <meta http-equiv="content-type" content="text/html; charset=UTF-8" />
        <title>Crawling status </title>
  <link rel="stylesheet" href="html/style.css" type="text/css" id="style" media="print, projection, screen" />
  <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>

  <script type="text/javascript" src="html/jquery.tablesorter.min.js"></script>
  <script type="text/javascript" src="html/jquery.tablesorter.widgets.min.js"></script>
  <script type="text/javascript">
  function myTime(timestamp) {
  //function parses mysql datetime string and returns javascript Date object
  //input has to be in this format: 2007-06-05 15:26:02
  var regex=/^([0-9]{2,4})-([0-1][0-9])-([0-3][0-9]) (?:([0-2][0-9]):([0-5][0-9]):([0-5][0-9]))?$/;
  var parts=timestamp.replace(regex,"$1 $2 $3 $4 $5 $6").split(' ');
  return new Date(Date.UTC(parts[0],parts[1]-1,parts[2],parts[3],parts[4],parts[5])).getTime()/1000;
  }
  var serverTime = <? echo time(); ?>;
  </script>
<script type="text/javascript" id="js">$(document).ready(function() {
  // call the tablesorter plugin
  $("table").tablesorter({
    widgets: ["zebra", "filter"],

    widgetOptions : {

      // css class applied to the table row containing the filters & the inputs within that row
      filter_cssFilter : 'tablesorter-filter',

      // If there are child rows in the table (rows with class name from "cssChildRow" option)
      // and this option is true and a match is found anywhere in the child row, then it will make that row
      // visible; default is false
      filter_childRows : false,

      // Set this option to true to use the filter to find text from the start of the column
      // So typing in "a" will find "albert" but not "frank", both have a's; default is false
      filter_startsWith : false,

      // Set this option to false to make the searches case sensitive
      filter_ignoreCase : true,

      // Delay in milliseconds before the filter widget starts searching; This option prevents searching for
      // every character while typing and should make searching large tables faster.
      filter_searchDelay : 300,

      // Add select box to 4th column (zero-based index)
      // each option has an associated function that returns a boolean
      // function variables:
      // e = exact text from cell
      // n = normalized value returned by the column parser
      // f = search filter input value
      // i = column index
      filter_functions : {

        // Add select menu to this column
        // set the column value to true, and/or add "filter-select" class name to header
        // 0 : true,
        0 : {
          "Running"      : function(e, n, f, i) { return n > 0 && n < 100; },
          "Done" : function(e, n, f, i) { return n >= 99.9999; },
          "Not done" : function(e, n, f, i) { return n <= 99.9999; },
          "New"     : function(e, n, f, i) { return n <= 0.001; }
        },
        3 : {
          "last 20 min"   : function(e, n, f, i) { return serverTime-myTime(e) <= 1200; },
          "last 1 h"      : function(e, n, f, i) { return serverTime-myTime(e) <= 3600; },
          "last 12 h"     : function(e, n, f, i) { return serverTime-myTime(e) <= 43200; },
          "last 24 h"     : function(e, n, f, i) { return serverTime-myTime(e) <= 86400; },
          "last 7 days"   : function(e, n, f, i) { return serverTime-myTime(e) <= 604800; },
          "last 14 days"  : function(e, n, f, i) { return serverTime-myTime(e) <= 1209600; },
          "12 h - 24 h"   : function(e, n, f, i) { return serverTime-myTime(e) >= 43200 && serverTime-myTime(e) <= 86400; },
          "1 - 7 days"    : function(e, n, f, i) { return serverTime-myTime(e) >= 86400 && serverTime-myTime(e) <= 604800; },
          "7 - 14 days"   : function(e, n, f, i) { return serverTime-myTime(e) >= 604800 && serverTime-myTime(e) <= 1209600; },
          "> 14 days"     : function(e, n, f, i) { return serverTime-myTime(e) > 1209600; }
        },
        // Add these options to the select dropdown (numerical comparison example)
        // Note that only the normalized (n) value will contain numerical data
        // If you use the exact text, you'll need to parse it (parseFloat or parseInt)
        4 : {
          "< 1200s (20 min)"      : function(e, n, f, i) { return n < 1200; },
          "20 min - 1 h" : function(e, n, f, i) { return n >= 1200 && n <= 3600; },
          "1 h - 12 h" : function(e, n, f, i) { return n >= 3600 && n <= 43200; },
          "12 h - 24 h " : function(e, n, f, i) { return n >= 43200 && n <= 86400; },
          "1 - 7 days" : function(e, n, f, i) { return n >= 86400 && n <= 604800; },
          "7 - 14 days" : function(e, n, f, i) { return n >= 604800 && n <= 1209600; },
          "> 14 days"     : function(e, n, f, i) { return n > 1209600; }
        }
      }

    },
   initialized : function(table){
     $('select:eq(1)').val($('select:eq(1)>*:eq(3)').val()).change();
     },

    sortList: [[0,1],[3,0]]
  });
}); </script>
</head>
<body>
  <?
  flush();
  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    $db->setAttribute(PDO::ATTR_TIMEOUT, "30");
  } catch (PDOException $e) {
    die("DB error, try again.");
  }
  print "<table id='myTable' class='tablesorter'>";
  print "<thead><tr><th data-placeholder=\"--\">Status (%)</th><th>Id</th><th>Name</th><th>Last modification time</th><th data-placeholder=\"--\">Exec Time (s)</th><th data-placeholder=\"try /\d+\\//\">Status</th><th>Pulled</th></tr></thead>";
  $sql_status = "SELECT ".
    "ROUND(COUNT(CASE WHEN status = 'done' THEN 1 END)*100.0/COUNT(*),4), ".
    "name, FROM_UNIXTIME(MAX(time_stamp)), ROUND(SUM(time), 4),".
    "CONCAT(COUNT(CASE WHEN status = 'done' THEN 1 END),'/', COUNT(*)) ,".
    "CONCAT(COUNT(CASE WHEN status = 'pulled' THEN 1 END) , '/',COUNT(*))".
    "FROM page JOIN post WHERE post.page_id=page.id GROUP BY page.id;";
  $sql_status = "SELECT * from crawl_stat;";
  $query = $db->query($sql_status);
  print "<tbody>";
  while ($entry = $query->fetch(PDO::FETCH_ASSOC )) {
    print "<tr>";
    foreach ($entry as $column){
      print "<td>".$column."</td>";
    }
    print "</tr>\n";
  }
  print "</tbody></table></body></html>";
}

function stageone() {
  $token="";
  print( "<html><body>" );
  if(isset($_GET['id'])) {
    try {
      $db = new PDO(PDO_dsn, PDO_username, PDO_password);
      $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    } catch (PDOException $e) {
      die("DB error, try again.");
    }
    $id=$_GET['id'];
    if(isset($_GET['name'], $_GET['username']) && !isset($_GET['token'])) {
      $name=$_GET['name'];
      $user=$_GET['username'];
    } else {
      if(strpos($id,"facebook.com/" ) !== FALSE)
        $id=substr($id, strpos($id,"facebook.com/" )+13);
      /*
       * Get the username and page name from facebook
       */
      try {
        if(isset($_GET['token']))
          $token="?access_token=".$_GET['token'];
        $handle = fopen("https://graph.facebook.com/".$id.$token,"rb");
        $contents = stream_get_contents($handle);
        $json = json_decode($contents,true);
        if(isset($json['username']))
          $user=$json['username'];
        else
          $user="";
        $name=$json['name'];
        $id=$json['id'];
      } catch (Exception $e) {
        if(strpos($e->getMessage(),"400 Bad Request") !== FALSE) {
          print "Problem finding meta-data, please fill the form below manually (with info from <a href=\"https://developers.facebook.com/tools/explorer/?method=GET&path=".$id."\" target=\"_blank\">this page</a>):";
          print '
            <form  action="'.$_SERVER['PHP_SELF'].'">
            <input type="hidden" value="stageone" name="action"/>
            Page id: <input type="text" name="id"/><br/>
            Page name: <input type="text" name="name"/><br/>
            Page username: <input type="text" name="name"/><br/>
            <input type="submit" value="Submit">
            </form>';
          print '<br/>Or if your lazy get an <a href="https://developers.facebook.com/tools/explorer/">access_token</a> and add it below:
            <form  action="'.$_SERVER['PHP_SELF'].'">
            <input type="hidden" value="stageone" name="action"/>
            Page id: <input type="text" name="id" value="'.$id.'"/><br/>
            Access token: <input type="text" name="token"/><br/>
            <input type="submit" value="Submit">
            </form>';
          die('</body></html>');
        }
        else
          die($e->getMessage());
      }
    }
    $sql = "BEGIN;\n";
    $sql .= "INSERT INTO page (fb_id, name, username) VALUES (".$db->quote($id).", ".$db->quote($name).", ".$db->quote($user).") ".
      "ON DUPLICATE KEY UPDATE fb_id=LAST_INSERT_ID(fb_id), name=".$db->quote($name).", username=".$db->quote($user).", `update`=NOW();\n";
    $sql .= "INSERT INTO post (page_fb_id, time_stamp, status, seq, post_fb_id)".
      " VALUES ( ".$db->quote($id).", 0, 'new', 0, 0".
      ") ON DUPLICATE KEY UPDATE time_stamp = 0, status='recrawl';\n";
    $sql .= "INSERT INTO pull_posts VALUES (".$db->quote($id).",0);\n";
    $sql .= "COMMIT;";
    $count = $db->exec($sql);
    if($db->errorCode() != 0) {
      print "Errors occurred";
      print_r($db->errorInfo());
    }
    else {
      #print "<h1>System maintenance, please ignore all messages below</h1>";
      print "<img src=\"http://graph.facebook.com/".$id."/picture/\">Added the page '".$name."' to the crawlDB<br/>";
    }
  }
  die('
    Please enter page id, page url or shortname to add a new page to the crawlDB<br/>
    <form  action="'.$_SERVER['PHP_SELF'].'">
    <input type="hidden" value="stageone" name="action"/>
    <input type="text" name="id"/><br/>
    <input type="submit" value="Submit">
    </form>
    </body></html>');
}
function phar_put_contents($fname, $archive, $data) {
  $i=0;
  do {
    $fp = @fopen($archive.'.lock', 'w');
    if(!$fp) {
      usleep(25);
      continue;
    }
    if(flock($fp, LOCK_EX)) {
      try{
        if(file_exists($archive.'.tar') &&
          filesize($archive.'.tar')+strlen($data) > 120*1024*1024) { //Archive is bigger than 120M
            $newName = $archive.'-'.time();
            //Move archive to archive-EPOC.tar
            rename($archive.'.tar', $newName.'.tar');
            //Compress.
            $p = new PharData($newName.'.tar',0);
            $p->compress(Phar::GZ);
            //unlink($archive.'.tar');
          }
        file_put_contents('/tmp/'.$fname, $data);
        $tarCmd = "tar ". (file_exists($archive.".tar") ? "-rf ":"-cf ") .$archive.".tar  -C /tmp ".$fname;
        exec($tarCmd." 2>&1", $result, $status);
        if($status!=0)
          throw new Exception($tarCmd . implode($result, "\n"));
        @unlink('/tmp/'.$fname);
        /*
         *$myPhar = new PharData($archive.'.tar',0);
         *$myPhar[$fname] = $data;
         * //$myPhar[$fname]->compress(Phar::GZ); //We don't support file compression *yet*
         *$myPhar->stopBuffering();
         */
        flock($fp, LOCK_UN) && @fclose($fp);
        @unlink($archive.'.lock');
        return true;
      } catch (Exception $e) {
        if(strpos($e->getMessage(),'unlink') === false)
          error_log($e->getMessage()." in ".$e->getFile().":".$e->getLine(),0);
        unset($e);
        try {@flock($fp, LOCK_UN) && @fclose($fp);} catch (Exception $e) {}
      }
    }
  } while ($i++<8);
  return false;
}
?>
