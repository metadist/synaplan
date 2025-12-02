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
-- Table structure for table `BCAPABILITIES`
--

DROP TABLE IF EXISTS `BCAPABILITIES`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `BCAPABILITIES` (
  `BID` bigint(20) NOT NULL AUTO_INCREMENT,
  `BKEY` varchar(64) NOT NULL,
  `BCOMMENT` varchar(128) NOT NULL,
  `BRATELIMIT_CATEGORY` varchar(32) DEFAULT NULL COMMENT 'Maps to rate limit category (IMAGES, VIDEOS, AUDIOS, FILE_ANALYSIS, MESSAGES)',
  PRIMARY KEY (`BID`),
  KEY `BKEY` (`BKEY`),
  KEY `BRATELIMIT_CATEGORY` (`BRATELIMIT_CATEGORY`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `BCAPABILITIES`
--

LOCK TABLES `BCAPABILITIES` WRITE;
/*!40000 ALTER TABLE `BCAPABILITIES` DISABLE KEYS */;
INSERT INTO `BCAPABILITIES` VALUES
(1,'sort','Big prompt to classify input, needs a capable LLM',NULL),
(2,'chat','Classic LLM functionality','MESSAGES'),
(3,'text2sound','Speak the text','AUDIOS'),
(4,'text2music','Make a song','AUDIOS'),
(5,'text2pic','Create an image','IMAGES'),
(6,'text2vid','Create a video','VIDEOS'),
(7,'pic2pic','Rework a picture','IMAGES'),
(8,'pic2vid','Create video from image','VIDEOS'),
(9,'pic2text','Explain the picture in text','FILE_ANALYSIS'),
(10,'sound2text','Transcribe - whisper style','FILE_ANALYSIS'),
(11,'translate','Translate from one language to another','MESSAGES'),
(12,'vectorize','Put text into the database as vectors and enrich RAG',NULL),
(13,'text2moderate','Check for insulting language',NULL),
(14,'pic2moderate','Check for insulting images and nudity',NULL),
(15,'analyze','Analytical models','FILE_ANALYSIS');
/*!40000 ALTER TABLE `BCAPABILITIES` ENABLE KEYS */;
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
