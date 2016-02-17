-- MySQL dump 10.15  Distrib 10.0.22-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: crawling
-- ------------------------------------------------------
-- Server version	10.0.22-MariaDB-1~trusty-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Temporary table structure for view `crawl_stat`
--

DROP TABLE IF EXISTS `crawl_stat`;
/*!50001 DROP VIEW IF EXISTS `crawl_stat`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE TABLE `crawl_stat` (
  `progress` tinyint NOT NULL,
  `id` tinyint NOT NULL,
  `name` tinyint NOT NULL,
  `mod_time` tinyint NOT NULL,
  `exec_time` tinyint NOT NULL,
  `done` tinyint NOT NULL,
  `pulled` tinyint NOT NULL
) ENGINE=MyISAM */;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `page`
--

DROP TABLE IF EXISTS `page`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `page` (
  `fb_id` bigint(20) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `update` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`fb_id`)
) ENGINE=TokuDB DEFAULT CHARSET=utf8 `compression`='tokudb_zlib';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `post`
--

DROP TABLE IF EXISTS `post`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `post` (
  `page_fb_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `post_fb_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `seq` int(11) DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  `from_user` bigint(20) DEFAULT NULL,
  `status` enum('done','new','pulled','updated','recrawl','removed') DEFAULT 'new',
  `time_stamp` int(11) DEFAULT NULL,
  `who` int(10) unsigned DEFAULT NULL,
  `time` float DEFAULT NULL,
  PRIMARY KEY (`page_fb_id`,`post_fb_id`),
  KEY `time_stamp` (`time_stamp`),
  KEY `status_time` (`status`,`time_stamp`)
) ENGINE=TokuDB DEFAULT CHARSET=utf8 `compression`='tokudb_fast';
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `pull_posts`
--

DROP TABLE IF EXISTS `pull_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pull_posts` (
  `page_fb_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `post_fb_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`page_fb_id`,`post_fb_id`)
) ENGINE=MEMORY DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = '' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER pull_posts_pop AFTER DELETE ON pull_posts FOR EACH ROW
  BEGIN
    UPDATE post SET status = 'pulled', time_stamp = UNIX_TIMESTAMP(), who = INET_ATON(@WHO) WHERE page_fb_id =  OLD.page_fb_id AND post_fb_id = OLD.post_fb_id;
    SET @deletedIDs = CONCAT_WS(';', @deletedIDs, CONCAT_WS(",", OLD.page_fb_id , OLD.post_fb_id));
  END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;


--
-- Final view structure for view `crawl_stat`
--

/*!50001 DROP TABLE IF EXISTS `crawl_stat`*/;
/*!50001 DROP VIEW IF EXISTS `crawl_stat`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `crawl_stat` AS select round(((count((case when (`post`.`status` = 'done') then 1 when (`post`.`status` = 'updated') then 1 when (`post`.`status` = 'removed') then 1 end)) * 100.0) / count(0)),4) AS `progress`,`page`.`fb_id` AS `id`,`page`.`name` AS `name`,from_unixtime(max(`post`.`time_stamp`)) AS `mod_time`,round(sum(`post`.`time`),4) AS `exec_time`,concat(count((case when (`post`.`status` = 'done') then 1 when (`post`.`status` = 'updated') then 1 when (`post`.`status` = 'removed') then 1 end)),'/',count(0)) AS `done`,concat(count((case when (`post`.`status` = 'pulled') then 1 end)),'/',count(0)) AS `pulled` from (`page` join `post`) where (`post`.`page_fb_id` = `page`.`fb_id`) group by `page`.`fb_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `pull_post_queue`
--

/*!50001 DROP TABLE IF EXISTS `pull_post_queue`*/;
/*!50001 DROP VIEW IF EXISTS `pull_post_queue`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8 */;
/*!50001 SET character_set_results     = utf8 */;
/*!50001 SET collation_connection      = utf8_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`sincere`@`garm.comlab.bth.se` SQL SECURITY DEFINER */
/*!50001 VIEW `pull_post_queue` AS select sec_to_time((count(0) / (select (count(0) / 14400) from `post` where ((`crawling`.`post`.`status` = 'done') and (`crawling`.`post`.`time_stamp` > (unix_timestamp() - 14400)))))) AS `pull_post_queue` from `pull_posts` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2016-02-17  5:28:05
