<?php
/**
 * AgainLogic Class
 * 
 * Handles the "Again" functionality for replaying existing user messages 
 * and generating new AI answers with the same or next eligible model.
 * 
 * @package AgainLogic
 */

class AgainLogic {
    
    /**
     * Get previous IN message
     * 
     * Accepts either an IN or OUT ID and returns the corresponding IN message row.
     * Validates ownership and handles OUT->IN resolution within same BTRACKID.
     * 
     * @param int $id Message ID (can be IN or OUT)
     * @param int $userId User ID for ownership validation
     * @return array IN message row from BMESSAGES
     * @throws Exception with HTTP status codes: 404 (not found), 403 (ownership)
     */
    public static function getPrevInMessage($id, $userId) {
        $id = intval($id);
        $userId = intval($userId);
        
        if ($id <= 0 || $userId <= 0) {
            http_response_code(400);
            throw new Exception("Invalid message ID or user ID");
        }
        
        // First, get the message to check if it exists and ownership
        $sql = "SELECT * FROM BMESSAGES WHERE BID = " . $id . " LIMIT 1";
        $res = DB::Query($sql);
        $row = DB::FetchArr($res);
        
        if (!$row || !is_array($row)) {
            http_response_code(404);
            throw new Exception("Message not found");
        }
        
        // Check ownership
        if (intval($row['BUSERID']) !== $userId) {
            http_response_code(403);
            throw new Exception("Access denied - message does not belong to user");
        }
        
        // If it's already an IN message, return it
        if ($row['BDIRECT'] === 'IN') {
            return $row;
        }
        
        // If it's an OUT message, find the previous IN within the same BTRACKID
        if ($row['BDIRECT'] === 'OUT') {
            $trackId = intval($row['BTRACKID']);
            
            // Find the last IN message before this OUT in the same track
            $sql = "SELECT * FROM BMESSAGES 
                    WHERE BTRACKID = " . $trackId . " 
                    AND BDIRECT = 'IN' 
                    AND BUSERID = " . $userId . " 
                    AND BID < " . $id . " 
                    ORDER BY BID DESC 
                    LIMIT 1";
            $res = DB::Query($sql);
            $inRow = DB::FetchArr($res);
            
            if (!$inRow || !is_array($inRow)) {
                http_response_code(404);
                throw new Exception("No corresponding IN message found for this OUT message");
            }
            
            return $inRow;
        }
        
        http_response_code(404);
        throw new Exception("Invalid message direction");
    }
    
    /**
     * Get last OUT for IN
     * 
     * Returns the nearest OUT message after the IN (same BTRACKID).
     * 
     * @param int $inId IN message ID
     * @return array|null OUT message row or null if no OUT exists
     */
    public static function getLastOutForIn($inId) {
        $inId = intval($inId);
        
        if ($inId <= 0) {
            return null;
        }
        
        // Get the IN message to find its BTRACKID
        $sql = "SELECT BTRACKID FROM BMESSAGES WHERE BID = " . $inId . " AND BDIRECT = 'IN' LIMIT 1";
        $res = DB::Query($sql);
        $inRow = DB::FetchArr($res);
        
        if (!$inRow || !is_array($inRow)) {
            return null;
        }
        
        $trackId = intval($inRow['BTRACKID']);
        
        // Find the nearest OUT message in the same track after this IN
        $sql = "SELECT * FROM BMESSAGES 
                WHERE BTRACKID = " . $trackId . " 
                AND BDIRECT = 'OUT' 
                AND BID > " . $inId . " 
                ORDER BY BID ASC 
                LIMIT 1";
        $res = DB::Query($sql);
        $outRow = DB::FetchArr($res);
        
        return ($outRow && is_array($outRow)) ? $outRow : null;
    }
    
    /**
     * Get eligible models
     * 
     * Query models by BTAG that are selectable, ordered by BRATING DESC, BID ASC.
     * 
     * @param string $btag Model tag (e.g., 'chat', 'pic2text', 'sound2text')
     * @return array Array of model rows
     */
    public static function getEligibleModels($btag) {
        $btag = DB::EscString($btag);
        
        // Get minimum rating from config, fallback to no filtering
        $minRating = self::getMinRatingFromConfig();
        $ratingFilter = $minRating !== null ? " AND BRATING > " . floatval($minRating) : "";
        
        $sql = "SELECT * FROM BMODELS 
                WHERE BTAG = '" . $btag . "' AND BSELECTABLE = 1" . $ratingFilter . "
                ORDER BY BRATING DESC, BID ASC";
        $res = DB::Query($sql);
        
        $models = [];
        while ($row = DB::FetchArr($res)) {
            if ($row && is_array($row)) {
                $models[] = $row;
            }
        }
        
        return $models;
    }

    /**
     * Get minimum rating threshold from BCONFIG
     * @return float|null Minimum rating or null if not configured
     */
    private static function getMinRatingFromConfig() {
        try {
            $sql = "SELECT BVALUE FROM BCONFIG WHERE BGROUP = 'MODEL' AND BSETTING = 'MIN_RATING' LIMIT 1";
            $res = DB::Query($sql);
            $row = DB::FetchArr($res);
            
            if ($row && is_array($row) && is_numeric($row['BVALUE'])) {
                return floatval($row['BVALUE']);
            }
            
            // Fallback: no filtering if not configured
            return null;
        } catch (\Throwable $e) {
            error_log("Error getting min rating from config: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Pick model
     * 
     * Rotation logic: if prevModelId is in eligible list, pick next element (with wraparound).
     * Otherwise, pick the first eligible model.
     * 
     * @param array $eligible Array of eligible model rows
     * @param int|null $prevModelId Previous model ID used
     * @return array Selected model row
     * @throws Exception if no eligible models
     */
    public static function pickModel($eligible, $prevModelId) {
        if (empty($eligible)) {
            http_response_code(422);
            throw new Exception("No eligible models available");
        }
        
        // If no previous model or only one model available, return first
        if ($prevModelId === null || count($eligible) === 1) {
            return $eligible[0];
        }
        
        // Find the index of the previous model
        $prevIndex = -1;
        for ($i = 0; $i < count($eligible); $i++) {
            if (intval($eligible[$i]['BID']) === $prevModelId) {
                $prevIndex = $i;
                break;
            }
        }
        
        // If previous model not found in eligible list, return first
        if ($prevIndex === -1) {
            return $eligible[0];
        }
        
        // Return next model (with wraparound)
        $nextIndex = ($prevIndex + 1) % count($eligible);
        return $eligible[$nextIndex];
    }
    
    /**
     * Resolve tag for replay
     * 
     * Determine BTAG from IN message metadata, with fallbacks for legacy data.
     * 
     * @param int $inId IN message ID
     * @param array|null $lastOut Last OUT message array
     * @return string BTAG to use
     */
    public static function resolveTagForReplay($inId, $lastOut) {
        // 1. Primary: Read BTAG from IN message metadata (new approach)
        $btagSQL = "SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = " . intval($inId) . " AND BTOKEN = 'BTAG' ORDER BY BID DESC LIMIT 1";
        $btagRes = DB::Query($btagSQL);
        $btagRow = DB::FetchArr($btagRes);
        
        if ($btagRow && is_array($btagRow) && !empty($btagRow['BVALUE'])) {
            return $btagRow['BVALUE']; // Direct BTAG from metadata
        }
        
        // 2. Legacy fallback: Try OUT message BPROVIDX
        if ($lastOut && !empty($lastOut['BPROVIDX'])) {
            $modelId = intval($lastOut['BPROVIDX']);
            if ($modelId > 0) {
                $modelSQL = "SELECT BTAG FROM BMODELS WHERE BID = " . $modelId . " LIMIT 1";
                $modelRes = DB::Query($modelSQL);
                $modelRow = DB::FetchArr($modelRes);
                if ($modelRow && is_array($modelRow) && !empty($modelRow['BTAG'])) {
                    return $modelRow['BTAG'];
                }
            }
        }
        
        // 3. Final fallback: default to chat
        return 'chat';
    }
    
    /**
     * Replay
     * 
     * Main method that orchestrates the replay process. Resolves the IN message,
     * determines the BTAG, handles model selection, and returns preparation data.
     * 
     * @param int $prevMessageId Previous message ID (IN or OUT)
     * @param int|null $modelIdOpt Optional specific model ID to use
     * @param int $userId User ID for ownership validation
     * @return array Replay preparation data
     * @throws Exception with various HTTP status codes for different errors
     */
    public static function replay($prevMessageId, $modelIdOpt, $userId) {
        try {
            // Step 1: Resolve the IN message
            $inMessage = self::getPrevInMessage($prevMessageId, $userId);
            $inId = intval($inMessage['BID']);
            
            // Step 2: Get last OUT for this IN
            $lastOut = self::getLastOutForIn($inId);
            
            // Step 3: Resolve BTAG
            $btag = self::resolveTagForReplay($inId, $lastOut);
            
            // Step 4: Handle model selection
            if ($modelIdOpt !== null) {
                // Validate provided model
                $modelId = intval($modelIdOpt);
                $sql = "SELECT * FROM BMODELS WHERE BID = " . $modelId . " AND BSELECTABLE = 1 LIMIT 1";
                $res = DB::Query($sql);
                $selectedModel = DB::FetchArr($res);
                
                if (!$selectedModel || !is_array($selectedModel)) {
                    http_response_code(422);
                    throw new Exception("Invalid model ID or model not selectable");
                }
                
                // Validate BTAG match
                if ($selectedModel['BTAG'] !== $btag) {
                    http_response_code(422);
                    throw new Exception("Model BTAG (" . $selectedModel['BTAG'] . ") does not match expected BTAG (" . $btag . ")");
                }
            } else {
                // Auto-select next model
                $eligible = self::getEligibleModels($btag);
                
                if (empty($eligible)) {
                    http_response_code(422);
                    throw new Exception("No eligible models found for BTAG: " . $btag);
                }
                
                // Get previous model ID from last OUT
                $prevModelId = null;
                if ($lastOut && !empty($lastOut['BPROVIDX'])) {
                    $prevModelId = intval($lastOut['BPROVIDX']);
                }
                
                $selectedModel = self::pickModel($eligible, $prevModelId);
            }
            
            // Return preparation data
            return [
                'model_id' => intval($selectedModel['BID']),
                'service' => $selectedModel['BSERVICE'],
                // Use BPROVID if available, fallback to BNAME
                'model' => !empty($selectedModel['BPROVID']) ? $selectedModel['BPROVID'] : $selectedModel['BNAME'],
                'btag' => $selectedModel['BTAG'],
                'prev_message_id' => $inId,
                'in_message' => $inMessage,
                'selectedModel' => $selectedModel  // Add full model row
            ];
            
        } catch (Exception $e) {
            // Re-throw with preserved HTTP status code
            throw $e;
        }
    }

    /**
     * Prepare Again globals and response.
     * Accepts raw request params, resolves IN message, and (optionally) sets forced model globals.
     * Returns a compact success response for API.
     */
    public static function prepareAgain(array $params) {
        try {
            // Resolve IN message id
            $inId = isset($params['in_id']) ? intval($params['in_id']) : 0;
            if ($inId <= 0) {
                $inId = Frontend::getLastInMessageIdForCurrentContext();
            }
            if ($inId <= 0) {
                return ['success' => false, 'error' => 'No previous IN message found'];
            }

            // Validate IN exists and is an IN message
            $msgArr = Central::getMsgById($inId);
            if (!$msgArr || $msgArr['BDIRECT'] !== 'IN') {
                return ['success' => false, 'error' => 'Invalid IN message ID'];
            }

            // Set base Again flag
            $GLOBALS['IS_AGAIN'] = true;

            // Optional model forcing
            $modelIdOpt = isset($params['model_id']) ? intval($params['model_id']) : null;
            if ($modelIdOpt) {
                $modelSQL = "SELECT * FROM BMODELS WHERE BID = " . $modelIdOpt . " AND BSELECTABLE = 1 LIMIT 1";
                $modelRes = db::Query($modelSQL);
                $selectedModel = db::FetchArr($modelRes);
                if (!$selectedModel || !is_array($selectedModel)) {
                    return ['success' => false, 'error' => 'Invalid model ID or model not selectable'];
                }
                $GLOBALS['FORCE_AI_MODEL']   = true;
                $GLOBALS['FORCED_AI_SERVICE'] = 'AI' . $selectedModel['BSERVICE'];
                $GLOBALS['FORCED_AI_MODEL']   = !empty($selectedModel['BPROVID']) ? $selectedModel['BPROVID'] : $selectedModel['BNAME'];
                $GLOBALS['FORCED_AI_MODELID'] = intval($selectedModel['BID']);
                $GLOBALS['FORCED_AI_BTAG']    = $selectedModel['BTAG'];
            }

            // Optional prompt id passthrough
            $resp = [
                'success' => true,
                'time'    => date('Y-m-d H:i:s')
            ];
            if ($modelIdOpt && isset($selectedModel)) {
                $resp['again'] = ['model_id' => intval($selectedModel['BID'])];
            }
            if (isset($params['promptId'])) {
                $resp['promptId'] = $params['promptId'];
            }
            return $resp;
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
