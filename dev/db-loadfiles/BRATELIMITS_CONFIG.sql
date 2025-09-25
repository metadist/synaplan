-- Rate Limiting Configuration using existing BCONFIG table
-- Integrates with existing BUSERLEVEL: Pro, Team, Business
-- Pattern: BGROUP = 'RATELIMITS_[LEVEL]', BSETTING = '[TYPE]_[TIMEFRAME]S', BVALUE = limit

-- Pro Level Limits (Entry level - most restrictive for expensive operations)
INSERT INTO `BCONFIG` (`BOWNERID`, `BGROUP`, `BSETTING`, `BVALUE`) VALUES
(0, 'RATELIMITS_PRO', 'MESSAGES_120S', '15'),       -- 15 messages per 2 minutes
(0, 'RATELIMITS_PRO', 'MESSAGES_3600S', '100'),     -- 100 messages per hour
(0, 'RATELIMITS_PRO', 'IMAGES_2592000S', '20'),     -- 20 images per month (30 days)
(0, 'RATELIMITS_PRO', 'VIDEOS_2592000S', '5'),      -- 5 videos per month 
(0, 'RATELIMITS_PRO', 'AUDIOS_2592000S', '10'),     -- 10 audio generations per month
(0, 'RATELIMITS_PRO', 'FILE_ANALYSIS_86400S', '10'), -- 10 file analyses per day
(0, 'RATELIMITS_PRO', 'FILEBYTES_3600S', '20971520'), -- 20MB per hour
(0, 'RATELIMITS_PRO', 'APICALLS_3600S', '150'),     -- 150 API calls per hour

-- Team Level Limits (Medium tier - reasonable for small teams)
(0, 'RATELIMITS_TEAM', 'MESSAGES_120S', '30'),       -- 30 messages per 2 minutes
(0, 'RATELIMITS_TEAM', 'MESSAGES_3600S', '200'),     -- 200 messages per hour
(0, 'RATELIMITS_TEAM', 'IMAGES_2592000S', '100'),    -- 100 images per month
(0, 'RATELIMITS_TEAM', 'VIDEOS_2592000S', '20'),     -- 20 videos per month
(0, 'RATELIMITS_TEAM', 'AUDIOS_2592000S', '50'),     -- 50 audio generations per month
(0, 'RATELIMITS_TEAM', 'FILE_ANALYSIS_86400S', '50'), -- 50 file analyses per day
(0, 'RATELIMITS_TEAM', 'FILEBYTES_3600S', '104857600'), -- 100MB per hour
(0, 'RATELIMITS_TEAM', 'APICALLS_3600S', '500'),     -- 500 API calls per hour

-- Business Level Limits (Highest tier - generous for enterprise use)
(0, 'RATELIMITS_BUSINESS', 'MESSAGES_120S', '100'),   -- 100 messages per 2 minutes
(0, 'RATELIMITS_BUSINESS', 'MESSAGES_3600S', '1000'), -- 1000 messages per hour
(0, 'RATELIMITS_BUSINESS', 'IMAGES_2592000S', '500'), -- 500 images per month
(0, 'RATELIMITS_BUSINESS', 'VIDEOS_2592000S', '100'), -- 100 videos per month
(0, 'RATELIMITS_BUSINESS', 'AUDIOS_2592000S', '300'), -- 300 audio generations per month
(0, 'RATELIMITS_BUSINESS', 'FILE_ANALYSIS_86400S', '200'), -- 200 file analyses per day
(0, 'RATELIMITS_BUSINESS', 'FILEBYTES_3600S', '1073741824'), -- 1GB per hour
(0, 'RATELIMITS_BUSINESS', 'APICALLS_3600S', '2000'), -- 2k API calls per hour

-- NEW User Limits (Very restrictive for cost control)
(0, 'RATELIMITS_NEW', 'MESSAGES_120S', '5'),         -- 5 messages per 2 minutes
(0, 'RATELIMITS_NEW', 'MESSAGES_3600S', '30'),       -- 30 messages per hour
(0, 'RATELIMITS_NEW', 'IMAGES_2592000S', '10'),      -- 10 images per month (like you wanted)
(0, 'RATELIMITS_NEW', 'VIDEOS_2592000S', '3'),       -- 3 videos per month (like you wanted)
(0, 'RATELIMITS_NEW', 'AUDIOS_2592000S', '5'),       -- 5 audio generations per month  
(0, 'RATELIMITS_NEW', 'FILE_ANALYSIS_86400S', '5'),  -- 5 file analyses per day
(0, 'RATELIMITS_NEW', 'FILEBYTES_3600S', '10485760'), -- 10MB per hour
(0, 'RATELIMITS_NEW', 'APICALLS_3600S', '50'),       -- 50 API calls per hour

-- Widget/Anonymous User Limits (Very restrictive)
(0, 'RATELIMITS_WIDGET', 'MESSAGES_300S', '10'),       -- 3 messages per 5 minutes for anonymous
(0, 'RATELIMITS_WIDGET', 'MESSAGES_3600S', '100'),     -- 10 messages per hour for anonymous
(0, 'RATELIMITS_WIDGET', 'FILEBYTES_3600S', '5242880'), -- 5MB per hour for anonymous

-- NOTE: No DEFAULT limits needed - users are either NEW or have specific subscription plans

-- Feature Flags for Smart Rate Limiting
(0, 'SYSTEM_FLAGS', 'SMART_RATE_LIMITING_ENABLED', '1'),
(0, 'SYSTEM_FLAGS', 'RATE_LIMITING_DEBUG_MODE', '0');
