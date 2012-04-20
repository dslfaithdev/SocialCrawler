<?php

ini_set('display_errors',1);
echo "crawler3";

/*
 * The control of the passes is via index.php --
 * if you want pass 1, you should have:
 * // include "postsCrawler.php";
 * include "small_spc.php";
 *
 * Otherwise, for pass 2,
 * include "postsCrawler.php";
 * // include "small_spc.php";
 */
$pass = 2;
switch($pass) {
case 1:
  include "small_spc.php";
  break;
case 2:
  include "postsCrawler.php";
  break;
}



?>
