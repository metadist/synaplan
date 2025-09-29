<?php
class XSControl {
    
    // Central upgrade and account URLs for easy maintenance
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

    /**
     * Count operations within the current billing cycle
     * For monthly limits, this respects subscription start/end dates
     * OPTIMIZED: Skip BPAYMENTDETAILS check if BUSERLEVEL is NEW
     */
    public static function countInCurrentBillingCycle($userId, $timeframe, $operationType = null):int {
        try {
            // For monthly limits (30+ days), use subscription billing cycle
            if ($timeframe >= 2592000) {
                // OPTIMIZATION: Check BUSERLEVEL first before parsing BPAYMENTDETAILS
                $paymentSQL = "SELECT BUSERLEVEL, BPAYMENTDETAILS FROM BUSER WHERE BID = " . intval($userId);
                $paymentResult = db::Query($paymentSQL);
                $paymentRow = db::FetchArr($paymentResult);
                
                // If user is NEW, skip BPAYMENTDETAILS parsing entirely
                if ($paymentRow && $paymentRow['BUSERLEVEL'] === 'NEW') {
                    // For NEW users, use simple timeframe-based counting
                    $timeStart = time() - $timeframe;
                    
                    if ($operationType) {
                        $countSQL = "SELECT COUNT(*) XSCOUNT FROM BUSELOG WHERE BUSERID = " . intval($userId) . 
                                   " AND BOPERATIONTYPE = '" . db::EscString($operationType) . "'" .
                                   " AND BTIMESTAMP >= " . $timeStart .
                                   " AND BSUBSCRIPTION_ID IS NULL";
                    } else {
                        $countSQL = "SELECT COUNT(*) XSCOUNT FROM BUSELOG WHERE BUSERID = " . intval($userId) . 
                                   " AND BTIMESTAMP >= " . $timeStart .
                                   " AND BSUBSCRIPTION_ID IS NULL";
                    }
                    
                    $countRes = db::Query($countSQL);
                    $countArr = db::FetchArr($countRes);
                    return intval($countArr["XSCOUNT"]);
                }
                
                // For non-NEW users, parse BPAYMENTDETAILS
                if ($paymentRow && !empty($paymentRow['BPAYMENTDETAILS'])) {
                    $paymentDetails = json_decode($paymentRow['BPAYMENTDETAILS'], true);
                    if ($paymentDetails && isset($paymentDetails['start_timestamp']) && isset($paymentDetails['end_timestamp'])) {
                        $startTime = intval($paymentDetails['start_timestamp']);
                        $endTime = intval($paymentDetails['end_timestamp']);
                        $currentTime = time();
                        
                        // Calculate the current billing cycle period
                        $billingCycleLength = $endTime - $startTime;
                        
                        if ($currentTime <= $endTime) {
                            // Within current subscription period
                            $periodStart = $startTime;
                        } else {
                            // Subscription expired, find current cycle
                            $cyclesSinceEnd = floor(($currentTime - $endTime) / $billingCycleLength);
                            $periodStart = $endTime + ($cyclesSinceEnd * $billingCycleLength);
                        }
                        
                        // Determine if user is currently using subscription benefits
                        $currentSubscriptionId = $paymentDetails['stripe_subscription_id'] ?? null;
                        $subscriptionStatus = $paymentDetails['status'] ?? null;
                        $isActiveSubscription = ($subscriptionStatus === 'active' && $currentTime <= $endTime);
                        
                        if ($operationType) {
                            $countSQL = "SELECT COUNT(*) XSCOUNT FROM BUSELOG WHERE BUSERID = " . intval($userId) . 
                                       " AND BOPERATIONTYPE = '" . db::EscString($operationType) . "'";
                        } else {
                            $countSQL = "SELECT COUNT(*) XSCOUNT FROM BUSELOG WHERE BUSERID = " . intval($userId);
                        }
                        
                        // Add subscription filter based on ACTUAL subscription status
                        if ($isActiveSubscription && $currentSubscriptionId) {
                            // Active subscription: count only current subscription usage
                            $countSQL .= " AND BSUBSCRIPTION_ID = '" . db::EscString($currentSubscriptionId) . "'";
                        } else {
                            // Inactive/expired/cancelled subscription: count only free usage (NULL subscription_id)
                            $countSQL .= " AND BSUBSCRIPTION_ID IS NULL";
                        }
                        
                        $countRes = db::Query($countSQL);
                        $countArr = db::FetchArr($countRes);
                        return intval($countArr["XSCOUNT"]);
                    }
                }
            }
            
            // For shorter periods or fallback, use traditional time-based counting
            if ($operationType) {
                return self::countInByType($userId, $timeframe, $operationType);
            } else {
                return self::countIn($userId, $timeframe);
            }
            
        } catch (Exception $e) {
            error_log("Error counting in billing cycle: " . $e->getMessage());
            // Fallback to traditional counting
            if ($operationType) {
                return self::countInByType($userId, $timeframe, $operationType);
            } else {
                return self::countIn($userId, $timeframe);
            }
        }
    }

    // update operation type in BUSELOG after sorting is complete
    /**
     * Get intelligent rate limit message for NEW users based on subscription history
     */
    private static function getIntelligentMessageForNewUser($userId, $limitType, $currentCount, $maxCount): array {
        try {
            $userSQL = "SELECT BPAYMENTDETAILS FROM BUSER WHERE BID = " . intval($userId);
            $userResult = db::Query($userSQL);
            $userRow = db::FetchArr($userResult);
            
            $result = [
                'reset_time' => 0,
                'reset_time_formatted' => 'never',
                'action_type' => 'upgrade',
                'action_message' => 'Get unlimited access with a subscription 🚀',
                'action_url' => ApiKeys::getPricingUrl()
            ];
            
            if ($userRow && !empty($userRow['BPAYMENTDETAILS'])) {
                $paymentDetails = json_decode($userRow['BPAYMENTDETAILS'], true);
                if ($paymentDetails && is_array($paymentDetails)) {
                    $status = $paymentDetails['status'] ?? null;
                    $plan = $paymentDetails['plan'] ?? null;
                    $autoRenew = $paymentDetails['auto_renew'] ?? false;
                    $endTimestamp = $paymentDetails['end_timestamp'] ?? null;
                    $currentTime = time();
                    
                    if ($status === 'deactive') {
                        // User cancelled/paused subscription
                        $result['action_type'] = 'reactivate';
                        $result['action_message'] = 'Reactivate your ' . $plan . ' subscription for unlimited access';
                        $result['action_url'] = ApiKeys::getAccountUrl();
                    } else if ($status === 'active' && $endTimestamp && $currentTime > $endTimestamp) {
                        // Subscription expired
                        if ($autoRenew) {
                            $result['action_type'] = 'renew';
                            $result['action_message'] = 'Renewal failed. Please update your payment method';
                            $result['action_url'] = ApiKeys::getAccountUrl();
                        } else {
                            $result['action_type'] = 'renew';
                            $result['action_message'] = 'Your ' . $plan . ' subscription expired. Renew or enable auto-renew';
                            $result['action_url'] = ApiKeys::getPricingUrl();
                        }
                    } else if ($plan && $plan !== 'NEW') {
                        // Had a subscription but something else went wrong
                        $result['action_type'] = 'reactivate';
                        $result['action_message'] = 'Reactivate your ' . $plan . ' subscription';
                        $result['action_url'] = ApiKeys::getAccountUrl();
                    }
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error getting intelligent message for NEW user: " . $e->getMessage());
            // Return default message
            return [
                'reset_time' => 0,
                'reset_time_formatted' => 'never',
                'action_type' => 'upgrade',
                'action_message' => 'Get unlimited access with a subscription 🚀',
                'action_url' => ApiKeys::getPricingUrl()
            ];
        }
    }

    /**
     * Check if user has an active subscription
     * OPTIMIZED: Skip BPAYMENTDETAILS check if BUSERLEVEL is NEW
     */
    private static function isActiveSubscription($userId): bool {
        try {
            // First check BUSERLEVEL - if NEW, guaranteed no active subscription
            $userSQL = "SELECT BUSERLEVEL, BPAYMENTDETAILS FROM BUSER WHERE BID = " . intval($userId);
            $userResult = db::Query($userSQL);
            
            if (!$userRow = db::FetchArr($userResult)) {
                return false;
            }
            
            // OPTIMIZATION: If user level is NEW, they cannot have an active subscription
            if ($userRow['BUSERLEVEL'] === 'NEW') {
                return false;
            }
            
            // Only parse BPAYMENTDETAILS for non-NEW users (saves LONGTEXT parsing)
            $paymentDetails = json_decode($userRow['BPAYMENTDETAILS'], true);
            if (!$paymentDetails || !is_array($paymentDetails)) {
                return false;
            }
            
            $subscriptionStatus = $paymentDetails['status'] ?? null;
            $endTimestamp = $paymentDetails['end_timestamp'] ?? null;
            $currentTime = time();
            
            return ($subscriptionStatus === 'active' && $endTimestamp && $currentTime <= $endTimestamp);
            
        } catch (Exception $e) {
            if($GLOBALS["debug"]) error_log("Error checking active subscription: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current user's subscription ID for BUSELOG tracking
     * Only returns ID if subscription is actually active AND user level is not NEW
     */
    private static function getCurrentSubscriptionId($userId): ?string {
        try {
            $userSQL = "SELECT BUSERLEVEL, BPAYMENTDETAILS FROM BUSER WHERE BID = " . intval($userId);
            $userResult = db::Query($userSQL);
            $userRow = db::FetchArr($userResult);
            
            if (!$userRow) {
                return null;
            }
            
            // If user level is NEW, always return NULL (treat as free user)
            if ($userRow['BUSERLEVEL'] === 'NEW') {
                return null;
            }
            
            if (!empty($userRow['BPAYMENTDETAILS'])) {
                $paymentDetails = json_decode($userRow['BPAYMENTDETAILS'], true);
                if ($paymentDetails && isset($paymentDetails['stripe_subscription_id'])) {
                    // Only return subscription ID if subscription is active
                    $subscriptionStatus = $paymentDetails['status'] ?? null;
                    $endTimestamp = $paymentDetails['end_timestamp'] ?? null;
                    $currentTime = time();
                    
                    $isActive = ($subscriptionStatus === 'active' && $endTimestamp && $currentTime <= $endTimestamp);
                    
                    if ($isActive) {
                        return $paymentDetails['stripe_subscription_id'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error getting subscription ID: " . $e->getMessage());
        }
        
        return null;
    }
    
    public static function updateOperationType($userId, $msgId, $operationType):bool {
        $subscriptionId = self::getCurrentSubscriptionId($userId);
        
        $updateSQL = "UPDATE BUSELOG SET BOPERATIONTYPE = '".DB::EscString($operationType)."', BSUBSCRIPTION_ID = " . ($subscriptionId ? "'".DB::EscString($subscriptionId)."'" : "NULL") . " WHERE BUSERID = ".intval($userId)." AND BMSGID = ".intval($msgId);
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
                    'action_url' => ApiKeys::getUpgradeUrl(),
                    'reset_time' => $correctResetTime,
                    'reset_time_formatted' => self::formatTimeRemaining($timeRemaining)
                ];
            } elseif ($subscriptionStatus === 'deactive' && $isSubscriptionValid) {
                // Cancelled/paused subscription but still within valid period
                return [
                    'message' => $baseMessage,
                    'action_type' => 'reactivate',
                    'action_message' => 'Subscription paused/cancelled. 🔄 Reactivate subscription',
                    'action_url' => ApiKeys::getAccountUrl(),
                    'reset_time' => $correctResetTime,
                    'reset_time_formatted' => self::formatTimeRemaining($timeRemaining)
                ];
            } elseif ($subscriptionStatus === 'deactive' && !$isSubscriptionValid) {
                // Expired subscription
                return [
                    'message' => $baseMessage,
                    'action_type' => 'renew',
                    'action_message' => 'Subscription expired. 🆕 Subscribe to continue',
                    'action_url' => ApiKeys::getUpgradeUrl(),
                    'reset_time' => $correctResetTime,
                    'reset_time_formatted' => 'expired'
                ];
            } else {
                // Fallback for unknown status or NEW users
                return [
                    'message' => $baseMessage,
                    'action_type' => 'upgrade',
                    'action_message' => 'Need higher limits? 🚀 Upgrade your plan',
                    'action_url' => ApiKeys::getUpgradeUrl(),
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
                'action_url' => ApiKeys::getPricingUrl(),
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
    // This function has been removed - use checkMessagesLimit() or checkOperationLimit() instead

    // simple count method: puts details into the database into the BUSELOG table
    // call as XSControl::countThis($userId, $msgId)
    public static function countThis($userId, $msgId, $operationType = 'general'):int {
        $subscriptionId = self::getCurrentSubscriptionId($userId);
        
        $newSQL = "INSERT INTO BUSELOG (BID, BTIMESTAMP, BUSERID, BMSGID, BOPERATIONTYPE, BSUBSCRIPTION_ID) VALUES (DEFAULT, ".time().", ".($userId).", ".($msgId).", '".DB::EscString($operationType)."', " . ($subscriptionId ? "'".DB::EscString($subscriptionId)."'" : "NULL") . ")";
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
                $configRes = db::Query($configSQL);
                while ($row = db::FetchArr($configRes)) {
                    $limits[$row['BSETTING']] = intval($row['BVALUE']);
                }
                return $limits;
            }
            
            // Get user level from payment-based subscription system
            $userLevel = strtoupper(self::getUserSubscriptionLevel($userId));
            
            // Load limits from BCONFIG for this user level
            $configSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'RATELIMITS_" . db::EscString($userLevel) . "' AND BOWNERID = 0";
            $configRes = db::Query($configSQL);
            
            while ($row = db::FetchArr($configRes)) {
                $limits[$row['BSETTING']] = intval($row['BVALUE']);
            }
            
            // If no limits found for this user level, this is a critical system error
            if (empty($limits)) {
                error_log("CRITICAL: No rate limits found for user level: $userLevel (User ID: $userId)");
                // Emergency mode - EXTREMELY restrictive to prevent abuse
                $limits = [
                    'MESSAGES_120S' => 1,
                    'MESSAGES_3600S' => 3,
                    'IMAGES_2592000S' => 1,
                    'VIDEOS_2592000S' => 0,  // No videos in emergency mode
                    'AUDIOS_2592000S' => 0,  // No audio in emergency mode
                    'FILE_ANALYSIS_86400S' => 1,
                    'FILEBYTES_3600S' => 1048576,  // 1MB
                    'APICALLS_3600S' => 5
                ];
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
                // Use billing cycle aware counting for monthly limits
                $currentCount = self::countInCurrentBillingCycle($msgArr['BUSERID'], $timeframe);
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
                
                // Count specific operation type in BUSELOG using billing cycle aware counting
                $currentCount = self::countInCurrentBillingCycle($msgArr['BUSERID'], $timeframe, $operationType);
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
        
        $bytesRes = db::Query($bytesSQL);
        $bytesArr = db::FetchArr($bytesRes);
        
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
        
        $apiRes = db::Query($apiSQL);
        $apiArr = db::FetchArr($apiRes);
        
        return intval($apiArr['API_COUNT'] ?? 0);
    }
    
    
    /**
     * Check if rate limiting is enabled via ENV configuration
     */
    public static function isRateLimitingEnabled(): bool {
        return ApiKeys::isRateLimitingEnabled();
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
                    // Use billing cycle aware counting for monthly limits
                    $currentCount = self::countInCurrentBillingCycle($userId, $timeframe);
                    
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
     * Get FREE user limits - LIFETIME limits that apply once per account
     * These are bonus limits that can be used in addition to subscription limits
     */
    private static function getFreeLimits(): array {
        try {
            // Use NEW limits as the baseline for free usage
            $configSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'RATELIMITS_NEW' AND BOWNERID = 0";
            $configResult = db::Query($configSQL);
            $limits = [];
            
            while ($configRow = db::FetchArr($configResult)) {
                $limits[$configRow['BSETTING']] = intval($configRow['BVALUE']);
            }
            
            // If no NEW limits found, system error - should always exist
            if (empty($limits)) {
                error_log("CRITICAL: No RATELIMITS_NEW found in BCONFIG - System misconfigured!");
                // Emergency fallback - VERY restrictive to prevent abuse
                return [
                    'MESSAGES_120S' => 1,
                    'MESSAGES_3600S' => 3,
                    'IMAGES_2592000S' => 0, // No images in emergency modes
                    'VIDEOS_2592000S' => 0,  // No videos in emergency mode
                    'AUDIOS_2592000S' => 1,  
                    'FILE_ANALYSIS_86400S' => 1
                ];
            }
            
            return $limits;
        } catch (Exception $e) {
            error_log("CRITICAL ERROR getting free limits: " . $e->getMessage());
            // Emergency fallback - EXTREMELY restrictive to prevent abuse
            return [
                'MESSAGES_120S' => 1,
                'MESSAGES_3600S' => 3,
                'IMAGES_2592000S' => 1,
                'VIDEOS_2592000S' => 0,  // No videos in emergency mode
                'AUDIOS_2592000S' => 0,  // No audio in emergency mode
                'FILE_ANALYSIS_86400S' => 1
            ];
        }
    }

    /**
     * Check specific limit for specific usage type (subscription vs free)
     */
    private static function checkSpecificLimitForUsageType($msgArr, $operation, $timeframe, $maxCount, $usageType): array {
        $result = ['exceeded' => false, 'reason' => '', 'current_count' => 0];
        $userId = $msgArr['BUSERID'];
        
        // Get operation type from capabilities
        $operationMapping = self::getOperationMappingFromCapabilities();
        $operationType = $operationMapping[$operation] ?? 'general';
        
        // Count based on usage type
        if ($usageType === 'subscription') {
            // Count only PAID usage (BSUBSCRIPTION_ID IS NOT NULL)
            $currentCount = self::countUsageByType($userId, $timeframe, $operationType, 'paid');
        } else {
            // Count only FREE usage (BSUBSCRIPTION_ID IS NULL)
            $currentCount = self::countUsageByType($userId, $timeframe, $operationType, 'free');
        }
        
        $result['current_count'] = $currentCount;
        
        if ($currentCount >= $maxCount) {
            $result['exceeded'] = true;
            $usageLabel = ($usageType === 'subscription') ? 'subscription' : 'free';
            
            // Use intelligent message for subscription limits, simple message for free limits
            if ($usageType === 'subscription') {
                $intelligentMessage = self::getIntelligentRateLimitMessage($userId, $operation, $currentCount, $maxCount, $timeframe);
                $result['reason'] = $intelligentMessage['message'];
                $result['action_type'] = $intelligentMessage['action_type'];
                $result['action_message'] = $intelligentMessage['action_message'];
                $result['action_url'] = $intelligentMessage['action_url'];
                $result['reset_time'] = $intelligentMessage['reset_time'];
                $result['reset_time_formatted'] = $intelligentMessage['reset_time_formatted'];
            } else {
                // Free limit exceeded - always suggest upgrade
                $result['reason'] = ucfirst(strtolower($operation)) . " generation free limit exceeded: {$currentCount}/{$maxCount}";
                $result['action_type'] = 'upgrade';
                $result['action_message'] = 'Free limit reached. 🚀 Upgrade for higher limits';
                $result['action_url'] = ApiKeys::getPricingUrl();
                $result['reset_time'] = self::getCorrectResetTime($userId, $timeframe);
                $result['reset_time_formatted'] = self::formatTimeRemaining($result['reset_time'] - time());
            }
        }
        
        return $result;
    }

    /**
     * Count usage by type (paid or free)
     * 
     * IMPORTANT: 
     * - PAID usage: counted within timeframe (monthly reset)
     * - FREE usage: counted LIFETIME (once per account, no reset)
     */
    private static function countUsageByType($userId, $timeframe, $operationType, $usageType): int {
        try {
            $countSQL = "SELECT COUNT(*) as count FROM BUSELOG 
                        WHERE BUSERID = " . intval($userId) . " 
                        AND BOPERATIONTYPE = '" . db::EscString($operationType) . "'";
            
            if ($usageType === 'paid') {
                // PAID usage: only count within timeframe (monthly reset with subscription)
                $timeFrame = time() - $timeframe;
                $countSQL .= " AND BTIMESTAMP > " . intval($timeFrame);
                $countSQL .= " AND BSUBSCRIPTION_ID IS NOT NULL";
            } else {
                // FREE usage: count LIFETIME (no timeframe restriction)
                // This is a once-per-account bonus, not monthly recurring
                $countSQL .= " AND BSUBSCRIPTION_ID IS NULL";
            }
            
            $countResult = db::Query($countSQL);
            $countRow = db::FetchArr($countResult);
            return intval($countRow['count']);
            
        } catch (Exception $e) {
            error_log("Error counting usage by type: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check rate limit for specific sorted operation (post-sorting)
     * Called after sorting to check operation-specific limits
     * 
     * SECURITY: Checks BOTH subscription AND free limits independently
     */
    public static function checkOperationLimit($msgArr, $operation): array {
        $result = ['limited' => false, 'reason' => '', 'reset_time' => 0];
        
        if (!self::isRateLimitingEnabled()) {
            return $result;
        }
        
        // Security: Validate inputs
        if (!isset($msgArr['BUSERID']) || !$operation || $msgArr['BUSERID'] <= 0) {
            error_log("SECURITY: Invalid rate limit check - UserID: " . ($msgArr['BUSERID'] ?? 'NULL') . ", Operation: " . ($operation ?? 'NULL'));
            // Fail secure - block invalid requests
            return [
                'limited' => true,
                'reason' => 'Invalid request',
                'reset_time' => time() + 3600,
                'reset_time_formatted' => '1h',
                'message' => 'Request blocked due to invalid parameters',
                'action_type' => 'error',
                'action_message' => 'Please try again',
                'action_url' => ''
            ];
        }
        
        try {
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
            
            $userId = $msgArr['BUSERID'];
            $userLevel = self::getUserSubscriptionLevel($userId);
            
            // LOGIC: 
            // 1. NEW users: Only check NEW limits
            // 2. Paid users (PRO/TEAM/BUSINESS): Check specific plan limits + FREE lifetime limits
            
            if ($userLevel === 'NEW') {
                // NEW users: Only check NEW limits (monthly reset)
                $newLimits = self::getFreeLimits(); // Uses NEW limits
                foreach ($newLimits as $setting => $maxCount) {
                    if (preg_match('/^' . $limitType . '_(\d+)S$/', $setting, $matches)) {
                        $timeframe = intval($matches[1]);
                        
                        // For NEW users, count everything within timeframe (no subscription separation)
                        $currentCount = self::countInCurrentBillingCycle($userId, $timeframe, 
                            self::getOperationMappingFromCapabilities()[$limitType] ?? 'general');
                        
                        if ($currentCount >= $maxCount) {
                            $result['limited'] = true;
                            $result['reason'] = ucfirst(strtolower($limitType)) . " generation limit exceeded: {$currentCount}/{$maxCount}";
                            
                            // Get intelligent message for NEW users based on their subscription history
                            $intelligentMessage = self::getIntelligentMessageForNewUser($userId, $limitType, $currentCount, $maxCount);
                            
                            $result['reset_time'] = $intelligentMessage['reset_time'];
                            $result['reset_time_formatted'] = $intelligentMessage['reset_time_formatted'];
                            $result['action_type'] = $intelligentMessage['action_type'];
                            $result['action_message'] = $intelligentMessage['action_message'];
                            $result['action_url'] = $intelligentMessage['action_url'];
                            $result['message'] = $result['reason'];
                            return $result;
                        }
                    }
                }
            } else {
                // PAID users: Check subscription limits first, then free limits only if subscription is inactive
                
                // Check if user has active subscription
                $isActiveSubscription = self::isActiveSubscription($userId);
                
                if ($isActiveSubscription) {
                    // ACTIVE subscription: Only check subscription limits
                    $subscriptionLimits = self::getUserLimits($userId);
                    foreach ($subscriptionLimits as $setting => $maxCount) {
                        if (preg_match('/^' . $limitType . '_(\d+)S$/', $setting, $matches)) {
                            $timeframe = intval($matches[1]);
                            $limitCheck = self::checkSpecificLimitForUsageType($msgArr, $limitType, $timeframe, $maxCount, 'paid');
                            
                            if ($limitCheck['exceeded']) {
                                $result['limited'] = true;
                                $result['reason'] = $limitCheck['reason'];
                                $result['reset_time'] = $limitCheck['reset_time'];
                                $result['reset_time_formatted'] = $limitCheck['reset_time_formatted'];
                                $result['action_type'] = $limitCheck['action_type'];
                                $result['action_message'] = $limitCheck['action_message'];
                                $result['action_url'] = $limitCheck['action_url'];
                                $result['message'] = ucfirst(strtolower($limitType)) . " generation {$userLevel} limit exceeded: " . $limitCheck['current_count'] . "/{$maxCount} per month";
                                return $result;
                            }
                        }
                    }
                    // Active subscription user is NOT limited - return success
                    return $result;
                } else {
                    // INACTIVE subscription: Only check free limits (user should be treated as free user)
                    $freeLimits = self::getFreeLimits();
                foreach ($freeLimits as $setting => $maxCount) {
                    if (preg_match('/^' . $limitType . '_(\d+)S$/', $setting, $matches)) {
                        $limitCheck = self::checkSpecificLimitForUsageType($msgArr, $limitType, 0, $maxCount, 'free'); // 0 = no timeframe = lifetime
                        
                        if ($limitCheck['exceeded']) {
                            $result['limited'] = true;
                            $result['reason'] = $limitCheck['reason'];
                            $result['reset_time'] = 0; // Never resets
                            $result['reset_time_formatted'] = 'never';
                            
                            // Check if user has an active subscription - if yes, they shouldn't see this message
                            // This should only happen if they exhausted both paid AND free limits
                            $subscriptionStatus = self::isActiveSubscription($userId);
                            
                            if ($subscriptionStatus) {
                                // User has active subscription but exhausted both paid and free limits
                                $result['action_type'] = 'upgrade';
                                $result['action_message'] = 'All limits exhausted. Consider upgrading your plan.';
                                $result['action_url'] = ApiKeys::getUpgradeUrl();
                            } else {
                                // User has no active subscription - show appropriate message based on their level
                                if ($userLevel === 'NEW') {
                                    $result['action_type'] = 'upgrade';
                                    $result['action_message'] = 'Free limit reached. Get more with a subscription.';
                                    $result['action_url'] = ApiKeys::getPricingUrl();
                                } else {
                                    // User had a subscription (PRO, TEAM, etc.) but it's deactivated/expired
                                    $result['action_type'] = 'renew';
                                    $result['action_message'] = 'Free bonus limit reached. Renew your ' . $userLevel . ' subscription for more limits.';
                                    $result['action_url'] = ApiKeys::getPricingUrl();
                                }
                            }
                            
                            $result['message'] = ucfirst(strtolower($limitType)) . " generation free limit exceeded: " . $limitCheck['current_count'] . "/{$maxCount} lifetime";
                            return $result;
                        }
                    }
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

}