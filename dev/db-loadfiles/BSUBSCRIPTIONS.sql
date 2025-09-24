-- Subscription Plans and Pricing Configuration
-- Defines available subscription tiers and their pricing

DROP TABLE IF EXISTS `BSUBSCRIPTIONS`;
CREATE TABLE `BSUBSCRIPTIONS` (
  `BID` bigint(20) NOT NULL AUTO_INCREMENT,
  `BNAME` varchar(64) NOT NULL,
  `BLEVEL` varchar(32) NOT NULL COMMENT 'Rate limiting level: NEW, PRO, TEAM, BUSINESS',
  `BPRICE_MONTHLY` decimal(10,2) NOT NULL COMMENT 'Monthly price in EUR',
  `BPRICE_YEARLY` decimal(10,2) NOT NULL COMMENT 'Yearly price in EUR', 
  `BDESCRIPTION` text NOT NULL,
  `BACTIVE` tinyint(1) NOT NULL DEFAULT 1,
  `BSTRIPE_MONTHLY_ID` varchar(128) DEFAULT NULL COMMENT 'Stripe price ID for monthly',
  `BSTRIPE_YEARLY_ID` varchar(128) DEFAULT NULL COMMENT 'Stripe price ID for yearly',
  PRIMARY KEY (`BID`),
  KEY `BLEVEL` (`BLEVEL`),
  KEY `BACTIVE` (`BACTIVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Subscription Plans Data
INSERT INTO `BSUBSCRIPTIONS` (`BID`, `BNAME`, `BLEVEL`, `BPRICE_MONTHLY`, `BPRICE_YEARLY`, `BDESCRIPTION`, `BACTIVE`, `BSTRIPE_MONTHLY_ID`, `BSTRIPE_YEARLY_ID`) VALUES
(1, 'Free Plan', 'NEW', 0.00, 0.00, 'Basic free tier with limited usage', 1, NULL, NULL),
(2, 'Pro Plan', 'PRO', 19.95, 199.50, 'Professional plan with increased limits', 1, 'price_stripe_pro_monthly', 'price_stripe_pro_yearly'),
(3, 'Team Plan', 'TEAM', 49.95, 499.50, 'Team collaboration with higher limits', 1, 'price_stripe_team_monthly', 'price_stripe_team_yearly'),
(4, 'Business Plan', 'BUSINESS', 99.95, 999.50, 'Enterprise-grade with maximum limits', 1, 'price_stripe_business_monthly', 'price_stripe_business_yearly');
