<?php
/*
 * Assumes a (my)sql database like this:
--
-- Table structure for table `page`
--

DROP TABLE IF EXISTS `page`;
CREATE TABLE `page` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=745 DEFAULT CHARSET=latin1;

--
-- Table structure for table `post`
--

DROP TABLE IF EXISTS `post`;
CREATE TABLE `post` (
  `id` int(32) NOT NULL AUTO_INCREMENT,
  `post_id` varchar(48) DEFAULT NULL,
  `page_id` int(11) DEFAULT NULL,
  `seq` int(11) DEFAULT NULL,
  `date` varchar(13) DEFAULT NULL,
  `data` longblob,
  `status` varchar(40) DEFAULT NULL,
  `time_stamp` int(11) DEFAULT NULL,
  `who` int(10) unsigned DEFAULT NULL,
  `time` float DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `post_id` (`post_id`),
  KEY `page_id` (`page_id`),
  KEY `IDX_FIELD2` (`post_id`),
  KEY `status_time_stamp_id` (`status`,`time_stamp`,`id`),
  KEY `status_post_id_time_stamp_id` (`status`,`post_id`,`time_stamp`,`id`),
  KEY `id_status_timestamp` (`id`,`status`,`time_stamp`)
) ENGINE=InnoDB AUTO_INCREMENT=3472802 DEFAULT CHARSET=latin1;

--
-- Table structure for table `post_data`
--

DROP TABLE IF EXISTS `post_data`;
CREATE TABLE `post_data` (
  `id` int(32) NOT NULL AUTO_INCREMENT,
  `data` longblob,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3472802 DEFAULT CHARSET=latin1;
--
-- Table structure for table `pull_posts`
--

DROP TABLE IF EXISTS `pull_posts`;
CREATE TABLE `pull_posts` (
  `id` int(32) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
 *
 * Don't forget to set the default values below.
 */
define(_PDO_dsn_,'Data Source Name');
define(_PDO_username_,'username');
define(_PDO_password_, 'password');


error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set('error_log','logFile.log');
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
  set_time_limit(300);
  $count =0;
  $dbh = new PDO(PDO_dsn, PDO_username, PDO_password);
  $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  //Read file for now.
  $files = glob('posts/posts.txt.*');
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
    print "Adding $file <br/>\n"; flush(); ob_flush();
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
      $sth->closeCursor();
    }
  }
  print "$count rows added.<br/>\n";
}

function pull_post($count=3) {
  if(isset($_GET['count']))
    $count = intval($_GET['count']);
  if($count < 1)
    $count = 3;
  $db = new PDO(PDO_dsn, PDO_username, PDO_password);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  $db->setAttribute(PDO::ATTR_TIMEOUT, "120");
  //Make sure we are not already adding items to our helper table.
  for ($i = 0; $i < 20; $i++) {
      $result = $db->query("SELECT count(*) FROM pull_posts WHERE id = -1");
      if ($result->fetchColumn() == 0)
          break;
      sleep(10);
  }
  $result = $db->query("SELECT count(*) FROM pull_posts WHERE id != -1");
  if ($result->fetchColumn() <= $count + 10) {
      // Add posts to our helper..
      $sql = "INSERT INTO pull_posts VALUES (-1);";
      $sql .= "INSERT INTO pull_posts SELECT id FROM post FORCE INDEX (id_status_timestamp) WHERE ((status='pulled' AND UNIX_TIMESTAMP()-time_stamp > 4200) OR status='new') ORDER BY id LIMIT 500;";
      $sql .= "DELETE FROM pull_posts WHERE id = -1;";
      $db->query($sql);
  }
  $sql = "SELECT id FROM pull_posts LIMIT ".intval($count);
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
        " WHERE id = '".$row['id']."'; DELETE FROM pull_posts WHERE id =  ".$row['id'].";";
      $result = $db->query("SELECT post_id FROM post WHERE id = ".$row['id']);
      $id[]=$result->fetchColumn();
  }
  $result->closeCursor();
  $result = $db->exec($sql);
  if(count($id) == 0)
    die("0&No new posts");
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
        ", who = INET_ATON(".$db->quote($_SERVER["REMOTE_ADDR"]).") ".
        ", time = ".$db->quote($post['exec_time']).
        ", time_stamp = UNIX_TIMESTAMP()".
        " WHERE id = ".$row['id']."; ";
      $sql .= "INSERT INTO post_data VALUES (".$row['id'].
          ", data = ".$db->quote($post['data']).
          ") on duplicate key UPDATE ".
          "data = ".$db->quote($post['data']).";";
      file_put_contents('raw/'.$row['fname'], gzinflate(substr(base64_decode($post['data']),10,-8)));
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
          "New"     : function(e, n, f, i) { return n <= 0.001; }
        },
        2 : {
          "last 20 min"      : function(e, n, f, i) { return serverTime-myTime(e) < 1200; },
          "last 1 h" : function(e, n, f, i) { return serverTime-myTime(e) <= 3600; },
          "last 12 h" : function(e, n, f, i) { return serverTime-myTime(e) <= 43200; },
          "12 h - 24 h " : function(e, n, f, i) { return serverTime-myTime(e) >= 43200 && serverTime-myTime(e) <= 86400; },
          "1 - 7 days" : function(e, n, f, i) { return serverTime-myTime(e) >= 86400 && serverTime-myTime(e) <= 604800; },
          "7 - 14 days" : function(e, n, f, i) { return serverTime-myTime(e) >= 604800 && serverTime-myTime(e) <= 1209600; },
          "> 14 days"     : function(e, n, f, i) { return serverTime-myTime(e) > 1209600; }
        },
        // Add these options to the select dropdown (regex example)
        4 : {
          "A - D" : function(e, n, f, i) { return /^[A-D]/.test(e); },
          "E - H" : function(e, n, f, i) { return /^[E-H]/.test(e); },
          "I - L" : function(e, n, f, i) { return /^[I-L]/.test(e); },
          "M - P" : function(e, n, f, i) { return /^[M-P]/.test(e); },
          "Q - T" : function(e, n, f, i) { return /^[Q-T]/.test(e); },
          "U - X" : function(e, n, f, i) { return /^[U-X]/.test(e); },
          "Y - Z" : function(e, n, f, i) { return /^[Y-Z]/.test(e); }
        },

        // Add these options to the select dropdown (numerical comparison example)
        // Note that only the normalized (n) value will contain numerical data
        // If you use the exact text, you'll need to parse it (parseFloat or parseInt)
        3 : {
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

    sortList: [[0,1],[2,0]]
  });
}); </script>

  <?
  flush();
  $db = new PDO(PDO_dsn, PDO_username, PDO_password);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
  $db->setAttribute(PDO::ATTR_TIMEOUT, "30");
  print "<table id='myTable' class='tablesorter'>";
  print "<thead><tr><th data-placeholder=\"--\">Status (%)</th><th>Name</th><th>Last modification time</th><th data-placeholder=\"--\">Exec Time (s)</th><th data-placeholder=\"try /\d+\\//\">Status</th><th>Pulled</th></tr></thead>";
  $sql_status = "SELECT ".
    "ROUND(COUNT(CASE WHEN status = 'done' THEN 1 END)*100.0/COUNT(*),4), ".
    "name, FROM_UNIXTIME(MAX(time_stamp)), ROUND(SUM(time), 4),".
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
