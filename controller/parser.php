<?php

function exception_error_handler($errno, $errstr, $errfile, $errline ) {
    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}
set_error_handler("exception_error_handler");

function parseJsonString($string, &$table = []) {
  $likes=0; $cs=0; $shares=0;
  $comments = array();
  $data = preg_split("/(\r\n|\n)/", $string);
  //Parse the first row as a post(message).
  $post = json_decode(array_shift($data),true);
  if($post == "")
    throw new Exception("Empty post", E_WARNING);
  if(isset($post['ep_likes']) || $post === NULL || !isset($post['id']))
    throw new Exception("Broken post", E_WARNING);

  $page_id = strstr($post['id'],'_', true);
  $post_id = substr(strstr($post['id'],'_'),1);

  // Handle users found in $post[from/to]
  if( isset($post['from']) ) {
    $users[]=$post['from'];
    if( isset($post['to'] ))
      $users=array_merge((array)$users, (array)$post['to']['data']);
    foreach($users as $user) {
      if( isset($user['category'])) {
	$table['page'][$user['id']] = [ $user['id'], isSetOr($user['name'],'null',true), isSetOr($user['category'], 'null', true) ];
      } else {
	$table['fb_user'][$user['id']] = [ isSetOr($user['id'], 0), isSetOr($user['name'],'null',true), "null" ];
      }
    }
  }

  // Handle place found in $post[place]
  if(isset($post['place'])) {
    if(isset($post['place']['location']['latitude'], $post['place']['location']['longitude']))
    $table['place'][$post['place']['id']] = [
      $post['place']['id'], isSetOr($post['place']['name'], 'null', true),
        $post['place']['location']['latitude'], $post['place']['location']['longitude'] ];
    else
      file_put_contents("parserDebug.txt" , json_encode($post['place']) . PHP_EOL, FILE_APPEND | LOCK_EX);
  }
  // Handle application found in $post[applicatin]
  if(isset($post['application'])) {
    $table['application'][$post['application']['id']] = [
      $post['application']['id'], isSetOr($post['application']['name'],'null',true), isSetOr($post['application']['namespace'],'null',true) ];
  }
  // Handle story_tags/message_tags
  foreach(['story_tags', 'message_tags'] as $type) {
    if(!isset($post[$type]))
      continue;
    foreach($post[$type] as $tags)
      foreach($tags as $tag) {
          $table[$type][] = [
          $tag['id'], $page_id, $post_id, 'null', $tag['offset'],
          $tag['length'], isSetOr($tag['type'],'null',true), isSetOr($tag['name'], 'null', true) ];
      }
    unset($post[$type]);
  }
  // Handle with_tags
  if(isset($post['with_tags'])) {
    foreach($post['with_tags']['data'] as $with) {
      $table['with_tags'][] = [ $page_id, $post_id, isSetOr($with['id']) ];
    }
    unset($post['with_tags']);
  }

  // Handle the single post assuming
  // post_id, page_id, from_id (either a fb_id or a page_id), message, type,
  // picture, story, link, name, description, caption, icon, created_time,
  // updated_time, can_remove, shares_count, likes_count, comments_count, entr_pg,
  // entr_ug, object_id, status_type, source, is_hidden, application_id,
  // place_id
  $table["post"][$page_id."_".$post_id] =  array(
    $post_id,
    $page_id,
    isSetOr($users[0]['id'], 0),
    isSetOr($post['message'],'null',true),
    isSetOr($post['type'],'null',true),
    isSetOr($post['picture'],'null',true),
    isSetOr($post['story'],'null',true),
    isSetOr($post['link'],'null',true),
    isSetOr($post['name'],'null',true),
    isSetOr($post['description'],'null',true),
    isSetOr($post['caption'],'null',true),
    isSetOr($post['icon'],'null',true),
    isSetOr($post['created_time'],'null',true),
    isSetOr($post['updated_time'],'null',true),
    'null', //can_remove
    isSetOr($post['shares']['count']),
    isSetOr($post['likes']['summary']['total_count']), //isSetOr($post['likes']['count']),
    isSetOr($post['comments']['summary']['total_count']), //isSetOr($post['comments']['count']),
    'null', 'null', //we can't extract entropy from our crawled post
    isSetOr($post['object_id'], 'null', true),
    isSetOr($post['status_type'],'null',true),
    isSetOr($post['source'],'null',true),
    'null', //is_hidden
    isSetOr($post['application']['id']),
    isSetOr($post['place']['id'])
  );

  //Remove the used info.
  $msg_id= $post['id'];
  unset($post['id'], $post['from'], $post['to'], $post['message'], $post['type'],
    $post['picture'], $post['story'], $post['link'], $post['name'],
    $post['description'], $post['caption'], $post['icon'],
    $post['created_time'], $post['updated_time'], $post['can_remove'],
    $post['shares'], $post['likes'], $post['comments'],
    $post['object_id'], $post['status_type'], $post['source'],
    $post['is_hidden'], $post['application'], $post['place']);

  //We don't care about this.
  unset($post['width'], $post['expanded_width'], $post['height'], $post['expanded_height'], $post['actions']);
  unset($post['privacy']); //{"privacy":{"value":"","description":"","friends":"","allow":"","deny":""}}
  if(!empty($post)) {
    $missed = json_encode($post);
    $missed = '"'.$msg_id.'":'.$missed.','.PHP_EOL;
    #  print $missed."\n";
    file_put_contents('missed_data.json', $missed, FILE_APPEND);
  }

  foreach ($data as $line){
    $d = json_decode($line,true);
    if(isset($d['ep_likes'])) {
      if(isset($d['ep_likes']['summary']['total_count']))
        $table["post"][$page_id."_".$post_id][16] = $d['ep_likes']['summary']['total_count'];
      if(empty($d['ep_likes']['data']))
        continue;
      //unset($d['ep_likes']['paging']['cursors']);
      //preg_match('/([0-9]+)_([0-9]+)\\/likes/', current($d['ep_likes']['paging']),$matches);
      foreach ($d['ep_likes']['data'] as $user)
        if(isset($user['id'])) {
          if( isset($user['category'])) {
            $table['page'][$user['id']] = [ $user['id'], isSetOr($user['name'], 'null', true), isSetOr($user['category'], 'null', true) ];
          } else {
            $table['fb_user'][$user['id']] = [ isSetOr($user['id'],0), isSetOr($user['name'],'null',true), "null" ];
          }
          if(isset($user['type'])) { //It is a reaction!!
            $table["reaction"][] = [
              $page_id, $post_id, 0, $user['id'], isSetOr($user['type'], 'null', true)];
          } else {
            $table["likedby"][] = [
              $page_id, $post_id, 0, $user['id'], isSetOr($user['created_time'], 'null', true)];
          }
          $likes++;
        }
    }
    if(isset($d['ec_comments'])) {
      if(isset($d['ec_comments']['summary']['total_count']))
        $table["post"][$page_id."_".$post_id][17] = $d['ec_comments']['summary']['total_count'];
      if(empty($d['ec_comments']['data']))
        continue;
      //unset($d['ec_comments']['paging']['cursors']);
      //preg_match('/([0-9]+)_([0-9]+)\\/comments/', current($d['ec_comments']['paging']),$matches);
      foreach ($d['ec_comments']['data'] as $c) {
        if(isset($c['id'])) {
          if(!isset($c['from'])) {
            file_put_contents("parserDebug.txt" , json_encode($c) . PHP_EOL, FILE_APPEND | LOCK_EX);
            continue;
          }
          $user = $c['from'];
          if( isset($user['category'])) {
            $table['page'][$user['id']] = [ $user['id'], isSetOr($user['name'], 'null', true), isSetOr($user['category'], 'null', true) ];
          } else {
            $table['fb_user'][$user['id']] = [ isSetOr($user['id'],0), isSetOr($user['name'],'null',true), "null" ];
          }
          $ids=explode('_',$c['id']);
          //if(count($ids) == 3)
            //$page_id = $ids[0]; $post_id = $ids[1]; $message_id = $ids[2];
          //else
          $message_id = array_pop($ids);
          $comments[]=$message_id;
          if(isset($c['parent'])) {
            $table["reply"][] = array(
              $message_id, $post_id, $page_id,
              substr(strstr($c['parent']['id'], '_'), 1), //Just use the commentId (postId_commentId)
              isSetOr($user['id']),
              isSetOr($c['message'],'null',true),
              (isset($c['can_remove']) ? 1 : 0),
              isSetOr($c['created_time'],'null',true)
            );
          } else {
            $table["comment"][] = array(
              $message_id, $post_id, $page_id, isSetOr($user['id']),
              isSetOr($c['message'],'null',true),
              (isset($c['can_remove']) ? 1 : 0),
              isSetOr($c['created_time'],'null',true));
          }
          $cs++;
          //"to_timestamp('".  pg_escape_string(isSetOr($c['created_time'])).  "', 'YYYY-MM-DD HH24:MI:SS')"));

          // Handle story_tags/message_tags
          foreach(['story_tags', 'message_tags'] as $type) {
            if(!isset($c[$type]))
              continue;
            foreach($c[$type] as $tag)
              if(!isset($tag['id'])) {
                file_put_contents("parserDebug.txt", print_r($c['message_tags'], true) . PHP_EOL, FILE_APPEND | LOCK_EX);
                var_dump($c['message_tags']); echo "No id\n"; die(0);
              }
              $table[$type][] = [
                $tag['id'], $page_id, $post_id, $message_id, $tag['offset'],
                $tag['length'], isSetOr($tag['type'],'null',true),
                isSetOr($tag['name'], 'generic_tag', true)
              ];
          }
        }
      }
    }
    if(isset($d['ec_likes'])) {
      $comment_id=array_shift($comments);
      if(isset($d['ec_likes']['paging']) && empty($d['ec_likes']['data']))
        continue;
      if(isset($d['ec_likes']['id']))
        $comment_id=substr(strrchr($d['ec_likes']['id'],'_'), 1);
      //print "$page_id $post_id $comment_id".PHP_EOL;
      //if(empty($d['ec_likes']['data']))
        //continue;
      //print "$page_id $post_id $comment_id".PHP_EOL;
      //print_r($d);
      //exit(-1);
      //unset($d['ec_likes']['paging']['cursors']);
      //preg_match('/([0-9]+)_([0-9]+)_([0-9]+)\\/likes/', current($d['ec_likes']['paging']),$matches);
      foreach ($d['ec_likes']['data'] as $user)
        if(isset($user['id'])) {
          if( isset($user['category'])) {
            $table['page'][$user['id']] = [ $user['id'], isSetOr($user['name'], 'null', true), isSetOr($user['category'], 'null', true) ];
          } else {
            $table['fb_user'][$user['id']] = [ isSetOr($user['id'],0), isSetOr($user['name'],'null',true), "null" ];
          }
          if(empty($page_id) || empty($post_id) || empty($comment_id)) {
            throw new Exception('Problem parsing likes on comments, recrawl is suggested.'); }//print_r($comments);print_r($d); exit(PHP_EOL.$page_id.", ".$post_id. ", ". $comment_id.PHP_EOL); }
          $table["likedby"][] = [
            $page_id, $post_id, $comment_id, $user['id'], isSetOr($like['created_time'],'null',true)];
            //$matches[1], $matches[2], $matches[3], $user['id'], isSetOr($like['created_time'],'null',true)];
            //$matches[1], $matches[2], $matches[3], $like['id'], "to_timestamp('".isSetOr($like['created_time'])."', 'YYYY-MM-DD HH24:MI:SS')"));
        }
    }
    if(isset($d['ep_shares'], $d['ep_shares']['data'])) {
      foreach($d['ep_shares']['data'] as $share) {
        if(isset($share['from'])) {
          $user=$share['from'];
          if( isset($user['category'])) {
            $table['page'][$user['id']] = [ $user['id'], isSetOr($user['name'], 'null', true), isSetOr($user['category'], 'null', true) ];
          } else {
            $table['fb_user'][$user['id']] = [ isSetOr($user['id'],0), isSetOr($user['name'],'null',true), "null" ];
          }
          $table['shares'][] =  [ strstr($share['id'],'_',true), $post_id, isSetOr($user['id'],0),
            isSetOr($share['updated_time'],'0000-00-00 00:00:00',true), isSetOr($like['created_time'],'0000-00-00 00:00:00',true) ];
          $shares++;
        }
      }
    }
  }
  if($table["post"][$page_id."_".$post_id][15] === "null")
    $table["post"][$page_id."_".$post_id][15] = $shares;
  if($table["post"][$page_id."_".$post_id][16] === "null")
    $table["post"][$page_id."_".$post_id][16] = $likes;
  if($table["post"][$page_id."_".$post_id][17] === "null")
    $table["post"][$page_id."_".$post_id][17] = $cs;
  return $table;
}
function createInserts($filePrefix, $array, $db) {
  foreach($array as $key => $value){
    $f = fopen($filePrefix.".".$key.".sql", "a");
    if($f === FALSE)
      return "error opening ".$filePrefix.".".$key.".sql".PHP_EOL;
    $arr = array_chunk($value, 250000);
    unset($value);
    foreach($arr as $v) {
      foreach ($v as &$line)
        $line = "(".implode(",", $line).")";
      //$sql = "INSERT IGNORE INTO ".$key." VALUES ".implode(',', $v ).";".PHP_EOL;
      $sql = "REPLACE INTO ".$key." VALUES ".implode(',', $v).";".PHP_EOL;
      fwrite($f, $sql);
    }
    fclose($f);
  }
}

function insertToDB($query, $db) {
  if(DB == "mysql") {
    //if(!$db->autocommit(FALSE))
      //die($db->error);
    $db->set_charset("utf8");
    foreach($query as $key => $value){
      //foreach ($value as &$line)
        //$line = "(".implode(",", $line).")";
      //while(count($value)) {
      $arr = array_chunk($value, 200);
      unset($value);
      foreach($arr as $v) {
        foreach ($v as &$line) {
          /*
           *foreach ($line as $l)
           *  if(is_array($l))
           *    throw new Exception($key . " . ". var_export($line, true). PHP_EOL . var_export($l, true). PHP_EOL);
           */
          $line = "(".implode(",", $line).")";
        }
        //$sql = "INSERT IGNORE INTO ".$key." VALUES ".implode(',', $v ).";".PHP_EOL;
        $sql = "REPLACE INTO ".$key." VALUES ".implode(',', $v).";".PHP_EOL;
        #       print count($value).'.';
        //$sql = "INSERT IGNORE INTO ".$key." VALUES ".implode(',', array_splice($value,0,25000)).";".PHP_EOL;
          //file_put_contents("insert-".$key.".sql", $sql, FILE_APPEND);
        #     print $sql;
        if(!$db->real_query($sql)) {
          file_put_contents("db-error.sql", ";" . $db->error . PHP_EOL . $sql . PHP_EOL, FILE_APPEND | LOCK_EX);
          throw new Exception($db->error, E_WARNING);
          //die($db->error);
        }
      }
    }
    //if(!$db->commit())
        //die($db->error);
    //$db->close();
    return;
  }

  foreach ($query as &$t)
    foreach ($t as &$line)
      $line = "(".implode(",", $line).")";
  $sql = "";
  //Test with temp tables: http://robbat2.livejournal.com/214267.html
  //Better solution..
  //http://stackoverflow.com/questions/7463842/postgresql-clean-way-to-insert-records-if-they-dont-exist-update-if-they-do
  //$rows = $query['fb_user'];
  //$sql .= "DROP TABLE IF EXISTS tmp_fb_user; CREATE TEMPORARY TABLE tmp_fb_user AS SELECT * FROM fb_user; ";
  $sql .= "INSERT INTO fb_user VALUES ";
  $sql .= implode(",",array_unique($query['fb_user']));
  $sql .= ";"; //" INSERT INTO fb_user SELECT tmp_fb_user.* FROM tmp_fb_user WHERE (id) NOT IN (SELECT id FROM fb_user)";
  //$sql .= " EXCEPT SELECT id, name, category FROM fb_user;\n";
  $result = pg_query($db, $sql);// or die('Query failed: ' . pg_last_error());

  //$rows = $query['page'];
  $sql = "INSERT INTO page VALUES ";
  $sql .= implode(",",array_unique($query['page']));
  $sql .= " EXCEPT SELECT id, name, category FROM page;\n";
  $result = pg_query($db, $sql);// or die('Query failed: ' . pg_last_error());

  //$rows = $query['post'];
  $sql = "INSERT INTO post VALUES ";
  $sql .= implode(",",array_unique($query['post']));
  $sql .= " EXCEPT SELECT id, page_id, fb_id, message, type, picture, ".
    "story, link, link_name, link_description, link_caption, icon, ".
    "created_time, updated_time, can_remove, shares_count, likes_count, comments_count FROM post;\n";
  $result = pg_query($db, $sql);// or die('Query failed: ' . pg_last_error());

  //$rows = $query['comment'];
  $sql = "INSERT INTO comment VALUES ";
  $sql .= implode(",",array_unique($query['comment']));
  $sql .= " EXCEPT SELECT id, post_id, page_id, fb_id, message, can_remove, created_time FROM comment;\n";
  $result = pg_query($db, $sql);// or die('Query failed: ' . pg_last_error());

  //$rows = $query['likedby'];
  $sql = "INSERT INTO likedby VALUES ";
  $sql .= implode(",",array_unique($query['likedby']));
  $sql .= " EXCEPT SELECT page_id, post_id, comment_id, fb_id, created_time FROM likedby;\n";
  $result = pg_query($db, $sql);// or die('Query failed: ' . pg_last_error());

  if($db == NULL)
    die($sql);
  $result = pg_query($db, $sql);// or die('Query failed: ' . pg_last_error());
  /*if (!$result) {
    die("error inserting in DB");
  }
  print pg_affected_rows($result);
   */
  if($result === FALSE)
    return -1;
  return pg_affected_rows($result);
}

function exportToCsv($filePrefix, $array) {
/*
  if(myputcsv($filePrefix.".user.csv", $array['fb_user']) != 0)
    return "error opening ".$filePrefix.".user.csv";

  if(myputcsv($filePrefix.".page.csv", $array['page']) != 0)
    return "error opening ".$filePrefix.".page.csv";

  if(myputcsv($filePrefix.".post.csv", $array['post']) != 0)
    return "error opening ".$filePrefix.".user.csv";

  if(myputcsv($filePrefix.".comment.csv", $array['comment']) != 0)
    return "error opening ".$filePrefix.".comment.csv";
 */
  foreach($array as $key => $value){
    $f = fopen($filePrefix.".".$key.".csv", "a");
    if($f === FALSE)
      return "error opening ".$filePrefix.".".$key.".csv".PHP_EOL;

    //Test of new array_unique
  # $value = array_map('unserialize', array_unique(array_map('serialize', $value)));

    foreach($value as $fields) {
      fputcsv($f, $fields, ',','"');
    }
    fclose($f);
  }
  return 0;
}
/*
function myputcsv($fileName, $a) {
  $f = fopen($fileName, "a");
  if($f === FALSE)
    return -1;

  foreach($a as $fields) {
    fputcsv($f, $fields, ',', '"');
  }
  fclose($f);

  return 0;
}
 */
function isSetOr(&$var, $or='null', $escape=false){
  $ret = (($var === null) ? $or : $var);
  if ($escape && $ret != 'null') {
    return my_escape($ret);
  }
  return $ret;
}

function shiftAndEscape(&$array, $key){
  $return = $array[$key];
  unset($array[$key]);
  $array = array_values($array);
  return pg_escape_string($return);
  break;
}

function my_escape($key) {
  if(defined('DB')) {
    if(DB == "psql")
      return "'".pg_escape_string($key)."'";
    if(DB == "mysql") {
      global $db;
      return "'".addcslashes($db->real_escape_string($key),'%_')."'";
    }
  }
  return $key;
}

function return_bytes ($size_str)
{
    switch (substr ($size_str, -1))
    {
        case 'M': case 'm': return (int)$size_str * 1048576;
        case 'K': case 'k': return (int)$size_str * 1024;
        case 'G': case 'g': return (int)$size_str * 1073741824;
        default: return $size_str;
    }
}
?>
