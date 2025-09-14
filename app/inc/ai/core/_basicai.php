<?php

// Offer basic AI functionalities

Class BasicAI {
    // ****************************************************************************************************** 
    // tool prompt
    // ****************************************************************************************************** 
    public static function toolPrompt($msgArr, $stream = false): array|string|bool { 
        $textArr = explode(" ", $msgArr['BTEXT']);
        
        if($stream) {
            Frontend::statusToStream($msgArr['BID'], 'pre', $textArr[0].' ');
        }
        // -----------------------------------------------------
        // process the tool
        // -----------------------------------------------------
        $AIT2P = $GLOBALS["AI_TEXT2PIC"]["SERVICE"];
        $AIT2Pmodel = $GLOBALS["AI_TEXT2PIC"]["MODEL"];
        $AIT2PmodelId = $GLOBALS["AI_TEXT2PIC"]["MODELID"];

        $AIGENERAL = $GLOBALS["AI_CHAT"]["SERVICE"];
        $AIGENERALmodel = $GLOBALS["AI_CHAT"]["MODEL"];
        $AIGENERALmodelId = $GLOBALS["AI_CHAT"]["MODELID"];

        $AIT2V = $GLOBALS["AI_TEXT2VID"]["SERVICE"];
        $AIT2Vmodel = $GLOBALS["AI_TEXT2VID"]["MODEL"];
        $AIT2VmodelId = $GLOBALS["AI_TEXT2VID"]["MODELID"];

        $AIT2S = $GLOBALS["AI_TEXT2SOUND"]["SERVICE"];
        $AIT2Smodel = $GLOBALS["AI_TEXT2SOUND"]["MODEL"];
        $AIT2SmodelId = $GLOBALS["AI_TEXT2SOUND"]["MODELID"];

        switch($textArr[0]) {
            
            case "/aboutai":
                ProcessMethods::$toolProcessed = false;
                if($msgArr['BFILE']<1) {
                    $researchArr = $msgArr;
                    $researchArr['BTEXT'] = "/web https://www.synaplan.com/";
                    $researchArr = Tools::webScreenshot($researchArr, 1170, 1200);
                    $msgArr['BFILE'] = $researchArr['BFILE']*2; // indicate that this shall be kept!
                    $msgArr['BFILEPATH'] =  $researchArr['BFILEPATH'];
                    $msgArr['BFILETYPE'] =  $researchArr['BFILETYPE'];
                    $msgArr['BFILETEXT'] =  $researchArr['BFILETEXT'];
                }
                $promptAi = self::getAprompt("tools:aboutai", $msgArr['BLANG']);
                $msgArr['BTOPIC'] = "general";
                $msgArr['BFILETEXT'] = $msgArr['BFILETEXT']."\n\nExtra Info:\n".$promptAi['BPROMPT'];
                $msgArr['BTEXT'] = str_replace("/aboutai ","",$msgArr['BTEXT']) . " <loading><br>\n";
                break;

            case "/web":
                $msgArr = Tools::webScreenshot($msgArr);
                break;

            case "/link":
                $msgArr = Tools::memberLink($msgArr);
                break;

            case "/pic":
                if($stream) {
                    Frontend::statusToStream($msgArr['BID'], 'pre', ' - calling '.$AIT2P.' ');
                }
                // For Again requests, add a unique identifier to force new generation
                if (isset($GLOBALS["IS_AGAIN"]) && $GLOBALS["IS_AGAIN"] === true) {
                    $msgArr = $AIT2P::picPrompt($msgArr, $stream);
                    $msgArr['BTEXT'] =  $msgArr['BTEXT'] . ' [Again-' . time() . ']';
                    // Keep the AI-generated text, don't restore original
                    // This ensures proper output text is displayed
                } else {
                    $msgArr = $AIT2P::picPrompt($msgArr, $stream);
                }
                XSControl::storeAIDetails($msgArr, 'AISERVICE', $AIT2P, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODEL', $AIT2Pmodel, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODELID', $AIT2PmodelId, $stream);
                break;

            case "/vid":
                if($stream) {
                    Frontend::statusToStream($msgArr['BID'], 'pre', ' - video! Patience please (around 40s): ');
                }
                // For Again requests, add a unique identifier to force new generation
                if (isset($GLOBALS["IS_AGAIN"]) && $GLOBALS["IS_AGAIN"] === true) {
                    $originalText = $msgArr['BTEXT'];
                    $msgArr['BTEXT'] = $originalText . ' [Again-' . time() . ']';
                    $msgArr = $AIT2V::createVideo($msgArr, $stream);
                    // Keep the AI-generated text, don't restore original
                    // This ensures proper output text is displayed
                } else {
                    $msgArr = $AIT2V::createVideo($msgArr, $stream);
                }
                XSControl::storeAIDetails($msgArr, 'AISERVICE', $AIT2V, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODEL', $AIT2Vmodel, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODELID', $AIT2VmodelId, $stream);
                break;

            case "/search":
                $qTerm = "";
                $qTerm = str_replace("/search ","",$msgArr['BTEXT']);
                $msgArr = Tools::searchWeb($msgArr, $qTerm);
                XSControl::storeAIDetails($msgArr, 'WEBSEARCH', 'YES', $stream);
                break;

            case "/docs":
                $msgArr = Tools::searchDocs($msgArr);
                break;

            case "/filesort":
                $msgArr['BTEXT'] = $msgArr['BTEXT']=str_replace("/filesort ","",$msgArr['BTEXT']);
                break;

            case "/lang":
                if($stream) {
                    Frontend::statusToStream($msgArr['BID'], 'pre', ' - calling '.$AIGENERAL.' ');
                }
                $msgArr = $AIGENERAL::translateTo($msgArr, $textArr[1], 'BTEXT');
                XSControl::storeAIDetails($msgArr, 'AISERVICE', $AIGENERAL, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODEL', $AIGENERALmodel, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODELID', $AIGENERALmodelId, $stream);
                break;

            case "/audio":
                if($stream) {
                    Frontend::statusToStream($msgArr['BID'], 'pre', ' - TTS generating... ');
                }
                $msgArr['BTEXT'] = str_replace("/audio ","",$msgArr['BTEXT']);
                
                // For Again requests, add a unique identifier to force new generation
                if (isset($GLOBALS["IS_AGAIN"]) && $GLOBALS["IS_AGAIN"] === true) {
                    $originalText = $msgArr['BTEXT'];
                    $msgArr['BTEXT'] = $originalText . ' [Again-' . time() . ']';
                    $soundArr = $AIT2S::textToSpeech($msgArr, $_SESSION['USERPROFILE']);
                    // Keep the AI-generated text, don't restore original
                    // This ensures proper output text is displayed
                } else {
                    $soundArr = $AIT2S::textToSpeech($msgArr, $_SESSION['USERPROFILE']);
                }
                
                if(count($soundArr) > 0) {
                    $msgArr['BFILE'] = 1;
                    $msgArr['BFILEPATH'] = $soundArr['BFILEPATH'];
                    $msgArr['BFILETYPE'] = $soundArr['BFILETYPE'];
                    XSControl::storeAIDetails($msgArr, 'AISERVICE', $AIT2S, $stream);
                    XSControl::storeAIDetails($msgArr, 'AIMODEL', $AIT2Smodel, $stream);
                    XSControl::storeAIDetails($msgArr, 'AIMODELID', $AIT2SmodelId, $stream);
                }
                break;
                
            default:
                // Default to /list functionality when no other cases match
                $msgArr['BTEXT'] = $AIGENERAL::welcomePrompt($msgArr);
                XSControl::storeAIDetails($msgArr, 'AISERVICE', $AIGENERAL, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODEL', $AIGENERALmodel, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODELID', $AIGENERALmodelId, $stream);
                break;
            }   
        return $msgArr;
    }
    // ****************************************************************************************************** 
    // create a file from a text
    // ****************************************************************************************************** 
    public static function errorAIcheck($msgArr, $errorText, $systemArr): array {   
        

        return $msgArr;
    }


    // ****************************************************************************************************** 
    // create chunks of a big text to vectorize it
    // ****************************************************************************************************** 
    public static function chunkify($content, $minChars = 80, $maxChars = 4096) {
        $lines = explode("\n", $content);
        $chunks = [];
        $chunk = [];
        $length = 0;
        $start = 0;
    
        // Loop through all lines plus one extra sentinel line
        $totalLines = count($lines);
        for($i = 0; $i <= $totalLines; $i++) {
            // If we're past the last real line, use an empty string (sentinel)
            $line = ($i < $totalLines) ? $lines[$i] : "";
    
            $trimmedLine = trim($line);
            $leftTrimmedLine = ltrim($line);
    
            // Check if the current line starts with '#' (after ltrim)
            $startsWithHash = (strlen($leftTrimmedLine) > 0 && $leftTrimmedLine[0] === '#');
            // Check if the current line is blank
            $isEmptyLine = (strlen($trimmedLine) === 0);
    
            // If we already have something in $chunk and we hit a boundary, 
            // and the current chunk is at least $minChars
            if (
                (count($chunk) > 0) && 
                ($startsWithHash || $isEmptyLine || ($length + strlen($line) > $maxChars)) &&
                ($length >= $minChars)
            ) {
                $chunks[] = [
                    'content'    => trim(implode("\n", $chunk)),
                    'start_line' => $start,
                    'end_line'   => $i - 1,
                ];
                $chunk = [];
                $length = 0;
                $start = $i;
            }
    
            $chunk[] = $line;
            $length += strlen($line) + 1;  // +1 accounts for the newline
        }
    
        return $chunks;
    }
    // ****************************************************************************************************** 
    // get a prompt form the table BPROMPTS by user id or default and keyword
    // ****************************************************************************************************** 
    public static function getApromptById($promptId) {
        $promptKey = "general";
        $promptSQL = "select * from BPROMPTS where BID=".intval($promptId);
        $promptRes = DB::Query($promptSQL);
        $promptArr = DB::FetchArr($promptRes);
        if($promptArr && is_array($promptArr)) {
            $promptKey = $promptArr['BTOPIC'];
        }
        return self::getAprompt($promptKey, "en", [], false);
    }
    // ****************************************************************************************************** 
    // get a prompt form the table BPROMPTS by user id or default and keyword
    // ****************************************************************************************************** 
    public static function getAprompt($keyword, $lang="en", $msgArr = [], $addInfos = true) {
        $arrPrompt = [];
        $userId = $_SESSION['USERPROFILE']['BID'];

        // Validate and sanitize the keyword to prevent SQL issues
        if (empty($keyword) || !is_string($keyword)) {
            $keyword = 'chat'; // Default fallback
            if($GLOBALS["debug"]) error_log('Warning: getAprompt received empty or invalid keyword, defaulting to "general"');
        }
        $keyword = db::EscString($keyword);

        // get prompt from BPROMPTS
        $pSQL= "select * from BPROMPTS where BTOPIC='".$keyword."' and (BLANG like '".$lang."' OR BLANG='en') AND (BOWNERID='".$userId."' OR BOWNERID=0) ORDER BY BID DESC LIMIT 1";
        $pRes = DB::Query($pSQL);
        $pArr = DB::FetchArr($pRes);
        
        // ****************************************************************************************************** 
        if($pArr && is_array($pArr)) {
            $arrPrompt = $pArr;
        } else {
            // No prompt found - create a default one to prevent errors
            if($GLOBALS["debug"]) error_log('Warning: No prompt found for keyword: ' . $keyword . ', creating default');
            $arrPrompt = [
                'BID' => 0,
                'BTOPIC' => $keyword,
                'BPROMPT' => 'You are a helpful AI assistant. Please help the user with their request.',
                'BLANG' => 'en',
                'BSHORTDESC' => 'Default prompt for ' . $keyword
            ];
        }

        // if prompt is sort
        if(isset($arrPrompt['BTOPIC']) && $arrPrompt['BTOPIC'] == 'tools:sort') {
            $DYNAMICLIST = "";
            $KEYLIST = "";
            $prompts = self::getAllPrompts();
            foreach($prompts as $dynaLine) {
                $DYNAMICLIST .= "   * **".$dynaLine['BTOPIC']."**:\n";
                $DYNAMICLIST .= "    ".$dynaLine['BSHORTDESC']."\n";
                $DYNAMICLIST .= "    "."\n";
                $KEYLIST .= $dynaLine['BTOPIC']." | ";
            }
            $arrPrompt['BPROMPT'] = str_replace("[DYNAMICLIST]", $DYNAMICLIST, $arrPrompt['BPROMPT']);
            $arrPrompt['BPROMPT'] = str_replace("[KEYLIST]", $KEYLIST, $arrPrompt['BPROMPT']);
            if($addInfos) {
                $arrPrompt['BPROMPT'] .= "\n\n"."(current date: ".date("Y-m-d").")";
            }
        } else {
            // Only try to get file metadata if we have a valid BID
            if(isset($msgArr['BID']) && intval($msgArr['BID']) > 0) {
                $fileSQL = "select * from BMESSAGEMETA where BMESSID=".intval($msgArr['BID'])." AND BTOKEN='FILECOUNT' ORDER BY BID DESC LIMIT 1";  
                $fileRes = DB::Query($fileSQL);
                $fileArr = DB::FetchArr($fileRes);
                if($fileArr && is_array($fileArr) && $addInfos) {
                    $arrPrompt['BPROMPT'] .= "\n\n"."(Original message contained ".($fileArr["BVALUE"])." files)";
                }
            }
            if($addInfos) {
                $arrPrompt['BPROMPT'] .= "\n\n"."(current date: ".date("Y-m-d").")";
            }

            // ****************************************************************************************************** 
            // enrichments?
            // ****************************************************************************************************** 
            if(isset($arrPrompt['BTOPIC']) && $arrPrompt['BTOPIC'] == 'tools:filesort') {
                $fileTopicArr = self::getFileSortTopics();
                if(count($fileTopicArr) == 0) {
                    $fileTopicArr[] = "DEFAULT";
                }
                $arrPrompt['BPROMPT'] = str_replace("[RAGGROUPS]", implode(", ", $fileTopicArr), $arrPrompt['BPROMPT']);
            }

            // ****************************************************************************************************** 
            // tools to use?
            // ****************************************************************************************************** 
            if(isset($arrPrompt['BID']) && intval($arrPrompt['BID']) > 0) {
                $toolSQL = "select * from BPROMPTMETA where BPROMPTID=".$arrPrompt['BID'];
                //error_log($toolSQL);
                $toolRes = DB::Query($toolSQL);

                while($toolArr = DB::FetchArr($toolRes)) {
                    if ($toolArr && is_array($toolArr)) {
                        $arrPrompt['SETTINGS'][] = $toolArr;
                    }
                }
            }
        }
        return $arrPrompt;
    }
    // ****************************************************************************************************** 
    // get all prompts
    // ****************************************************************************************************** 
    public static function getAllPrompts() {
        $prompts = [];
        $topicArr = [];
        $userId = $_SESSION['USERPROFILE']['BID'];

        // BTOPIC not like 'tools:%
        $outerDynaSQL = "select DISTINCT BTOPIC from BPROMPTS where (BOWNERID=".$userId." OR BOWNERID=0) AND BTOPIC NOT LIKE 'tools:%' ORDER BY BOWNERID DESC";
        $outerDynaRes = DB::Query($outerDynaSQL);
        while($outerDynaLine = DB::FetchArr($outerDynaRes)) {
            if (!$outerDynaLine || !is_array($outerDynaLine) || !isset($outerDynaLine['BTOPIC'])) {
                continue;
            }
            $dynaSQL = "select * from BPROMPTS where BTOPIC='".$outerDynaLine['BTOPIC']."' AND (BOWNERID=".$userId." OR BOWNERID=0) ORDER BY BOWNERID DESC";
            $dynaRes = DB::Query($dynaSQL);
            while($dynaLine = DB::FetchArr($dynaRes)) {
                if (!$dynaLine || !is_array($dynaLine) || !isset($dynaLine['BTOPIC']) || !isset($dynaLine['BID'])) {
                    continue;
                }
                if(!in_array($dynaLine['BTOPIC'], $topicArr)) {
                    $topicArr[] = $dynaLine['BTOPIC'];
                    // ****************************************************************************************************** 
                    // tools to use?
                    // ****************************************************************************************************** 
                    $toolSQL = "select * from BPROMPTMETA where BPROMPTID=".$dynaLine['BID'];
                    //error_log($toolSQL);
                    $toolRes = DB::Query($toolSQL);

                    while($toolArr = DB::FetchArr($toolRes)) {
                        if ($toolArr && is_array($toolArr)) {
                            $dynaLine['SETTINGS'][] = $toolArr;
                        }
                    }
                    // ****************************************************************************************************** 
                    $prompts[] = $dynaLine;
                }
            }
        }
        return $prompts;
    }
    // ****************************************************************************************************** 
    // get all models
    // ****************************************************************************************************** 
    public static function getAllModels() {
        $models = [];
        $userId = $_SESSION['USERPROFILE']['BID'];

        $dynaSQL = "select * from BMODELS ORDER BY BTAG ASC";
        $dynaRes = DB::Query($dynaSQL);
        while($dynaLine = DB::FetchArr($dynaRes)) {
            if ($dynaLine && is_array($dynaLine)) {
                $models[] = $dynaLine;
            }
        }
        return $models;
    }

    // ****************************************************************************************************** 
    // get the default model for a service (AIGroq, AIOllama, AIOpenAi, etc.) and the task you want to do,
    // like "vision", "soundcreate", "text", "pic2video", "code", "musiccreate", "voice2text", ...
    // ****************************************************************************************************** 
    public static function getModel($service, $task): string {
        $model = "";
        switch($service) {
            case "AIGroq":
                $model = "llama-3.3-70b-versatile";
                break;
        }
        return $model;
    }

    // ****************************************************************************************************** 
    // get the model details
    // ****************************************************************************************************** 
    public static function getModelDetails($modelId): array {
        $mArr = [];
        $mSQL = "select * from BMODELS where BID=".intval($modelId);
        $mRes = DB::Query($mSQL);
        $result = DB::FetchArr($mRes);
        if($result && is_array($result)) {
            $mArr = $result;
        }
        return $mArr;
    }
    // ****************************************************************************************************** 
    // update a prompt - save new or update existing, add tools config
    // ****************************************************************************************************** 
    public static function updatePrompt($promptKey): array {
        $userId = $_SESSION['USERPROFILE']['BID'];

        // Sanitize input data
        $promptKey = db::EscString($promptKey);
        $prompt = db::EscString($_REQUEST['promptContent']);
        $lang = "en"; //db::EscString($lang);
        // needs to define the language of the prompt via a local model
        //--------------------------------
        $saveFlag = db::EscString($_REQUEST['saveFlag'] ?? '');
        $aiModel = db::EscString($_REQUEST['aiModel'] ?? '');
        $description = db::EscString($_REQUEST['promptDescription'] ?? '');
        
        // Handle tools settings - updated to use new parameter format
        $tools = [
            'internet' => $_REQUEST['tool_internet'] ?? '0',
            'files' => $_REQUEST['tool_files'] ?? '0',
            'screenshot' => $_REQUEST['tool_screenshot'] ?? '0',
            'transfer' => $_REQUEST['tool_transfer'] ?? '0'
        ];

        // If saving as new name, use that as the prompt key
        if ($saveFlag === 'saveAs' && !empty($_REQUEST['newName'])) {
            $newName = db::EscString($_REQUEST['newName']);
            if (!empty($newName)) {
                $promptKey = $newName;
            }
        }

        // Get the ID of the deleted prompt to clean up metadata
        $oldPromptId = "DEFAULT";
        $sql = "SELECT BID FROM BPROMPTS WHERE BTOPIC = '{$promptKey}' AND BOWNERID = {$userId} AND BOWNERID > 0";
        
        $res = db::Query($sql);
        if ($row = db::FetchArr($res)) {
            $oldPromptId = $row['BID'];
            // Delete associated metadata
            $sql = "DELETE FROM BPROMPTMETA WHERE BPROMPTID = {$oldPromptId}";
            db::Query($sql);

            // Delete any existing user-specific prompt and its metadata
            $sql = "DELETE FROM BPROMPTS WHERE BTOPIC = '{$promptKey}' AND BOWNERID = {$userId} AND BOWNERID > 0";
            db::Query($sql);
        }

        // Create new prompt entry for the user
        $sql = "INSERT INTO BPROMPTS (BID, BOWNERID, BLANG, BTOPIC, BPROMPT, BSHORTDESC) 
                VALUES ({$oldPromptId}, {$userId}, '{$lang}', '{$promptKey}', '{$prompt}', '{$description}')";
        db::Query($sql);
        $promptId = db::LastId();

        // Save AI model setting
        $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) 
                VALUES ({$promptId}, 'aiModel', '{$aiModel}')
                ON DUPLICATE KEY UPDATE BVALUE = '{$aiModel}'";
        db::Query($sql);

        // Save tools settings
        foreach ($tools as $tool => $value) {
            $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) 
                    VALUES ({$promptId}, 'tool_{$tool}', '{$value}')
                    ON DUPLICATE KEY UPDATE BVALUE = '{$value}'";
            db::Query($sql);
        }

        // After saving the standard tool settings
        // Save tool_files_keyword if present
        if (!empty($_REQUEST['tool_files_keyword'])) {
            $cleanKey = db::EscString($_REQUEST['tool_files_keyword']);
            $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) 
                    VALUES ({$promptId}, 'tool_files_keyword', '".$cleanKey."')
                    ON DUPLICATE KEY UPDATE BVALUE = '".$cleanKey."'";
            db::Query($sql);
        }
        // Save screenshot dimensions if present
        if (!empty($_REQUEST['tool_screenshot_x'])) {
            $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) 
                    VALUES ({$promptId}, 'tool_screenshot_x', '".db::EscString($_REQUEST['tool_screenshot_x'])."')
                    ON DUPLICATE KEY UPDATE BVALUE = '".intval($_REQUEST['tool_screenshot_x'])."'";
            db::Query($sql);
        }
        if (!empty($_REQUEST['tool_screenshot_y'])) {
            $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) 
                    VALUES ({$promptId}, 'tool_screenshot_y', '".db::EscString($_REQUEST['tool_screenshot_y'])."')
                    ON DUPLICATE KEY UPDATE BVALUE = '".intval($_REQUEST['tool_screenshot_y'])."'";
            db::Query($sql);
        }
        //--------------------------------
        $resArr = ['success' => true, 'promptId' => $promptId];
        return $resArr;
    }
    // ****************************************************************************************************** 
    // delete a prompt
    // ****************************************************************************************************** 
    public static function deletePrompt($promptKey): bool {
        $userId = $_SESSION['USERPROFILE']['BID'];

        // First, check if the prompt exists and get its ID (sanity check)
        $sql = "SELECT BID FROM BPROMPTS WHERE BTOPIC = '{$promptKey}' AND BOWNERID = {$userId} AND BOWNERID > 0";
        $res = db::Query($sql);
        
        if ($row = db::FetchArr($res)) {
            $promptId = $row['BID'];
            // Delete associated metadata
            $sql = "DELETE FROM BPROMPTMETA WHERE BPROMPTID = {$promptId}";
            db::Query($sql);
       
            // Now delete the prompt itself
            $sql = "DELETE FROM BPROMPTS WHERE BID = {$promptId} AND BOWNERID = {$userId} AND BOWNERID > 0";
            db::Query($sql);
            return true;
        }
        return false;
    }
    // ****************************************************************************************************** 
    // get prompt details
    // ****************************************************************************************************** 
    public static function getPromptDetails($promptKey): array {
        $arrPrompt = [];
        $arrPrompt = self::getAprompt($promptKey, "%", [], false);
        $arrPrompt['SETTINGS'] = [];
        
        // Ensure BID exists before using it in SQL query
        if (isset($arrPrompt['BID']) && !empty($arrPrompt['BID'])) {
            $toolSQL = "select * from BPROMPTMETA where BPROMPTID=".$arrPrompt['BID'];
            $toolRes = DB::Query($toolSQL);
            while($toolArr = DB::FetchArr($toolRes)) {
                if ($toolArr && is_array($toolArr)) {
                    $arrPrompt['SETTINGS'][] = $toolArr;
                }
            }
        } else {
            if($GLOBALS["debug"]) error_log('Warning: getPromptDetails could not find prompt for key: ' . $promptKey);
        }
        
        return $arrPrompt;
    }
    // ****************************************************************************************************** 
    // get the file sorting topics
    // ****************************************************************************************************** 
    public static function getFileSortTopics(): array {
        $groupKeys = [];
        $sql = "SELECT DISTINCT BRAG.BGROUPKEY
                FROM BMESSAGES
                INNER JOIN BRAG ON BRAG.BMID = BMESSAGES.BID
                WHERE BMESSAGES.BUSERID = " . $_SESSION["USERPROFILE"]["BID"] . "
                  AND BMESSAGES.BDIRECT = 'IN'
                  AND BMESSAGES.BFILE > 0
                  AND BMESSAGES.BFILEPATH != ''";
        $res = db::Query($sql);
        while ($row = db::FetchArr($res)) {
            if (!empty($row['BGROUPKEY'])) {
                $groupKeys[] = $row['BGROUPKEY'];
            }
        }
        return $groupKeys;
    }
    // ****************************************************************************************************** 
    // get the prompt summarized in short, if too long:
    // ****************************************************************************************************** 
    public static function getShortPrompt($prompt): string {
        $AISUMMARIZE = $GLOBALS["AI_SUMMARIZE"]["SERVICE"];
        $prompt = $AISUMMARIZE::summarizePrompt($prompt);
        return $prompt;
    }
    
    // ****************************************************************************************************** 
    // get all file groups for the current user
    // ****************************************************************************************************** 
    public static function getAllFileGroups(): array {
        $groups = [];
        $userId = $_SESSION["USERPROFILE"]["BID"];
        
        $sql = "SELECT DISTINCT BRAG.BGROUPKEY
                FROM BMESSAGES
                INNER JOIN BRAG ON BRAG.BMID = BMESSAGES.BID
                WHERE BMESSAGES.BUSERID = " . intval($userId) . "
                  AND BMESSAGES.BDIRECT = 'IN'
                  AND BMESSAGES.BFILE > 0
                  AND BMESSAGES.BFILEPATH != ''
                  AND BRAG.BGROUPKEY != ''
                  AND BRAG.BGROUPKEY IS NOT NULL
                ORDER BY BRAG.BGROUPKEY";
        
        $res = db::Query($sql);
        while ($row = db::FetchArr($res)) {
            if (!empty($row['BGROUPKEY'])) {
                $groups[] = $row['BGROUPKEY'];
            }
        }
        
        return $groups;
    }
    
    // ****************************************************************************************************** 
    // change the group of a specific file
    // ****************************************************************************************************** 
    public static function changeGroupOfFile($fileId, $newGroup): array {
        $resArr = ['success' => false, 'error' => ''];
        $userId = $_SESSION["USERPROFILE"]["BID"];
        
        if($GLOBALS["debug"]) error_log("BasicAI::changeGroupOfFile called with fileId: $fileId, newGroup: '$newGroup', userId: $userId");
        
        // Validate file ownership
        $fileSQL = "SELECT * FROM BMESSAGES WHERE BID = " . intval($fileId) . " AND BUSERID = " . intval($userId) . " AND BFILE > 0";
        $fileRes = db::Query($fileSQL);
        $fileArr = db::FetchArr($fileRes);
        
        if (!$fileArr) {
            $resArr['error'] = 'File not found or access denied';
            if($GLOBALS["debug"]) error_log("BasicAI::changeGroupOfFile - File not found: $fileId for user: $userId");
            return $resArr;
        }
        
        if($GLOBALS["debug"]) error_log("BasicAI::changeGroupOfFile - File found: " . json_encode($fileArr));
        
        // Update existing BRAG records for this file
        if (empty($newGroup)) {
            // Remove group (set to empty string)
            $updateSQL = "UPDATE BRAG SET BGROUPKEY = '' WHERE BMID = " . intval($fileId);
        } else {
            // Update group
            $updateSQL = "UPDATE BRAG SET BGROUPKEY = '" . db::EscString($newGroup) . "' WHERE BMID = " . intval($fileId);
        }
        
        if($GLOBALS["debug"]) error_log("BasicAI::changeGroupOfFile - SQL: $updateSQL");
        
        // Execute the update query
        $result = db::Query($updateSQL);
        
        if ($result) {
            $resArr['success'] = true;
            $resArr['message'] = 'File group updated successfully';
            if($GLOBALS["debug"]) error_log("BasicAI::changeGroupOfFile - Success");
        } else {
            $resArr['error'] = 'Database error occurred while updating file group';
            if($GLOBALS["debug"]) error_log("BasicAI::changeGroupOfFile - Database error");
        }
        
        return $resArr;
    }

    // ****************************************************************************************************** 
    // document summarization functionality
    // ****************************************************************************************************** 
    public static function doDocSum(): array {
        $resArr = ['success' => false, 'error' => '', 'summary' => ''];
        
        try {
            // Validate input
            if (empty($_REQUEST['BFILETEXT'])) {
                $resArr['error'] = 'No document text provided';
                return $resArr;
            }

            $documentText = db::EscString(trim($_REQUEST['BFILETEXT']));
            if (strlen($documentText) < 100) {
                $resArr['error'] = 'Document text is too short (minimum 100 characters)';
                return $resArr;
            }

            // Get configuration parameters to build the system prompt
            $summaryType = db::EscString($_REQUEST['summaryType'] ?? 'abstractive');
            $summaryLength = db::EscString($_REQUEST['summaryLength'] ?? 'medium');
            $length = 500;
            switch($summaryLength) {
                case 'short':
                    $length = 200;
                    break;
                case 'medium':
                    $length = 400;
                    break;
                case 'long':
                    $length = 1000;
                    break;
            }
            $language = db::EscString($_REQUEST['language'] ?? 'en');
            $customLength = db::EscString($_REQUEST['customLength'] ?? $length);
            if(intval($customLength) < 200) {
                $customLength = $length;
            }
            if(intval($customLength) > 2000) {
                $customLength = 2000;
            }
            $focusAreas = $_REQUEST['focusAreas'] ?? ['main_ideas', 'key_facts'];

            /*
            error_log("********** REQUEST DOCSUM: ");
            error_log("** summaryType: " . $summaryType)    ;
            error_log("** summaryLength: " . $summaryLength);
            error_log("** language: " . $language);
            error_log("** customLength: " . $customLength);
            error_log("** focusAreas: " . print_r($focusAreas, true));
            error_log("** documentText: " . strlen($documentText) . " characters");
            error_log("*********************************************************** ");
            */
            // System prompt

            $systemPrompt = "You are a helpful assistant that summarizes documents in various languages. 
              You will be given a document text and you will need to summarize it.
              Please create a ".$summaryType." summary with ca. ".$summaryLength." length in language: '".$language."'.
              The summary should be ".$customLength." characters long.
              The summary should be in the following focus areas: ".implode(", ", $focusAreas).".";

            // get the summarize service directly from global configuration (like other methods)
            $AISUMMARIZE = $GLOBALS["AI_SUMMARIZE"]["SERVICE"];

            // --- execute the summarize model
            $resArr = $AISUMMARIZE::simplePrompt($systemPrompt, $documentText);
            
        } catch (Exception $e) {
            if($GLOBALS["debug"]) error_log("BasicAI::doDocSum - Error: " . $e->getMessage());
            $resArr['error'] = 'An error occurred while processing the document: ' . $e->getMessage();
        }
        
        return $resArr;
    }
}