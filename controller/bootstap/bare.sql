-- MySQL dump 10.14  Distrib 5.5.28-MariaDB, for FreeBSD9.0 (amd64)
--
-- Host: localhost    Database: crawling
-- ------------------------------------------------------
-- Server version	5.5.28-MariaDB

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
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
  `status` enum('done','new','pulled','updated','recrawl') DEFAULT 'new',
  `time_stamp` int(11) DEFAULT NULL,
  `who` int(10) unsigned DEFAULT NULL,
  `time` float DEFAULT NULL,
  PRIMARY KEY (`page_fb_id`,`post_fb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
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
/*!50001 VIEW `crawl_stat` AS select round(((count((case when (`post`.`status` = 'done') then 1 when (`post`.`status` = 'updated') then 1 end)) * 100.0) / count(0)),4) AS `progress`,`page`.`fb_id` AS `id`,`page`.`name` AS `name`,from_unixtime(max(`post`.`time_stamp`)) AS `mod_time`,round(sum(`post`.`time`),4) AS `exec_time`,concat(count((case when (`post`.`status` = 'done') then 1 when (`post`.`status` = 'updated') then 1 end)),'/',count(0)) AS `done`,concat(count((case when (`post`.`status` = 'pulled') then 1 end)),'/',count(0)) AS `pulled` from (`page` join `post`) where (`post`.`page_fb_id` = `page`.`fb_id`) group by `page`.`fb_id` */;
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

-- Dump completed on 2013-02-05 17:50:30
