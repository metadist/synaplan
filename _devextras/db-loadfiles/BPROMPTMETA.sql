/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.7.2-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: synadb
-- ------------------------------------------------------
-- Server version	11.7.2-MariaDB-ubu2404

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `BPROMPTMETA`
--

DROP TABLE IF EXISTS `BPROMPTMETA`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `BPROMPTMETA` (
  `BID` bigint(20) NOT NULL AUTO_INCREMENT,
  `BPROMPTID` bigint(20) NOT NULL COMMENT 'Reference to BPROMPTS.BID',
  `BTOKEN` varchar(64) NOT NULL COMMENT 'Meta data key',
  `BVALUE` varchar(128) NOT NULL COMMENT 'Meta data value',
  PRIMARY KEY (`BID`),
  KEY `BPROMPTID` (`BPROMPTID`),
  KEY `BTOKEN` (`BTOKEN`)
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `BPROMPTMETA`
--

LOCK TABLES `BPROMPTMETA` WRITE;
/*!40000 ALTER TABLE `BPROMPTMETA` DISABLE KEYS */;
INSERT INTO `BPROMPTMETA` VALUES
(11,135,'aiModel','-1'),
(12,135,'tool_internet','0'),
(13,135,'tool_files','0'),
(14,135,'tool_screenshot','0'),
(15,135,'tool_transfer','0'),
(16,136,'aiModel','-1'),
(17,136,'tool_internet','0'),
(18,136,'tool_files','0'),
(19,136,'tool_screenshot','0'),
(20,136,'tool_transfer','0'),
(26,137,'aiModel','57'),
(27,137,'tool_internet','1'),
(28,137,'tool_files','0'),
(29,137,'tool_screenshot','0'),
(30,137,'tool_transfer','0'),
(31,138,'aiModel','57'),
(32,138,'tool_internet','1'),
(33,138,'tool_files','1'),
(34,138,'tool_screenshot','0'),
(35,138,'tool_transfer','0'),
(36,139,'aiModel','-1'),
(37,139,'tool_internet','0'),
(38,139,'tool_files','0'),
(39,139,'tool_screenshot','0'),
(40,139,'tool_transfer','0'),
(46,141,'aiModel','-1'),
(47,141,'tool_internet','0'),
(48,141,'tool_files','0'),
(49,141,'tool_screenshot','0'),
(50,141,'tool_transfer','0'),
(72,144,'aiModel','69'),
(73,144,'tool_internet','0'),
(74,144,'tool_files','0'),
(75,144,'tool_screenshot','0'),
(76,144,'tool_transfer','0');
/*!40000 ALTER TABLE `BPROMPTMETA` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2025-08-05 15:19:30
