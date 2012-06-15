<?php
/*
 * Assumes a sqlite3 database called database.db containing:
 * CREATE TABLE page(
 * id integer primary key autoincrement,
 * name VARCHAR(255));
 * CREATE TABLE post(id integer primary key autoincrement,post_id varchar(48),page_id integer,seq int,date varchar(13),data blob, status varchar(40), time_stamp int, who carchar(60), time int,foreign key (page_id) references page(id));
 */


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
  $dbh = new PDO("sqlite:database.db");
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
    $sql = "INSERT INTO page (name) VALUES (".$file.")";
    $dbh->query($sql);
    $insertId = $dbh->lastInsertId();
    $postsCount = 0;
    $sql = 'INSERT INTO post (page_id, time_stamp, status, seq, date, post_id)'.
      ' VALUES ( '.$insertId.',strftime(\'%s\', \'now\'), \'new\', ?, ?, ?)';
    $sth = $dbh->prepare($sql);
    while(!feof($postsFilePtr)){
      fscanf($postsFilePtr, "%s\n", $currentTime);
      fscanf($postsFilePtr, "%s\n", $currentPost);
      $postsCount++;
      $sth->execute(array($postsCount,$currentTime,$currentPost));
    }
  }
}

function pull_post($count=3) {
  if(isset($_GET['count']))
    $count = intval($_GET['count']);
  if($count < 1)
    $count = 3;
  $db = new PDO("sqlite:database.db");
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  $id = array();
  $sql = "SELECT id, post_id FROM post WHERE ".
    "((status='pulled' AND strftime('%s','now')-time_stamp > 100) ".
    "OR status='new') LIMIT ".$db->quote($count);
  $result = $db->query($sql);
//  print $sql;
  $sql ="";
  while(($row=$result->fetch())) {
      $sql .= "UPDATE post SET status = 'pulled', ".
        "time_stamp = strftime('%s', 'now'), ".
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
  file_put_contents('logFile.log', serialize($_POST));
  $db = new PDO("sqlite:database.db");
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  foreach ($_POST as $post_id => $post){
    if(!isset($post['data'], $post['exec_time']))
      die("Wrong parameters");
    //Make sure that we already have the posts file in the DB.
    $result = $db->query("SELECT id FROM post WHERE post_id = ".$db->quote($post_id));
    if(($row=$result->fetch())) {
      $sql = "UPDATE post SET status = 'done'".
        ", data = ".$db->quote($post['data']).
        ", time = ".$db->quote($post['exec_time']).
        ", time_stamp = strftime('%s', 'now')".
        ", who = ".$db->quote($_SERVER["REMOTE_ADDR"].":".$_SERVER["REMOTE_PORT"]).
        " WHERE post_id = ".$db->quote($post_id).";";
    } else {
      die("No post with id $post_id in db");
    }
    $result->closeCursor();
    $query = $db->exec($sql);
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
    // sort on the first column and third column, order asc
    sortList: [[2,1],[0,0]]
  });
}); </script>

  <?
  $db = new PDO("sqlite:database.db");
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  print "<table id='myTable' class='tablesorter'>";
  print "<thead><tr><th>Name</th><th>Exec Time (s)</th><th>Status</th><th>Pulled</th><th> Status (%)</th></tr></thead>";
  $sql_status = "SELECT name, SUM(time),".
    "COUNT(CASE WHEN status = 'done' THEN 1 END) || '/'|| COUNT(*),".
    "COUNT(CASE WHEN status = 'pulled' THEN 1 END) || '/'|| COUNT(*),".
    "COUNT(CASE WHEN status = 'done' THEN 1 END)*100.0/COUNT(*) ".
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
