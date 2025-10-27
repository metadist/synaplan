<?php

/**
 * API Key Management Class
 *
 * Handles all API key related operations including creation, listing,
 * status management and deletion. Extracted from Frontend class for
 * better separation of concerns.
 *
 * @package Auth
 */

class ApiKeyManager
{
    /**
     * Get all API keys for the current user
     *
     * Returns a list of API keys with masked key values for security.
     * Only shows first 12 and last 4 characters of the actual key.
     *
     * @return array Array with success status and keys list
     */
    public static function getApiKeys(): array {
        $ret = ['success' => false, 'keys' => []];

        // Check if user is logged in
        if (!isset($_SESSION['USERPROFILE']['BID'])) {
            return $ret;
        }

        $uid = intval($_SESSION['USERPROFILE']['BID']);

        // Query API keys with masked key values for security
        $sql = "SELECT BID, BOWNERID, BNAME, CONCAT(SUBSTRING(BKEY,1,12),'...',RIGHT(BKEY,4)) AS BMASKEDKEY, BSTATUS, BCREATED, BLASTUSED 
                FROM BAPIKEYS 
                WHERE BOWNERID = " . $uid . ' 
                ORDER BY BID DESC';

        $res = db::Query($sql);
        $rows = [];

        while ($row = db::FetchArr($res)) {
            if ($row && is_array($row)) {
                $rows[] = $row;
            }
        }

        $ret['success'] = true;
        $ret['keys'] = $rows;

        return $ret;
    }

    /**
     * Create a new API key for the current user
     *
     * Generates a secure API key with 'sk_live_' prefix and stores it
     * in the database with active status.
     *
     * @return array Array with success status and the generated key
     */
    public static function createApiKey(): array {
        $ret = ['success' => false];

        // Check if user is logged in
        if (!isset($_SESSION['USERPROFILE']['BID'])) {
            return $ret;
        }

        $uid = intval($_SESSION['USERPROFILE']['BID']);
        $name = isset($_REQUEST['name']) ? db::EscString($_REQUEST['name']) : '';
        $now = time();

        // Generate secure API key
        $random = bin2hex(random_bytes(24));
        $key = 'sk_live_' . $random;

        // Insert new API key into database
        $ins = 'INSERT INTO BAPIKEYS (BOWNERID, BNAME, BKEY, BSTATUS, BCREATED, BLASTUSED) 
                VALUES (' . $uid . ", '" . $name . "', '" . db::EscString($key) . "', 'active', " . $now . ', 0)';

        db::Query($ins);

        $ret['success'] = true;
        $ret['key'] = $key;

        return $ret;
    }

    /**
     * Set API key status (active/paused)
     *
     * Updates the status of an API key. Only the owner can modify their keys.
     *
     * @return array Array with success status
     */
    public static function setApiKeyStatus(): array {
        $ret = ['success' => false];

        // Check if user is logged in
        if (!isset($_SESSION['USERPROFILE']['BID'])) {
            return $ret;
        }

        $uid = intval($_SESSION['USERPROFILE']['BID']);
        $id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
        $status = isset($_REQUEST['status']) ? db::EscString($_REQUEST['status']) : '';

        // Validate input parameters
        if ($id <= 0 || !in_array($status, ['active', 'paused'])) {
            return $ret;
        }

        // Update API key status (only for keys owned by current user)
        $upd = "UPDATE BAPIKEYS 
                SET BSTATUS = '" . $status . "' 
                WHERE BID = " . $id . ' AND BOWNERID = ' . $uid;

        db::Query($upd);

        // Consider success even if no rows were affected (unchanged status)
        $ret['success'] = db::AffectedRows() ? true : true;

        return $ret;
    }

    /**
     * Delete an API key
     *
     * Permanently removes an API key from the database. Only the owner
     * can delete their keys.
     *
     * @return array Array with success status
     */
    public static function deleteApiKey(): array {
        $ret = ['success' => false];

        // Check if user is logged in
        if (!isset($_SESSION['USERPROFILE']['BID'])) {
            return $ret;
        }

        $uid = intval($_SESSION['USERPROFILE']['BID']);
        $id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;

        // Validate input parameters
        if ($id <= 0) {
            return $ret;
        }

        // Delete API key (only for keys owned by current user)
        $del = 'DELETE FROM BAPIKEYS 
                WHERE BID = ' . $id . ' AND BOWNERID = ' . $uid;

        db::Query($del);

        // Consider success even if no rows were affected (key didn't exist)
        $ret['success'] = db::AffectedRows() ? true : true;

        return $ret;
    }
}
