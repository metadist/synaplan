<?php

class Tools {
    // ****************************************************************************************************** 
    // get config value per user or default
    // ****************************************************************************************************** 
    public static function getConfigValue($msgArr, $setting): string {
        $setSQL = "select * from BCONFIG where (BOWNERID = ".$msgArr['BUSERID']." OR BOWNERID = 0)
                     AND BSETTING = '".$setting."' order by BID desc limit 1";
        $res = db::Query($setSQL);
        $setArr = db::FetchArr($res);
        return $setArr['BVALUE'];
    }
    // ****************************************************************************************************** 
    // member link
    // ****************************************************************************************************** 
    public static function memberLink($msgArr): array {
        $usrArr = Central::getUsrById($msgArr['BUSERID']);
        $ticketStr = uniqid(dechex(rand(100000, 999999)));
        $userDetailsArr = json_decode($usrArr['BUSERDETAILS'], true);
        $userDetailsArr['ticket'] = $ticketStr;
        $usrArr['BUSERDETAILS'] = json_encode($userDetailsArr,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $updateSQL = "UPDATE BUSER SET BUSERDETAILS = '".db::EscString($usrArr['BUSERDETAILS'])."' WHERE BID = ".$usrArr['BID'];
        if(db::Query($updateSQL)) {
            $msgArr['BTEXT'] = $GLOBALS["baseUrl"]."?id=".$usrArr['BID']."&lid=".urlencode($ticketStr);
        } else {
            $msgArr['BTEXT'] = "Error: Could not update user details";
        }
        return $msgArr;
    }

    // ****************************************************************************************************** 
    // search web
    // ****************************************************************************************************** 
    public static function searchWeb($msgArr, $qTerm): array {
        // Initialize API credentials
        $braveKey = ApiKeys::getBraveSearch();

        $country = strtoupper($msgArr['BLANG']);

        if($msgArr['BLANG'] == 'en') {
            $country = 'US';
        }

        $lang = $msgArr['BLANG'];

        $arrRes = Curler::callJson('https://api.search.brave.com/res/v1/web/search?q='.urlencode($qTerm).'&search_lang='.$lang.'&country='.$country.'&count=5', 
                ['Accept: application/json', 'Accept-Encoding: gzip', 'X-Subscription-Token: '.$braveKey]);
                
        //error_log("call: https://api.search.brave.com/res/v1/web/search?q=".urlencode($qTerm)."&search_lang=".$lang."&country=".$country."&count=5");
        //error_log("X-Subscription-Token:: ".$braveKey);
        //error_log("arrRes: ".print_r($arrRes, true));
        
        if(array_key_exists('news', $arrRes) && count($arrRes['news']['results']) > 0) {
            $msgArr['BTEXT'] .= "\n\n"."**NEWS**"."\n\n";
            foreach($arrRes['news']['results'] as $news) {
                $msgArr['BTEXT'] .= "\n* [".$news['title'] . "](" . $news['url']. ")\n\n";
            }
        }
        
        if(array_key_exists('videos', $arrRes) && count($arrRes['videos']['results']) > 0) {
            $msgArr['BTEXT'] .= "\n\n"."**VIDEO**"."\n";
            foreach($arrRes['videos']['results'] as $videos) {
                $msgArr['BTEXT'] .= "\n* [".$videos['title'] . "](" . $videos['url']. ")\n\n";
            }
        }

        if(array_key_exists('web', $arrRes) && count($arrRes['web']['results']) > 0) {
            $msgArr['BTEXT'] .= "\n\n"."**WEB**"."\n";
            foreach($arrRes['web']['results'] as $web) {
                $msgArr['BTEXT'] .= "\n* [".$web['title'] . "](" . $web['url']. ")\n\n";
            }
        }
        return $msgArr;
    }
    // ****************************************************************************************************** 
    // search RAG
    // ****************************************************************************************************** 
    public static function searchRAG($msgArr): array {
        // get the prompt summarized in short, if too long:
        if(strlen($msgArr['BTEXT']) > 128) {
            $msgArr['BTEXT'] = BasicAI::getShortPrompt($msgArr['BTEXT']);
        }
        // now RAG along:
        $AIVEC = $GLOBALS["AI_VECTORIZE"]["SERVICE"];
        $embedPrompt = [];
        try {
            $embedPrompt = $AIVEC::embed($msgArr['BTEXT']);
        } catch (\Throwable $e) {
            if (!empty($GLOBALS['debug'])) error_log('searchRAG embed failed: ' . $e->getMessage());
        }
        // If embeddings failed or returned empty, skip RAG gracefully
        if (!is_array($embedPrompt) || count($embedPrompt) === 0) {
            return [];
        }
        $distanceSQL = "SELECT BMESSAGES.BID, BMESSAGES.BFILETEXT, BMESSAGES.BFILEPATH,
            VEC_DISTANCE_EUCLIDEAN(BRAG.BEMBED, VEC_FromText('[".implode(", ", $embedPrompt)."]')) AS distance
            from BMESSAGES, BRAG 
            where BMESSAGES.BID = BRAG.BMID AND BMESSAGES.BUSERID=".$msgArr['BUSERID']."
            ORDER BY distance ASC
            LIMIT 5";

        $res = db::Query($distanceSQL);

        $msgTextArr = [];
        $msgKeyArr = [];
        while($one = db::FetchArr($res)) {
            if(!array_key_exists($one['BID'], $msgKeyArr)) {
                $msgKeyArr[$one['BID']] = $one['BFILEPATH'];
                $msgTextArr[] = ['BID' => $one['BID'], 'BTEXT' => '**File '.basename($one['BFILEPATH']).'**:'."\n".$one['BFILETEXT'], 'BFILEPATH' => $one['BFILEPATH']];
            }
        }

        return $msgTextArr;
    }
    // ****************************************************************************************************** 
    // search docs with, eg: /docs images of picard
    // ****************************************************************************************************** 
    public static function searchDocs($msgArr): array {
        $country = strtoupper($msgArr['BLANG']);
        $usrArr = Central::getUsrById($msgArr['BUSERID']);
        
        $commandArr = explode(" ", $msgArr['BTEXT']);
        if($commandArr[0] == "/docs") {
            $mySearchText = db::EscString(substr(implode(" ", $commandArr), 6));
            // add that to search
            // BUSERID=".$usrArr['BID']." AND
            $searchSQL = "select DISTINCT * from BMESSAGES where BUSERID=".$usrArr['BID']." AND BMESSAGES.BFILE>0 AND MATCH(BFILETEXT) AGAINST('".$mySearchText."')";
            $res = db::Query($searchSQL);
            $msgArr['BTEXT'] .= "\n";
            $entryCounter = 0;
            while($oneVec = db::FetchArr($res)) {
                if(strlen($oneVec['BFILEPATH']) > 5) {
                    // attach a file to the reply
                    if($entryCounter == 0 AND 
                        ($oneVec['BFILETYPE'] == 'pdf' OR
                        $oneVec['BFILETYPE'] == 'docx' OR
                        $oneVec['BFILETYPE'] == 'pptx' OR
                        $oneVec['BFILETYPE'] == 'png' OR
                        $oneVec['BFILETYPE'] == 'jpg' OR
                        $oneVec['BFILETYPE'] == 'mp4' OR
                        $oneVec['BFILETYPE'] == 'mp3')
                    ) {
                        $msgArr['BFILETYPE'] = $oneVec['BFILETYPE'];
                        $msgArr['BFILE'] = $oneVec['BFILE'] = 1;
                        $msgArr['BFILEPATH'] = $oneVec['BFILEPATH'];
                    }
                    $msgArr['BTEXT'] .= "\n".substr($oneVec['BFILETEXT'], 0, 96)."...";
                    $msgArr['BTEXT'] .= "\n".$GLOBALS["baseUrl"]."up/".$oneVec['BFILEPATH']."\n";
                    $entryCounter++;

                }
            }
        } else {
            $msgArr['BTEXT'] = "Error: Invalid command - please use /docs [text]";
        }


        return $msgArr;
    }
    // get file extension from mime type
    public static function getFileExtension(string $mimeType): string {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
            'audio/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'application/pdf' => 'pdf',
            'video/webm' => 'webm',
            'text/plain' => 'txt',
            'audio/ogg' => 'ogg',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.ms-powerpoint' => 'ppt',
        ];

        return $mimeMap[$mimeType] ?? 'unknown';
    }
    // ****************************************************************************************************** 
    public static function vectorSearch($msgArr): array {
        return $msgArr;
    }
    // ****************************************************************************************************** 
    // Create a screenshot of a web page from URL
    // ****************************************************************************************************** 

    public static function webScreenshot($msgArr, $x=1170, $y=2400): array {
        $usrArr = Central::getUsrById($msgArr['BUSERID']);
        
        $commandArr = explode(" ", $msgArr['BTEXT']);
        if($commandArr[0] == "/web" and filter_var($commandArr[1], FILTER_VALIDATE_URL)) {
            $url = $commandArr[1];
            /*
            chromium-browser --headless 
            --no-sandbox 
            --user-data-dir=/root/ 
            --force-device-scale-factor=1 
            --window-size=1200,1600 
            --screenshot=filename.png
            --screenshot https://www.google.com/
            */
            
            // get the WHOLE PATH from globals + local user details
            $dirPart1 = substr($usrArr['BPROVIDERID'], -5, 3);
            if(!is_dir($dirPart1)) {
                mkdir($dirPart1, 0777, true);
            }
            $dirPart2 = substr($usrArr['BPROVIDERID'], -2, 2);
            if(!is_dir($dirPart2)) {
                mkdir($dirPart2, 0777, true);
            }

            $userRelPath = $dirPart1.DIRECTORY_SEPARATOR.$dirPart2.DIRECTORY_SEPARATOR;
            $userDatePath = date("Ym").DIRECTORY_SEPARATOR;
            $fileBasename = 'web_'.(time()).'.png';

            // Create directory using Flysystem with fallback to mkdir
            $fullDirectoryPath = $userRelPath.$userDatePath;
            if(!is_dir($fullDirectoryPath)) {
                mkdir($fullDirectoryPath, 0777, true);
            }
            
            $homeDir = getcwd() . "/up/" . $fullDirectoryPath;
            if(!is_dir($homeDir)) {
                mkdir($homeDir, 0777, true);
            }
            putenv("HOME=" . $homeDir);

            $chromiumDestPath = './up/'.$userRelPath.$userDatePath.$fileBasename;
            $fileDBPath = $userRelPath.$userDatePath.$fileBasename;

            $cmd = 'chromium --headless --no-sandbox --force-device-scale-factor=1 --window-size='.$x.','.$y.' --screenshot='.$chromiumDestPath.' "'.($url).'"'; // 2>/dev/null';
            $result=exec($cmd);
            
            //error_log($result);


            if(file_exists($chromiumDestPath) AND filesize('./up/'.$fileDBPath) > 1000) {
                $msgArr['BFILE'] = 1;
                $msgArr['BFILEPATH'] = $fileDBPath;
                $msgArr['BFILETYPE'] = 'png';
                $msgArr['BTEXT'] = "/screenshot of URL: ".$url;

            } else {
                $msgArr['BTEXT'] = "Error: Could not create screenshot of the web page.";
            }
        } else {
            $msgArr['BTEXT'] = "Error: Invalid URL - please make sure the second word is a valid URL, like: /web https://www.ralfs.ai/ - the rest will be ignored.";
        }

        // translate the text to the language of the user
        /*
        if($msgArr['BLANG'] != 'en') {
            $msgArr = AIGroq::translateTo($msgArr, $msgArr['BLANG'], 'BTEXT');
        }
        */
        // return the message array completely
        return $msgArr;
    }

    // --------------------------------------------------------------------------
    public static function sysStr($in): string {
        $out = basename(strtolower($in));
        if(substr($out, 0,1) == ".") {
            $out = substr($out, 1);
            $out = "DOTFILES_forbidden".rand(100000, 999999);
        }
        if(substr_count($out, ".php")>0) {
            $out = "PHPFILES_forbidden".rand(100000, 999999);
        }
        $out = str_replace(" ","-", $out);
        $out = str_replace("!","", $out);
        $out = str_replace(">","", $out);
        $out = str_replace("<","", $out);
        $out = str_replace("'","", $out);
        $out = str_replace("?","", $out);
        $out = str_replace(":","", $out);
        $out = str_replace("./","_", $out);
        $out = str_replace("/","_", $out);
        $out = str_replace("\$","s", $out);
        $out = str_replace("\*","_", $out);
        $out = preg_replace('([^\w\d\-\_\/\.öüäÖÜÄ])', '_', $out);
        $out = str_replace("---","_", $out);
        $out = str_replace("--","_", $out);
        $out = str_replace("-","_", $out);
        return $out;
    }
    // --------------------------------------------------------------------------
    public static function idFromMail($in): string {
        // $strMyId = str_pad($strMyId, 7, "0", STR_PAD_LEFT);
        return md5($in);
        /*
        $mailparts = explode("@", $in);
        $out = strtolower($mailparts[0]);
        $out = str_replace(" ","", $out);
        $out = str_replace("!","", $out);
        $out = str_replace(">","", $out);
        $out = str_replace("<","", $out);
        $out = str_replace("'","", $out);
        $out = str_replace("?","", $out);
        $out = str_replace(":","", $out);
        $out = str_replace("./","", $out);
        $out = preg_replace('([^\w\d\-\_\/\öüäÖÜÄ])', '', $out);
        $out = str_replace("--","-", $out);
        $out = str_replace("--","-", $out);
        $out = str_replace(".","-", $out);
        $out = str_replace("-","", $out);
        $out = str_pad($out, 7, "0", STR_PAD_LEFT);
        return $out;
        */
    }
    // --------------------------------------------------------------------------
    public static function cleanGMail($from) {
        $mailParts = explode("<", $from);
        $mailParts = explode(">", $mailParts[1]);
        $plainMail = strtolower($mailParts[0]);
        $plainMail = str_replace(" ","", $plainMail);
        $plainMail = db::EscString($plainMail);
        return $plainMail;
    }
    // ---
    public static function ensure_utf8(string $text): string {
        return (mb_detect_encoding($text, 'UTF-8', true) === 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'auto');
    }
    // ---
    public static function cleanTextBlock($text): string {
        while(substr_count($text, "\\r\\n\\r\\n") > 0) {
            $text = str_replace("\\r\\n", "\\r\\n", $text);
            $text = str_replace("\\r\\n", " ", $text);
        }
        while(substr_count($text, "\\n\\n") > 0) {
            $text = str_replace("\\n\\n", "\\n", $text);
        }
        $text = str_replace("\\n", " ", $text);

        $text = str_replace("&nbsp;", " ", $text);
        
        while(substr_count($text, "  ") > 0) {
            $text = str_replace("  ", " ", $text);
        }
        return $text;
    }
    // --- image loader
    public static function giveSmallImage($myPath, $giveImage = true, $newWidth=800) {
        $path = "up/".$myPath;

        $mimetype = mime_content_type($path);
        // hacker stop!
        if (substr_count(strtolower($mimetype), 'image/')==0) {
            header("content-type: image/png");
            header("custom-note: 'Mime recognition failed: ".$mimetype."'");

            $fp = fopen("img/icon_love.png", 'rb');
            fpassthru($fp);
            exit;
        }
        // all good, lets open the image
        // resize image
        $mimeSupported = false;
        if(substr_count(strtolower($mimetype), "jpg") > 0 OR substr_count(strtolower($mimetype), "jpeg") > 0) {
            $image = imagecreatefromjpeg($path);
            $mimeSupported = true;
        } elseif (substr_count(strtolower($mimetype), "gif") > 0) {
            $image = imagecreatefromgif($path);
            imagealphablending($image, false);
            imagesavealpha($image, true);
        } elseif (substr_count(strtolower($mimetype), "png") > 0) {
            $image = imagecreatefrompng($path);
            imagealphablending($image, false);
            imagesavealpha($image, true);
        } elseif (substr_count(strtolower($mimetype), "webp") > 0) {
            $image = imagecreatefromwebp($path);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $mimeSupported = true;
        } elseif (substr_count(strtolower($mimetype), "svg") > 0) {
            header("content-type: image/svg");
            readfile( $path );
            exit;
        } else {
            header("content-type: ".$mimetype);
            readfile( $path );
            exit;
        }
        // -------------------------------------------------------------------------------------
        if ($image) {
            // rotate the stuff right before resampling!
            $ort = 0;
            if($mimeSupported) {
                $exif = exif_read_data($path);
                if(isset($exif['Orientation'])){
                    $ort = $exif['Orientation'];
                }
            }

            switch ($ort) {
                case 3: // 180 rotate left
                    $image = imagerotate($image, 180, 0);
                    break;
                case 6: // 90 rotate right
                    $image = imagerotate($image, -90, 0);
                    break;
                case 8:    // 90 rotate left
                    $image = imagerotate($image, 90, 0);
                    break;
            }

            $newImage = imagescale($image, $newWidth, -1, IMG_BILINEAR_FIXED);

            if($giveImage) {
                header("custom-orientation: " . (0 + $ort));
                header("content-type: image/png");
                imagepng($newImage);
                return true;
            } else {
                return $newImage;
            }
        }
        return false;
    }
    // datetime string
    public static function myDateTime($datestr): string {
        return substr($datestr,6,2).".".substr($datestr,4,2).".".substr($datestr,0,4) . " - " . substr($datestr,8,2).":".substr($datestr,10,2);
    }
    // is valid json
    public static function isValidJson($string): bool {
        if (!is_string($string)) return false;
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }
    // Rate limiting helper used by API endpoints
    public static function checkRateLimit($key, $window, $maxRequests) {
        $currentTime = time();
        $rateLimitKey = 'rate_limit_' . $key;

        if (!isset($_SESSION[$rateLimitKey])) {
            $_SESSION[$rateLimitKey] = [
                'count' => 0,
                'window_start' => $currentTime
            ];
        }

        $rateData = $_SESSION[$rateLimitKey];

        // New window
        if ($currentTime - $rateData['window_start'] >= $window) {
            $_SESSION[$rateLimitKey] = [
                'count' => 1,
                'window_start' => $currentTime
            ];
            return ['allowed' => true, 'retry_after' => 0];
        }

        // Within window
        if ($rateData['count'] < $maxRequests) {
            $_SESSION[$rateLimitKey]['count']++;
            return ['allowed' => true, 'retry_after' => 0];
        }

        // Exceeded
        $retryAfter = $window - ($currentTime - $rateData['window_start']);
        return ['allowed' => false, 'retry_after' => $retryAfter];
    }
    // Get Authorization header value from current request (Bearer ...)
    public static function getAuthHeaderValue(): string {
        $headers = [];
        if (function_exists('getallheaders')) { $headers = getallheaders(); }
        $auth = '';
        if (isset($headers['Authorization'])) { $auth = $headers['Authorization']; }
        elseif (isset($headers['authorization'])) { $auth = $headers['authorization']; }
        elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) { $auth = $_SERVER['HTTP_AUTHORIZATION']; }
        return trim($auth);
    }
    // migrate an half filled array to a full array
    public static function migrateArray($destinationArr, $sourceArr): array { 
        // Create a copy of the destination array to avoid modifying the original
        $result = $destinationArr;
        
        // Iterate through each key in the destination array
        foreach ($destinationArr as $key => $value) {
            // If the source array has this key, update the destination with the source value
            if (array_key_exists($key, $sourceArr)) {
                $result[$key] = $sourceArr[$key];
            }
        }
        
        return $result;
    }
    // --------------------------------------------------------------------------
    // change the text to include media to the output
    public static function addMediaToText($msgArr): string {
        // Process complex HTML first
        $outText = self::processComplexHtml($msgArr['BTEXT']);

        if($msgArr['BFILE']>0 AND $msgArr['BFILETYPE'] != '' AND str_contains($msgArr['BFILEPATH'], '/')) {
            // add image
            if($msgArr['BFILETYPE'] == 'png' OR $msgArr['BFILETYPE'] == 'jpg' OR $msgArr['BFILETYPE'] == 'jpeg') {
                // If text still contains the original tool command or Again marker, replace with a clean caption
                $btextRaw = isset($msgArr['BTEXT']) ? trim($msgArr['BTEXT']) : '';
                if ($btextRaw !== '' && (strpos(ltrim($btextRaw), '/pic') === 0 || strpos($btextRaw, '[Again-') !== false)) {
                    $outText = 'Generated Image';
                }
                $outText = "<img src='".$GLOBALS["baseUrl"] . "up/" . $msgArr['BFILEPATH']."' style='max-width: 500px;'><BR>\n".$outText;
            }
            // addvideo
            if($msgArr['BFILETYPE'] == 'mp4' OR $msgArr['BFILETYPE'] == 'webm') {
                $outText = "<video src='".$GLOBALS["baseUrl"] . "up/" . $msgArr['BFILEPATH']."' style='max-width: 500px;' controls><BR>\n".$outText;
            }
            // add mp3 player
            if($msgArr['BFILETYPE'] == 'mp3') {
                $outText = "<audio src='".$GLOBALS["baseUrl"] . "up/" . $msgArr['BFILEPATH']."' controls><BR>\n".$outText;
            }
            // documents and other download files
            if($msgArr['BFILETYPE'] == 'pdf' OR $msgArr['BFILETYPE'] == 'docx' OR $msgArr['BFILETYPE'] == 'pptx' OR $msgArr['BFILETYPE'] == 'xlsx' OR $msgArr['BFILETYPE'] == 'xls' OR $msgArr['BFILETYPE'] == 'ppt') {
                $outText = "<a href='".$GLOBALS["baseUrl"] . "up/" . $msgArr['BFILEPATH']."'>".basename($msgArr['BFILEPATH'])."</a><BR>\n".$outText;
            }
        }        
        return $outText;
    }
    
    // --------------------------------------------------------------------------
    // Check if text contains complex HTML and convert to markdown source if needed
    public static function processComplexHtml($text): string {
        // Define simple HTML tags that are allowed (video, image, link elements)
        $simpleTags = ['img', 'video', 'audio', 'a', 'br'];
        
        // Define complex HTML tags that should trigger markdown conversion
        $complexTags = ['html', 'body', 'script', 'div', 'span', 'table', 'style', 'p'];
        
        // Check for complex HTML tags
        $hasComplexHtml = false;
        foreach ($complexTags as $tag) {
            if (preg_match('/<' . $tag . '\b[^>]*>/i', $text) || preg_match('/<\/' . $tag . '>/i', $text)) {
                $hasComplexHtml = true;
                break;
            }
        }
        
        // If complex HTML is found, convert to markdown code block
        if ($hasComplexHtml) {
            return "```html\n" . $text . "\n```";
        }
        
        return $text;
    }
    // converting urlencoded into real utf8 
    public static function turnURLencodedIntoUTF8(string $in): string {
        // 1️⃣ %xx  → byte
        // 1️⃣ %xx & + ➜ byte / space
        $step1 = urldecode($in);

        // Since we fixed the frontend to send proper newlines and Unicode characters,
        // we don't need JSON-style unescaping anymore. Just return the URL-decoded string.
        return $step1;
    }

    // --------------------------------------------------------------------------
    // CRON coordination helpers (store state in BCONFIG with BOWNERID=0, BGROUP='CRON')
    // --------------------------------------------------------------------------
    // Lightweight debug output helper for cron jobs controlled by $GLOBALS['DEBUG_CRON']
    public static function debugCronLog(string $message): void {
        if (!empty($GLOBALS['DEBUG_CRON'])) { echo $message; }
    }
    public static function addCron(string $cronId): bool {
        $id = db::EscString($cronId);
        $ts = date("YmdHis");
        // Try to create the CRON row if it doesn't exist
        $insertSql = "INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)\n"
                   . "SELECT 0, 'CRON', '".$id."', '".$ts."'\n"
                   . "FROM DUAL\n"
                   . "WHERE NOT EXISTS (SELECT 1 FROM BCONFIG WHERE BOWNERID=0 AND BGROUP='CRON' AND BSETTING='".$id."')";
        db::Query($insertSql);

        // Verify if we own the slot (value equals our timestamp -> we inserted just now)
        $sel = "SELECT BVALUE FROM BCONFIG WHERE BOWNERID=0 AND BGROUP='CRON' AND BSETTING='".$id."' ORDER BY BID DESC LIMIT 1";
        $res = db::Query($sel);
        $row = $res ? db::FetchArr($res) : null;
        return ($row && $row['BVALUE'] === $ts);
    }


    public static function updateCron(string $cronId): bool {
        $id = db::EscString($cronId);
        $ts = date("YmdHis");
        $sql = "UPDATE BCONFIG SET BVALUE='".$ts."' WHERE BOWNERID=0 AND BGROUP='CRON' AND BSETTING='".$id."'";
        return (bool) db::Query($sql);
    }

    public static function deleteCron(string $cronId): bool {
        $id = db::EscString($cronId);
        $sql = "DELETE FROM BCONFIG WHERE BOWNERID=0 AND BGROUP='CRON' AND BSETTING='".$id."'";
        return (bool) db::Query($sql);
    }

    // Returns human-friendly runtime string like "123s" or "2m 3s"
    public static function cronTime(string $cronId): string {
        $id = db::EscString($cronId);
        $sel = "SELECT BVALUE FROM BCONFIG WHERE BOWNERID=0 AND BGROUP='CRON' AND BSETTING='".$id."' ORDER BY BID DESC LIMIT 1";
        $res = db::Query($sel);
        $row = $res ? db::FetchArr($res) : null;
        if (!$row || empty($row['BVALUE'])) {
            return '0s';
        }
        $start = DateTime::createFromFormat('YmdHis', $row['BVALUE']);
        if (!$start) {
            return 'unknown';
        }
        $diff = time() - $start->getTimestamp();
        if ($diff < 0) { $diff = 0; }
        $m = intdiv($diff, 60);
        $s = $diff % 60;
        if ($m > 0) {
            return $m.'m '.$s.'s';
        }
        return $s.'s';
    }

    // Check if a cron with given ID is already running anywhere. If not running, register start.
    // Returns true if already running (caller should exit), false if successfully registered this run.
    public static function cronRunCheck(string $cronId): bool {
        $id = db::EscString($cronId);
        $sel = "SELECT BVALUE FROM BCONFIG WHERE BOWNERID=0 AND BGROUP='CRON' AND BSETTING='".$id."' ORDER BY BID DESC LIMIT 1";
        $res = db::Query($sel);
        $row = $res ? db::FetchArr($res) : null;
        if ($row && !empty($row['BVALUE'])) {
            // Already running
            return true;
        }
        // Try to claim the cron slot
        return !self::addCron($cronId) ? true : false;
    }

    // --------------------------------------------------------------------------
    // Create a secure random string for passwords/tokens
    public static function createRandomString(int $minLength = 8, int $maxLength = 12): string {
        $min = max(4, $minLength);
        $max = max($min, $maxLength);
        $length = random_int($min, $max);

        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // exclude I/O
        $lowercase = 'abcdefghijkmnopqrstuvwxyz'; // exclude l
        $digits    = '23456789'; // exclude 0/1
        $symbols   = '!()*+.-';

        $all = $uppercase . $lowercase . $digits . $symbols;

        // Ensure at least one character from each set
        $chars = [
            $uppercase[random_int(0, strlen($uppercase)-1)],
            $lowercase[random_int(0, strlen($lowercase)-1)],
            $digits[random_int(0, strlen($digits)-1)],
            $symbols[random_int(0, strlen($symbols)-1)]
        ];

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all)-1)];
        }

        // Shuffle
        for ($i = 0; $i < count($chars); $i++) {
            $j = random_int(0, count($chars)-1);
            $tmp = $chars[$i];
            $chars[$i] = $chars[$j];
            $chars[$j] = $tmp;
        }

        return implode('', $chars);
    }
}