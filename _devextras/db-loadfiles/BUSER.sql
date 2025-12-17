/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.2-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: synaplan
-- ------------------------------------------------------
-- Server version	11.8.2-MariaDB-ubu2404

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
-- Table structure for table `BUSER`
--

DROP TABLE IF EXISTS `BUSER`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `BUSER` (
  `BID` bigint(20) NOT NULL AUTO_INCREMENT,
  `BCREATED` varchar(20) NOT NULL,
  `BINTYPE` varchar(16) NOT NULL DEFAULT 'WEB',
  `BMAIL` varchar(128) NOT NULL,
  `BPW` varchar(64) DEFAULT NULL,
  `BPROVIDERID` varchar(32) NOT NULL,
  `BUSERLEVEL` varchar(32) NOT NULL DEFAULT 'NEW',
  `BEMAILVERIFIED` tinyint(1) NOT NULL DEFAULT 0,
  `BUSERDETAILS` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`BUSERDETAILS`)),
  `BPAYMENTDETAILS` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`BPAYMENTDETAILS`)),
  PRIMARY KEY (`BID`),
  KEY `BMAIL` (`BMAIL`),
  KEY `BINTYPE` (`BINTYPE`),
  KEY `BPROVIDERID` (`BPROVIDERID`),
  KEY `BUSERLEVEL` (`BUSERLEVEL`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `BUSER`
--

LOCK TABLES `BUSER` WRITE;
/*!40000 ALTER TABLE `BUSER` DISABLE KEYS */;
set autocommit=0;
INSERT INTO `BUSER` VALUES
(1,'2025-12-17 09:36:29','WEB','admin@synaplan.com','$2y$13$ySvbD1NP62YmsWSF7Fvl7OzG2wHv1psU1yeXGnF3Zcdqd9Wd2QPM.','','ADMIN',1,'{\"firstName\":\"Admin\",\"lastName\":\"User\",\"company\":\"Synaplan\"}','[]'),
(2,'2025-12-17 09:36:29','WEB','demo@synaplan.com','$2y$13$dNXBx6Tp/twh84RHdSmxZefol1fwdzgOy862ENOJl4Icposkaw2di','','PRO',1,'{\"firstName\":\"Demo\",\"lastName\":\"User\"}','[]'),
(3,'2025-12-17 09:36:29','WEB','test@example.com','$2y$13$4YluKVE7TR6Ms.OvGnqzmuZ.wejgAdqERvJ1rqOs7qtPyD7RXSF8C','','NEW',0,'{\"firstName\":\"Test\",\"lastName\":\"User\"}','[]');
/*!40000 ALTER TABLE `BUSER` ENABLE KEYS */;
UNLOCK TABLES;
commit;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2025-12-17  9:37:29
