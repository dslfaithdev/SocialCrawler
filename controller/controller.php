<?php
define('VERSION', 2.2);
require_once('config.php');
include_once('parser.php');

if(isset($_GET['action']))
  $action = $_GET['action'];
else
  $action='';

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
case 'page_stat':
  my_list();
  break;
default:
  crawl_stat();
}

function crawl_stat() {
?>
<!DOCTYPE HTML>
<html xmlns="http://www.w3.org/1999/xhtml"  xml:lang="en" lang="en">
<head>
  <meta http-equiv="content-type" content="text/html; charset=UTF-8" />
  <title>Crawling status </title>
  <link rel="stylesheet" href="html/style.css" type="text/css" id="style" media="print, projection, screen" />
</head>
<body>
<?
  if(isset($_GET['total']))
    print '<a href="'.$_SERVER["SCRIPT_NAME"].'">Current post status</a>&nbsp;';
  else
    print '<a href="'.$_SERVER["SCRIPT_NAME"].'?total">Total post status (slow)</a>&nbsp;';
?>
  <a href="<?$_SERVER["SCRIPT_NAME"]?>?action=page_stat">Status of all pages (slower)</a>&nbsp;
  <a href="<?$_SERVER["SCRIPT_NAME"]?>?action=stageone">Insert new page</a>&nbsp;
  <br/>
  <?
  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    $db->setAttribute(PDO::ATTR_TIMEOUT, "0");
    $db->query("SET profiling = 1;");
  } catch (PDOException $e) {
    die("DB error, try again.");
  }
  $timeSpan = [ '30 min' => 1800, 'hour' => 3600, '6 hours' => 3600*6,
    '12 hours' => 3600*12, '24 hours' => 3600*24, '48 hours' => 3600*48 ];
  foreach ( $timeSpan as $time => $sec)
  {
    print "<div style=\" display: inline-block; margin: 10px; \">";
    print "Status over the last ". $time . PHP_EOL;
    print "<table class='tablesorter' style='width: 0 !important; margin: auto; margin-right: auto;'>\n";
    print "<tr><th>Status</th><th>Total</th><th>Per sec</th></tr>\n";
    $query=$db->query("SELECT status, COUNT(*), COUNT(*)/$sec FROM post WHERE time_stamp>UNIX_TIMESTAMP()-".$sec." GROUP BY status;");
    while ($entry = $query->fetch(PDO::FETCH_NUM ))
      print "<tr><td>".$entry[0]."</td><td>".$entry[1]."</td><td>".$entry[2]."</td></tr>\n";
    $exec_time_row = $db->query("SELECT query_id, SUM(duration) FROM information_schema.profiling GROUP BY query_id ORDER BY query_id DESC LIMIT 1;")
      ->fetch(PDO::FETCH_NUM);
    print "</table>Exec time: ".$exec_time_row[1]."</div>";
    ob_flush();flush();
  }
  if(isset($_GET['total'])) {
    print "<br/>Total status";
    print "<table class='tablesorter' style='width: 0 !important;'>\n";
    print "<tr><th>Status</th><th>Total</th></tr>\n";
    $query=$db->query("SELECT status, FORMAT(COUNT(*),0) AS count FROM post GROUP BY status");
    while ($entry = $query->fetch(PDO::FETCH_NUM ))
      print "<tr><td>".$entry[0]."</td><td>".$entry[1]."</td></tr>\n";
    $exec_time_row = $db->query("SELECT query_id, SUM(duration) FROM information_schema.profiling GROUP BY query_id ORDER BY query_id DESC LIMIT 1;")
      ->fetch(PDO::FETCH_NUM);
    print "</table>Exec time: ".$exec_time_row[1];
  }
?>
<br/>
<span style="text-decoration: underline">Legend</span><br/><ul>
<li>  done = Crawled completely</li>
<li>  new = Added to DB without processing</li>
<li>  pulled = Checked out by an agent</li>
<li>  updated = Post have been updated since last crawl</li>
<li>  removed = Post does no longer exist on Facebook</li>
</ul>
</body></html>
<?
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
function update_page($id, $exec_time, $data){
  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  } catch (PDOException $e) {
    error_log($e->getMessage()." in ".$e->getFile().":".$e->getLine(),0);
    return false;
  }
  $db->query("START TRANSACTION");

  //Verify that the page exist in the page table.
  $sql = "INSERT INTO page (fb_id) VALUES (".$db->quote($id).") ON DUPLICATE KEY UPDATE `update`=NOW(); ";
  $sql .= "INSERT INTO post (page_fb_id, post_fb_id, status, who, time_stamp, time) VALUES (".
    $db->quote($id).",0,'done',".
    "INET_ATON(".$db->quote($_SERVER["REMOTE_ADDR"])."), UNIX_TIMESTAMP(), ".  $db->quote($exec_time).
    ") ON DUPLICATE KEY UPDATE status = 'done'".
    ", who = INET_ATON(".$db->quote($_SERVER["REMOTE_ADDR"]).") ".
    ", time_stamp = UNIX_TIMESTAMP()".
    ", date=FROM_UNIXTIME(".$db->quote($data['until']).") ".
    ", seq=".$db->quote($data['seq']).
    ", time =".$db->quote($exec_time).";";
  //Set all "old" entries to seq -1 and status old.
  $sql .= "UPDATE post SET seq=-1 WHERE page_fb_id=".$db->quote($id)." AND seq!=0;";
  $sth = $db->exec($sql);
  $sql = "INSERT INTO post (time_stamp, status, seq, date, page_fb_id,post_fb_id, from_user)".
      " VALUES ( UNIX_TIMESTAMP(), 'new', :seq, FROM_UNIXTIME(:date), :page_fb_id, :post_fb_id, :from)".
      " ON DUPLICATE KEY UPDATE".
      " time_stamp = IF ((`status`='done' OR `status`='updated') AND `date`<>FROM_UNIXTIME(:date), UNIX_TIMESTAMP(), `time_stamp`),".
      " status = IF ((`status`='done' OR `status`='updated') AND `date`<>FROM_UNIXTIME(:date), 'updated', `status`),".
      " date=FROM_UNIXTIME(:date), seq=:seq, from_user=:from;";
  $sth = $db->prepare($sql);
  foreach($data['feed'] as $seq => $post)
    $sth->execute([ 'seq'=>$seq, 'date'=>$post[1], 'page_fb_id'=>$id, 'post_fb_id'=>$post[0], 'from'=>$post[2] ]);

  if(!$data['done']) { //For some reason did the agent not finish, continue where it left of.
    $sql="INSERT IGNORE INTO pull_posts VALUES (".$db->quote($id).",0);";
    $db->exec($sql);
  }
  $db->query("COMMIT");
  return $seq;
}

function pull_post($count=3) {
  $ret=array('version' => VERSION,
    'status' => 'ok',
    'posts' => array());
  header('Content-type: application/json');
  if(isset($GLOBALS['maintenance']))
    die(json_encode(array('status'=>'maintenance')+$ret));
  if(isset($_GET['count']))
    $count = intval($_GET['count']);
  if($count < 1)
    $count = 3;
  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    $db->setAttribute(PDO::ATTR_TIMEOUT, "120");
  } catch (PDOException $e) {
    die(json_encode(array('status'=>'db error, '. $e->getMessage())+$ret));
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
    $db->exec("set session binlog_format = 'MIXED'; START TRANSACTION");
    $sql = "SELECT page_fb_id, post_fb_id FROM pull_posts LIMIT ".intval($count);
    $result = $db->query($sql);
    if($result->rowCount() == 0)
      die(json_encode(array('status'=>'no new posts')+$ret));
    $rows=$result->fetchAll();
    $result->closeCursor();
    $posts = array();
    $sql ="";
    foreach ($rows as $row){
      $sql .= "UPDATE post SET status = 'pulled', ".
        "time_stamp = UNIX_TIMESTAMP(), ".
        "who = INET_ATON(".$db->quote($_SERVER["REMOTE_ADDR"]). ") ".
        " WHERE page_fb_id = '".$row['page_fb_id']."' AND post_fb_id = '".$row['post_fb_id']."'; ".
        "DELETE FROM pull_posts WHERE post_fb_id =  ".$row['post_fb_id']." AND page_fb_id= ".$row['page_fb_id'].";";
      if($row['post_fb_id'] == 0) { //It's a page
        $s="SELECT seq, UNIX_TIMESTAMP(date) AS until FROM post ".
        " WHERE page_fb_id=".$row['page_fb_id']." AND post_fb_id=0";//.$row['post_fb_id'];
        $result = $db->query($s);
        $page=$result->fetchAll();
        foreach($page as $p) {
          if(is_null($p['until'])) {
            $since=$db->query("SELECT MIN(UNIX_TIMESTAMP(date)) FROM post WHERE page_fb_id=".$row['page_fb_id'])->fetchAll()[0][0];
            $posts[] = [ 'id' => $row['page_fb_id'], 'type' => 'page',
              'data' => [ 'seq'=>$p['seq'], 'since'=>$since ] ];
          }
          else
            $posts[] = [ 'id' => $row['page_fb_id'], 'type' => 'page',
            'data' => [ 'seq'=>$p['seq'], 'until'=>$p['until'] ] ];
        }
      } else {
        $posts[] = array('id' => $row['page_fb_id'].'_'.$row['post_fb_id'], 'type' => 'post');
      }
    }
    try {
      $result = $db->exec($sql);
      $db->query("COMMIT");
    } catch (Exception $e) {
      die(json_encode(array('status'=>'db error, '. $e->getMessage())+$ret));
    }
    if ($result !== 0 && count($posts) !== 0)
      break;
  }
  if(count($posts) == 0)
    die(json_encode(array('status'=>'no new posts')+$ret));
  if($result == 0)
    die(json_encode(array('status'=>'db error, '. $e->getMessage())+$ret));
    //die(json_encode(array('status'=>'db error')+$ret));
  #print_r($ret);

  die(json_encode(array('posts'=>$posts)+$ret));
}

function my_push() {
  $rawData = gzinflate(substr(file_get_contents('php://input'),10,-8));
  $postedJson = json_decode($rawData,true);

  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  } catch (PDOException $e) {
    die("DB error, try again.");
  }
  foreach ($postedJson['d'] as $post) {
    if(!isset($post['data'], $post['exec_time'], $post['id'], $post['status'], $post['type']))
      die("Wrong parameters");
    if(!is_numeric($post['exec_time']))
      die("Wrong parameters");
//    print "working with ". $post['id'] .PHP_EOL; flush();
    if($post['type'] == "page") { //Is it a stage one crawl.
      update_page($post['id'], $post['exec_time'], $post['data']);
      continue;
    }
    if($post['status'] != "done") { //For some reason the agent did not complete, switch trough error messages and try to react.
      if(isset($post['error_msg'])) {
      if(strpos($post['error_msg'], "(#21)") !== false) { //We got a error 21 "Page ID <old> was migrated to page ID <new>"
        continue;
      }
      if(strpos($post['error_msg'], "Unsupported get request") !== false) { //We got a error 21 "Page ID <old> was migrated to page ID <new>"
        $sql = "UPDATE post SET status = 'removed'".
          ", who = INET_ATON(".$db->quote($_SERVER["REMOTE_ADDR"]).") ".
          ", time = ".$post['exec_time'].
          ", time_stamp = UNIX_TIMESTAMP()".
          " WHERE page_fb_id = ".$db->quote(strstr($post['id'],'_',true))." AND ".
          " post_fb_id= ".$db->quote(substr(strstr($post['id'],'_'),1)) ."; ";
        //$result->closeCursor();
        $query = $db->exec($sql);
        //if($query != 1)
          //die("DB error, try again.");
          continue;
      }
      }
      $sql="INSERT IGNORE INTO pull_posts VALUES (".$db->quote(strstr($post['id'],'_',true)).",".
        (($post['type']== "page") ? "0" :  $db->quote(substr(strstr($post['id'],'_'),1))).");";
      $result = $db->exec($sql);
      continue;
    }
    //Make sure that we already have the posts file in the DB.
   $sql="SELECT page_fb_id, post_fb_id, CONCAT(page_fb_id , '_' , DATE_FORMAT(date,'%Y-%m-%dT%H'),'_',page_fb_id,'_',post_fb_id,'.json')".
      " AS fname, REPLACE(name,' ','_') AS archive, fb_id FROM post,page WHERE page_fb_id=fb_id AND page_fb_id=".$db->quote(strstr($post['id'],'_',true)).
      " AND post_fb_id=".$db->quote(substr(strstr($post['id'],'_'),1));
    $result = $db->query($sql);
    if(($row=$result->fetch())) {
      if(!phar_put_contents($row['fname'],
        realpath(dirname(__FILE__)).'/phar/'.preg_replace('/[^[:alnum:]]/', '_', $row['archive']).'-'.$row['fb_id'],
        $post['data']))
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
        insertToDB(parseJsonString($post['data'],$mysqli));
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
  define("MY_LIST",true);
  require('html/list.html.php');
  flush();
  try {
    $db = new PDO(PDO_dsn, PDO_username, PDO_password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
    $db->setAttribute(PDO::ATTR_TIMEOUT, "0");
    $db->query("SET profiling = 1;");
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
    flush(); ob_flush();
  }
  $exec_time_row = $db->query("SELECT query_id, SUM(duration) FROM information_schema.profiling GROUP BY query_id ORDER BY query_id DESC LIMIT 1;")
    ->fetch(PDO::FETCH_NUM);
  print "</tbody></table>Exec time: ".$exec_time_row[1]."</body></html>";
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
    $sql .= "INSERT INTO post (page_fb_id, time_stamp, status, seq, post_fb_id, date)".
      " VALUES ( ".$db->quote($id).", 0, 'new', 0, 0, 0".
      ") ON DUPLICATE KEY UPDATE time_stamp = 0, status='recrawl';\n";
    $sql .= "INSERT IGNORE INTO pull_posts VALUES (".$db->quote($id).",0);\n";
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
  $fp = FALSE;
  do {
    try{
      $fp = fopen($archive.'.lock', 'w');
      if(!$fp) {
        usleep(25);
        continue;
      }
    }
    catch (Exception $er) {
      //  error_log($i . "::". $er->getMessage()." $i in ".$er->getFile().":".$er->getLine(),0);
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
            rename($newName.'.tar.gz', dirname($newName.'.tar').'/gz/'.basename($newName).'.tar.gz');
            rename($newName.'.tar', dirname($newName.'.tar').'/tar/'.basename($newName).'.tar');
          }
        file_put_contents('/tmp/'.$fname, $data);
        $tarCmd = "tar ". (file_exists($archive.".tar") ? "-rf ":"-cf ") .$archive.".tar  -C /tmp ".$fname;
        exec($tarCmd." 2>&1", $result, $status);
        if($status!=0)
          throw new Exception($result[0]);
        @unlink('/tmp/'.$fname);
        @flock($fp, LOCK_UN) && @fclose($fp);
        @unlink($archive.'.lock');
        return true;
      } catch (Exception $e) {
        if(strpos($e->getMessage(),'unlink') === false)
          error_log($e->getMessage()." in ".$e->getFile().":".$e->getLine(),0);
        unset($e);
        @flock($fp, LOCK_UN) && @fclose($fp);
        @unlink($archive.'.lock');
      }
    }
  } while ($i++<8);
  error_log($er->getMessage()." in ".$er->getFile().":".$er->getLine(),0);
  @flock($fp, LOCK_UN) && @fclose($fp);
  @unlink($archive.'.lock');
  return false;
}

?>
