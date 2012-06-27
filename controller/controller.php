<?php
/*
 * Assumes a (my)sql database like this:
--
-- Table structure for table `page`
--

DROP TABLE IF EXISTS `page`;
CREATE TABLE `page` (
  `id` int(11) NOT NULL auto_increment,
  `name` varchar(255) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=554 DEFAULT CHARSET=latin1;

--
-- Table structure for table `post`
--

DROP TABLE IF EXISTS `post`;
CREATE TABLE `post` (
  `id` int(32) NOT NULL default '0',
  `post_id` varchar(48) default NULL,
  `page_id` int(11) default NULL,
  `seq` int(11) default NULL,
  `date` varchar(13) default NULL,
  `data` longblob,
  `status` varchar(40) default NULL,
  `time_stamp` int(11) default NULL,
  `who` varchar(60) default NULL,
  `time` float default NULL,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `post_id` (`post_id`),
  KEY `page_id` (`page_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2427809 DEFAULT CHARSET=latin1;
 *
 * Don't forget to set the default values below.
 */
define(_PDO_dsn_,'Data Source Name');
define(_PDO_username_,'username');
define(_PDO_password_, 'password');


error_reporting(E_ALL);
ini_set("display_errors", 1);
$action='';
if(isset($_GET['action']))
  $action = $_GET['action'];


switch ($action) {
case 'add':
  checkout();
  my_list();
  break;
case 'pull':
  pull_post();
  break;
case 'push':
  my_push();
  break;
default:
  my_list();
}

#Checkout file, add to db.
function checkout() {
  set_time_limit(120);
  $count =0;
  $dbh = new PDO(PDO_dsn, PDO_username, PDO_password);
  $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  //Read file for now.
  $files = glob('posts-files/posts.txt.*');
  //$postsFName = $file;
  foreach ($files as $postsFName){
    if (!(file_exists($postsFName) && $postsFilePtr = fopen($postsFName, "r")))
      return "error opening the file";
    $file = $dbh->quote(substr(strrchr($postsFName,"."),1));
    //Make sure the file does not exist.
    $result = $dbh->query("SELECT * FROM page WHERE name=$file");
    if(($row=$result->fetch())) {
      $result->closeCursor();
      continue;
    }
    $result->closeCursor();

    //Create page row.
    $count++;
    $sql = "INSERT INTO page (name) VALUES (".$file.")";
    $dbh->query($sql);
    $insertId = $dbh->lastInsertId();
    $postsCount = 0;
    $sql = 'INSERT IGNORE INTO post (page_id, time_stamp, status, seq, date, post_id)'.
      ' VALUES ( '.$insertId.', UNIX_TIMESTAMP(), \'new\', ?, ?, ?)';
    $sth = $dbh->prepare($sql);
    while(!feof($postsFilePtr)){
      fscanf($postsFilePtr, "%s\n", $currentTime);
      fscanf($postsFilePtr, "%s\n", $currentPost);
      $postsCount++;
      $sth->execute(array($postsCount,$currentTime,$currentPost));
    }
  }
  print "$count rows added.\n<br/>";
}

function pull_post($count=3) {
  if(isset($_GET['count']))
    $count = intval($_GET['count']);
  if($count < 1)
    $count = 3;
  $db = new PDO(PDO_dsn, PDO_username, PDO_password);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  $db->setAttribute(PDO::ATTR_TIMEOUT, "120");
  $id = array();
  $sql = "SELECT id, post_id FROM post WHERE ".
    "((status='pulled' AND UNIX_TIMESTAMP()-time_stamp > 4200) ".
    "OR status='new') LIMIT ".intval($count);
  $result = $db->query($sql);
  $sql ="";
  while(($row=$result->fetch())) {
      $sql .= "UPDATE post SET status = 'pulled', ".
        "time_stamp = UNIX_TIMESTAMP(), ".
        "who = ".$db->quote($_SERVER["REMOTE_ADDR"].":".$_SERVER["REMOTE_PORT"]).
        " WHERE id = '".$row['id']."'; ";
      $id[]=$row['post_id'];
  }
  $result->closeCursor();
  if(count($id) == 0)
    die("0&No new posts");
  $result = $db->exec($sql);
  if($result == 0) {
    header('HTTP/1.1 501 Not Implemented');
    die("Error updating DB");
  }
  print implode('&',$id);

}

function my_push() {
#  file_put_contents('logFile.log', serialize($_POST));
  $db = new PDO(PDO_dsn, PDO_username, PDO_password);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  foreach ($_POST as $post_id => $post){
    if(!isset($post['data'], $post['exec_time']))
      die("Wrong parameters");
    //Make sure that we already have the posts file in the DB.
    $sql="SELECT post.id, CONCAT(page.name , '_' , SUBSTR(CONCAT('00000000',seq),-8,8) , '_' , SUBSTR(date,1,13),'_', post_id ,'.json')  AS fname FROM post JOIN page ON post.page_id=page.id WHERE post_id=".$db->quote($post_id);
    $result = $db->query($sql);
    if(($row=$result->fetch())) {
      $sql = "UPDATE post SET status = 'done'".
        ", data = ".$db->quote($post['data']).
        ", time = ".$db->quote($post['exec_time']).
        ", time_stamp = UNIX_TIMESTAMP()".
        ", who = ".$db->quote($_SERVER["REMOTE_ADDR"].":".$_SERVER["REMOTE_PORT"]).
        " WHERE id = '".$row['id']."'; ";
      file_put_contents('done/raw/'.$row['fname'], gzinflate(substr(base64_decode($post['data']),10,-8)));
    } else {
      die("No post with id $post_id in db");
    }
    $result->closeCursor();
    $query = $db->exec($sql);
    if($query != 1)
      die("DB error, try again.");
  }
  print "Pushed to db.\n";  return;
}

function my_list() {
?>
  <link rel="stylesheet" href="html/style.css" type="text/css" id="" media="print, projection, screen" />
  <script type="text/javascript" src="html/jquery-latest.js"></script>

  <script type="text/javascript" src="html/__jquery.tablesorter.js"></script>
<script type="text/javascript" id="js">$(document).ready(function() {
  // call the tablesorter plugin
  $("table").tablesorter({
    sortList: [[0,1],[2,0]]
  });
}); </script>

  <?
  $db = new PDO(PDO_dsn, PDO_username, PDO_password);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  $db->setAttribute(PDO::ATTR_TIMEOUT, "120");
  print "<table id='myTable' class='tablesorter'>";
  print "<thead><tr><th> Status (%)</th><th>Name</th><th>Time</th><th>Exec Time (s)</th><th>Status</th><th>Pulled</th></tr></thead>";
  $sql_status = "SELECT ".
    "ROUND(COUNT(CASE WHEN status = 'done' THEN 1 END)*100.0/COUNT(*),4), ".
    "name, FROM_UNIXTIME(time_stamp), ROUND(SUM(time), 4),".
    "CONCAT(COUNT(CASE WHEN status = 'done' THEN 1 END),'/', COUNT(*)) ,".
    "CONCAT(COUNT(CASE WHEN status = 'pulled' THEN 1 END) , '/',COUNT(*))".
    "FROM page JOIN post WHERE post.page_id=page.id GROUP BY page.id;";
  $query = $db->query($sql_status);
  print "<tbody>";
  while ($entry = $query->fetch(PDO::FETCH_ASSOC )) {
    print "<tr>";
    foreach ($entry as $column){
      print "<td>".$column."</td>";
    }
    print "</tr>\n";
  }
  print "</tbody></table>";
}
?>
