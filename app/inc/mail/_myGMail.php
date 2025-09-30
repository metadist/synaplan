<?php
use Google\Client as Google_Client;
use Google\Service\Gmail as Google_Service_Gmail;

class myGMail {
    private static $allowedAttachmentTypes = ['pdf', 'docx', 'pptx', 'jpg', 'jpeg', 'png', 'mp3'];
    private static $excludedFilenames = ['logo', 'footer', 'signature', 'banner'];

    /**
     * Called by getMail() to refresh the OAuth token if expired
     * Returns a configured Google client with valid access token
     */
    public static function refreshToken() {
        try {
            $client = OAuthConfig::createGoogleClient($GLOBALS["baseUrl"].'gmail_callback2oauth.php');
            return $client;
        } catch (Exception $e) {
            throw new Exception('OAuth configuration error: ' . $e->getMessage());
        }
    }

    public static function getMail() {
        try {
            $client = self::refreshToken();
            $service = new Google_Service_Gmail($client);
            
            $messagesResponse = $service->users_messages->listUsersMessages('me', [
                'labelIds' => ['INBOX'],
                'maxResults' => 20
            ]);

            $messages = $messagesResponse->getMessages();
            $processedMails = [];

            foreach ($messages as $message) {
                $processedMail = self::processMessage($service, $message->getId());
                if ($processedMail) {
                    $processedMails[] = $processedMail;
                }
            }

            return $processedMails;
        } catch (Exception $e) {
            throw new Exception('Error processing emails: ' . $e->getMessage());
        }
    }

    // function to extract the phone number from the to string
    // eg.: "Some Guy <smart+49175407011@gmail.com>"
    // returns: 49175407011
    private static function extractPhoneNumberOrTag($toString) {
        if(substr_count($toString, "+") > 0) {
            $mailParts = explode('+', $toString);
            $phoneParts = explode('@', $mailParts[1]);
            return db::EscString($phoneParts[0]);
        } else {
            // this can easily lead to double entries,
            // fix the search by complete mail address!
            $mailParts = explode('<', $toString);
            $mailParts = explode('@', $mailParts[1]);
            return db::EscString($mailParts[0]);
        }
    }

    /**
     * Called by getMail() to process a single email message
     * Extracts message details (subject, from, to, date) and processes attachments
     * @param $service Gmail Service instance
     * @param $messageId ID of the message to process
     * @return array Processed mail data including headers and attachments
     */
    private static function processMessage($service, $messageId) {
        $message = $service->users_messages->get('me', $messageId, ['format' => 'full']);

        $payload = $message->getPayload();
        $mimeType = $payload->getMimeType();
        $headers = $payload->getHeaders();
        
        $usrArr = [];

        $mailData = [
            'message_id' => $messageId,
            'subject' => '',
            'from' => '',
            'to' => '',
            'date' => '',
            'body' => '',
            'attachments' => []
        ];

        // Process headers
        foreach ($headers as $header) {
            //print "HEADER: " . $header->getName() . " === " . $header->getValue() . "\n";
            switch ($header->getName()) {
                case 'Subject':
                    $mailData['subject'] = $header->getValue();
                    break;
                case 'From':
                    $mailData['from'] = $header->getValue();
                    break;
                case 'To':
                    $mailData['to'] = $header->getValue();
                    $mailData['usrPhoneOrTag'] = self::extractPhoneNumberOrTag($mailData['to']);
                    break;
                case 'Date':
                    $mailData['date'] = $header->getValue();
                    break;
            }
        }

        // check if the mail is to an existing user
        // get the assigned email
        // print_r($mailData);
        $mailData['plainmail'] = Tools::cleanGMail($mailData['from']);

        if($mailData['usrPhoneOrTag'] > 0) { // OR strlen($mailData['usrPhoneOrTag'] > 0)) {
            // $usrArr = Central::getUserByPhoneNumber($mailData['usrPhoneOrTag'], false);
            // new User matching logic:
            $usrArr = Central::getUserByMail($mailData['plainmail'], $mailData['usrPhoneOrTag']);
        } else {
            // get the user by the mail
            $usrArr = Central::getUserByMail($mailData['plainmail'], "");
        }

        // ------------------------------------------------------------
        if(is_array($usrArr) AND count($usrArr) > 0 AND $usrArr["DETAILS"]["MAILCHECKED"] == 1) {
            $mailData['usrArr'] = $usrArr;
            // Process body and attachments
            if($usrArr["BID"] > 0) {
                // print "USER FOUND\n";
                // If the top-level is plain or html (non-multipart), decode directly
                if (in_array($mimeType, ['text/plain','text/html'])) {
                    // This is not multipart, so the body is directly in $payload->getBody()
                    $mailData['body'] = self::decodeBodyTopLevel($payload);
                    self::deleteMessage($messageId);
                } else {
                    // Otherwise, it might be multipart (or something else), so process parts
                    try {
                        self::processPayloadParts($payload->getParts(), $mailData, $service, $messageId);
                        self::deleteMessage($messageId);
                    } catch (Exception $e) {
                        $mailData['body'] = "Error processing payload parts: " . $e->getMessage();
                        self::deleteMessage($messageId);
                    }
                }
            } else {
                // print "USER NOT FOUND\n";
                // Just delete the message
                self::deleteMessage($messageId);
                $mailData = false;
            }
        } else {
            if(is_array($usrArr) AND isset($usrArr["DETAILS"]["MAIL"]) AND 
                (!isset($usrArr["DETAILS"]["MAILCHECKED"]) 
                OR $usrArr["DETAILS"]["MAILCHECKED"] !== 1)) {
                    // legacy user update
                    if(!isset($usrArr["DETAILS"]["MAILCHECKED"])) {
                        $usrArr["DETAILS"]["MAILCHECKED"] = dechex(rand(100000, 999999));
                        $userDetailsJson = json_encode($usrArr["DETAILS"],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $updateSQL = "UPDATE BUSER SET BUSERDETAILS = '".DB::EscString($userDetailsJson)."' WHERE BID = ".$usrArr["BID"];
                        db::Query($updateSQL);
                    }
                    // create the confirmation link
                    XSControl::createConfirmationLink($usrArr);
            }
            // delete
            self::deleteMessage($messageId);
            $mailData = false;
        }

        return $mailData;
    }

    /**
     * Called by processMessage() to recursively process message parts
     * Extracts plain text body and identifies attachments
     * @param $parts Message parts to process
     * @param &$mailData Reference to mail data array to populate
     * @param $service Gmail Service instance
     * @param $messageId Current message ID
     * @param $prefix Used for nested parts (optional)
     */
    private static function processPayloadParts($parts, &$mailData, $service, $messageId) {
        if (empty($parts)) {
            return;
        }
    
        foreach ($parts as $part) {
            // Check MIME type
            $mimeType = $part->getMimeType();
            $filename = $part->getFilename();
    
            if ($mimeType === 'text/plain' && !$part->getFilename()) {
                $mailData['body'] = self::decodeBody($part, $part->getBody()->getData());
            } elseif($mimeType === 'text/html' && !$part->getFilename()) {
                $mailData['body'] = strip_tags(self::decodeBody($part, $part->getBody()->getData()));
            } elseif (!empty($filename)) {
                $attachment = self::processAttachment($part, $service, $messageId);
                if ($attachment) {
                    $mailData['attachments'][] = $attachment;
                }
            }
    
            // If there's another level of parts, recurse
            $subParts = $part->getParts();
            if (!empty($subParts)) {
                self::processPayloadParts($subParts, $mailData, $service, $messageId);
            }
        }
    }

    /**
     * Called by processPayloadParts() to handle individual attachments
     * Validates attachment type and retrieves attachment data
     * @param $part Message part containing attachment
     * @param $service Gmail Service instance
     * @param $messageId ID of message containing attachment
     * @return array|null Attachment data if valid, null if filtered out
     */
    private static function processAttachment($part, $service, $messageId) {
        $filename = $part->getFilename();
        $attachmentId = $part->getBody()->getAttachmentId();
        
        if (!$attachmentId) {
            return null;
        }

        // Check if it's an allowed file type
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::$allowedAttachmentTypes)) {
            return null;
        }

        /*
        // Check if it's not an excluded filename (like logos, footers, etc.)
        foreach (self::$excludedFilenames as $excludedName) {
            if (stripos($filename, $excludedName) !== false) {
                return null;
            }
        }
        */

        // Get attachment content
        $attachment = $service->users_messages_attachments->get('me', $messageId, $attachmentId);
        $data = $attachment->getData();
        
        // Properly handle the base64url encoded data
        $data = str_replace(['-', '_'], ['+', '/'], $data);
        $data = base64_decode($data);
        
        return [
            'filename' => $filename,
            'mimeType' => $part->getMimeType(),
            'data' => $data  // Now contains raw binary data, not base64 encoded
        ];
    }

    /**
     * Called by processPayloadParts() to decode base64 encoded message bodies
     */
    private static function decodeBody($part, $partBodyData): string {
        // First do the normal base64url decoding that the Gmail API expects
        $base64Decoded = strtr($partBodyData, '-_', '+/');
        $rawContent    = base64_decode($base64Decoded);
    
        // If this part is quoted-printable, do that decoding too
        // You can check $part->getHeaders() or $part->getMimeType(), etc.
        // In your raw example, it was "Content-Transfer-Encoding: quoted-printable"
        $headers = $part->getHeaders();
        if (is_array($headers)) {
            foreach ($headers as $header) {
                if (strtolower($header->getName()) === 'content-transfer-encoding'
                    && strtolower($header->getValue()) === 'quoted-printable') {
                    $rawContent = quoted_printable_decode($rawContent);
                }
            }
        }
    
        // If the charset is iso-8859-1 and you want UTF-8, you can convert:
        // (you could also sniff the header "Content-Type: text/plain; charset=iso-8859-1")
        if (strpos(strtolower($part->getMimeType()), 'iso-8859-1') !== false
            || strpos(strtolower($rawContent), 'charset=iso-8859-1') !== false) {
            $rawContent = mb_convert_encoding($rawContent, 'UTF-8', 'ISO-8859-1');
        }
    
        return $rawContent;
    }
    // whole mail is simple text
    private static function decodeBodyTopLevel($payload) {
        // This is the raw base64url string for the body
        $rawData = $payload->getBody() ? $payload->getBody()->getData() : '';
        if (!$rawData) {
            return '';
        }
    
        // Step 1: Base64URL decode
        $base64Decoded = strtr($rawData, '-_', '+/');
        $decoded       = base64_decode($base64Decoded);
    
        // Step 2: Check Content-Transfer-Encoding
        // You can look at $payload->getHeaders() for a "Content-Transfer-Encoding: quoted-printable"
        $headers = $payload->getHeaders();
        foreach ($headers as $header) {
            if (strtolower($header->getName()) === 'content-transfer-encoding'
                && strtolower($header->getValue()) === 'quoted-printable') {
                $decoded = quoted_printable_decode($decoded);
            }
        }
    
        // Step 3: Convert character set if iso-8859-1
        // (Your raw data says: charset="iso-8859-1")
        if (strpos(strtolower($payload->getMimeType()), 'iso-8859-1') !== false) {
            $decoded = mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
        }
    
        // If it was "text/html" top-level, you could do `strip_tags` or
        // store it in a separate $mailData['body_html']. Up to you.
        return $decoded;
    }
    // ------------------------------------------------------------
    public static function saveToDatabase($processedMails) {
        $myTrackingId = (int) (microtime(true) * 1000000);

        foreach ($processedMails as $mail) {
            try {
                // print_r($mail);
                $lastInsertsId = [];
                $inMessageArr = [];
                // fill for sorting first
                $inMessageArr['BUSERID'] = $mail['usrArr']['BID'];
                $inMessageArr['BTEXT'] = trim(strip_tags($mail['body']));
                $inMessageArr['BUNIXTIMES'] = time();
                $inMessageArr['BDATETIME'] = (string) date("YmdHis");
                // --
                $convArr = Central::searchConversation($inMessageArr);
                if(is_array($convArr) AND $convArr['BID'] > 0) {
                    $inMessageArr['BTRACKID'] = $convArr['BTRACKID'];
                    $inMessageArr['BTOPIC'] = $convArr['BTOPIC'];
                    $inMessageArr['BLANG'] = $convArr['BLANG'];
                } else {
                    $inMessageArr['BTRACKID'] = $myTrackingId;
                    $inMessageArr['BLANG'] = Central::getLanguageByCountryCode($mail['usrArr']['BPROVIDERID']);
                    $inMessageArr['BTOPIC'] = ''; 
                }

                //$inMessageArr['BTOPIC'] = "other";
                //$inMessageArr['BLANG'] = 'en';

                // --
                $inMessageArr['BID'] = 'DEFAULT';
                $inMessageArr['BPROVIDX'] = $mail['message_id'];
                $inMessageArr['BMESSTYPE'] = 'MAIL';
                $inMessageArr['BFILE'] = 0;
                $inMessageArr['BFILEPATH'] = '';
                $inMessageArr['BFILETYPE'] = '';
                $inMessageArr['BDIRECT'] = 'IN';
                $inMessageArr['BSTATUS'] = 'NEW';
                $inMessageArr['BFILETEXT'] = '';

                // counter, if it was saved to DB,
                // is also used to jump over mail when limit is reached
                $saveToDB = 0;

                // Rate limiting check - only block if MESSAGES limit reached (only if rate limiting enabled)
                if (XSControl::isRateLimitingEnabled()) {
                    $limitCheck = XSControl::checkMessagesLimit($inMessageArr['BUSERID']);
                    if (is_array($limitCheck) && $limitCheck['limited']) {
                        // Send rate limit notification via email
                        $limitMessage = "⏳ Usage Limit Reached\n" . $limitCheck['message'] . "\nNext available in: " . $limitCheck['reset_time_formatted'] . "\nNeed higher limits? 🚀 Upgrade your plan";
                        
                        // Send auto-reply email
                        $replySubject = "Re: " . $mail['subject'];
                        $replyBody = $limitMessage;
                        _mymail('info@metadist.de', $mail['plainmail'], $replySubject, $replyBody, $replyBody);
                        
                        $mail['attachments'] = [];
                        $saveToDB = 1;
                    }
                }

                // Save attachments
                $fileCount = 0;
                foreach ($mail['attachments'] as $attachment) {
                    if(strlen($mail['usrArr']['BPROVIDERID']) > 5) {                        
                        $userRelPath = substr($mail['usrArr']['BPROVIDERID'], -5, 3) . '/' . 
                                     substr($mail['usrArr']['BPROVIDERID'], -2, 2) . '/' . 
                                     date("Ym") . "/";
                        
                        // Fix directory creation
                        $fullUploadDir = rtrim(UPLOAD_DIR, '/').'/' . $userRelPath;
                        if (!is_dir($fullUploadDir)) {
                            mkdir($fullUploadDir, 0755, true);  // Use recursive directory creation
                        }
                        
                        // save file
                        $fileType = Tools::getFileExtension($attachment['mimeType']);
                        $fileRelPath = $userRelPath . 'gm-' . date("YmdHis") . '-' . ($fileCount++) . '.' . $fileType;
                        $filePath = $fullUploadDir . basename($fileRelPath);
                        
                        // Write the raw binary data directly to file
                        file_put_contents($filePath, $attachment['data']);
                        
                        $inMessageArr['BFILEPATH'] = $fileRelPath;
                        $inMessageArr['BFILETYPE'] = $fileType;
                        $inMessageArr['BFILE'] = 1;
                        // -- count up the number of attachments
                        $saveToDB++;
                        // -------------------- Message Array filled --------------------
                        $resArr = Central::handleInMessage($inMessageArr);
                        $lastInsertsId[] = $resArr['lastId'];
                    }
                }

                if($saveToDB == 0) {
                    $resArr = Central::handleInMessage($inMessageArr);
                    $lastInsertsId[] = $resArr['lastId'];
                    // log the message to the DB
                    XSControl::countThis($inMessageArr['BUSERID'], $resArr['lastId']);
                }

                // Delete the message after processing: old place, was moved to mail process
                // self::deleteMessage($mail['message_id']);
                // -------------------- Message Array saved to DB -------------------
                if(count($lastInsertsId) > 0) {
                    foreach($lastInsertsId as $lastInsertId) {
                        if(intval($lastInsertId) > 0) {
                            // log the message to the DB
                            XSControl::countThis($inMessageArr['BUSERID'], $lastInsertId);

                            $metaSQL = "insert into BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) values (DEFAULT, ".(0 + $lastInsertId).", 'FILECOUNT', '".($fileCount)."');";
                            $metaRes = db::Query($metaSQL);
                            // count bytes
                            XSControl::countBytes($inMessageArr, 'BOTH', false);
                                
                            // (2) start the preprocessor and monitor the pid in the pids folder
                            $cmd = "nohup php preprocessor.php ".($lastInsertId)." > /dev/null 2>&1 &";
                            $pidfile = "pids/m".($lastInsertId).".pid";
                            exec(sprintf("%s echo $! >> %s", $cmd, $pidfile));
                        }
                    }
                }
            } catch (Exception $e) {
                throw new Exception('Error saving to database: ' . $e->getMessage());
            }
        }
        return true;
    }

    /**
     * Called by deleteMessage() to remove processed messages
     * Moves message to Gmail trash
     * @param $messageId ID of message to delete
     * @return bool True if successful
     */
    public static function deleteMessage($messageId) {
        try {
            $client = self::refreshToken();
            $service = new Google_Service_Gmail($client);
            
            // Move the message to trash
            $service->users_messages->trash('me', $messageId);
            return true;
        } catch (Exception $e) {
            throw new Exception('Error deleting message: ' . $e->getMessage());
        }
    }
} 