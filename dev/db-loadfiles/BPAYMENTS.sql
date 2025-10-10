-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 24, 2025 at 01:01 PM
-- Server version: 11.7.2-MariaDB-ubu2204-log
-- PHP Version: 8.3.24

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `synaplan`
--

-- --------------------------------------------------------

--
-- Table structure for table `BPAYMENTS`
--

DROP TABLE IF EXISTS `BPAYMENTS`;
CREATE TABLE `BPAYMENTS` (
  `BID` bigint(20) NOT NULL,
  `BUID` bigint(20) NOT NULL,
  `BPAYPROVIDER` varchar(12) NOT NULL,
  `BDATE` varchar(14) NOT NULL,
  `BAMOUNT` float NOT NULL,
  `BCURR` varchar(3) NOT NULL,
  `BJSON` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`BJSON`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `BPAYMENTS`
--

INSERT INTO `BPAYMENTS` (`BID`, `BUID`, `BPAYPROVIDER`, `BDATE`, `BAMOUNT`, `BCURR`, `BJSON`) VALUES
(1, 2, 'STRIPE', '202509211421', 1995, 'EUR', '{\r\n  \"line_items\": [\r\n    {\r\n      \"price_data\": {\r\n        \"currency\": \"usd\",\r\n        \"product_data\": {\r\n          \"name\": \"T-Shirt\"\r\n        },\r\n        \"unit_amount\": 2000\r\n      },\r\n      \"quantity\": 1\r\n    },\r\n    {\r\n      \"price_data\": {\r\n        \"currency\": \"usd\",\r\n        \"product_data\": {\r\n          \"name\": \"Mug\"\r\n        },\r\n        \"unit_amount\": 1500\r\n      },\r\n      \"quantity\": 2\r\n    }\r\n  ],\r\n  \"mode\": \"payment\",\r\n  \"success_url\": \"https://yourwebsite.com/success?session_id=xyz\",\r\n  \"cancel_url\": \"https://yourwebsite.com/cancel\"\r\n}'),
(2, 2, 'STRIPE', '202509240919', -1995, 'EUR', '{\r\n  \"line_items\": [\r\n    {\r\n      \"price_data\": {\r\n        \"currency\": \"usd\",\r\n        \"product_data\": {\r\n          \"name\": \"T-Shirt\"\r\n        },\r\n        \"unit_amount\": 2000\r\n      },\r\n      \"quantity\": 1\r\n    },\r\n    {\r\n      \"price_data\": {\r\n        \"currency\": \"usd\",\r\n        \"product_data\": {\r\n          \"name\": \"Mug\"\r\n        },\r\n        \"unit_amount\": 1500\r\n      },\r\n      \"quantity\": 2\r\n    }\r\n  ],\r\n  \"mode\": \"payment\",\r\n  \"success_url\": \"https://yourwebsite.com/success?session_id=xzy\",\r\n  \"cancel_url\": \"https://yourwebsite.com/cancel\"\r\n}');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `BPAYMENTS`
--
ALTER TABLE `BPAYMENTS`
  ADD PRIMARY KEY (`BID`),
  ADD KEY `BUID` (`BUID`),
  ADD KEY `BDATE` (`BDATE`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `BPAYMENTS`
--
ALTER TABLE `BPAYMENTS`
  MODIFY `BID` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
