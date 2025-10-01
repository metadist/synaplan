<?php

class XSControl
{
    // Central upgrade and account URLs for easy maintenance
    // count the messages the user sent in the last x seconds
    public static function countIn($userId, $secondsCount): int
    {
        $timeFrame = time() - $secondsCount;
        $countSQL = 'SELECT COUNT(*) XSCOUNT FROM BUSELOG WHERE BUSERID = '.($userId).' AND BTIMESTAMP > '.($timeFrame);
        $countRes = db::Query($countSQL);
        $countArr = db::FetchArr($countRes);
        return $countArr['XSCOUNT'];
    }

    // count specific operation types the user sent in the last x seconds
    public static function countInByType($userId, $secondsCount, $operationType): int
    {
        $timeFrame = time() - $secondsCount;
        $countSQL = 'SELECT COUNT(*) XSCOUNT FROM BUSELOG WHERE BUSERID = '.($userId).' AND BTIMESTAMP > '.($timeFrame)." AND BOPERATIONTYPE = '".db::EscString($operationType)."'";
        $countRes = db::Query($countSQL);
        $countArr = db::FetchArr($countRes);
        return $countArr['XSCOUNT'];
    }

    /**
     * Count operations with STRICT timeframe and subscription logic
     * Always uses operationType and separates paid vs free counting
     */
    public static function countInCurrentBillingCycle($userId, $timeframe, $operationType = 'general'): int
    {
        try {
            $userSQL = 'SELECT BUSERLEVEL, BPAYMENTDETAILS FROM BUSER WHERE BID = ' . intval($userId);
            $userResult = db::Query($userSQL);
            $userRow = db::FetchArr($userResult);

            if (!$userRow) {
                return 0;
            }

            $userLevel = $userRow['BUSERLEVEL'] ?? 'NEW';
            $isActiveSubscription = false;
            $currentSubscriptionId = null;
            $cycleStart = 0;
            $cycleEnd = 0;

            // Check for STRICT active subscription
            if ($userLevel !== 'NEW' && !empty($userRow['BPAYMENTDETAILS'])) {
                $paymentDetails = json_decode($userRow['BPAYMENTDETAILS'], true);
                if ($paymentDetails) {
                    $subscriptionStatus = $paymentDetails['status'] ?? null;
                    $startTimestamp = intval($paymentDetails['start_timestamp'] ?? 0);
                    $endTimestamp = intval($paymentDetails['end_timestamp'] ?? 0);
                    $currentTime = time();

                    // STRICT: Only 'active' status AND current time < end_timestamp
                    $isActiveSubscription = ($subscriptionStatus === 'active' && $endTimestamp > 0 && $currentTime < $endTimestamp);
                    if ($isActiveSubscription) {
                        $currentSubscriptionId = $paymentDetails['stripe_subscription_id'] ?? null;
                        $cycleStart = $startTimestamp;
                        $cycleEnd = $endTimestamp;
                    }
                }
            }

            // Build count query based on subscription status
            $countSQL = 'SELECT COUNT(*) XSCOUNT FROM BUSELOG WHERE BUSERID = ' . intval($userId);

            // Always filter by operation type (including 'general' for MESSAGES)
            if ($operationType) {
                $countSQL .= " AND BOPERATIONTYPE = '" . db::EscString($operationType) . "'";
            }

            if ($isActiveSubscription && $currentSubscriptionId) {
                // ACTIVE SUBSCRIPTION: Use time window AND subscription_id filter
                if ($timeframe >= 2592000) {
                    // Monthly: inclusive start, exclusive end
                    $countSQL .= ' AND BTIMESTAMP >= ' . intval($cycleStart) . ' AND BTIMESTAMP < ' . intval($cycleEnd);
                } else {
                    // Hourly: BTIMESTAMP >= now()-3600
                    $timeStart = time() - $timeframe;
                    $countSQL .= ' AND BTIMESTAMP >= ' . intval($timeStart);
                }
                $countSQL .= " AND BSUBSCRIPTION_ID = '" . db::EscString($currentSubscriptionId) . "'";
            } else {
                // NEW/INACTIVE: Use only BSUBSCRIPTION_ID IS NULL (lifetime, no time window)
                $countSQL .= ' AND BSUBSCRIPTION_ID IS NULL';
            }

            $countRes = db::Query($countSQL);
            $countArr = db::FetchArr($countRes);
            return intval($countArr['XSCOUNT']);

        } catch (Exception $e) {
            error_log('Error counting in billing cycle: ' . $e->getMessage());
            return 0;
        }
    }

    // update operation type in BUSELOG after sorting is complete
    /**
     * Get intelligent rate limit message for NEW users based on subscription history
     */
    private static function getIntelligentMessageForNewUser($userId, $limitType, $currentCount, $maxCount): array
    {
        try {
            $userSQL = 'SELECT BPAYMENTDETAILS FROM BUSER WHERE BID = ' . intval($userId);
            $userResult = db::Query($userSQL);
            $userRow = db::FetchArr($userResult);

            $result = [
                'reset_time' => 0,
                'reset_time_formatted' => 'never',
                'action_type' => 'upgrade',
                'action_message' => 'Get unlimited access with a subscription 🚀',
                'action_url' => ApiKeys::getUpgradeUrl()
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
                    } elseif ($status === 'active' && $endTimestamp && $currentTime > $endTimestamp) {
                        // Subscription expired
                        if ($autoRenew) {
                            $result['action_type'] = 'renew';
                            $result['action_message'] = 'Renewal failed. Please update your payment method';
                            $result['action_url'] = ApiKeys::getAccountUrl();
                        } else {
                            $result['action_type'] = 'renew';
                            $result['action_message'] = 'Your ' . $plan . ' subscription expired. Renew or enable auto-renew';
                            $result['action_url'] = ApiKeys::getUpgradeUrl();
                        }
                    } elseif ($plan && $plan !== 'NEW') {
                        // Had a subscription but something else went wrong
                        $result['action_type'] = 'reactivate';
                        $result['action_message'] = 'Reactivate your ' . $plan . ' subscription';
                        $result['action_url'] = ApiKeys::getAccountUrl();
                    }
                }
            }

            return $result;

        } catch (Exception $e) {
            error_log('Error getting intelligent message for NEW user: ' . $e->getMessage());
            // Return default message
            return [
                'reset_time' => 0,
                'reset_time_formatted' => 'never',
                'action_type' => 'upgrade',
                'action_message' => 'Get unlimited access with a subscription 🚀',
                'action_url' => ApiKeys::getUpgradeUrl()
            ];
        }
    }

    /**
     * Check if user has an active subscription
     * STRICT: Only status==='active' AND now < end_timestamp
     */
    private static function isActiveSubscription($userId): bool
    {
        try {
            $userSQL = 'SELECT BUSERLEVEL, BPAYMENTDETAILS FROM BUSER WHERE BID = ' . intval($userId);
            $userResult = db::Query($userSQL);

            if (!$userRow = db::FetchArr($userResult)) {
                return false;
            }

            // NEW users cannot have active subscriptions
            if ($userRow['BUSERLEVEL'] === 'NEW') {
                return false;
            }

            $paymentDetails = json_decode($userRow['BPAYMENTDETAILS'], true);
            if (!$paymentDetails || !is_array($paymentDetails)) {
                return false;
            }

            $subscriptionStatus = $paymentDetails['status'] ?? null;
            $endTimestamp = intval($paymentDetails['end_timestamp'] ?? 0);
            $currentTime = time();

            // STRICT: Only 'active' status AND current time < end_timestamp
            return ($subscriptionStatus === 'active' && $endTimestamp > 0 && $currentTime < $endTimestamp);

        } catch (Exception $e) {
            if ($GLOBALS['debug']) {
                error_log('Error checking active subscription: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Get current user's subscription ID for BUSELOG tracking
     * STRICT: Only returns ID if status==='active' AND now < end_timestamp
     */
    private static function getCurrentSubscriptionId($userId): ?string
    {
        try {
            $userSQL = 'SELECT BUSERLEVEL, BPAYMENTDETAILS FROM BUSER WHERE BID = ' . intval($userId);
            $userResult = db::Query($userSQL);
            $userRow = db::FetchArr($userResult);

            if (!$userRow) {
                return null;
            }

            // NEW users always return NULL (treat as free user)
            if ($userRow['BUSERLEVEL'] === 'NEW') {
                return null;
            }

            if (!empty($userRow['BPAYMENTDETAILS'])) {
                $paymentDetails = json_decode($userRow['BPAYMENTDETAILS'], true);
                if ($paymentDetails && isset($paymentDetails['stripe_subscription_id'])) {
                    $subscriptionStatus = $paymentDetails['status'] ?? null;
                    $endTimestamp = intval($paymentDetails['end_timestamp'] ?? 0);
                    $currentTime = time();

                    // STRICT: Only 'active' status AND current time < end_timestamp
                    $isActive = ($subscriptionStatus === 'active' && $endTimestamp > 0 && $currentTime < $endTimestamp);

                    if ($isActive) {
                        return $paymentDetails['stripe_subscription_id'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Error getting subscription ID: ' . $e->getMessage());
        }

        return null;
    }

    public static function updateOperationType($userId, $msgId, $operationType): bool
    {
        $subscriptionId = self::getCurrentSubscriptionId($userId);

        $updateSQL = "UPDATE BUSELOG SET BOPERATIONTYPE = '".db::EscString($operationType)."', BSUBSCRIPTION_ID = " . ($subscriptionId ? "'".db::EscString($subscriptionId)."'" : 'NULL') . ' WHERE BUSERID = '.intval($userId).' AND BMSGID = '.intval($msgId);
        $updateRes = db::Query($updateSQL);
        return $updateRes !== false;
    }

    /**
     * Get user's subscription level from BUSER table (primary source)
     * Fast lookup - BUSERLEVEL is the authoritative source for rate limits
     */
    public static function getUserSubscriptionLevel($userId): string
    {
        try {
            // Primary: Get BUSERLEVEL from BUSER table (fastest)
            $userSQL = 'SELECT BUSERLEVEL FROM BUSER WHERE BID = ' . intval($userId);
            $userResult = db::Query($userSQL);
            $userRow = db::FetchArr($userResult);

            if ($userRow && !empty($userRow['BUSERLEVEL'])) {
                return strtoupper($userRow['BUSERLEVEL']);
            }

            // Default to NEW if not set
            return 'NEW';

        } catch (Exception $e) {
            error_log('Error getting user subscription level: ' . $e->getMessage());
            return 'NEW'; // Safe fallback
        }
    }

    /**
     * Get subscription end timestamp for countdown timer
     * Uses BPAYMENTDETAILS JSON for accurate expiry dates
     */
    public static function getSubscriptionEndTimestamp($userId): int
    {
        try {
            $paymentSQL = 'SELECT BPAYMENTDETAILS FROM BUSER WHERE BID = ' . intval($userId);
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
            error_log('Error getting subscription end timestamp: ' . $e->getMessage());
            return time() + (30 * 24 * 60 * 60); // Safe fallback
        }
    }

    /**
     * Calculate the correct reset time for rate limits based on subscription billing cycle
     */
    public static function getCorrectResetTime($userId, $timeframe): int
    {
        try {
            $paymentSQL = 'SELECT BPAYMENTDETAILS, BUSERLEVEL FROM BUSER WHERE BID = ' . intval($userId);
            $paymentResult = db::Query($paymentSQL);
            $paymentRow = db::FetchArr($paymentResult);

            // NEW users never get reset time - they must upgrade
            $userLevel = $paymentRow['BUSERLEVEL'] ?? 'NEW';
            if ($userLevel === 'NEW') {
                return 0; // No reset for NEW users
            }

            // Check for STRICT active subscription
            $isActiveSubscription = false;
            $cycleEnd = 0;

            if ($paymentRow && !empty($paymentRow['BPAYMENTDETAILS'])) {
                $paymentDetails = json_decode($paymentRow['BPAYMENTDETAILS'], true);
                if ($paymentDetails && isset($paymentDetails['start_timestamp']) && isset($paymentDetails['end_timestamp'])) {
                    $subscriptionStatus = $paymentDetails['status'] ?? null;
                    $endTimestamp = intval($paymentDetails['end_timestamp']);
                    $currentTime = time();

                    // STRICT: Only 'active' status AND current time < end_timestamp
                    $isActiveSubscription = ($subscriptionStatus === 'active' && $endTimestamp > 0 && $currentTime < $endTimestamp);
                    if ($isActiveSubscription) {
                        $cycleEnd = $endTimestamp;
                    }
                }
            }

            // If no active subscription, treat as NEW/free (no reset)
            if (!$isActiveSubscription) {
                return 0;
            }

            // Active subscription: calculate reset time based on timeframe
            if ($timeframe === 0) {
                // TOTAL limits: never reset
                return 0;
            } elseif ($timeframe >= 2592000) {
                // MONTHLY limits: reset at cycle end
                return $cycleEnd;
            } else {
                // HOURLY limits: calculate based on oldest message in current window
                $oldestMessageTime = self::getOldestMessageInTimeframe($userId, $timeframe);
                if ($oldestMessageTime > 0) {
                    // Reset time = oldest message + timeframe
                    return $oldestMessageTime + $timeframe;
                } else {
                    // No messages in timeframe, use current time + timeframe
                    return time() + $timeframe;
                }
            }

        } catch (Exception $e) {
            error_log('Error calculating reset time: ' . $e->getMessage());
            return 0; // Safe fallback: no reset
        }
    }

    /**
     * Get the timestamp of the oldest message in the current timeframe
     * Used for precise reset time calculation for rolling windows
     */
    private static function getOldestMessageInTimeframe($userId, $timeframe): int
    {
        try {
            // Get current subscription info
            $currentSubscriptionId = self::getCurrentSubscriptionId($userId);
            $isActiveSubscription = self::isActiveSubscription($userId);

            $oldestSQL = 'SELECT MIN(BTIMESTAMP) as OLDEST_TIME FROM BUSELOG WHERE BUSERID = ' . intval($userId);

            // Add operation type filter for general messages
            $oldestSQL .= " AND BOPERATIONTYPE = 'general'";

            if ($isActiveSubscription && $currentSubscriptionId) {
                // Active subscription: filter by subscription_id and time window
                $timeStart = time() - $timeframe;
                $oldestSQL .= ' AND BTIMESTAMP >= ' . intval($timeStart);
                $oldestSQL .= " AND BSUBSCRIPTION_ID = '" . db::EscString($currentSubscriptionId) . "'";
            } else {
                // NEW/inactive: filter by NULL subscription and time window
                $timeStart = time() - $timeframe;
                $oldestSQL .= ' AND BTIMESTAMP >= ' . intval($timeStart);
                $oldestSQL .= ' AND BSUBSCRIPTION_ID IS NULL';
            }

            $oldestRes = db::Query($oldestSQL);
            $oldestArr = db::FetchArr($oldestRes);

            return intval($oldestArr['OLDEST_TIME'] ?? 0);

        } catch (Exception $e) {
            error_log('Error getting oldest message time: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get intelligent rate limit message based on subscription status
     */
    public static function getIntelligentRateLimitMessage($userId, $operationType, $currentCount, $maxCount, $timeframe): array
    {
        try {
            $paymentSQL = 'SELECT BPAYMENTDETAILS, BUSERLEVEL FROM BUSER WHERE BID = ' . intval($userId);
            $paymentResult = db::Query($paymentSQL);
            $paymentRow = db::FetchArr($paymentResult);

            $subscriptionStatus = 'unknown';
            $plan = 'NEW';
            $paymentDetails = null;  // Initialize safely

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

            // Check if this is a widget session to customize messages early
            $isWidget = isset($_SESSION['is_widget']) && $_SESSION['is_widget'] === true;

            // Generate base message - different for widgets vs regular users
            if ($isWidget) {
                // Widget users get generic demo message without owner details
                $baseMessage = 'Demo chat limit reached';
            } else {
                // Regular users get detailed technical message
                $operationName = ucfirst($operationType);
                if ($operationType === 'IMAGES') {
                    $operationName = 'Image generation';
                } elseif ($operationType === 'VIDEOS') {
                    $operationName = 'Video generation';
                } elseif ($operationType === 'AUDIOS') {
                    $operationName = 'Audio generation';
                } elseif ($operationType === 'FILE_ANALYSIS') {
                    $operationName = 'File analysis';
                }

                // Use different message format for lifetime limits vs. time-based limits
                if ($timeframe === 0) {
                    // Lifetime limit (NEW users)
                    $baseMessage = "$operationName limit exceeded: $currentCount/$maxCount (free trial)";
                } else {
                    // Time-based limit (paid users)
                    $timeframeText = self::formatTimeframe($timeframe);
                    $baseMessage = "$operationName limit exceeded: $currentCount/$maxCount per $timeframeText";
                }
            }

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
                // NEW users or unknown status - NO RESET TIME, only upgrade
                $userLevel = $paymentRow['BUSERLEVEL'] ?? 'NEW';
                if ($userLevel === 'NEW') {
                    if ($isWidget) {
                        // Widget session with NEW owner - special demo message
                        return [
                            'message' => 'Demo chat limit reached',
                            'action_type' => 'widget_signup',
                            'action_message' => 'This demo has limited usage. 🚀 Create your free SynaPlan account to continue chatting!',
                            'action_url' => ApiKeys::getUpgradeUrl(),
                            'reset_time' => 0, // No reset for NEW users
                            'reset_time_formatted' => 'never'
                        ];
                    } else {
                        // Regular NEW user
                        return [
                            'message' => $baseMessage,
                            'action_type' => 'upgrade',
                            'action_message' => 'Free plan limit reached. 🚀 Upgrade to continue',
                            'action_url' => ApiKeys::getUpgradeUrl(),
                            'reset_time' => 0, // No reset for NEW users
                            'reset_time_formatted' => 'never'
                        ];
                    }
                } else {
                    // Fallback for other unknown states
                    return [
                        'message' => $baseMessage,
                        'action_type' => 'upgrade',
                        'action_message' => 'Need higher limits? 🚀 Upgrade your plan',
                        'action_url' => ApiKeys::getUpgradeUrl(),
                        'reset_time' => $correctResetTime,
                        'reset_time_formatted' => self::formatTimeRemaining($timeRemaining)
                    ];
                }
            }

        } catch (Exception $e) {
            error_log('Error getting intelligent rate limit message: ' . $e->getMessage());
            // Safe fallback
            return [
                'message' => "Rate limit exceeded: $currentCount/$maxCount",
                'action_type' => 'upgrade',
                'action_message' => 'Need higher limits? 🚀 Upgrade your plan',
                'action_url' => ApiKeys::getUpgradeUrl(),
                'reset_time' => time() + (30 * 24 * 60 * 60),
                'reset_time_formatted' => '30 days'
            ];
        }
    }

    /**
     * Format time remaining in a human-readable way
     */
    private static function formatTimeRemaining($seconds): string
    {
        if ($seconds <= 0) {
            return 'now';
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($days > 0) {
            return $days . 'd ' . $hours . 'h';
        } elseif ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm';
        } else {
            return $minutes . 'm';
        }
    }

    /**
     * Format timeframe in a human-readable way
     */
    private static function formatTimeframe($seconds): string
    {
        if ($seconds >= 86400) {
            return floor($seconds / 86400) . ' day(s)';
        } elseif ($seconds >= 3600) {
            return floor($seconds / 3600) . ' hour(s)';
        } else {
            return floor($seconds / 60) . ' minute(s)';
        }
    }

    /**
     * Get operation type mapping from BCAPABILITIES table dynamically
     * Maps rate limit categories to BUSELOG operation types
     */
    public static function getOperationMappingFromCapabilities(): array
    {
        static $mapping = null;

        if ($mapping === null) {
            $mapping = [];

            try {
                // Query BCAPABILITIES to get mapping from rate limit categories to BKEY values
                $sql = 'SELECT BRATELIMIT_CATEGORY, BKEY FROM BCAPABILITIES WHERE BRATELIMIT_CATEGORY IS NOT NULL';
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
                    switch ($category) {
                        case 'IMAGES':
                        case 'VIDEOS':
                        case 'AUDIOS':
                            // Use priority mapping to pick the primary operation type
                            if (!isset($mapping[$category]) && in_array($bkey, $priorityMapping[$category] ?? [])) {
                                $mapping[$category] = $bkey;
                            }
                            // Fallback: use first available key if no priority match found
                            elseif (!isset($mapping[$category])) {
                                $mapping[$category] = $bkey;
                            }
                            break;
                        case 'FILE_ANALYSIS':
                            // Consolidate ALL FILE_ANALYSIS under 'analyzefile'
                            $mapping[$category] = 'analyzefile';
                            break;
                        case 'MESSAGES':
                            $mapping[$category] = 'general';
                            break;
                        default:
                            // Handle new categories dynamically
                            if (!isset($mapping[$category])) {
                                $mapping[$category] = $bkey;
                            }
                            break;
                    }
                }
            } catch (Exception $e) {
                error_log('Failed to load operation mapping from BCAPABILITIES: ' . $e->getMessage());
            }

            // Fallback to hardcoded if DB query fails
            if (empty($mapping)) {
                $mapping = [
                    'IMAGES' => 'text2pic',
                    'VIDEOS' => 'text2vid',
                    'AUDIOS' => 'text2sound',
                    'FILE_ANALYSIS' => 'analyzefile',  // Consolidated
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
    public static function countThis($userId, $msgId, $operationType = 'general'): int
    {
        $subscriptionId = self::getCurrentSubscriptionId($userId);

        $newSQL = 'INSERT INTO BUSELOG (BID, BTIMESTAMP, BUSERID, BMSGID, BOPERATIONTYPE, BSUBSCRIPTION_ID) VALUES (DEFAULT, '.time().', '.($userId).', '.($msgId).", '".db::EscString($operationType)."', " . ($subscriptionId ? "'".db::EscString($subscriptionId)."'" : 'NULL') . ')';
        $newRes = db::Query($newSQL);
        return db::LastId();
    }

    // create a confirmation link for a fresh user
    // and send it to the user in his/her language
    public static function createConfirmationLink($usrArr): void
    {
        $confirmLink = $GLOBALS['baseUrl'].'da/confirm.php?id='.$usrArr['BID'].'&c='.$usrArr['DETAILS']['MAILCHECKED'];
        $msgTxt = "Welcome to Ralfs.AI BETA!<BR>\n<BR>\n";
        $msgTxt .= "Please confirm your email by clicking the link below:<BR>\n<BR>\n";
        $msgTxt .= $confirmLink;
        $msgTxt .= "<BR>\n<BR>\n";
        $msgTxt .= "Please note that this is a BETA version we are working on it!<BR>\n";
        $msgTxt .= "Best regards,<BR>\n";
        $msgTxt .= "Ralfs.AI Team<BR>\n";
        EmailService::sendEmailConfirmation($usrArr['DETAILS']['MAIL']);
    }

    // combined methods to count and block, if needed, uses
    // the methods above

    // basic auth methods
    public static function getBearerToken(): ?string
    {
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
    public static function countBytes($msgArr, $FILEORTEXT = 'ALL', $stream = false): void
    {
        // Safety check: ensure BID exists before proceeding
        if (!isset($msgArr['BID']) || empty($msgArr['BID'])) {
            if ($GLOBALS['debug']) {
                error_log('Warning: Attempted to count bytes without BID. Message array: ' . json_encode($msgArr));
            }
            return;
        }

        // check if the message is a file
        if ($msgArr['BFILE'] == 1 and ($FILEORTEXT == 'ALL' or $FILEORTEXT == 'FILE')) {
            // get the file size (guard against missing files)
            $abs = rtrim(UPLOAD_DIR, '/').'/'.$msgArr['BFILEPATH'];
            $fileSize = is_file($abs) ? filesize($abs) : 0;
            // fetch the file bytes from the database
            $fileBytesSQL = 'SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = '.intval($msgArr['BID'])." AND BTOKEN = 'FILEBYTES'";
            $fileBytesRes = db::Query($fileBytesSQL);
            if ($fileBytesArr = db::FetchArr($fileBytesRes)) {
                // add the file size to the file bytes
                $fileSize = intval($fileBytesArr['BVALUE']) + $fileSize;
                // save the file bytes to the database
                $fileBytesSQL = "UPDATE BMESSAGEMETA SET BVALUE = '".intval($fileSize)."' WHERE BMESSID = ".intval($msgArr['BID'])." AND BTOKEN = 'FILEBYTES'";
                db::Query($fileBytesSQL);
            } else {
                // save the file bytes to the database
                $fileBytesSQL = 'INSERT INTO BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) VALUES (DEFAULT, '.intval($msgArr['BID']).", 'FILEBYTES', '".intval($fileSize)."')";
                db::Query($fileBytesSQL);
            }
        }
        // check if the message is a chat message
        if ((strlen($msgArr['BTEXT']) > 0 or $msgArr['BFILETEXT'] > 0) and ($FILEORTEXT == 'ALL' or $FILEORTEXT == 'TEXT')) {
            // get the chat bytes from the database
            $chatBytesSQL = 'SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = '.intval($msgArr['BID'])." AND BTOKEN = 'CHATBYTES'";
            $chatBytesRes = db::Query($chatBytesSQL);

            if ($chatBytesArr = db::FetchArr($chatBytesRes)) {
                // add the chat bytes to the chat bytes
                $chatBytes = intval($chatBytesArr['BVALUE']) + strlen($msgArr['BTEXT']) + strlen($msgArr['BFILETEXT']);
                // save the chat bytes to the database
                $chatBytesSQL = "UPDATE BMESSAGEMETA SET BVALUE = '".intval($chatBytes)."' WHERE BMESSID = ".intval($msgArr['BID'])." AND BTOKEN = 'CHATBYTES'";
                db::Query($chatBytesSQL);
            } else {
                // save the chat bytes to the database
                $chatBytes = strlen($msgArr['BTEXT']) + strlen($msgArr['BFILETEXT']);
                $chatBytesSQL = 'INSERT INTO BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) VALUES (DEFAULT, '.intval($msgArr['BID']).", 'CHATBYTES', '".intval($chatBytes)."')";
                db::Query($chatBytesSQL);
            }
        }
        // check if the message is a sort message
        if ((strlen($msgArr['BTEXT']) > 0 or $msgArr['BFILETEXT'] > 0) and ($FILEORTEXT == 'ALL' or $FILEORTEXT == 'SORT')) {
            // get the chat bytes from the database
            $sortBytesSQL = 'SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = '.intval($msgArr['BID'])." AND BTOKEN = 'SORTBYTES'";
            $sortBytesRes = db::Query($sortBytesSQL);

            if ($sortBytesArr = db::FetchArr($sortBytesRes)) {
                // add the chat bytes to the chat bytes
                $sortBytes = intval($sortBytesArr['BVALUE']) + strlen($msgArr['BTEXT']) + strlen($msgArr['BFILETEXT']);
                // save the chat bytes to the database
                $sortBytesSQL = "UPDATE BMESSAGEMETA SET BVALUE = '".intval($sortBytes)."' WHERE BMESSID = ".intval($msgArr['BID'])." AND BTOKEN = 'SORTBYTES'";
                db::Query($sortBytesSQL);
            } else {
                // save the chat bytes to the database
                $sortBytes = strlen($msgArr['BTEXT']) + strlen($msgArr['BFILETEXT']);
                $sortBytesSQL = 'INSERT INTO BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) VALUES (DEFAULT, '.intval($msgArr['BID']).", 'SORTBYTES', '".intval($sortBytes)."')";
                db::Query($sortBytesSQL);
            }
        }
    }
    // store the AI details per message
    // AI models used and how fast they answer! Use the BMESSAGEMETA table
    public static function storeAIDetails($msgArr, $modelKey, $modelValue, $stream = false): bool
    {
        // Safety check: ensure BID exists before proceeding
        if (!isset($msgArr['BID']) || empty($msgArr['BID'])) {
            if ($GLOBALS['debug']) {
                error_log('Warning: Attempted to store AI details without BID. Message array: ' . json_encode($msgArr));
            }
            return false;
        }

        // save the AI details to the database
        $aiDetailsSQL = 'INSERT INTO BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) VALUES (DEFAULT, '.intval($msgArr['BID']).", '{$modelKey}', '{$modelValue}')";
        db::Query($aiDetailsSQL);
        return true;
    }

    /**
     * Get user-specific rate limits from BCONFIG table
     * Inactive subscription = RATELIMITS_NEW, Active = RATELIMITS_[LEVEL]
     */
    public static function getUserLimits($userId): array
    {
        $limits = [];

        try {
            // Handle widget/anonymous users - use widget owner's limits
            if (isset($_SESSION['is_widget']) && $_SESSION['is_widget'] === true && $userId != intval($_SESSION['widget_owner_id'] ?? 0)) {
                $ownerId = intval($_SESSION['widget_owner_id'] ?? 0);
                if ($ownerId > 0) {
                    // Use widget owner's limits - directly load without recursion
                    $userId = $ownerId; // Use owner ID for the rest of the function
                } else {
                    // Fallback to widget-specific limits if no owner ID
                    $configSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'RATELIMITS_WIDGET' AND BOWNERID = 0";
                    $configRes = db::Query($configSQL);
                    while ($row = db::FetchArr($configRes)) {
                        $limits[$row['BSETTING']] = intval($row['BVALUE']);
                    }
                    return $limits;
                }
            }

            // Determine which limits to use based on subscription status
            $isActive = self::isActiveSubscription($userId);
            $userLevel = strtoupper(self::getUserSubscriptionLevel($userId));

            if (!$isActive || $userLevel === 'NEW') {
                // Inactive subscription OR NEW user: use RATELIMITS_NEW
                $limitGroup = 'RATELIMITS_NEW';
            } else {
                // Active subscription: use user's subscription level
                $limitGroup = 'RATELIMITS_' . $userLevel;
            }

            // Load limits from BCONFIG
            $configSQL = "SELECT BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = '" . db::EscString($limitGroup) . "' AND BOWNERID = 0";
            $configRes = db::Query($configSQL);

            while ($row = db::FetchArr($configRes)) {
                $limits[$row['BSETTING']] = intval($row['BVALUE']);
            }

            // Emergency fallback with NEW format keys
            if (empty($limits)) {
                error_log("CRITICAL: No rate limits found for group: $limitGroup (User ID: $userId)");
                $limits = [
                    'MESSAGES_TOTAL' => 50,
                    'IMAGES_TOTAL' => 5,
                    'VIDEOS_TOTAL' => 2,
                    'AUDIOS_TOTAL' => 3,
                    'FILE_ANALYSIS_TOTAL' => 10
                ];
            }

        } catch (Exception $e) {
            if ($GLOBALS['debug']) {
                error_log('Error loading user limits: ' . $e->getMessage());
            }
        }

        return $limits;
    }

    /**
     * Check a specific limit type using NEW config keys and unified logic
     */
    public static function checkSpecificLimit($msgArr, $operation, $limitKey, $maxCount): array
    {
        $result = ['exceeded' => false, 'reason' => '', 'current_count' => 0];

        // Determine timeframe from limit key
        $timeframe = 0; // Default: lifetime
        if (str_ends_with($limitKey, '_HOURLY')) {
            $timeframe = 3600;
        } elseif (str_ends_with($limitKey, '_MONTHLY')) {
            $timeframe = 2592000; // 30 days
        }
        // _TOTAL uses timeframe = 0 (lifetime)

        switch ($operation) {
            case 'MESSAGES':
                $currentCount = self::countInCurrentBillingCycle($msgArr['BUSERID'], $timeframe, 'general');
                $result['current_count'] = $currentCount;
                if ($currentCount >= $maxCount) {
                    $result['exceeded'] = true;
                    $result['reason'] = "Message limit exceeded: {$currentCount}/{$maxCount}";
                }
                break;

            case 'FILEBYTES':
                $currentBytes = self::countFileBytes($msgArr['BUSERID'], $timeframe);
                $result['current_count'] = $currentBytes;
                if ($currentBytes >= $maxCount) {
                    $result['exceeded'] = true;
                    $fileSizeMB = round($currentBytes / 1048576, 2);
                    $limitMB = round($maxCount / 1048576, 2);
                    $result['reason'] = "File size limit exceeded: {$fileSizeMB}MB/{$limitMB}MB";
                }
                break;

            case 'APICALLS':
                $currentCalls = self::countApiCalls($msgArr['BUSERID'], $timeframe);
                $result['current_count'] = $currentCalls;
                if ($currentCalls >= $maxCount) {
                    $result['exceeded'] = true;
                    $result['reason'] = "API call limit exceeded: {$currentCalls}/{$maxCount}";
                }
                break;

            case 'AUDIOS':
            case 'IMAGES':
            case 'VIDEOS':
            case 'FILE_ANALYSIS':
                // Get operation type from BCAPABILITIES table dynamically
                $operationMapping = self::getOperationMappingFromCapabilities();
                $operationType = $operationMapping[$operation] ?? 'general';

                // Count using unified billing cycle logic
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
    private static function countFileBytes($userId, $timeframe): int
    {
        $bytesSQL = 'SELECT SUM(CAST(m.BVALUE AS UNSIGNED)) as TOTAL_BYTES 
                     FROM BMESSAGEMETA m 
                     JOIN BMESSAGES b ON m.BMESSID = b.BID 
                     WHERE b.BUSERID = ' . intval($userId) . " 
                     AND m.BTOKEN = 'FILEBYTES'";

        // Apply time filter only if timeframe > 0 (not lifetime)
        if ($timeframe > 0) {
            $timeFrame = time() - $timeframe;
            $bytesSQL .= ' AND b.BUNIXTIMES > ' . intval($timeFrame);
        }

        $bytesRes = db::Query($bytesSQL);
        $bytesArr = db::FetchArr($bytesRes);

        return intval($bytesArr['TOTAL_BYTES'] ?? 0);
    }

    /**
     * Count API calls by user in timeframe
     * Uses BUSELOG table filtering by message type
     */
    private static function countApiCalls($userId, $timeframe): int
    {
        // Count messages that came through API (BMESSTYPE = 'API' or similar)
        $apiSQL = 'SELECT COUNT(*) as API_COUNT 
                   FROM BUSELOG l 
                   JOIN BMESSAGES b ON l.BMSGID = b.BID 
                   WHERE l.BUSERID = ' . intval($userId) . " 
                   AND b.BMESSTYPE = 'API'";

        // Apply time filter only if timeframe > 0 (not lifetime)
        if ($timeframe > 0) {
            $timeFrame = time() - $timeframe;
            $apiSQL .= ' AND l.BTIMESTAMP > ' . intval($timeFrame);
        }

        $apiRes = db::Query($apiSQL);
        $apiArr = db::FetchArr($apiRes);

        return intval($apiArr['API_COUNT'] ?? 0);
    }


    /**
     * Check if rate limiting is enabled via ENV configuration
     */
    public static function isRateLimitingEnabled(): bool
    {
        return ApiKeys::isRateLimitingEnabled();
    }

    /**
     * Check general MESSAGES limit for API pre-filtering
     */
    public static function checkMessagesLimit($userId): bool|array
    {
        if (!self::isRateLimitingEnabled()) {
            return true; // Always pass if disabled
        }

        try {
            $limits = self::getUserLimits($userId);
            if (empty($limits)) {
                return true;
            }

            // Check MESSAGES limits using new keys only
            foreach ($limits as $setting => $maxCount) {
                if (str_starts_with($setting, 'MESSAGES_') && !preg_match('/\d+S$/', $setting)) {
                    // Only use new keys: MESSAGES_TOTAL, MESSAGES_HOURLY, MESSAGES_MONTHLY
                    // Skip deprecated _S keys

                    $timeframe = 0; // Default: lifetime
                    if (str_ends_with($setting, '_HOURLY')) {
                        $timeframe = 3600;
                    } elseif (str_ends_with($setting, '_MONTHLY')) {
                        $timeframe = 2592000;
                    }

                    $currentCount = self::countInCurrentBillingCycle($userId, $timeframe, 'general');

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
            error_log('Rate limit check error: ' . $e->getMessage());
            return true; // Fail open
        }
    }




    /**
     * Check rate limit for specific sorted operation (post-sorting)
     * Called after sorting to check operation-specific limits
     *
     * SECURITY: Checks BOTH subscription AND free limits independently
     */
    public static function checkOperationLimit($msgArr, $operation): array
    {
        $result = ['limited' => false, 'reason' => '', 'reset_time' => 0];

        if (!self::isRateLimitingEnabled()) {
            return $result;
        }

        // Security: Validate inputs
        if (!isset($msgArr['BUSERID']) || !$operation || $msgArr['BUSERID'] <= 0) {
            error_log('SECURITY: Invalid rate limit check - UserID: ' . ($msgArr['BUSERID'] ?? 'NULL') . ', Operation: ' . ($operation ?? 'NULL'));
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
            if (!$limitType) {
                return $result;
            } // No limit for this operation

            $userId = $msgArr['BUSERID'];
            $userLevel = self::getUserSubscriptionLevel($userId);

            // LOGIC:
            // 1. NEW users: Only check NEW limits
            // 2. Paid users (PRO/TEAM/BUSINESS): Check specific plan limits + FREE lifetime limits

            // Check limits using unified approach
            $limits = self::getUserLimits($userId);

            foreach ($limits as $setting => $maxCount) {
                if (str_starts_with($setting, $limitType . '_') && !preg_match('/\d+S$/', $setting)) {
                    // Only use new keys: IMAGES_TOTAL, IMAGES_MONTHLY, etc.
                    // Skip deprecated _S keys

                    $limitCheck = self::checkSpecificLimit($msgArr, $limitType, $setting, $maxCount);

                    if ($limitCheck['exceeded']) {
                        $result['limited'] = true;
                        $result['reason'] = $limitCheck['reason'];
                        $result['reset_time'] = $limitCheck['reset_time'] ?? 0;
                        $result['reset_time_formatted'] = $limitCheck['reset_time_formatted'] ?? 'never';
                        $result['action_type'] = $limitCheck['action_type'] ?? 'upgrade';
                        $result['action_message'] = $limitCheck['action_message'] ?? 'Need higher limits? Upgrade your plan';
                        $result['action_url'] = $limitCheck['action_url'] ?? ApiKeys::getUpgradeUrl();
                        $result['message'] = $limitCheck['reason'];
                        return $result;
                    }
                }
            }

        } catch (Exception $e) {
            if ($GLOBALS['debug']) {
                error_log('Error checking operation limit: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Check rate limits for any topic after sorting
     * Maps topics to appropriate limit types
     */
    public static function checkTopicLimit($msgArr): array
    {
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
                if (empty($limits)) {
                    return $result;
                }

                foreach ($limits as $setting => $maxCount) {
                    if (str_starts_with($setting, 'FILE_ANALYSIS_') && !preg_match('/\d+S$/', $setting)) {
                        // Only use new keys: FILE_ANALYSIS_TOTAL, FILE_ANALYSIS_MONTHLY
                        $limitCheck = self::checkSpecificLimit($msgArr, 'FILE_ANALYSIS', $setting, $maxCount);

                        if ($limitCheck['exceeded']) {
                            $result['limited'] = true;
                            $result['reason'] = $limitCheck['reason'];
                            $result['reset_time'] = $limitCheck['reset_time'] ?? 0;
                            $result['reset_time_formatted'] = $limitCheck['reset_time_formatted'] ?? 'never';
                            break;
                        }
                    }
                }
                return $result;

            case 'general':
            default:
                // For general chat and all custom prompts: check message limits
                $limits = self::getUserLimits($msgArr['BUSERID']);

                // Check all message limits with new format
                foreach ($limits as $setting => $maxCount) {
                    if (str_starts_with($setting, 'MESSAGES_') && !preg_match('/\d+S$/', $setting)) {
                        // Only use new keys: MESSAGES_TOTAL, MESSAGES_HOURLY, MESSAGES_MONTHLY
                        $limitCheck = self::checkSpecificLimit($msgArr, 'MESSAGES', $setting, $maxCount);
                        if ($limitCheck['exceeded']) {
                            $result['limited'] = true;
                            $result['reason'] = $limitCheck['reason'];
                            $result['reset_time'] = $limitCheck['reset_time'] ?? 0;
                            $result['reset_time_formatted'] = $limitCheck['reset_time_formatted'] ?? 'never';
                            return $result;
                        }
                    }
                }

                return $result;
        }
    }

}
