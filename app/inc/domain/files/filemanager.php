<?php

/**
 * File Management Class
 *
 * Handles all file-related operations including message files retrieval,
 * RAG file processing, and latest files queries. Extracted from Frontend
 * class for better separation of concerns.
 *
 * @package Files
 */

class FileManager
{
    /**
     * Get file details for a specific message
     *
     * Retrieves all files associated with a message based on track ID and timestamp
     *
     * @param int $messageId The message ID to get files for
     * @return array Array of files associated with the message
     */
    public static function getMessageFiles($messageId) {
        $files = [];

        // Handle anonymous widget sessions
        if (isset($_SESSION['is_widget']) && $_SESSION['is_widget'] === true) {
            // Use widget owner ID for anonymous sessions
            $userId = $_SESSION['widget_owner_id'];
        } else {
            // Regular authenticated user sessions
            $userId = $_SESSION['USERPROFILE']['BID'];
        }

        // First get the original message to find its track ID and timestamp
        $msgSQL = 'SELECT * FROM BMESSAGES WHERE BUSERID = '.$userId.' AND BID = '.intval($messageId);
        $msgRes = db::Query($msgSQL);
        $msgArr = db::FetchArr($msgRes);

        if ($msgArr) {
            // Get all messages with files that have the same track ID and are within a few seconds
            $timeWindow = 10; // seconds
            $sql = 'SELECT * FROM BMESSAGES WHERE BUSERID = '.$userId.' 
                    AND BTRACKID = '.$msgArr['BTRACKID'].' 
                    AND BFILE > 0 
                    AND ABS(BUNIXTIMES - '.$msgArr['BUNIXTIMES'].') <= '.$timeWindow.'
                    ORDER BY BID ASC';
            $res = db::Query($sql);
            while ($fileArr = db::FetchArr($res)) {
                if ($fileArr && is_array($fileArr) && !empty($fileArr['BFILEPATH']) && !empty($fileArr['BFILETYPE'])) {
                    $files[] = [
                        'BID' => $fileArr['BID'],
                        'BFILEPATH' => $fileArr['BFILEPATH'],
                        'BFILETYPE' => $fileArr['BFILETYPE'],
                        'BTEXT' => $fileArr['BTEXT'],
                        'BDATETIME' => $fileArr['BDATETIME']
                    ];
                }
            }
        }
        return $files;
    }

    /**
     * Save RAG files with custom group key
     *
     * Handles saving of RAG files with specific group keys for file manager uploads
     *
     * @return array Array containing status and processing information
     */
    public static function saveRAGFiles(): array {
        $retArr = ['error' => '', 'success' => false, 'processedFiles' => []];

        // Handle anonymous widget sessions
        if (isset($_SESSION['is_widget']) && $_SESSION['is_widget'] === true) {
            // Use widget owner ID for anonymous sessions
            $userId = $_SESSION['widget_owner_id'];
        } else {
            // Regular authenticated user sessions
            $userId = $_SESSION['USERPROFILE']['BID'];
        }

        // Get the group key from POST data or use default based on session type
        $groupKey = 'DEFAULT';
        if (isset($_SESSION['is_widget']) && $_SESSION['is_widget'] === true) {
            // Anonymous widget users use "WIDGET" as default group key
            $groupKey = isset($_REQUEST['groupKey']) ? trim(db::EscString($_REQUEST['groupKey'])) : 'WIDGET';
        } else {
            // Regular users can specify their own group key
            $groupKey = isset($_REQUEST['groupKey']) ? trim(db::EscString($_REQUEST['groupKey'])) : 'DEFAULT';
        }

        error_log('[RAG] saveRAGFiles: userId=' . $userId . ', groupKey="' . $groupKey . '", is_widget=' . (isset($_SESSION['is_widget']) ? 'yes' : 'no'));

        if (empty($groupKey) || $groupKey === '') {
            $retArr['error'] = 'Group key is required';
            error_log('[RAG] saveRAGFiles: ERROR - Group key is empty');
            return $retArr;
        }

        // Handle file uploads
        $filesArr = [];
        if (!empty($_FILES['files'])) {
            foreach ($_FILES['files']['tmp_name'] as $i => $tmpName) {
                if (!is_uploaded_file($tmpName)) {
                    $retArr['error'] .= 'Invalid upload: '.$_FILES['files']['name'][$i]."\n";
                    continue;
                }

                $originalName = $_FILES['files']['name'][$i];
                $fileSize = $_FILES['files']['size'][$i];

                if ($fileSize > 1024 * 1024 * 90) {
                    $retArr['error'] .= 'File too large: '.$originalName."\n";
                    continue;
                }

                $fileType = mime_content_type($tmpName);
                $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                // Use appropriate MIME type checking based on session type
                $mimeTypeAllowed = false;
                if (isset($_SESSION['is_widget']) && $_SESSION['is_widget'] === true) {
                    // Anonymous widget users have restricted file types
                    $mimeTypeAllowed = Central::checkMimeTypesForAnonymous($fileExtension, $fileType);
                } else {
                    // Regular authenticated users have full file type access
                    $mimeTypeAllowed = Central::checkMimeTypes($fileExtension, $fileType);
                }

                if ($mimeTypeAllowed) {
                    // Security: Sanitize HTML files to prevent malicious landing pages
                    $sanitizeResult = Central::sanitizeHtmlUpload($tmpName, $fileExtension);
                    if ($sanitizeResult['converted']) {
                        $fileExtension = $sanitizeResult['newExtension'];
                        $originalName = preg_replace('/\.(html?|htm)$/i', '.txt', $originalName);
                    }

                    // Create file path
                    $userRelPath = substr($userId, -5, 3) . '/' . substr($userId, -2, 2) . '/' . date('Ym') . '/';
                    $fullUploadDir = rtrim(UPLOAD_DIR, '/').'/' . $userRelPath;
                    if (!is_dir($fullUploadDir)) {
                        mkdir($fullUploadDir, 0755, true);
                    }

                    $newFileName = Tools::sysStr($originalName);
                    $targetPath = $fullUploadDir . $newFileName;

                    // Save file - write sanitized content for HTML, or move file for others
                    $uploadSuccess = false;
                    if ($sanitizeResult['converted']) {
                        $uploadSuccess = file_put_contents($targetPath, $sanitizeResult['content']) !== false;
                        @unlink($tmpName); // Clean up temp file
                    } else {
                        $uploadSuccess = move_uploaded_file($tmpName, $targetPath);
                    }

                    // Move uploaded file
                    if ($uploadSuccess) {
                        // Create message entry first
                        $inMessageArr = [];
                        $inMessageArr['BUSERID'] = $userId;
                        $inMessageArr['BTEXT'] = 'RAG file: ' . $originalName;
                        $inMessageArr['BUNIXTIMES'] = time();
                        $inMessageArr['BDATETIME'] = date('YmdHis');
                        $inMessageArr['BTRACKID'] = (int) (microtime(true) * 1000000);
                        $inMessageArr['BLANG'] = 'en';
                        $inMessageArr['BTOPIC'] = 'RAG';
                        $inMessageArr['BID'] = 'DEFAULT';
                        $inMessageArr['BPROVIDX'] = session_id();
                        $inMessageArr['BMESSTYPE'] = 'RAG';
                        $inMessageArr['BFILE'] = 1;
                        $inMessageArr['BFILEPATH'] = $userRelPath . $newFileName;
                        $inMessageArr['BFILETYPE'] = $fileExtension;
                        $inMessageArr['BDIRECT'] = 'IN';
                        $inMessageArr['BSTATUS'] = 'NEW';
                        $inMessageArr['BFILETEXT'] = '';

                        // Save to database
                        $resArr = Central::handleInMessage($inMessageArr);

                        if ($resArr['lastId'] > 0) {
                            $filesArr[] = [
                                'BID' => $resArr['lastId'],
                                'BFILEPATH' => $userRelPath . $newFileName,
                                'BFILETYPE' => $fileExtension,
                                'BTEXT' => 'RAG file: ' . $originalName
                            ];
                        }
                    } else {
                        $retArr['error'] .= 'Failed to move file: '.$originalName."\n";
                    }
                } else {
                    $retArr['error'] .= 'Invalid file type: '.$fileExtension."\n";
                }
            }
        }

        if (count($filesArr) == 0) {
            $retArr['error'] = 'No valid files uploaded';
            return $retArr;
        }

        // Process files through RAG system
        error_log('[RAG] saveRAGFiles: Calling Central::processRAGFiles with ' . count($filesArr) . ' files, groupKey="' . $groupKey . '"');
        if ($GLOBALS['debug']) {
            error_log('* * * * * * ************** _________ PROCESSING RAG FILES: '.print_r($filesArr, true));
        }
        $ragResult = Central::processRAGFiles($filesArr, $userId, $groupKey, false);
        error_log('[RAG] saveRAGFiles: processRAGFiles returned - success=' . ($ragResult['success'] ? 'true' : 'false') . ', processedCount=' . ($ragResult['processedCount'] ?? 0) . ', groupKey in result=' . ($ragResult['groupKey'] ?? 'N/A'));

        $retArr['success'] = $ragResult['success'];
        $retArr['processedFiles'] = $ragResult['results'];
        $retArr['processedCount'] = $ragResult['processedCount'];
        $retArr['totalFiles'] = $ragResult['totalFiles'];
        $retArr['groupKey'] = $ragResult['groupKey'];
        $retArr['message'] = 'Successfully processed ' . $ragResult['processedCount'] . ' out of ' . $ragResult['totalFiles'] . ' files with group key: ' . $groupKey;

        return $retArr;
    }

    /**
     * Get latest files for the dashboard
     *
     * Retrieves the most recent files uploaded by the current user
     *
     * @param int $limit Maximum number of files to return
     * @return array Array of latest files
     */
    public static function getLatestFiles($limit = 10): array {
        $userId = $_SESSION['USERPROFILE']['BID'];
        $files = [];

        $sql = 'SELECT BID, BFILEPATH, BFILETYPE, BTEXT, BDIRECT, BDATETIME, BTOPIC 
                FROM BMESSAGES 
                WHERE BUSERID = '.$userId." 
                AND BFILE > 0 
                AND BFILEPATH != '' 
                ORDER BY BID DESC 
                LIMIT ".intval($limit);

        $res = db::Query($sql);
        while ($fileArr = db::FetchArr($res)) {
            if ($fileArr && is_array($fileArr) && !empty($fileArr['BFILEPATH']) && !empty($fileArr['BFILETYPE'])) {
                $files[] = [
                    'BID' => $fileArr['BID'],
                    'BFILEPATH' => $fileArr['BFILEPATH'],
                    'BFILETYPE' => $fileArr['BFILETYPE'],
                    'BTEXT' => $fileArr['BTEXT'],
                    'BDIRECT' => $fileArr['BDIRECT'],
                    'BDATETIME' => $fileArr['BDATETIME'],
                    'BTOPIC' => $fileArr['BTOPIC'],
                    'FILENAME' => basename($fileArr['BFILEPATH'])
                ];
            }
        }

        return $files;
    }
}
