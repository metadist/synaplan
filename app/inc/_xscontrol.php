<?php
class XSControl {
    // count the messages the user sent in the last x seconds
    public static function countIn($userId, $secondsCount):int {
        $timeFrame = time() - $secondsCount;
        $countSQL = "SELECT COUNT(*) XSCOUNT FROM BUSELOG WHERE BUSERID = ".($userId)." AND BTIMESTAMP > ".($timeFrame);
        $countRes = db::Query($countSQL);
        $countArr = db::FetchArr($countRes);
        return $countArr["XSCOUNT"];
    }

    // count specific operation types the user sent in the last x seconds
    public static function countInByType($userId, $secondsCount, $operationType):int {
        $timeFrame = time() - $secondsCount;
        $countSQL = "SELECT COUNT(*) XSCOUNT FROM BUSELOG WHERE BUSERID = ".($userId)." AND BTIMESTAMP > ".($timeFrame)." AND BOPERATIONTYPE = '".DB::EscString($operationType)."'";
        $countRes = db::Query($countSQL);
        $countArr = db::FetchArr($countRes);
        return $countArr["XSCOUNT"];
    }

    // update operation type in BUSELOG after sorting is complete
    public static function updateOperationType($userId, $msgId, $operationType):bool {
        $updateSQL = "UPDATE BUSELOG SET BOPERATIONTYPE = '".DB::EscString($operationType)."' WHERE BUSERID = ".intval($userId)." AND BMSGID = ".intval($msgId);
        $updateRes = db::Query($updateSQL);
        return $updateRes !== false;
    }

    /**
     * Get user's subscription level from BUSER table (primary source)
     * Fast lookup - BUSERLEVEL is the authoritative source for rate limits
     */
    public static function getUserSubscriptionLevel($userId): string {
        try {
            // Primary: Get BUSERLEVEL from BUSER table (fastest)
            $userSQL = "SELECT BUSERLEVEL FROM BUSER WHERE BID = " . intval($userId);
            $userResult = db::Query($userSQL);
            $userRow = db::FetchArr($userResult);
            
            if ($userRow && !empty($userRow['BUSERLEVEL'])) {
                return strtoupper($userRow['BUSERLEVEL']);
            }
            
            // Default to NEW if not set
            return 'NEW';
            
        } catch (Exception $e) {
            error_log("Error getting user subscription level: " . $e->getMessage());
            return 'NEW'; // Safe fallback
        }
    }

    /**
     * Get subscription end timestamp for countdown timer
     * Uses BPAYMENTDETAILS JSON for accurate expiry dates
     */
    public static function getSubscriptionEndTimestamp($userId): int {
        try {
            $paymentSQL = "SELECT BPAYMENTDETAILS FROM BUSER WHERE BID = " . intval($userId);
            $paymentResult = db::Query($paymentSQL);
            $paymentRow = db::FetchArr($paymentResult);
            
            if ($paymentRow && !empty($paymentRow['BPAYMENTDETAILS'])) {
                $paymentDetails = json_decode($paymentRow['BPAYMENTDETAILS'], true);
                
                if (isset($paymentDetails['end_timestamp'])) {
                    return intval($paymentDetails['end_timestamp']);
                }
            }
            
            // Fallback: Return current time + 30 days for NEW users
            return time() + (30 * 24 * 60 * 60);
            
        } catch (Exception $e) {
            error_log("Error getting subscription end timestamp: " . $e->getMessage());
            return time() + (30 * 24 * 60 * 60); // Safe fallback
        }
    }

    /**
     * Calculate the correct reset time for rate limits based on subscription billing cycle
     */
    public static function getCorrectResetTime($userId, $timeframe): int {
        try {
            $paymentSQL = "SELECT BPAYMENTDETAILS FROM BUSER WHERE BID = " . intval($userId);
            $paymentResult = db::Query($paymentSQL);
            $paymentRow = db::FetchArr($paymentResult);
            
            if ($paymentRow && !empty($paymentRow['BPAYMENTDETAILS'])) {
                $paymentDetails = json_decode($paymentRow['BPAYMENTDETAILS'], true);
                if ($paymentDetails && isset($paymentDetails['start_timestamp']) && isset($paymentDetails['end_timestamp'])) {
                    $startTime = intval($paymentDetails['start_timestamp']);
                    $endTime = intval($paymentDetails['end_timestamp']);
                    $currentTime = time();
                    
                    // For monthly limits (30+ days), use subscription cycle
                    if ($timeframe >= 2592000) { // 30 days or more
                        // If we're within subscription period, reset at end_timestamp
                        if ($currentTime <= $endTime) {
                            return $endTime;
                        } else {
                            // Subscription expired, calculate next monthly cycle
                            $billingCycle = $endTime - $startTime; // Original subscription duration
                            $monthsSinceEnd = ceil(($currentTime - $endTime) / $billingCycle);
                            return $endTime + ($monthsSinceEnd * $billingCycle);
                        }
                    }
                    
                    // For shorter periods (daily, hourly), calculate next interval
                    if ($timeframe >= 86400) { // Daily limits
                        // Reset at start of next day based on subscription timezone
                        $nextDayStart = strtotime('tomorrow 00:00:00', $currentTime);
                        return $nextDayStart;
                    } else {
                        // For hourly/minute limits, use standard timeframe
                        return $currentTime + $timeframe;
                    }
                }
            }
            
            // Fallback for users without subscription details
            if ($timeframe >= 2592000) {
                // Monthly fallback: next month from now
                return strtotime('+1 month', time());
            } else {
                return time() + $timeframe;
            }
            
        } catch (Exception $e) {
            error_log("Error calculating reset time: " . $e->getMessage());
            return time() + $timeframe; // Safe fallback
        }
    }

    /**
     * Get intelligent rate limit message based on subscription status
     */
    public static function getIntelligentRateLimitMessage($userId, $operationType, $currentCount, $maxCount, $timeframe): array {
        try {
            $paymentSQL = "SELECT BPAYMENTDETAILS, BUSERLEVEL FROM BUSER WHERE BID = " . intval($userId);
            $paymentResult = db::Query($paymentSQL);
            $paymentRow = db::FetchArr($paymentResult);
            
            $subscriptionStatus = 'unknown';
            $plan = 'NEW';
            
            if ($paymentRow && !empty($paymentRow['BPAYMENTDETAILS'])) {
                $paymentDetails = json_decode($paymentRow['BPAYMENTDETAILS'], true);
                if ($paymentDetails) {
                    $subscriptionStatus = $paymentDetails['status'] ?? 'unknown';
                    $plan = $paymentDetails['plan'] ?? $paymentRow['BUSERLEVEL'] ?? 'NEW';
                }
            }
            
            // Use correct reset time based on billing cycle
            $correctResetTime = self::getCorrectResetTime($userId, $timeframe);
            $currentTime = time();
            $isSubscriptionValid = isset($paymentDetails['end_timestamp']) && 
                                   intval($paymentDetails['end_timestamp']) > $currentTime;
            $timeRemaining = max(0, $correctResetTime - $currentTime);
            
            // Generate base message
            $operationName = ucfirst($operationType);
            if ($operationType === 'IMAGES') $operationName = 'Image generation';
            elseif ($operationType === 'VIDEOS') $operationName = 'Video generation';
            elseif ($operationType === 'AUDIOS') $operationName = 'Audio generation';
            elseif ($operationType === 'FILE_ANALYSIS') $operationName = 'File analysis';
            
            $timeframeText = self::formatTimeframe($timeframe);
            $baseMessage = "$operationName limit exceeded: $currentCount/$maxCount per $timeframeText";
            
            // Determine message type and action based on status and validity
            if ($subscriptionStatus === 'active' && $isSubscriptionValid) {
                // Active subscription with valid end date - suggest upgrade
                return [
                    'message' => $baseMessage,
                    'action_type' => 'upgrade',
                    'action_message' => 'Need higher limits? 🚀 Upgrade your plan',
                    'action_url' => 'https://www.synaplan.com/pricing',
                    'reset_time' => $correctResetTime,
                    'reset_time_formatted' => self::formatTimeRemaining($timeRemaining)
                ];
            } elseif ($subscriptionStatus === 'deactive' && $isSubscriptionValid) {
                // Cancelled/paused subscription but still within valid period
                return [
                    'message' => $baseMessage,
                    'action_type' => 'reactivate',
                    'action_message' => 'Subscription paused/cancelled. 🔄 Reactivate subscription',
                    'action_url' => 'https://www.synaplan.com/account',
                    'reset_time' => $correctResetTime,
                    'reset_time_formatted' => self::formatTimeRemaining($timeRemaining)
                ];
            } elseif ($subscriptionStatus === 'deactive' && !$isSubscriptionValid) {
                // Expired subscription
                return [
                    'message' => $baseMessage,
                    'action_type' => 'renew',
                    'action_message' => 'Subscription expired. 🆕 Subscribe to continue',
                    'action_url' => 'https://www.synaplan.com/pricing',
                    'reset_time' => $correctResetTime,
                    'reset_time_formatted' => 'expired'
                ];
            } else {
                // Fallback for unknown status or NEW users
                return [
                    'message' => $baseMessage,
                    'action_type' => 'upgrade',
                    'action_message' => 'Need higher limits? 🚀 Upgrade your plan',
                    'action_url' => 'https://www.synaplan.com/pricing',
                    'reset_time' => $correctResetTime,
                    'reset_time_formatted' => self::formatTimeRemaining($timeRemaining)
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error getting intelligent rate limit message: " . $e->getMessage());
            // Safe fallback
            return [
                'message' => "Rate limit exceeded: $currentCount/$maxCount",
                'action_type' => 'upgrade',
                'action_message' => 'Need higher limits? 🚀 Upgrade your plan',
                'action_url' => 'https://www.synaplan.com/pricing',
                'reset_time' => time() + (30 * 24 * 60 * 60),
                'reset_time_formatted' => '30 days'
            ];
        }
    }

    /**
     * Format time remaining in a human-readable way
     */
    private static function formatTimeRemaining($seconds): string {
        if ($seconds <= 0) return "now";
        
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        if ($days > 0) {
            return $days . "d " . $hours . "h";
        } elseif ($hours > 0) {
            return $hours . "h " . $minutes . "m";
        } else {
            return $minutes . "m";
        }
    }
    
    /**
     * Format timeframe in a human-readable way
     */
    private static function formatTimeframe($seconds): string {
        if ($seconds >= 86400) {
            return floor($seconds / 86400) . " day(s)";
        } elseif ($seconds >= 3600) {
            return floor($seconds / 3600) . " hour(s)";
        } else {
            return floor($seconds / 60) . " minute(s)";
        }
    }

    /**
     * Get operation type mapping from BCAPABILITIES table dynamically
     * Maps rate limit categories (IMAGES, VIDEOS, etc.) to actual capability BKEY values
     */
    public static function getOperationMappingFromCapabilities(): array {
        static $mapping = null;
        
        if ($mapping === null) {
            $mapping = [];
            
            try {
                // Query BCAPABILITIES to get mapping from rate limit categories to BKEY values
                $sql = "SELECT BRATELIMIT_CATEGORY, BKEY FROM BCAPABILITIES WHERE BRATELIMIT_CATEGORY IS NOT NULL";
                $result = db::Query($sql);
                
                // Priority mapping for main operation types
                $priorityMapping = [
                    'IMAGES' => ['text2pic', 'pic2pic'],
                    'VIDEOS' => ['text2vid', 'pic2vid'], 
                    'AUDIOS' => ['text2sound', 'text2music'],
                    'FILE_ANALYSIS' => ['pic2text', 'sound2text', 'analyze'],
                    'MESSAGES' => ['chat', 'translate']
                ];
                
                while ($row = db::FetchArr($result)) {
                    $category = $row['BRATELIMIT_CATEGORY'];
                    $bkey = $row['BKEY'];
                    
                    // Map to appropriate BUSELOG operation type with priority
                    switch($category) {
                        case 'IMAGES':
                        case 'VIDEOS':
                        case 'AUDIOS':
                            // Use priority mapping to pick the primary operation type
                            if (!isset($mapping[$category]) && in_array($bkey, $priorityMapping[$category])) {
                                $mapping[$category] = $bkey;
                            }
                            break;
                        case 'FILE_ANALYSIS':
                            $mapping[$category] = 'analyzefile'; // Unified under analyzefile
                            break;
                        case 'MESSAGES':
                            $mapping[$category] = 'general';
                            break;
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to load operation mapping from BCAPABILITIES: " . $e->getMessage());
            }
            
            // Fallback to hardcoded if DB query fails
            if (empty($mapping)) {
                $mapping = [
                    'IMAGES' => 'text2pic',
                    'VIDEOS' => 'text2vid', 
                    'AUDIOS' => 'text2sound',
                    'FILE_ANALYSIS' => 'analyzefile',
                    'MESSAGES' => 'general'
                ];
            }
        }
        
        return $mapping;
    }

    // method to give a "block" yes/no answer, if the user has sent too many messages in the last x seconds
    // Enhanced with smart rate limiting - backward compatible
    // DEPRECATED: Use checkMessagesLimit() or checkOperationLimit() instead
    public static function isLimited($msgArr, $secondsCount = null, $maxCount = null):bool {
        // Fallback for legacy code - redirect to new system
        if ($secondsCount === null && $maxCount === null) {
            $messageCheck = self::checkMessagesLimit($msgArr['BUSERID']);
            return is_array($messageCheck) && $messageCheck['limited'];
        }
        
        // Legacy direct check
        $count = self::countIn($msgArr['BUSERID'], $secondsCount);
        return $count >= $maxCount;
    }

    // simple count method: puts details into the database into the BUSELOG table
    // call as XSControl::countThis($userId, $msgId)
    public static function countThis($userId, $msgId, $operationType = 'general'):int {
        $newSQL = "INSERT INTO BUSELOG (BID, BTIMESTAMP, BUSERID, BMSGID, BOPERATIONTYPE) VALUES (DEFAULT, ".time().", ".($userId).", ".($msgId).", '".DB::EscString($operationType)."')";
        $newRes = db::Query($newSQL);
        return db::LastId();
    }

    // create a confirmation link for a fresh user
    // and send it to the user in his/her language
    public static function createConfirmationLink($usrArr):void {
        $confirmLink = $GLOBALS["baseUrl"]."da/confirm.php?id=".$usrArr['BID']."&c=".$usrArr['DETAILS']['MAILCHECKED'];
        $msgTxt = "Welcome to Ralfs.AI BETA!<BR>\n<BR>\n";
        $msgTxt .= "Please confirm your email by clicking the link below:<BR>\n<BR>\n";
        $msgTxt .= $confirmLink;
        $msgTxt .= "<BR>\n<BR>\n";
        $msgTxt .= "Please note that this is a BETA version we are working on it!<BR>\n";
        $msgTxt .= "Best regards,<BR>\n";
        $msgTxt .= "Ralfs.AI Team<BR>\n";
        EmailService::sendEmailConfirmation($usrArr["DETAILS"]["MAIL"]);
    }

    // combined methods to count and block, if needed, uses
    // the methods above

    // basic auth methods
    public static function getBearerToken(): ?string {
        $headers = null;

        // Check different possible locations of the Authorization header.
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER['Authorization']);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            // Nginx or fast CGI
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (function_exists('getallheaders')) {
            // Fallback to getallheaders() if available
            foreach (getallheaders() as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    $headers = trim($value);
                    break;
                }
            }
        }
        // If no Authorization header found, return null
        if (empty($headers)) {
            return null;
        }
        // Extract the token from the Bearer string
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1]; // The actual JWT or Bearer token
        }
        return null;
    }

    // count the bytes of the message in and out, save it in the database in table
    // BMESSAGEMETA - BID, BMESSID, BTOKEN, BVALUE (BTOKEN = 'FILEBYTES', 'CHATBYTES' - in BMESSAGES, the BDIRECT is 'IN' or 'OUT')
    // BMESSAGES also has BFILEPATH where the file is stored
    // and BFILE>0 if there is a file.
    // It could be that messages are worked twice and FILEBYTES or CHATBYTES are already set,
    // therefore: always check if the values are set and ADD to them. Start at 0, if there is no value.
    // 
    public static function countBytes($msgArr, $FILEORTEXT='ALL', $stream = false): void {
        // Safety check: ensure BID exists before proceeding
        if (!isset($msgArr['BID']) || empty($msgArr['BID'])) {
            if($GLOBALS["debug"]) error_log("Warning: Attempted to count bytes without BID. Message array: " . json_encode($msgArr));
            return;
        }
        
        // check if the message is a file
        if($msgArr['BFILE'] == 1 AND ($FILEORTEXT == 'ALL' OR $FILEORTEXT == 'FILE')) {
            // get the file size (guard against missing files)
            $abs = rtrim(UPLOAD_DIR, '/').'/'.$msgArr['BFILEPATH'];
            $fileSize = is_file($abs) ? filesize($abs) : 0;
            // fetch the file bytes from the database
            $fileBytesSQL = "SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ".intval($msgArr['BID'])." AND BTOKEN = 'FILEBYTES'";
            $fileBytesRes = db::Query($fileBytesSQL);
            if($fileBytesArr = db::FetchArr($fileBytesRes)) {
                // add the file size to the file bytes
                $fileSize = intval($fileBytesArr['BVALUE']) + $fileSize;
                // save the file bytes to the database
                $fileBytesSQL = "UPDATE BMESSAGEMETA SET BVALUE = '".intval($fileSize)."' WHERE BMESSID = ".intval($msgArr['BID'])." AND BTOKEN = 'FILEBYTES'";
                db::Query($fileBytesSQL);
            } else {
                // save the file bytes to the database
                $fileBytesSQL = "INSERT INTO BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) VALUES (DEFAULT, ".intval($msgArr['BID']).", 'FILEBYTES', '".intval($fileSize)."')";
                db::Query($fileBytesSQL);
            }
        }
        // check if the message is a chat message
        if((strlen($msgArr['BTEXT']) > 0 OR $msgArr["BFILETEXT"] > 0) AND ($FILEORTEXT == 'ALL' OR $FILEORTEXT == 'TEXT')) {
            // get the chat bytes from the database
            $chatBytesSQL = "SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ".intval($msgArr['BID'])." AND BTOKEN = 'CHATBYTES'";
            $chatBytesRes = db::Query($chatBytesSQL);

            if($chatBytesArr = db::FetchArr($chatBytesRes)) {
                // add the chat bytes to the chat bytes
                $chatBytes = intval($chatBytesArr['BVALUE']) + strlen($msgArr['BTEXT']) + strlen($msgArr["BFILETEXT"]);
                // save the chat bytes to the database
                $chatBytesSQL = "UPDATE BMESSAGEMETA SET BVALUE = '".intval($chatBytes)."' WHERE BMESSID = ".intval($msgArr['BID'])." AND BTOKEN = 'CHATBYTES'";
                db::Query($chatBytesSQL);
            } else {
                // save the chat bytes to the database
                $chatBytes = strlen($msgArr['BTEXT']) + strlen($msgArr["BFILETEXT"]);
                $chatBytesSQL = "INSERT INTO BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) VALUES (DEFAULT, ".intval($msgArr['BID']).", 'CHATBYTES', '".intval($chatBytes)."')";
                db::Query($chatBytesSQL);
            }
        }
        // check if the message is a sort message
        if((strlen($msgArr['BTEXT']) > 0 OR $msgArr["BFILETEXT"] > 0) AND ($FILEORTEXT == 'ALL' OR $FILEORTEXT == 'SORT')) {
            // get the chat bytes from the database
            $sortBytesSQL = "SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ".intval($msgArr['BID'])." AND BTOKEN = 'SORTBYTES'";
            $sortBytesRes = db::Query($sortBytesSQL);

            if($sortBytesArr = db::FetchArr($sortBytesRes)) {
                // add the chat bytes to the chat bytes
                $sortBytes = intval($sortBytesArr['BVALUE']) + strlen($msgArr['BTEXT']) + strlen($msgArr["BFILETEXT"]);
                // save the chat bytes to the database
                $sortBytesSQL = "UPDATE BMESSAGEMETA SET BVALUE = '".intval($sortBytes)."' WHERE BMESSID = ".intval($msgArr['BID'])." AND BTOKEN = 'SORTBYTES'";
                db::Query($sortBytesSQL);
            } else {
                // save the chat bytes to the database
                $sortBytes = strlen($msgArr['BTEXT']) + strlen($msgArr["BFILETEXT"]);
                $sortBytesSQL = "INSERT INTO BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) VALUES (DEFAULT, ".intval($msgArr['BID']).", 'SORTBYTES', '".intval($sortBytes)."')";
                db::Query($sortBytesSQL);
            }
        }
    }
    // store the AI details per message
    // AI models used and how fast they answer! Use the BMESSAGEMETA table
    public static function storeAIDetails($msgArr, $modelKey, $modelValue, $stream = false): bool {
        // Safety check: ensure BID exists before proceeding
        if (!isset($msgArr['BID']) || empty($msgArr['BID'])) {
            if($GLOBALS["debug"]) error_log("Warning: Attempted to store AI details without BID. Message array: " . json_encode($msgArr));
            return false;
        }
        
        // save the AI details to the database
        $aiDetailsSQL = "INSERT INTO BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) VALUES (DEFAULT, ".intval($msgArr['BID']).", '{$modelKey}', '{$modelValue}')";
        db::Query($aiDetailsSQL);
        return true;
    }

    // ========== SMART RATE LIMITING METHODS ==========
    
    /**
     * Smart rate limiting based on BUSERLEVEL from BUSER table
     * Uses existing BCONFIG table for limit configuration
     * Supports Pro, Team, Business hierarchy
     */
    public static function isSmartLimited($msgArr): array {
        $result = [
            'limited' => false, 
            'reason' => '', 
            'limits_remaining' => [],
            'reset_time' => 0
        ];
        
        try {
            // Check if rate limiting is enabled via database configuration
            if (!self::isRateLimitingEnabled()) {
                return $result; // Not limited if feature disabled
            }
            
            // Get user limits based on BUSERLEVEL
            $limits = self::getUserLimits($msgArr['BUSERID']);
            if (empty($limits)) {
                if($GLOBALS["debug"]) error_log("No rate limits found for user: " . $msgArr['BUSERID']);
                // Don't block if no limits configured - allow through
                return $result; // limited = false
            }
            
            // Check all configured limits
            foreach ($limits as $setting => $maxCount) {
                if (preg_match('/^(\w+)_(\d+)S$/', $setting, $matches)) {
                    $limitType = $matches[1]; // MESSAGES, FILEBYTES, APICALLS
                    $timeframe = intval($matches[2]); // seconds
                    
                    $limitCheck = self::checkSpecificLimit($msgArr, $limitType, $timeframe, $maxCount);
                    if ($limitCheck['exceeded']) {
                        $result['limited'] = true;
                        $result['reason'] = $limitCheck['reason'];
                        $result['reset_time'] = time() + $timeframe;
                        
                        // Store rate limit event in BMESSAGEMETA if BID available
                        if (isset($msgArr['BID']) && !empty($msgArr['BID'])) {
                            self::storeAIDetails($msgArr, 'RATE_LIMIT_EXCEEDED', $limitCheck['reason'], false);
                        }
                        
                        return $result;
                    }
                    
                    $result['limits_remaining'][$setting] = $maxCount - $limitCheck['current_count'];
                }
            }
            
        } catch (Exception $e) {
            if($GLOBALS["debug"]) error_log("Smart rate limiting error: " . $e->getMessage());
            // Fallback to safe default
            $fallbackCount = self::countIn($msgArr['BUSERID'], 120);
            if ($fallbackCount >= 5) {
                $result['limited'] = true;
                $result['reason'] = 'Fallback limit exceeded (5 messages per 2 minutes)';
            }
        }
        
        return $result;
    }
    
    /**
     * Get user-specific rate limits from BCONFIG table
     * Based on BUSERLEVEL: Pro, Team, Business
     */
    public static function getUserLimits($userId): array {
        $limits = [];
        
        try {
            // Handle widget/anonymous users
            if (isset($_SESSION["is_widget"]) && $_SESSION["is_widget"] === true) {
                $configSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'RATELIMITS_WIDGET' AND BOWNERID = 0";
                $configRes = DB::Query($configSQL);
                while ($row = DB::FetchArr($configRes)) {
                    $limits[$row['BSETTING']] = intval($row['BVALUE']);
                }
                return $limits;
            }
            
            // Get user level from payment-based subscription system
            $userLevel = strtoupper(self::getUserSubscriptionLevel($userId));
            
            // Load limits from BCONFIG for this user level
            $configSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'RATELIMITS_" . DB::EscString($userLevel) . "' AND BOWNERID = 0";
            $configRes = DB::Query($configSQL);
            
            while ($row = DB::FetchArr($configRes)) {
                $limits[$row['BSETTING']] = intval($row['BVALUE']);
            }
            
            // Fallback to DEFAULT limits if no specific limits found
            if (empty($limits) && $userLevel !== 'DEFAULT') {
                $defaultSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'RATELIMITS_DEFAULT' AND BOWNERID = 0";
                $defaultRes = DB::Query($defaultSQL);
                while ($row = DB::FetchArr($defaultRes)) {
                    $limits[$row['BSETTING']] = intval($row['BVALUE']);
                }
            }
            
        } catch (Exception $e) {
            if($GLOBALS["debug"]) error_log("Error loading user limits: " . $e->getMessage());
        }
        
        return $limits;
    }
    
    /**
     * Check a specific limit type (MESSAGES, FILEBYTES, APICALLS)
     */
    public static function checkSpecificLimit($msgArr, $operation, $timeframe, $maxCount): array {
        $result = ['exceeded' => false, 'reason' => '', 'current_count' => 0];
        
        switch ($operation) {
            case 'MESSAGES':
                $currentCount = self::countIn($msgArr['BUSERID'], $timeframe);
                $result['current_count'] = $currentCount;
                if ($currentCount >= $maxCount) {
                    $result['exceeded'] = true;
                    $result['reason'] = "Message limit exceeded: {$currentCount}/{$maxCount} in {$timeframe}s";
                }
                break;
                
            case 'FILEBYTES':
                $currentBytes = self::countFileBytes($msgArr['BUSERID'], $timeframe);
                $result['current_count'] = $currentBytes;
                if ($currentBytes >= $maxCount) {
                    $result['exceeded'] = true;
                    $fileSizeMB = round($currentBytes / 1048576, 2);
                    $limitMB = round($maxCount / 1048576, 2);
                    $result['reason'] = "File size limit exceeded: {$fileSizeMB}MB/{$limitMB}MB in {$timeframe}s";
                }
                break;
                
            case 'APICALLS':
                // For API calls, we can use the same BUSELOG table with different filtering
                $currentCalls = self::countApiCalls($msgArr['BUSERID'], $timeframe);
                $result['current_count'] = $currentCalls;
                if ($currentCalls >= $maxCount) {
                    $result['exceeded'] = true;
                    $result['reason'] = "API call limit exceeded: {$currentCalls}/{$maxCount} in {$timeframe}s";
                }
                break;
                
            case 'AUDIOS':
            case 'IMAGES':
            case 'VIDEOS':
            case 'FILE_ANALYSIS':
                // Get operation type from BCAPABILITIES table dynamically
                $operationMapping = self::getOperationMappingFromCapabilities();
                $operationType = $operationMapping[$operation] ?? 'general';
                
                // Count specific operation type in BUSELOG
                $currentCount = self::countInByType($msgArr['BUSERID'], $timeframe, $operationType);
                $result['current_count'] = $currentCount;
                if ($currentCount >= $maxCount) {
                    $result['exceeded'] = true;
                    
                    // Use intelligent message based on subscription status
                    $intelligentMessage = self::getIntelligentRateLimitMessage($msgArr['BUSERID'], $operation, $currentCount, $maxCount, $timeframe);
                    $result['reason'] = $intelligentMessage['message'];
                    $result['action_type'] = $intelligentMessage['action_type'];
                    $result['action_message'] = $intelligentMessage['action_message'];
                    $result['action_url'] = $intelligentMessage['action_url'];
                    $result['reset_time'] = $intelligentMessage['reset_time'];
                    $result['reset_time_formatted'] = $intelligentMessage['reset_time_formatted'];
                }
                break;
        }
        
        return $result;
    }
    
    /**
     * Count file bytes uploaded by user in timeframe
     * Uses existing BMESSAGEMETA table FILEBYTES entries
     */
    private static function countFileBytes($userId, $timeframe): int {
        $timeFrame = time() - $timeframe;
        
        $bytesSQL = "SELECT SUM(CAST(m.BVALUE AS UNSIGNED)) as TOTAL_BYTES 
                     FROM BMESSAGEMETA m 
                     JOIN BMESSAGES b ON m.BMESSID = b.BID 
                     WHERE b.BUSERID = " . intval($userId) . " 
                     AND b.BUNIXTIMES > " . intval($timeFrame) . " 
                     AND m.BTOKEN = 'FILEBYTES'";
        
        $bytesRes = DB::Query($bytesSQL);
        $bytesArr = DB::FetchArr($bytesRes);
        
        return intval($bytesArr['TOTAL_BYTES'] ?? 0);
    }
    
    /**
     * Count API calls by user in timeframe
     * Uses BUSELOG table filtering by message type
     */
    private static function countApiCalls($userId, $timeframe): int {
        $timeFrame = time() - $timeframe;
        
        // Count messages that came through API (BMESSTYPE = 'API' or similar)
        $apiSQL = "SELECT COUNT(*) as API_COUNT 
                   FROM BUSELOG l 
                   JOIN BMESSAGES b ON l.BMSGID = b.BID 
                   WHERE l.BUSERID = " . intval($userId) . " 
                   AND l.BTIMESTAMP > " . intval($timeFrame) . " 
                   AND b.BMESSTYPE = 'API'";
        
        $apiRes = DB::Query($apiSQL);
        $apiArr = DB::FetchArr($apiRes);
        
        return intval($apiArr['API_COUNT'] ?? 0);
    }
    
    
    /**
     * Check if rate limiting is enabled via database configuration
     */
    public static function isRateLimitingEnabled(): bool {
        $flagSQL = "SELECT BVALUE FROM BCONFIG WHERE BGROUP = 'SYSTEM_FLAGS' AND BSETTING = 'SMART_RATE_LIMITING_ENABLED' LIMIT 1";
        $flagRes = DB::Query($flagSQL);
        $flagArr = DB::FetchArr($flagRes);
        
        return !empty($flagArr) && $flagArr['BVALUE'] === '1';
    }

    /**
     * Check general MESSAGES limit for API pre-filtering
     */
    public static function checkMessagesLimit($userId): bool|array {
        if (!self::isRateLimitingEnabled()) {
            return true; // Always pass if disabled
        }
        
        try {
            $limits = self::getUserLimits($userId);
            if (empty($limits)) return true;
            
            // Check all MESSAGES timeframes
            foreach ($limits as $setting => $maxCount) {
                if (preg_match('/^MESSAGES_(\d+)S$/', $setting, $matches)) {
                    $timeframe = intval($matches[1]);
                    $currentCount = self::countIn($userId, $timeframe);
                    
                    if ($currentCount >= $maxCount) {
                        // Use intelligent message for message limits too
                        $intelligentMessage = self::getIntelligentRateLimitMessage($userId, 'MESSAGES', $currentCount, $maxCount, $timeframe);
                        
                        return [
                            'limited' => true,
                            'exceeded' => true,
                            'message' => $intelligentMessage['message'],
                            'action_type' => $intelligentMessage['action_type'],
                            'action_message' => $intelligentMessage['action_message'],
                            'action_url' => $intelligentMessage['action_url'],
                            'reset_time' => $intelligentMessage['reset_time'],
                            'reset_time_formatted' => $intelligentMessage['reset_time_formatted']
                        ];
                    }
                }
            }
            
            return true; // All MESSAGES limits OK
            
        } catch (Exception $e) {
            error_log("Rate limit check error: " . $e->getMessage());
            return true; // Fail open
        }
    }

    
    /**
     * Check rate limit for specific sorted operation (post-sorting)
     * Called after sorting to check operation-specific limits
     */
    public static function checkOperationLimit($msgArr, $operation): array {
        $result = ['limited' => false, 'reason' => '', 'reset_time' => 0];
        
        if (!self::isRateLimitingEnabled()) {
            return $result;
        }
        
        try {
            $limits = self::getUserLimits($msgArr['BUSERID']);
            $operationMap = [
                'text2pic' => 'IMAGES',
                'text2vid' => 'VIDEOS', 
                'text2sound' => 'AUDIOS',
                'pic2text' => 'FILE_ANALYSIS',
                'sound2text' => 'FILE_ANALYSIS',
                'analyzefile' => 'FILE_ANALYSIS'
            ];
            
            $limitType = $operationMap[$operation] ?? null;
            if (!$limitType) return $result; // No limit for this operation
            
            foreach ($limits as $setting => $maxCount) {
                if (preg_match('/^' . $limitType . '_(\d+)S$/', $setting, $matches)) {
                    $timeframe = intval($matches[1]);
                    $limitCheck = self::checkSpecificLimit($msgArr, $limitType, $timeframe, $maxCount);
                    
                    if ($limitCheck['exceeded']) {
                        // For monthly limits, use subscription end timestamp
                        // Use correct reset time based on subscription billing cycle
                        $correctResetTime = self::getCorrectResetTime($msgArr['BUSERID'], $timeframe);
                        $timeFormatted = self::formatTimeRemaining($correctResetTime - time());
                        
                        $result['limited'] = true;
                        $result['reason'] = $limitCheck['reason'];
                        $result['reset_time'] = $correctResetTime;
                        $result['reset_time_formatted'] = $timeFormatted;
                        
                        if ($timeframe >= 2592000) { // Monthly limits
                            $result['message'] = ucfirst(strtolower($limitType)) . " generation limit exceeded: " . $limitCheck['current_count'] . "/{$maxCount} per month";
                        } else {
                            $result['message'] = $limitCheck['reason'];
                        }
                        return $result;
                    }
                }
            }
            
        } catch (Exception $e) {
            if($GLOBALS["debug"]) error_log("Error checking operation limit: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Check rate limits for any topic after sorting
     * Maps topics to appropriate limit types
     */
    public static function checkTopicLimit($msgArr): array {
        $result = ['limited' => false, 'reason' => '', 'reset_time' => 0];
        
        if (!self::isRateLimitingEnabled()) {
            return $result;
        }
        
        $topic = $msgArr['BTOPIC'] ?? 'general';
        
        // Map topics to limit categories
        switch ($topic) {
            case 'mediamaker':
                // mediamaker is handled separately before AI generation
                return $result;
                
            case 'analyzefile':
            case 'analyzepdf': // Legacy support
                // Check FILE_ANALYSIS limits instead of direct operation
                $limits = self::getUserLimits($msgArr['BUSERID']);
                if (empty($limits)) return $result;
                
                foreach ($limits as $setting => $maxCount) {
                    if (preg_match('/^FILE_ANALYSIS_(\d+)S$/', $setting, $matches)) {
                        $timeframe = intval($matches[1]);
                        $limitCheck = self::checkSpecificLimit($msgArr, 'FILE_ANALYSIS', $timeframe, $maxCount);
                        
                        if ($limitCheck['exceeded']) {
                            $correctResetTime = self::getCorrectResetTime($msgArr['BUSERID'], $timeframe);
                            $result['limited'] = true;
                            $result['reason'] = $limitCheck['reason'];
                            $result['reset_time'] = $correctResetTime;
                            break;
                        }
                    }
                }
                return $result;
                
            case 'general':
            default:
                // For general chat and all custom prompts: check message limits
                $limits = self::getUserLimits($msgArr['BUSERID']);
                
                // Check short-term message limits (2 minutes)
                if (isset($limits['MESSAGES_120S'])) {
                    $limitCheck = self::checkSpecificLimit($msgArr, 'MESSAGES', 120, $limits['MESSAGES_120S']);
                    if ($limitCheck['exceeded']) {
                        $correctResetTime = self::getCorrectResetTime($msgArr['BUSERID'], 120);
                        $result['limited'] = true;
                        $result['reason'] = $limitCheck['reason'];
                        $result['reset_time'] = $correctResetTime;
                        return $result;
                    }
                }
                
                // Check hourly message limits
                if (isset($limits['MESSAGES_3600S'])) {
                    $limitCheck = self::checkSpecificLimit($msgArr, 'MESSAGES', 3600, $limits['MESSAGES_3600S']);
                    if ($limitCheck['exceeded']) {
                        $correctResetTime = self::getCorrectResetTime($msgArr['BUSERID'], 3600);
                        $result['limited'] = true;
                        $result['reason'] = $limitCheck['reason'];
                        $result['reset_time'] = $correctResetTime;
                        return $result;
                    }
                }
                
                return $result;
        }
    }

    /**
     * Enhanced notification for smart rate limiting
     */
    public static function notifySmartLimit($msgArr, $limitCheck): void {
        $usrArr = Central::getUsrById(intval($msgArr['BUSERID']));
        
        // Personalized message based on limit reason
        $msgTxt = "Rate limit exceeded: " . $limitCheck['reason'] . "<BR><BR>\n";
        $msgTxt .= "Your current plan has usage restrictions. Consider upgrading for higher limits.<BR><BR>\n";
        $msgTxt .= "Visit: https://ralfs.ai/ for plan details<BR><BR>\n";
        
        if (!empty($limitCheck['reset_time'])) {
            $resetTimeFormatted = date('H:i:s', $limitCheck['reset_time']);
            $msgTxt .= "Limits reset at: " . $resetTimeFormatted . "<BR>\n";
        }
        
        // Use existing notification methods
        if($msgArr['BMESSTYPE'] == 'WA') {
            $waIdSQL = "select BWAIDS.* from BWAIDS, BMESSAGES 
                where BWAIDS.BMID = BMESSAGES.BID AND BMESSAGES.BUSERID = ".intval($msgArr['BUSERID'])." ORDER BY BMESSAGES.BID DESC LIMIT 1";
            $waIdRes = db::Query($waIdSQL);
            $waDetailsArr = db::FetchArr($waIdRes);

            $GLOBALS['WAtoken'] = file_get_contents(__DIR__ . '/../.keys/.watoken.txt');
            $waSender = new waSender($waDetailsArr);
            $myRes = $waSender->sendText($usrArr["BPROVIDERID"], strip_tags($msgTxt));
        }
        
        if($msgArr['BMESSTYPE'] == 'MAIL' AND isset($usrArr["DETAILS"])) {
            $sentRes = EmailService::sendLimitNotification($usrArr["DETAILS"]["MAIL"], "smart_usage", "Smart Rate Limit Exceeded");    
        }
        
        if($msgArr['BMESSTYPE'] == 'API') {
            // For API, we'll let the calling code handle the HTTP response
            return;
        }
        
        exit;
    }
}