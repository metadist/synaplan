<?php
/**
 * MessageHistory Class
 * 
 * Handles retrieval and management of chat message history
 * for browsing and searching through past conversations.
 * 
 * @package Support
 */

class MessageHistory {
    
    /**
     * Get paginated user message prompts with file attachments
     * 
     * @param int $userId User ID
     * @param int $page Page number (1-indexed)
     * @param int $perPage Items per page (default 15)
     * @param array $filters Optional filters (keyword, hasAttachments, dateFrom, dateTo)
     * @return array Result with prompts, pagination info, and status
     */
    public static function getUserPrompts($userId, $page = 1, $perPage = 15, $filters = []) {
        $userId = intval($userId);
        $page = max(1, intval($page));
        $perPage = max(1, min(100, intval($perPage))); // Cap at 100
        $offset = ($page - 1) * $perPage;
        
        // Build WHERE clause with filters
        $where = "BMESSAGES.BUSERID = " . $userId . " 
                  AND BMESSAGES.BDIRECT = 'IN'
                  AND BMESSAGES.BTEXT != ''";
        
        // Keyword filter
        if (!empty($filters['keyword'])) {
            $keyword = db::EscString($filters['keyword']);
            $where .= " AND BMESSAGES.BTEXT LIKE '%" . $keyword . "%'";
        }
        
        // Has attachments filter
        if (isset($filters['hasAttachments'])) {
            if ($filters['hasAttachments'] === 'yes') {
                $where .= " AND BMESSAGES.BFILE > 0";
            } elseif ($filters['hasAttachments'] === 'no') {
                $where .= " AND BMESSAGES.BFILE = 0";
            }
        }
        
        // Date range filters
        if (!empty($filters['dateFrom'])) {
            $dateFrom = db::EscString($filters['dateFrom']);
            $where .= " AND BMESSAGES.BDATETIME >= '" . $dateFrom . "'";
        }
        if (!empty($filters['dateTo'])) {
            $dateTo = db::EscString($filters['dateTo']);
            $where .= " AND BMESSAGES.BDATETIME <= '" . $dateTo . " 23:59:59'";
        }
        
        // Get total count for pagination
        $countSQL = "SELECT COUNT(*) as total FROM BMESSAGES WHERE " . $where;
        $countRes = db::Query($countSQL);
        $countArr = db::FetchArr($countRes);
        $totalItems = intval($countArr['total']);
        $totalPages = ceil($totalItems / $perPage);
        
        // Get paginated prompts with file info
        // Files are stored as separate messages with same BTRACKID, BDIRECT='IN', and timestamps within 5 seconds
        $sql = "SELECT 
                    BMESSAGES.BID,
                    BMESSAGES.BTEXT,
                    BMESSAGES.BDATETIME,
                    BMESSAGES.BUNIXTIMES,
                    BMESSAGES.BFILE,
                    BMESSAGES.BTOPIC,
                    BMESSAGES.BTRACKID,
                    GROUP_CONCAT(DISTINCT files.BFILEPATH ORDER BY files.BID SEPARATOR '|||') as FILE_PATHS,
                    GROUP_CONCAT(DISTINCT files.BFILETYPE ORDER BY files.BID SEPARATOR '|||') as FILE_TYPES,
                    GROUP_CONCAT(DISTINCT files.BID ORDER BY files.BID SEPARATOR '|||') as FILE_IDS
                FROM BMESSAGES
                LEFT JOIN BMESSAGES as files ON files.BTRACKID = BMESSAGES.BTRACKID 
                    AND files.BUSERID = BMESSAGES.BUSERID
                    AND files.BDIRECT = 'IN'
                    AND files.BFILE > 0
                    AND files.BFILEPATH != ''
                    AND ABS(files.BUNIXTIMES - BMESSAGES.BUNIXTIMES) <= 5
                WHERE " . $where . "
                GROUP BY BMESSAGES.BID
                ORDER BY BMESSAGES.BID DESC
                LIMIT " . $offset . ", " . $perPage;
        
        $res = db::Query($sql);
        $prompts = [];
        
        while ($row = db::FetchArr($res)) {
            $files = [];
            if (!empty($row['FILE_PATHS'])) {
                $paths = explode('|||', $row['FILE_PATHS']);
                $types = explode('|||', $row['FILE_TYPES']);
                $ids = explode('|||', $row['FILE_IDS']);
                
                for ($i = 0; $i < count($paths); $i++) {
                    $files[] = [
                        'id' => $ids[$i],
                        'path' => $paths[$i],
                        'type' => $types[$i],
                        'name' => basename($paths[$i])
                    ];
                }
            }
            
            $prompts[] = [
                'id' => $row['BID'],
                'text' => $row['BTEXT'],
                'datetime' => $row['BDATETIME'],
                'timestamp' => $row['BUNIXTIMES'],
                'topic' => $row['BTOPIC'],
                'trackId' => $row['BTRACKID'],
                'hasFiles' => $row['BFILE'] > 0,
                'files' => $files
            ];
        }
        
        return [
            'success' => true,
            'prompts' => $prompts,
            'pagination' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1
            ]
        ];
    }
    
    /**
     * Get AI answers for a specific user message (prompt)
     * 
     * @param int $userId User ID
     * @param int $promptId Message ID of the user prompt
     * @return array Result with answers and status
     */
    public static function getAnswersForPrompt($userId, $promptId) {
        $userId = intval($userId);
        $promptId = intval($promptId);
        
        // First, verify the prompt belongs to the user and get its BTRACKID
        $verifySQL = "SELECT BTRACKID, BUNIXTIMES FROM BMESSAGES 
                      WHERE BID = " . $promptId . " 
                      AND BUSERID = " . $userId . "
                      AND BDIRECT = 'IN'";
        $verifyRes = db::Query($verifySQL);
        $verifyArr = db::FetchArr($verifyRes);
        
        if (!$verifyArr) {
            return ['success' => false, 'error' => 'Prompt not found or access denied'];
        }
        
        $trackId = $verifyArr['BTRACKID'];
        $promptTime = $verifyArr['BUNIXTIMES'];
        
        // Get all OUT messages in the same track that came after this prompt
        $sql = "SELECT 
                    BID,
                    BTEXT,
                    BDATETIME,
                    BUNIXTIMES,
                    BTOPIC,
                    BSTATUS
                FROM BMESSAGES
                WHERE BUSERID = " . $userId . "
                AND BTRACKID = " . $trackId . "
                AND BDIRECT = 'OUT'
                AND BUNIXTIMES >= " . $promptTime . "
                ORDER BY BID ASC
                LIMIT 10";
        
        $res = db::Query($sql);
        $answers = [];
        
        while ($row = db::FetchArr($res)) {
            $answers[] = [
                'id' => $row['BID'],
                'text' => $row['BTEXT'],
                'datetime' => $row['BDATETIME'],
                'timestamp' => $row['BUNIXTIMES'],
                'topic' => $row['BTOPIC'],
                'status' => $row['BSTATUS']
            ];
        }
        
        return [
            'success' => true,
            'answers' => $answers,
            'trackId' => $trackId
        ];
    }
    
    /**
     * Get detailed information about a specific answer
     * 
     * @param int $userId User ID
     * @param int $answerId Message ID of the AI answer
     * @return array Result with answer details and status
     */
    public static function getAnswerDetails($userId, $answerId) {
        $userId = intval($userId);
        $answerId = intval($answerId);
        
        // Get the answer with verification
        $sql = "SELECT 
                    BID,
                    BTEXT,
                    BDATETIME,
                    BUNIXTIMES,
                    BTOPIC,
                    BSTATUS,
                    BTRACKID,
                    BPROVIDX
                FROM BMESSAGES
                WHERE BID = " . $answerId . "
                AND BUSERID = " . $userId . "
                AND BDIRECT = 'OUT'";
        
        $res = db::Query($sql);
        $row = db::FetchArr($res);
        
        if (!$row) {
            return ['success' => false, 'error' => 'Answer not found or access denied'];
        }
        
        return [
            'success' => true,
            'answer' => [
                'id' => $row['BID'],
                'text' => $row['BTEXT'],
                'datetime' => $row['BDATETIME'],
                'timestamp' => $row['BUNIXTIMES'],
                'topic' => $row['BTOPIC'],
                'status' => $row['BSTATUS'],
                'trackId' => $row['BTRACKID'],
                'provider' => $row['BPROVIDX']
            ]
        ];
    }
    
    /**
     * Get summary statistics for user's message history
     * 
     * @param int $userId User ID
     * @return array Statistics about the user's messages
     */
    public static function getUserStats($userId) {
        $userId = intval($userId);
        
        $sql = "SELECT 
                    COUNT(*) as total_prompts,
                    SUM(CASE WHEN BFILE > 0 THEN 1 ELSE 0 END) as prompts_with_files,
                    COUNT(DISTINCT BTRACKID) as unique_conversations,
                    MIN(BDATETIME) as first_message,
                    MAX(BDATETIME) as last_message
                FROM BMESSAGES
                WHERE BUSERID = " . $userId . "
                AND BDIRECT = 'IN'
                AND BTEXT != ''";
        
        $res = db::Query($sql);
        $row = db::FetchArr($res);
        
        return [
            'success' => true,
            'stats' => $row
        ];
    }
}
