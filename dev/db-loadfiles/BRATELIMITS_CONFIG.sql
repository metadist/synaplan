-- Rate Limiting Configuration using existing BCONFIG table
-- SIMPLIFIED: NEW = lifetime totals (no reset), PAID = hourly + monthly
-- Pattern: BGROUP = 'RATELIMITS_[LEVEL]', BSETTING = '[TYPE]_[TIMEFRAME]', BVALUE = limit

DELETE FROM BCONFIG WHERE BGROUP LIKE 'RATELIMITS_%' AND BOWNERID = 0;
DELETE FROM BCONFIG WHERE BGROUP = 'SYSTEM_FLAGS' AND BOWNERID = 0;

-- NEW User Limits (LIFETIME TOTALS - NEVER RESET)
INSERT INTO `BCONFIG` (`BOWNERID`, `BGROUP`, `BSETTING`, `BVALUE`) VALUES
(0, 'RATELIMITS_NEW', 'MESSAGES_TOTAL', '50'),       -- 50 messages total (lifetime)
(0, 'RATELIMITS_NEW', 'IMAGES_TOTAL', '5'),          -- 5 images total (lifetime)
(0, 'RATELIMITS_NEW', 'VIDEOS_TOTAL', '2'),          -- 2 videos total (lifetime)
(0, 'RATELIMITS_NEW', 'AUDIOS_TOTAL', '3'),          -- 3 audio files total (lifetime)
(0, 'RATELIMITS_NEW', 'FILE_ANALYSIS_TOTAL', '10'),  -- 10 file analyses total (lifetime)

-- Pro Level Limits (HOURLY + MONTHLY)
(0, 'RATELIMITS_PRO', 'MESSAGES_HOURLY', '100'),     -- 100 messages per hour
(0, 'RATELIMITS_PRO', 'MESSAGES_MONTHLY', '5000'),   -- 5000 messages per month
(0, 'RATELIMITS_PRO', 'IMAGES_MONTHLY', '50'),       -- 50 images per month
(0, 'RATELIMITS_PRO', 'VIDEOS_MONTHLY', '10'),       -- 10 videos per month
(0, 'RATELIMITS_PRO', 'AUDIOS_MONTHLY', '20'),       -- 20 audio files per month
(0, 'RATELIMITS_PRO', 'FILE_ANALYSIS_MONTHLY', '200'), -- 200 file analyses per month

-- Team Level Limits (HOURLY + MONTHLY)
(0, 'RATELIMITS_TEAM', 'MESSAGES_HOURLY', '300'),    -- 300 messages per hour
(0, 'RATELIMITS_TEAM', 'MESSAGES_MONTHLY', '15000'), -- 15k messages per month
(0, 'RATELIMITS_TEAM', 'IMAGES_MONTHLY', '200'),     -- 200 images per month
(0, 'RATELIMITS_TEAM', 'VIDEOS_MONTHLY', '50'),      -- 50 videos per month
(0, 'RATELIMITS_TEAM', 'AUDIOS_MONTHLY', '100'),     -- 100 audio files per month
(0, 'RATELIMITS_TEAM', 'FILE_ANALYSIS_MONTHLY', '1000'), -- 1000 file analyses per month

-- Business Level Limits (HOURLY + MONTHLY)
(0, 'RATELIMITS_BUSINESS', 'MESSAGES_HOURLY', '1000'), -- 1000 messages per hour
(0, 'RATELIMITS_BUSINESS', 'MESSAGES_MONTHLY', '50000'), -- 50k messages per month
(0, 'RATELIMITS_BUSINESS', 'IMAGES_MONTHLY', '1000'),  -- 1000 images per month
(0, 'RATELIMITS_BUSINESS', 'VIDEOS_MONTHLY', '200'),   -- 200 videos per month
(0, 'RATELIMITS_BUSINESS', 'AUDIOS_MONTHLY', '500'),   -- 500 audio files per month
(0, 'RATELIMITS_BUSINESS', 'FILE_ANALYSIS_MONTHLY', '5000'), -- 5000 file analyses per month

-- Widget/Anonymous User Limits (inherit from widget owner's plan)
-- Note: Widget users use the widget owner's rate limits, no separate limits needed

-- Feature Flags for Smart Rate Limiting
(0, 'SYSTEM_FLAGS', 'SMART_RATE_LIMITING_ENABLED', '1'),
(0, 'SYSTEM_FLAGS', 'RATE_LIMITING_DEBUG_MODE', '0');
