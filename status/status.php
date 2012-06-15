<?
error_reporting(E_ALL);
ini_set("display_errors", 1);
/*
 * Supported functions:
 * 	pull (http://dsl.ucdavis.edu/~wu/posts_TBC/status.php?action=pull)
 *    Returns a posts.txt file (the first available).
 * 	push (http://dsl.ucdavis.edu/~wu/posts_TBC/status.php?action=push&file=posts.txt.Docomo_Pacific&time=1810.91&url=http://garm.comlab.bth.se/crawler.new/Docomo_Pacific.tgz)
 *    Insert information about a specific posts file in the db.
 * 	list (default http://dsl.ucdavis.edu/~wu/posts_TBC/status.php)
 *    List the current posts file in DB.
 *
 *  Expects a sqlite3 db called status.db (CREATE TABLE status(file varchar(120) PRIMARY KEY, status varchar(10), time_stamp int, url varchar(255), who varchar(255), time int);)
 */


/*
 * Default values
 */

$database = 'sqlite:./status.db';

if (!$db = new PDO($database)) {
  die('error opening Database');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

updateDB();

$action='';
if(isset($_GET['action']))
  $action = $_GET['action'];


switch ($action) {
case 'pull':
  my_pull();
  break;
case 'push':
  my_push();
  break;
default:
  my_list();
}

//Make sure the DB is updated with all posts entries.
function updateDB() {
  global $db;
  $files = glob('posts.txt.*');
  foreach($files as $file) {
    $file = $db->quote($file);
    $result = $db->query("SELECT * FROM status WHERE file=$file");
    if(!($row=$result->fetch())) {
      //Add the current file to the db.
      $result->closeCursor();
      $db->exec("INSERT INTO status (file, status, time_stamp) VALUES ($file, 'new', strftime('%s','now'));");
    }
  }
}

function my_list() {
  global $db;
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
  print "<table id='myTable' class='tablesorter'>";
  print "<thead><tr><th>File Name</th><th>Status</th><th>Update time</th><th>Execution time (s)</th><th>Who</th><th>URL</th></tr></thead>";
  $query = $db->query("SELECT file, status, datetime(time_stamp, 'unixepoch'), time, who, url FROM status ORDER BY status");
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


function my_push() {
  global $db;
  if(!isset($_GET['file']) && !isset($_GET['time']) && !isset($_GET['url']))
    die("Wrong parameters");
  //Make sure that we already have the posts file in the DB.
  $result = $db->query("SELECT * FROM status WHERE file = ".$db->quote($_GET['file']));
  if(($row=$result->fetch())) {
    $sql = "UPDATE status SET status = 'done'".
      ", time = ".$db->quote($_GET['time']).
      ", url = ".$db->quote("<a href='".$_GET['url']."'>".$_GET['url']."</a>").
      ", time_stamp = strftime('%s', 'now')".
      ", who = ".$db->quote($_SERVER["REMOTE_ADDR"].":".$_SERVER["REMOTE_PORT"]).
      " WHERE file = ".$db->quote($_GET['file']).";";
  } else {
    $sql = "INSERT INTO status  (status, time, url, time_stamp, who, file) VALUES (".
      "'done'".
      ", ".$db->quote($_GET['time']).
      ", ".$db->quote("<a href='".$_GET['url']."'>".$_GET['url']."</a>").
      ", strftime('%s', 'now')".
      ", ".$db->quote($_SERVER["REMOTE_ADDR"].":".$_SERVER["REMOTE_PORT"]).
      ", ".$db->quote($_GET['file']).");";
  }
  $result->closeCursor();
  $query = $db->exec($sql);
  if($query) {
    print $_GET['file']." updated sucessfully\n";
  }
  return;
}

function my_pull() {
  global $db;
  $result = $db->query("SELECT * FROM status WHERE status='new' ORDER BY time_stamp LIMIT 1;");
  if(($row=$result->fetch())) {
    $file = $row['file'];
    $result->closeCursor();
    if (file_exists($file)) {
      $sql = "UPDATE status SET status = 'pulled', ".
        "time_stamp = strftime('%s', 'now'), ".
        "who = ".$db->quote($_SERVER["REMOTE_ADDR"].":".$_SERVER["REMOTE_PORT"]).
        " WHERE file = '".$file."'";
      $result = $db->exec($sql);
      if($result == 0) {
        header('HTTP/1.1 501 Not Implemented');
        die("Error updating DB");
      }
      header('Content-Type: text/plain');
      header('Content-Disposition: attachment; filename='.basename($file));
      header('Expires: 0');
      header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
      header('Pragma: public');
      header('Content-Length: ' . filesize($file));
      ob_clean();
      flush();
      readfile($file);
      return;
    }
  }
  header('HTTP/1.1 501 Not Implemented');
  die("Error updating DB");
}

function selfURL() {
  return (isset($_SERVER["HTTPS"]) ? 'https' : 'http') ."://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
}
?>
