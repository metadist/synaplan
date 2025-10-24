<?php

// Offer basic AI functionalities

class BasicAI
{
    // ******************************************************************************************************
    // tool prompt
    // ******************************************************************************************************
    public static function toolPrompt($msgArr, $stream = false): array|string|bool
    {
        $textArr = explode(' ', $msgArr['BTEXT']);

        if ($stream) {
            Frontend::statusToStream($msgArr['BID'], 'pre', $textArr[0].' ');
        }
        // -----------------------------------------------------
        // process the tool
        // -----------------------------------------------------
        $AIT2P = $GLOBALS['AI_TEXT2PIC']['SERVICE'];
        $AIT2Pmodel = $GLOBALS['AI_TEXT2PIC']['MODEL'];
        $AIT2PmodelId = $GLOBALS['AI_TEXT2PIC']['MODELID'];

        $AIGENERAL = $GLOBALS['AI_CHAT']['SERVICE'];
        $AIGENERALmodel = $GLOBALS['AI_CHAT']['MODEL'];
        $AIGENERALmodelId = $GLOBALS['AI_CHAT']['MODELID'];

        $AIT2V = $GLOBALS['AI_TEXT2VID']['SERVICE'];
        $AIT2Vmodel = $GLOBALS['AI_TEXT2VID']['MODEL'];
        $AIT2VmodelId = $GLOBALS['AI_TEXT2VID']['MODELID'];

        $AIT2S = $GLOBALS['AI_TEXT2SOUND']['SERVICE'];
        $AIT2Smodel = $GLOBALS['AI_TEXT2SOUND']['MODEL'];
        $AIT2SmodelId = $GLOBALS['AI_TEXT2SOUND']['MODELID'];

        switch ($textArr[0]) {

            case '/aboutai':
                ProcessMethods::$toolProcessed = false;
                if ($msgArr['BFILE'] < 1) {
                    $researchArr = $msgArr;
                    $researchArr['BTEXT'] = '/web ' . ApiKeys::getBaseUrl() . '/';
                    $researchArr = Tools::webScreenshot($researchArr, 1170, 1200);
                    $msgArr['BFILE'] = $researchArr['BFILE'] * 2; // indicate that this shall be kept!
                    $msgArr['BFILEPATH'] =  $researchArr['BFILEPATH'];
                    $msgArr['BFILETYPE'] =  $researchArr['BFILETYPE'];
                    $msgArr['BFILETEXT'] =  $researchArr['BFILETEXT'];
                }
                $promptAi = self::getAprompt('tools:aboutai', $msgArr['BLANG']);
                $msgArr['BTOPIC'] = 'general';
                $msgArr['BFILETEXT'] = $msgArr['BFILETEXT']."\n\nExtra Info:\n".$promptAi['BPROMPT'];
                $msgArr['BTEXT'] = str_replace('/aboutai ', '', $msgArr['BTEXT']) . " <loading><br>\n";
                break;

            case '/web':
                $msgArr = Tools::webScreenshot($msgArr);
                break;

            case '/link':
                $msgArr = Tools::memberLink($msgArr);
                break;

            case '/pic':
                if ($stream) {
                    Frontend::statusToStream($msgArr['BID'], 'pre', ' - calling '.$AIT2P.' ');
                }
                // For Again requests, add a unique identifier to force new generation
                if (isset($GLOBALS['IS_AGAIN']) && $GLOBALS['IS_AGAIN'] === true) {
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

            case '/vid':
                if ($stream) {
                    Frontend::statusToStream($msgArr['BID'], 'pre', ' - video! Patience please (around 40s): ');
                }
                // For Again requests, add a unique identifier to force new generation
                if (isset($GLOBALS['IS_AGAIN']) && $GLOBALS['IS_AGAIN'] === true) {
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

            case '/search':
                $qTerm = '';
                $qTerm = str_replace('/search ', '', $msgArr['BTEXT']);
                $msgArr = Tools::searchWeb($msgArr, $qTerm);
                XSControl::storeAIDetails($msgArr, 'WEBSEARCH', 'YES', $stream);
                break;

            case '/docs':
                $msgArr = Tools::searchDocs($msgArr);
                break;

            case '/filesort':
                $msgArr['BTEXT'] = $msgArr['BTEXT'] = str_replace('/filesort ', '', $msgArr['BTEXT']);
                break;

            case '/lang':
                if ($stream) {
                    Frontend::statusToStream($msgArr['BID'], 'pre', ' - calling '.$AIGENERAL.' ');
                }
                $msgArr = $AIGENERAL::translateTo($msgArr, $textArr[1], 'BTEXT');
                XSControl::storeAIDetails($msgArr, 'AISERVICE', $AIGENERAL, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODEL', $AIGENERALmodel, $stream);
                XSControl::storeAIDetails($msgArr, 'AIMODELID', $AIGENERALmodelId, $stream);
                break;

            case '/audio':
                if ($stream) {
                    Frontend::statusToStream($msgArr['BID'], 'pre', ' - TTS generating... ');
                }
                $msgArr['BTEXT'] = str_replace('/audio ', '', $msgArr['BTEXT']);

                // For Again requests, add a unique identifier to force new generation
                if (isset($GLOBALS['IS_AGAIN']) && $GLOBALS['IS_AGAIN'] === true) {
                    $originalText = $msgArr['BTEXT'];
                    $msgArr['BTEXT'] = $originalText . ' [Again-' . time() . ']';
                    $soundArr = $AIT2S::textToSpeech($msgArr, $_SESSION['USERPROFILE']);
                    // Keep the AI-generated text, don't restore original
                    // This ensures proper output text is displayed
                } else {
                    $soundArr = $AIT2S::textToSpeech($msgArr, $_SESSION['USERPROFILE']);
                }

                if (count($soundArr) > 0) {
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
    public static function errorAIcheck($msgArr, $errorText, $systemArr): array
    {


        return $msgArr;
    }


    // ******************************************************************************************************
    // create chunks of a big text to vectorize it
    // ******************************************************************************************************
    public static function chunkify($content, $minChars = 80, $maxChars = 4096)
    {
        $lines = explode("\n", $content);
        $chunks = [];
        $chunk = [];
        $length = 0;
        $start = 0;

        // Loop through all lines plus one extra sentinel line
        $totalLines = count($lines);
        for ($i = 0; $i <= $totalLines; $i++) {
            // If we're past the last real line, use an empty string (sentinel)
            $line = ($i < $totalLines) ? $lines[$i] : '';

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
    public static function getApromptById($promptId)
    {
        $promptKey = 'general';
        $promptSQL = 'select * from BPROMPTS where BID='.intval($promptId);
        $promptRes = db::Query($promptSQL);
        $promptArr = db::FetchArr($promptRes);
        if ($promptArr && is_array($promptArr)) {
            $promptKey = $promptArr['BTOPIC'];
        }
        return self::getAprompt($promptKey, 'en', [], false);
    }
    // ******************************************************************************************************
    // get a prompt form the table BPROMPTS by user id or default and keyword
    // ******************************************************************************************************
    public static function getAprompt($keyword, $lang = 'en', $msgArr = [], $addInfos = true)
    {
        $arrPrompt = [];

        // CRITICAL FIX: Get user ID from message array first (for email/WhatsApp background processing)
        // Fall back to session only if message doesn't have user ID (for web chat)
        $userId = 0;
        if (isset($msgArr['BUSERID']) && intval($msgArr['BUSERID']) > 0) {
            $userId = intval($msgArr['BUSERID']);
        } elseif (isset($_SESSION['USERPROFILE']['BID'])) {
            $userId = intval($_SESSION['USERPROFILE']['BID']);
        }

        // Validate and sanitize the keyword to prevent SQL issues
        if (empty($keyword) || !is_string($keyword)) {
            $keyword = 'chat'; // Default fallback
            if ($GLOBALS['debug']) {
                error_log('Warning: getAprompt received empty or invalid keyword, defaulting to "general"');
            }
        }
        $keyword = db::EscString($keyword);

        // get prompt from BPROMPTS - prioritize user's custom prompt (higher BID with ORDER BY BID DESC)
        $pSQL = "select * from BPROMPTS where BTOPIC='".$keyword."' and (BLANG like '".$lang."' OR BLANG='en') AND (BOWNERID='".$userId."' OR BOWNERID=0) ORDER BY BID DESC LIMIT 1";
        $pRes = db::Query($pSQL);
        $pArr = db::FetchArr($pRes);

        // ******************************************************************************************************
        if ($pArr && is_array($pArr)) {
            $arrPrompt = $pArr;
        } else {
            // No prompt found - create a default one to prevent errors
            if ($GLOBALS['debug']) {
                error_log('Warning: No prompt found for keyword: ' . $keyword . ', creating default');
            }
            $arrPrompt = [
                'BID' => 0,
                'BTOPIC' => $keyword,
                'BPROMPT' => 'You are a helpful AI assistant. Please help the user with their request.',
                'BLANG' => 'en',
                'BSHORTDESC' => 'Default prompt for ' . $keyword
            ];
        }

        // if prompt is sort
        if (isset($arrPrompt['BTOPIC']) && $arrPrompt['BTOPIC'] == 'tools:sort') {
            $DYNAMICLIST = '';
            $KEYLIST = '';
            $prompts = self::getAllPrompts();
            foreach ($prompts as $dynaLine) {
                $DYNAMICLIST .= '   * **'.$dynaLine['BTOPIC']."**:\n";
                $DYNAMICLIST .= '    '.$dynaLine['BSHORTDESC']."\n";
                $DYNAMICLIST .= '    '."\n";
                $KEYLIST .= $dynaLine['BTOPIC'].' | ';
            }
            $arrPrompt['BPROMPT'] = str_replace('[DYNAMICLIST]', $DYNAMICLIST, $arrPrompt['BPROMPT']);
            $arrPrompt['BPROMPT'] = str_replace('[KEYLIST]', $KEYLIST, $arrPrompt['BPROMPT']);
            if ($addInfos) {
                $arrPrompt['BPROMPT'] .= "\n\n".'(current date: '.date('Y-m-d').')';
            }
        } else {
            // Only try to get file metadata if we have a valid BID
            if (isset($msgArr['BID']) && intval($msgArr['BID']) > 0) {
                $fileSQL = 'select * from BMESSAGEMETA where BMESSID='.intval($msgArr['BID'])." AND BTOKEN='FILECOUNT' ORDER BY BID DESC LIMIT 1";
                $fileRes = db::Query($fileSQL);
                $fileArr = db::FetchArr($fileRes);
                if ($fileArr && is_array($fileArr) && $addInfos) {
                    $arrPrompt['BPROMPT'] .= "\n\n".'(Original message contained '.($fileArr['BVALUE']).' files)';
                }
            }
            if ($addInfos) {
                $arrPrompt['BPROMPT'] .= "\n\n".'(current date: '.date('Y-m-d').')';
            }

            // ******************************************************************************************************
            // enrichments?
            // ******************************************************************************************************
            if (isset($arrPrompt['BTOPIC']) && $arrPrompt['BTOPIC'] == 'tools:filesort') {
                $fileTopicArr = self::getFileSortTopics();
                if (count($fileTopicArr) == 0) {
                    $fileTopicArr[] = 'DEFAULT';
                }
                $arrPrompt['BPROMPT'] = str_replace('[RAGGROUPS]', implode(', ', $fileTopicArr), $arrPrompt['BPROMPT']);
            }

            // ******************************************************************************************************
            // tools to use?
            // ******************************************************************************************************
            if (isset($arrPrompt['BID']) && intval($arrPrompt['BID']) > 0) {
                $toolSQL = 'select * from BPROMPTMETA where BPROMPTID='.$arrPrompt['BID'];
                //error_log($toolSQL);
                $toolRes = db::Query($toolSQL);

                while ($toolArr = db::FetchArr($toolRes)) {
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
    public static function getAllPrompts()
    {
        $prompts = [];
        $topicArr = [];
        $userId = $_SESSION['USERPROFILE']['BID'];

        // BTOPIC not like 'tools:%
        $outerDynaSQL = 'select DISTINCT BTOPIC from BPROMPTS where (BOWNERID='.$userId." OR BOWNERID=0) AND BTOPIC NOT LIKE 'tools:%' ORDER BY BOWNERID DESC";
        $outerDynaRes = db::Query($outerDynaSQL);
        while ($outerDynaLine = db::FetchArr($outerDynaRes)) {
            if (!$outerDynaLine || !is_array($outerDynaLine) || !isset($outerDynaLine['BTOPIC'])) {
                continue;
            }
            $dynaSQL = "select * from BPROMPTS where BTOPIC='".$outerDynaLine['BTOPIC']."' AND (BOWNERID=".$userId.' OR BOWNERID=0) ORDER BY BOWNERID DESC';
            $dynaRes = db::Query($dynaSQL);
            while ($dynaLine = db::FetchArr($dynaRes)) {
                if (!$dynaLine || !is_array($dynaLine) || !isset($dynaLine['BTOPIC']) || !isset($dynaLine['BID'])) {
                    continue;
                }
                if (!in_array($dynaLine['BTOPIC'], $topicArr)) {
                    $topicArr[] = $dynaLine['BTOPIC'];
                    // ******************************************************************************************************
                    // tools to use?
                    // ******************************************************************************************************
                    $toolSQL = 'select * from BPROMPTMETA where BPROMPTID='.$dynaLine['BID'];
                    //error_log($toolSQL);
                    $toolRes = db::Query($toolSQL);

                    while ($toolArr = db::FetchArr($toolRes)) {
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
    public static function getAllModels()
    {
        $models = [];
        $userId = $_SESSION['USERPROFILE']['BID'];

        $dynaSQL = 'select * from BMODELS ORDER BY BTAG ASC';
        $dynaRes = db::Query($dynaSQL);
        while ($dynaLine = db::FetchArr($dynaRes)) {
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
    public static function getModel($service, $task): string
    {
        $model = '';
        switch ($service) {
            case 'AIGroq':
                $model = 'llama-3.3-70b-versatile';
                break;
        }
        return $model;
    }

    // ******************************************************************************************************
    // get the model details
    // ******************************************************************************************************
    public static function getModelDetails($modelId): array
    {
        $mArr = [];
        $mSQL = 'select * from BMODELS where BID='.intval($modelId);
        $mRes = db::Query($mSQL);
        $result = db::FetchArr($mRes);
        if ($result && is_array($result)) {
            $mArr = $result;
        }
        return $mArr;
    }
    // ******************************************************************************************************
    // update a prompt - save new or update existing, add tools config
    // ******************************************************************************************************
    public static function updatePrompt($promptKey): array
    {
        $userId = $_SESSION['USERPROFILE']['BID'];

        // Sanitize input data
        $promptKey = db::EscString($promptKey);
        $prompt = db::EscString($_REQUEST['promptContent']);
        $lang = 'en'; //db::EscString($lang);
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
        $oldPromptId = 'DEFAULT';
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
    public static function deletePrompt($promptKey): bool
    {
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
    public static function getPromptDetails($promptKey): array
    {
        $arrPrompt = [];
        $arrPrompt = self::getAprompt($promptKey, '%', [], false);
        $arrPrompt['SETTINGS'] = [];

        // Ensure BID exists before using it in SQL query
        if (isset($arrPrompt['BID']) && !empty($arrPrompt['BID'])) {
            $toolSQL = 'select * from BPROMPTMETA where BPROMPTID='.$arrPrompt['BID'];
            $toolRes = db::Query($toolSQL);
            while ($toolArr = db::FetchArr($toolRes)) {
                if ($toolArr && is_array($toolArr)) {
                    $arrPrompt['SETTINGS'][] = $toolArr;
                }
            }
        } else {
            if ($GLOBALS['debug']) {
                error_log('Warning: getPromptDetails could not find prompt for key: ' . $promptKey);
            }
        }

        return $arrPrompt;
    }
    // ******************************************************************************************************
    // enable file search on a prompt with group filter
    // Creates user-specific copy if it's a default prompt (BOWNERID = 0)
    // ******************************************************************************************************
    public static function enablePromptFileSearch($promptKey, $groupKey): array
    {
        $retArr = ['error' => '', 'success' => false];

        try {
            $userId = $_SESSION['USERPROFILE']['BID'];
            $promptKey = db::EscString($promptKey);
            $groupKey = db::EscString($groupKey);

            if (empty($promptKey)) {
                $retArr['error'] = 'Prompt key is required';
                return $retArr;
            }

            // Get the current prompt (default or user-specific)
            $sql = "SELECT BID, BPROMPT, BSHORTDESC, BLANG, BOWNERID FROM BPROMPTS 
                    WHERE BTOPIC = '" . $promptKey . "' 
                    AND (BOWNERID = 0 OR BOWNERID = " . $userId . ')
                    ORDER BY BOWNERID DESC
                    LIMIT 1';
            $res = db::Query($sql);
            $currentPrompt = db::FetchArr($res);

            if (!$currentPrompt) {
                $retArr['error'] = 'Prompt not found';
                error_log("enablePromptFileSearch: Prompt '$promptKey' not found for user $userId");
                return $retArr;
            }

            $targetPromptId = $currentPrompt['BID'];
            $needsNewPrompt = false;

            error_log("enablePromptFileSearch: Found prompt BID={$targetPromptId}, BOWNERID={$currentPrompt['BOWNERID']}, BTOPIC='$promptKey' for user $userId");

            // If this is a default prompt (BOWNERID = 0), create a user-specific copy
            if ($currentPrompt['BOWNERID'] == 0) {
                // Check if user already has a custom copy
                $checkSql = "SELECT BID FROM BPROMPTS 
                             WHERE BTOPIC = '" . $promptKey . "' AND BOWNERID = " . $userId . ' LIMIT 1';
                $checkRes = db::Query($checkSql);
                $existingPrompt = db::FetchArr($checkRes);

                if ($existingPrompt) {
                    // User already has a custom prompt, use it
                    $targetPromptId = $existingPrompt['BID'];
                } else {
                    // Create user-specific copy of the prompt
                    $insertPromptSql = 'INSERT INTO BPROMPTS (BOWNERID, BLANG, BTOPIC, BPROMPT, BSHORTDESC) 
                                        VALUES (' . $userId . ", 
                                                '" . db::EscString($currentPrompt['BLANG']) . "', 
                                                '" . $promptKey . "', 
                                                '" . db::EscString($currentPrompt['BPROMPT']) . "', 
                                                '" . db::EscString($currentPrompt['BSHORTDESC']) . "')";
                    db::Query($insertPromptSql);
                    $targetPromptId = db::LastId();

                    if ($targetPromptId <= 0) {
                        $retArr['error'] = 'Failed to create user-specific prompt';
                        return $retArr;
                    }
                    $needsNewPrompt = true;
                }
            }

            // Get existing settings BEFORE deleting (to preserve values we don't change)
            $existingSettings = [];
            $settingsSql = 'SELECT BTOKEN, BVALUE FROM BPROMPTMETA WHERE BPROMPTID = ' . $targetPromptId;
            $settingsRes = db::Query($settingsSql);
            while ($settingRow = db::FetchArr($settingsRes)) {
                $existingSettings[$settingRow['BTOKEN']] = $settingRow['BVALUE'];
            }

            // If we just created a new prompt and it has no settings, copy from default
            if ($needsNewPrompt && empty($existingSettings)) {
                $defaultSettingsSql = 'SELECT BTOKEN, BVALUE FROM BPROMPTMETA WHERE BPROMPTID = ' . $currentPrompt['BID'];
                $defaultSettingsRes = db::Query($defaultSettingsSql);
                while ($settingRow = db::FetchArr($defaultSettingsRes)) {
                    $existingSettings[$settingRow['BTOKEN']] = $settingRow['BVALUE'];
                }
            }

            // DELETE old prompt settings (matching c_prompts.php pattern)
            // SECURITY: Only delete if prompt belongs to user AND is not a default prompt
            $deleteSql = 'DELETE FROM BPROMPTMETA 
                         WHERE BPROMPTID = ' . $targetPromptId . '
                         AND BPROMPTID IN (
                             SELECT BID FROM BPROMPTS 
                             WHERE BID = ' . $targetPromptId . '
                             AND BOWNERID = ' . $userId . '
                             AND BOWNERID > 0
                         )';
            db::Query($deleteSql);
            error_log("enablePromptFileSearch: Deleted old settings for prompt ID $targetPromptId (user $userId)");

            // Prepare all settings (matching c_prompts.php structure)
            // Use existing values or defaults
            $aiModel = $existingSettings['aiModel'] ?? '-1';
            $toolInternet = $existingSettings['tool_internet'] ?? '0';
            $toolFiles = '1';  // ENABLE file search
            $toolScreenshot = $existingSettings['tool_screenshot'] ?? '0';
            $toolTransfer = $existingSettings['tool_transfer'] ?? '0';
            $toolFilesKeyword = $groupKey;  // Set group filter
            $toolScreenshotX = $existingSettings['tool_screenshot_x'] ?? '';
            $toolScreenshotY = $existingSettings['tool_screenshot_y'] ?? '';

            // INSERT all settings fresh (matching c_prompts.php pattern)
            $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES ({$targetPromptId}, 'aiModel', '" . db::EscString($aiModel) . "')";
            db::Query($sql);

            $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES ({$targetPromptId}, 'tool_internet', '" . db::EscString($toolInternet) . "')";
            db::Query($sql);

            $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES ({$targetPromptId}, 'tool_files', '" . db::EscString($toolFiles) . "')";
            db::Query($sql);

            $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES ({$targetPromptId}, 'tool_screenshot', '" . db::EscString($toolScreenshot) . "')";
            db::Query($sql);

            $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES ({$targetPromptId}, 'tool_transfer', '" . db::EscString($toolTransfer) . "')";
            db::Query($sql);

            // Insert tool_files_keyword (group filter)
            if (!empty($toolFilesKeyword)) {
                $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES ({$targetPromptId}, 'tool_files_keyword', '" . db::EscString($toolFilesKeyword) . "')";
                db::Query($sql);
            }

            // Insert screenshot settings if they exist
            if (!empty($toolScreenshotX)) {
                $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES ({$targetPromptId}, 'tool_screenshot_x', '" . db::EscString($toolScreenshotX) . "')";
                db::Query($sql);
            }
            if (!empty($toolScreenshotY)) {
                $sql = "INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) VALUES ({$targetPromptId}, 'tool_screenshot_y', '" . db::EscString($toolScreenshotY) . "')";
                db::Query($sql);
            }

            $retArr['success'] = true;
            $retArr['message'] = 'File search enabled on prompt with filter: ' . $groupKey;
            $retArr['promptId'] = $targetPromptId;

            error_log("enablePromptFileSearch: SUCCESS - Prompt ID $targetPromptId updated with tool_files=1, tool_files_keyword=$groupKey");

            return $retArr;

        } catch (\Throwable $e) {
            error_log('Enable File Search Error: ' . $e->getMessage());
            $retArr['error'] = 'Failed to enable file search: ' . $e->getMessage();
            return $retArr;
        }
    }
    // ******************************************************************************************************
    // update file search filter on existing prompt
    // ******************************************************************************************************
    public static function updatePromptFileSearchFilter($promptKey, $groupKey): array
    {
        $retArr = ['error' => '', 'success' => false];

        try {
            $userId = $_SESSION['USERPROFILE']['BID'];
            $promptKey = db::EscString($promptKey);
            $groupKey = db::EscString($groupKey);

            if (empty($promptKey)) {
                $retArr['error'] = 'Prompt key is required';
                return $retArr;
            }

            // Get the user's prompt (only user-owned, not default)
            $sql = "SELECT BID FROM BPROMPTS 
                    WHERE BTOPIC = '" . $promptKey . "' 
                    AND BOWNERID = " . $userId . '
                    LIMIT 1';
            $res = db::Query($sql);
            $userPrompt = db::FetchArr($res);

            if (!$userPrompt) {
                $retArr['error'] = 'User-specific prompt not found. Cannot update default prompts.';
                return $retArr;
            }

            $promptId = $userPrompt['BID'];

            // Update the filter setting (use DELETE→INSERT pattern for consistency)
            // SECURITY: Only delete if prompt belongs to user AND is not a default prompt
            $deleteSql = 'DELETE FROM BPROMPTMETA 
                         WHERE BPROMPTID = ' . $promptId . "
                         AND BTOKEN = 'tool_files_keyword'
                         AND BPROMPTID IN (
                             SELECT BID FROM BPROMPTS 
                             WHERE BID = " . $promptId . '
                             AND BOWNERID = ' . $userId . '
                             AND BOWNERID > 0
                         )';
            db::Query($deleteSql);

            $insertSql = 'INSERT INTO BPROMPTMETA (BPROMPTID, BTOKEN, BVALUE) 
                         VALUES (' . $promptId . ", 'tool_files_keyword', '" . db::EscString($groupKey) . "')";
            db::Query($insertSql);

            $retArr['success'] = true;
            $retArr['message'] = 'File search filter updated to: ' . $groupKey;

            return $retArr;

        } catch (\Throwable $e) {
            error_log('Update File Search Filter Error: ' . $e->getMessage());
            $retArr['error'] = 'Failed to update filter: ' . $e->getMessage();
            return $retArr;
        }
    }
    // ******************************************************************************************************
    // get the file sorting topics
    // ******************************************************************************************************
    public static function getFileSortTopics(): array
    {
        $groupKeys = [];
        $sql = 'SELECT DISTINCT BRAG.BGROUPKEY
                FROM BMESSAGES
                INNER JOIN BRAG ON BRAG.BMID = BMESSAGES.BID
                WHERE BMESSAGES.BUSERID = ' . $_SESSION['USERPROFILE']['BID'] . "
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
    public static function getShortPrompt($prompt): string
    {
        $AISUMMARIZE = $GLOBALS['AI_SUMMARIZE']['SERVICE'];
        $prompt = $AISUMMARIZE::summarizePrompt($prompt);
        return $prompt;
    }

    // ******************************************************************************************************
    // get all file groups for the current user
    // ******************************************************************************************************
    public static function getAllFileGroups(): array
    {
        $groups = [];
        $userId = $_SESSION['USERPROFILE']['BID'];

        $sql = 'SELECT DISTINCT BRAG.BGROUPKEY
                FROM BMESSAGES
                INNER JOIN BRAG ON BRAG.BMID = BMESSAGES.BID
                WHERE BMESSAGES.BUSERID = ' . intval($userId) . "
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
    public static function changeGroupOfFile($fileId, $newGroup): array
    {
        $resArr = ['success' => false, 'error' => ''];
        $userId = $_SESSION['USERPROFILE']['BID'];

        if ($GLOBALS['debug']) {
            error_log("BasicAI::changeGroupOfFile called with fileId: $fileId, newGroup: '$newGroup', userId: $userId");
        }

        // Validate file ownership
        $fileSQL = 'SELECT * FROM BMESSAGES WHERE BID = ' . intval($fileId) . ' AND BUSERID = ' . intval($userId) . ' AND BFILE > 0';
        $fileRes = db::Query($fileSQL);
        $fileArr = db::FetchArr($fileRes);

        if (!$fileArr) {
            $resArr['error'] = 'File not found or access denied';
            if ($GLOBALS['debug']) {
                error_log("BasicAI::changeGroupOfFile - File not found: $fileId for user: $userId");
            }
            return $resArr;
        }

        if ($GLOBALS['debug']) {
            error_log('BasicAI::changeGroupOfFile - File found: ' . json_encode($fileArr));
        }

        // Update existing BRAG records for this file with user ID check for extra security
        if (empty($newGroup)) {
            // Remove group (set to empty string)
            $updateSQL = "UPDATE BRAG SET BGROUPKEY = '' WHERE BMID = " . intval($fileId) . ' AND BUID = ' . intval($userId);
        } else {
            // Update group
            $updateSQL = "UPDATE BRAG SET BGROUPKEY = '" . db::EscString($newGroup) . "' WHERE BMID = " . intval($fileId) . ' AND BUID = ' . intval($userId);
        }

        if ($GLOBALS['debug']) {
            error_log("BasicAI::changeGroupOfFile - SQL: $updateSQL");
        }

        // Execute the update query
        $result = db::Query($updateSQL);

        if ($result) {
            $resArr['success'] = true;
            $resArr['message'] = 'File group updated successfully';
            if ($GLOBALS['debug']) {
                error_log('BasicAI::changeGroupOfFile - Success');
            }
        } else {
            $resArr['error'] = 'Database error occurred while updating file group';
            if ($GLOBALS['debug']) {
                error_log('BasicAI::changeGroupOfFile - Database error');
            }
        }

        return $resArr;
    }

    // ******************************************************************************************************
    // document summarization functionality
    // ******************************************************************************************************
    public static function doDocSum(): array
    {
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
            switch ($summaryLength) {
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
            if (intval($customLength) < 200) {
                $customLength = $length;
            }
            if (intval($customLength) > 2000) {
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

            $systemPrompt = 'You are a helpful assistant that summarizes documents in various languages. 
              You will be given a document text and you will need to summarize it.
              Please create a '.$summaryType.' summary with ca. '.$summaryLength." length in language: '".$language."'.
              The summary should be ".$customLength.' characters long.
              The summary should be in the following focus areas: '.implode(', ', $focusAreas).'.';

            // get the summarize service directly from global configuration (like other methods)
            $AISUMMARIZE = $GLOBALS['AI_SUMMARIZE']['SERVICE'];

            // --- execute the summarize model
            $resArr = $AISUMMARIZE::simplePrompt($systemPrompt, $documentText);

        } catch (Exception $e) {
            if ($GLOBALS['debug']) {
                error_log('BasicAI::doDocSum - Error: ' . $e->getMessage());
            }
            $resArr['error'] = 'An error occurred while processing the document: ' . $e->getMessage();
        }

        return $resArr;
    }
}
