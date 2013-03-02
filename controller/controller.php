<?php
define('VERSION', 1.0);
#$GLOBALS['maintenance']=TRUE;
/*
 * Don't forget to set the default values below.
 */
define('PDO_dsn','mysql:dbname=crawling;unix_socket=/tmp/mysql.sock');
define('PDO_username','root');
define('PDO_password', '');

include_once('parser.php');

ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
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
  $sql = "INSERT INTO post (time_stamp, status, seq, date, page_fb_id,post_fb_id)".
      " VALUES ( UNIX_TIMESTAMP(), 'new', :seq, FROM_UNIXTIME(:date), :page_fb_id, :post_fb_id)".
      " ON DUPLICATE KEY UPDATE time_stamp = UNIX_TIMESTAMP(), status=IF(`status`='done','updated','new'), date=FROM_UNIXTIME(:date), seq=:seq;";
  $sth = $db->prepare($sql);
  foreach($data['feed'] as $seq => $post)
    $sth->execute([ 'seq'=>$seq, 'date'=>$post[1], 'page_fb_id'=>$id, 'post_fb_id'=>$post[0] ]);

  if(!$data['done']) { //For some reason did the agent not finish, continue where it left of.
    $sql="INSERT IGNORE INTO pull_posts VALUES (".$db->quote($id).",0);";
    $sql.="UPDATE post SET date=NULL, seq=".$db->quote($data['seq']).
      "WHERE page_fb_id=". $db->quote($id) ." AND post_fb_id=0;";
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
            $since=$db->query("SELECT MAX(UNIX_TIMESTAMP(date)) FROM post WHERE page_fb_id=".$row['page_fb_id'])->fetchAll()[0][0];
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
    $result->closeCursor();
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
  $rawData = file_get_contents('php://input','r');
  $postedJson = json_decode($rawData,true);
  if($postedJson['version'] < VERSION)
    die("Old version, please upgrade");

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
    if($post['status'] != "done") { //For some reason the agent did not complete insert to pull_posts.
      $sql="INSERT INTO pull_posts VALUES (".$db->quote(strstr($post['id'],'_',true)).",".
        (($post['type']== "page") ? "0" :  $db->quote(substr(strstr($post['id'],'_'),1))).")";
      $result = $db->exec($sql);
      continue;
    }
//    print "working with ". $post['id'] .PHP_EOL; flush();
    if($post['type'] == "page") { //Is it a stage one crawl.
      update_page($post['id'], $post['exec_time'], $post['data']);
      continue;
    }
    //Make sure that we already have the posts file in the DB.
    $sql="SELECT page_fb_id, post_fb_id, CONCAT(page_fb_id , '_' , SUBSTR(CONCAT('00000000',seq),-8,8) , '_' , DATE_FORMAT(date,'%Y-%m-%dT%H'),'-',page_fb_id,'_',post_fb_id,'.json')".
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
    $sql .= "INSERT INTO post (page_fb_id, time_stamp, status, seq, post_fb_id, date)".
      " VALUES ( ".$db->quote($id).", 0, 'new', 0, 0, 0".
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
  while (file_exists($archive.'.lock')) {
      usleep(125);
      if($i++>16)
        return false;
  }
  for($i=0;$i<8;$i++) {
    try{
      touch($archive.'.lock');
      if(file_exists($archive.'.tar') &&
        filesize($archive.'.tar')+strlen($data) > 120*1024*1024) { //Archive is bigger than 120M
          //Compress.
          $p = new PharData($archive.'.tar',0);
          $p->compress(Phar::GZ);
          unlink($archive.'.tar');
          //Move archive to archive-EPOC.tar
          rename($archive.'.tar.gz', $archive.'-'.time().'.tar.gz');
        }
      $myPhar = new PharData($archive.'.tar',0);
      $myPhar[$fname] = $data;
      //$myPhar[$fname]->compress(Phar::GZ); //We don't support file compression *yet*
      $myPhar->stopBuffering();
      unlink($archive.'.lock');
      return true;
    } catch (Exception $e) {
      error_log($e->getMessage()." in ".$e->getFile().":".$e->getLine(),0);
      unset($e);
      usleep(100);
    }
  }
  return false;
}
?>
