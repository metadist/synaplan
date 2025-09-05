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

    // method to give a "block" yes/no answer, if the user has sent too many messages in the last x seconds
    public static function isLimited($msgArr, $secondsCount, $maxCount):bool {
        $count = self::countIn($msgArr['BUSERID'], $secondsCount);
        //error_log("count: ".$count." maxCount: ".$maxCount." for user: ".$msgArr['BUSERID']);
        return $count >= $maxCount;
    }

    // simple count method: puts details into the database into the BUSELOG table
    // call as XSControl::countThis($userId, $msgId)
    public static function countThis($userId, $msgId):int {
        $newSQL = "INSERT INTO BUSELOG (BID, BTIMESTAMP, BUSERID, BMSGID) VALUES (DEFAULT, ".time().", ".($userId).", ".($msgId).")";
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

    // method to notify the user that their account has been limited
    // and a link to the mailing list or signup page is added!
    public static function notifyUser($msgArr, $limitSec = 60, $limitCount = 5):void {
        $usrArr = Central::getUsrById(intval($msgArr['BUSERID']));
        $msgTxt = "Limit reached! Please join our mailing list.<BR>\n\n";
        $msgTxt .= "https://ralfs.ai/<BR><BR>\n\n";
        $msgTxt .= "oder in Deutsch:<BR>\n";
        $msgTxt .= "https://ralfs.ai/de/";
        // whatsapp
        // error_log("msgArr: ". print_r($msgArr, true));
        if($msgArr['BMESSTYPE'] == 'WA') {
            // the right user for the reply
            $waIdSQL = "select BWAIDS.* from BWAIDS, BMESSAGES 
                where BWAIDS.BMID = BMESSAGES.BID AND BMESSAGES.BUSERID = ".intval($msgArr['BUSERID'])." ORDER BY BMESSAGES.BID DESC LIMIT 1";
            $waIdRes = db::Query($waIdSQL);
            $waDetailsArr = db::FetchArr($waIdRes);

            // ******************************************************
            // SEND WA
            $GLOBALS['WAtoken'] = file_get_contents(__DIR__ . '/../.keys/.watoken.txt');
            $waSender = new waSender($waDetailsArr);
            //error_log("waDetailsArr: ". print_r($waDetailsArr, true));
            $myRes = $waSender->sendText($usrArr["BPROVIDERID"], strip_tags($msgTxt));
        }
        // mail
        if($msgArr['BMESSTYPE'] == 'MAIL' AND isset($usrArr["DETAILS"])) {
            //print_r($msgArr);
            $sentRes = EmailService::sendLimitNotification($usrArr["DETAILS"]["MAIL"], "usage", "Rate limit exceeded");    
        }
        if($msgArr['BMESSTYPE'] == 'MAIL' AND !isset($usrArr["DETAILS"])) {
            //print_r($msgArr);
            $sentRes = EmailService::sendAdminNotification("Rate Limit Alert", "User without details exceeded limit: " . print_r($msgArr, true));    
        }
        exit;
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
}