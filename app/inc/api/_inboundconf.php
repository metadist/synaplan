<?php

// -----------------------------------------------------
// Inbound configuration
// -----------------------------------------------------

class InboundConf
{
    public static function getWhatsAppNumbers()
    {
        $numArr = [];
        $userId = $_SESSION['USERPROFILE']['BID'];
        $waSQL = 'select * from BWAPHONES where BOWNERID = '.$userId.' OR BOWNERID = 0 ORDER BY BOWNERID DESC';
        $waRes = db::Query($waSQL);
        while ($row = db::FetchArr($waRes)) {
            $numArr[] = $row;
        }
        return $numArr;
    }
    // *****************************************************
    // set the widget domain(s) in the BCONFIG table
    public static function setWidgetDomain($domain)
    {
        $userId = $_SESSION['USERPROFILE']['BID'];
        $waSQL = '';
        $waRes = db::Query($waSQL);
    }

    // *****************************************************
    // Gmail keyword management for smart+KEYWORD@synaplan.com
    // *****************************************************

    /**
     * Get the current Gmail keyword for the logged-in user
     * @return string|null The keyword or null if not set
     */
    public static function getGmailKeyword()
    {
        $userId = $_SESSION['USERPROFILE']['BID'];
        $sql = 'SELECT BVALUE FROM BCONFIG WHERE BOWNERID = ' . intval($userId) . " AND BGROUP = 'GMAILKEY' AND BSETTING = 'keyword' LIMIT 1";
        $res = db::Query($sql);

        if ($row = db::FetchArr($res)) {
            return $row['BVALUE'];
        }

        return null;
    }

    /**
     * Validate keyword format
     * Must be at least 4 characters, only alphanumeric, underscore, and hyphen
     * @param string $keyword The keyword to validate
     * @return array ['valid' => bool, 'message' => string]
     */
    public static function validateKeywordFormat($keyword)
    {
        // Check if keyword is empty
        if (empty($keyword)) {
            return ['valid' => false, 'message' => 'Keyword cannot be empty'];
        }

        // Check minimum length
        if (strlen($keyword) < 4) {
            return ['valid' => false, 'message' => 'Keyword must be at least 4 characters long'];
        }

        // Check for invalid characters (only allow letters, numbers, underscore, hyphen)
        // Gmail doesn't allow special chars like +*#' etc in the plus addressing part
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $keyword)) {
            return ['valid' => false, 'message' => 'Keyword can only contain letters, numbers, underscores, and hyphens'];
        }

        return ['valid' => true, 'message' => 'Keyword format is valid'];
    }

    /**
     * Check if keyword is unique system-wide
     * @param string $keyword The keyword to check
     * @param int|null $excludeUserId Optional user ID to exclude (for updates)
     * @return bool True if unique, false if already taken
     */
    public static function isKeywordUnique($keyword, $excludeUserId = null)
    {
        $keyword = db::EscString($keyword);
        $sql = "SELECT BOWNERID FROM BCONFIG WHERE BGROUP = 'GMAILKEY' AND BSETTING = 'keyword' AND BVALUE = '" . $keyword . "'";

        if ($excludeUserId !== null) {
            $sql .= ' AND BOWNERID != ' . intval($excludeUserId);
        }

        $sql .= ' LIMIT 1';
        $res = db::Query($sql);

        // Debug logging
        if ($GLOBALS['debug'] ?? false) {
            error_log('isKeywordUnique SQL: ' . $sql);
            error_log('Query result: ' . ($res ? 'valid result' : 'false'));
        }

        // Count how many rows we got
        $rowCount = $res ? db::CountRows($res) : 0;

        // Debug logging
        if ($GLOBALS['debug'] ?? false) {
            error_log('Row count: ' . $rowCount);
        }

        // If we found 0 rows, keyword is unique (available)
        // If we found 1+ rows, keyword is taken (not unique)
        return ($rowCount === 0);
    }

    /**
     * Save or update Gmail keyword for the current user
     * @param string $keyword The keyword to save
     * @return array ['success' => bool, 'message' => string]
     */
    public static function saveGmailKeyword($keyword)
    {
        $userId = $_SESSION['USERPROFILE']['BID'];

        // Normalize keyword (trim and lowercase for consistency)
        $keyword = strtolower(trim($keyword));

        // Validate format
        $validation = self::validateKeywordFormat($keyword);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => $validation['message']];
        }

        // Check uniqueness (exclude current user's ID for updates)
        if (!self::isKeywordUnique($keyword, $userId)) {
            return ['success' => false, 'message' => 'This keyword is already taken. Please choose another one.'];
        }

        // Check if user already has a keyword
        $existingKeyword = self::getGmailKeyword();
        $keyword = db::EscString($keyword);

        if ($existingKeyword !== null) {
            // Update existing keyword
            $sql = "UPDATE BCONFIG SET BVALUE = '" . $keyword . "' WHERE BOWNERID = " . intval($userId) . " AND BGROUP = 'GMAILKEY' AND BSETTING = 'keyword'";
        } else {
            // Insert new keyword
            $sql = 'INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (' . intval($userId) . ", 'GMAILKEY', 'keyword', '" . $keyword . "')";
        }

        $res = db::Query($sql);

        if ($res) {
            return ['success' => true, 'message' => 'Keyword saved successfully!', 'keyword' => $keyword];
        } else {
            return ['success' => false, 'message' => 'Database error: Could not save keyword'];
        }
    }

    /**
     * Test if a keyword is available (for AJAX checking)
     * @param string $keyword The keyword to test
     * @return array ['available' => bool, 'message' => string]
     */
    public static function testKeywordAvailability($keyword)
    {
        $userId = $_SESSION['USERPROFILE']['BID'];

        // Normalize keyword
        $keyword = strtolower(trim($keyword));

        // Validate format first
        $validation = self::validateKeywordFormat($keyword);
        if (!$validation['valid']) {
            return ['available' => false, 'message' => $validation['message'], 'type' => 'format'];
        }

        // Check uniqueness
        if (!self::isKeywordUnique($keyword, $userId)) {
            return ['available' => false, 'message' => 'This keyword is already taken', 'type' => 'taken'];
        }

        return ['available' => true, 'message' => 'Keyword is available!', 'type' => 'success'];
    }

    /**
     * Delete Gmail keyword for the current user
     * @return bool True if deleted successfully
     */
    public static function deleteGmailKeyword()
    {
        $userId = $_SESSION['USERPROFILE']['BID'];
        $sql = 'DELETE FROM BCONFIG WHERE BOWNERID = ' . intval($userId) . " AND BGROUP = 'GMAILKEY' AND BSETTING = 'keyword'";
        return db::Query($sql);
    }

    /**
     * Debug method to get all Gmail keywords in the system
     * @return array All GMAILKEY entries from BCONFIG
     */
    public static function getAllGmailKeywords()
    {
        $sql = "SELECT BID, BOWNERID, BGROUP, BSETTING, BVALUE FROM BCONFIG WHERE BGROUP = 'GMAILKEY' ORDER BY BOWNERID";
        $res = db::Query($sql);

        $keywords = [];
        while ($row = db::FetchArr($res)) {
            $keywords[] = $row;
        }

        return [
            'success' => true,
            'keywords' => $keywords,
            'count' => count($keywords)
        ];
    }

    /**
     * Get user ID by Gmail keyword
     * Returns the owner user ID for a given keyword, or system user (2) if not found/invalid
     * @param string $keyword The keyword to lookup
     * @return int User ID (owner of keyword, or 2 for system default)
     */
    public static function getUserIdByKeyword($keyword)
    {
        // Default to system user ID 2
        $systemUserId = 2;

        // Validate keyword
        if (empty($keyword) || strlen($keyword) < 4) {
            return $systemUserId;
        }

        // Normalize keyword (trim and lowercase)
        $keyword = strtolower(trim($keyword));

        // Escape for SQL
        $keyword = db::EscString($keyword);

        // Query for keyword owner
        $sql = "SELECT BOWNERID FROM BCONFIG WHERE BGROUP = 'GMAILKEY' AND BSETTING = 'keyword' AND BVALUE = '" . $keyword . "' LIMIT 1";
        $res = db::Query($sql);

        if ($row = db::FetchArr($res)) {
            $ownerId = intval($row['BOWNERID']);
            // Validate that owner ID is valid (greater than 0)
            return ($ownerId > 0) ? $ownerId : $systemUserId;
        }

        // No keyword found, return system user
        return $systemUserId;
    }

    /**
     * Extract keyword from email address like "smart+keyword@synaplan.com"
     * @param string $emailAddress Full email address
     * @return string|null Keyword if found, null otherwise
     */
    public static function extractKeywordFromEmail($emailAddress)
    {
        // Remove any name part: "John Doe <smart+support@synaplan.com>" -> "smart+support@synaplan.com"
        if (strpos($emailAddress, '<') !== false) {
            preg_match('/<([^>]+)>/', $emailAddress, $matches);
            if (isset($matches[1])) {
                $emailAddress = $matches[1];
            }
        }

        // Check if email contains '+'
        if (strpos($emailAddress, '+') === false) {
            return null;
        }

        // Extract keyword between + and @
        // Example: smart+support@synaplan.com -> support
        preg_match('/\+([^@]+)@/', $emailAddress, $matches);

        if (isset($matches[1])) {
            return trim($matches[1]);
        }

        return null;
    }
}
