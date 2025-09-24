<?php
/**
 * Process Methods Class
 * 
 * Contains methods for processing messages step by step. The aiprocessor.php calls these methods
 * with the message array and other parameters to handle message processing.
 * 
 * @package ProcessMethods
 */

class ProcessMethods {
    /** @var array Message array containing all message data */
    public static $msgArr;
    
    /** @var int Message ID */
    public static $msgId;
    
    /** @var array User array containing user data */
    public static $usrArr;
    
    /** @var array Thread array containing conversation thread */
    public static $threadArr;
    
    /** @var string Answer method type */
    public static $answerMethod;
    
    /** @var array Sorting array for message processing */
    public static $sortArr;
    
    /** @var array Tool answer array */
    public static $toolAnswer;

    /** @var bool Stream flag */
    public static $stream;

    /** @var bool Tool processed flag */
    public static $toolProcessed;

    /** @var array AIdetailArr */
    public static $AIdetailArr = [];
    
    /**
     * Map BTOPIC to operation type for BUSELOG tracking using BCAPABILITIES
     */
    private static function getOperationTypeFromTopic($topic): string {
        try {
            // Check if topic exists in BCAPABILITIES table
            $sql = "SELECT BKEY FROM BCAPABILITIES WHERE BKEY = '".DB::EscString($topic)."'";
            $result = db::Query($sql);
            $row = db::FetchArr($result);
            
            if ($row) {
                // Direct mapping for known capability types
                return $row['BKEY'];
            }
            
            // Handle special cases
            switch ($topic) {
                case 'mediamaker':
                    // mediamaker needs special handling - will be updated by mediamaker logic
                    return 'general'; // Default, will be updated later in mediamaker
                case 'analyzefile':
                case 'analyzepdf': // Legacy support
                    return 'analyzefile';
                default:
                    return 'general';
            }
        } catch (Exception $e) {
            error_log("Failed to get operation type from topic: " . $e->getMessage());
            return 'general'; // Fallback
        }
    }

    /**
     * Initialize the message processing
     * 
     * Sets up the message array and related data structures for processing
     * 
     * @param int $msgId The ID of the message to process
     * @param bool $stream Stream flag
     * @return void
     */
    public static function init($msgId, $stream = false) {
        self::$msgId = $msgId;
        self::$msgArr = Central::getMsgById($msgId);
        
        //error_log('msgArr: '.json_encode(self::$msgArr));

        self::$usrArr = Central::getUsrById(self::$msgArr['BUSERID']);
        self::$usrArr["DETAILS"] = json_decode(self::$usrArr["BUSERDETAILS"], true);
        
        self::$threadArr = Central::getThread(self::$msgArr, 1200);
        self::$answerMethod = self::$msgArr['BMESSTYPE'];

        self::$stream = $stream;
        self::$toolProcessed = false;
    }

    /**
     * Handle file translation
     * 
     * Translates file content to English if it's not already in English
     * 
     * @return void
     */
    public static function fileTranslation(): void {
        if(self::$msgArr['BFILE']>0 AND strlen(self::$msgArr['BFILETEXT'])>5 AND self::$msgArr['BLANG'] != 'en' 
            AND strlen(self::$msgArr['BFILETYPE'])>2 AND strlen(self::$msgArr['BFILETYPE'])<5) {
                $translatedText = AIGroq::translateTo(self::$msgArr, self::$msgArr['BLANG'], 'BFILETEXT');
                XSControl::storeAIDetails(self::$msgArr, 'AISERVICE', 'AIGroq', self::$stream);
                XSControl::storeAIDetails(self::$msgArr, 'AIMODEL', 'Translate', self::$stream);
                XSControl::storeAIDetails(self::$msgArr, 'AIMODELID', '0', self::$stream);

                self::$msgArr['BFILETEXT'] = $translatedText['BFILETEXT']."\n\n";
                self::$msgArr['BFILETEXT'] .= "Translated from this:\n".self::$msgArr['BFILETEXT'];
                $langSQL = "UPDATE BMESSAGES SET BFILETEXT = '".DB::EscString(self::$msgArr['BFILETEXT'])."' WHERE BID = ".self::$msgArr['BID'];
                DB::Query($langSQL);

                if(self::$stream) {
                    Frontend::statusToStream(self::$msgId, 'pre', 'File description translated. ');
                }
                
                // Update sorting array with file information
                self::$sortArr['BFILE'] = self::$msgArr['BFILE'];
                self::$sortArr['BFILETEXT'] = self::$msgArr['BFILETEXT'];
                self::$sortArr['BFILETYPE'] = self::$msgArr['BFILETYPE'];
                self::$sortArr['BFILEPATH'] = self::$msgArr['BFILEPATH'];
            }
    }

    /**
     * Sort and process the message
     * 
     * Determines the appropriate topic and processes the message accordingly
     * 
     * @return void
     */
    public static function sortMessage(): void {
        // -----------------------------------------------------
        // -----------------------------------------------------
        // Detect tool commands using safer method
        $isToolCommand = isset(self::$msgArr['BTEXT'][0]) && self::$msgArr['BTEXT'][0] === '/';
        
        if(!$isToolCommand) {
            // -----------------------------------------------------
            // it is NOT a tool request
            // -----------------------------------------------------
            // -----------------------------------------------------
            // Check for a set prompt ID, if it is set, use it to override the topic
            // -----------------------------------------------------
            $promptId = 'tools:sort';
            $metaSQL = "select BVALUE from BMESSAGEMETA where BMESSID = ".self::$msgArr['BID']." and BTOKEN = 'PROMPTID'";
            $metaRes = DB::Query($metaSQL);
            $metaArr = DB::FetchArr($metaRes);

            // ----------------------------------------------------- override the topic with the selected prompt
            if($metaArr && is_array($metaArr) && isset($metaArr['BVALUE']) AND strlen($metaArr['BVALUE'])>1 AND $metaArr['BVALUE'] != 'tools:sort') {
                $promptId = $metaArr['BVALUE'];
                // Override the topic with the selected prompt
                self::$msgArr['BTOPIC'] = $promptId;
                // Update the message in database to reflect the prompt-based topic
                $updateSQL = "update BMESSAGES set BTOPIC = '".DB::EscString($promptId)."' where BID = ".intval(self::$msgArr['BID']);
                DB::Query($updateSQL);
                // target prompt set previously
                if(self::$stream) {
                    Frontend::statusToStream(self::$msgId, 'pre', 'Target set: '.self::$msgArr['BTOPIC'].' ');
                }

            }

            // For anonymous widget sessions without explicit per-message prompt: enforce widget-selected prompt
            if ($promptId == 'tools:sort' && isset($_SESSION["is_widget"]) && $_SESSION["is_widget"] === true) {
                $widgetPrompt = $_SESSION['WIDGET_PROMPT'] ?? '';
                if (is_string($widgetPrompt) && strlen(trim($widgetPrompt)) > 0) {
                    $promptId = $widgetPrompt;
                    self::$msgArr['BTOPIC'] = $promptId;
                    $updateSQL = "update BMESSAGES set BTOPIC = '".DB::EscString($promptId)."' where BID = ".intval(self::$msgArr['BID']);
                    DB::Query($updateSQL);
                    if(self::$stream) {
                        Frontend::statusToStream(self::$msgId, 'pre', 'Target set: '.self::$msgArr['BTOPIC'].' ');
                    }
                }
            }

            // ----------------------------------------------------- Standard sorting
            // -----------------------------------------------------
            if($promptId == 'tools:sort') {
                $AIGENERAL = $GLOBALS["AI_SORT"]["SERVICE"];
                $AIGENERALmodel = $GLOBALS["AI_SORT"]["MODEL"];
                $AIGENERALmodelId = $GLOBALS["AI_SORT"]["MODELID"];

                // -----------------------------------------------------
                /*
                {
                    "BDATETIME": "20250314182858",
                    "BFILEPATH": "123/4321/soundfile.mp3",
                    "BTOPIC": "",
                    "BLANG": "en",
                    "BTEXT": "Please help me to translate this message to Spanish.",
                    "BFILETEXT": "Hello, this text was extracted from the sound file."
                }
                */
                $sortingArr = [];
                $sortingArr["BDATETIME"]=self::$msgArr['BDATETIME'] ?? date("YmdHis");
                $sortingArr["BFILEPATH"]=self::$msgArr['BFILEPATH'];
                $sortingArr["BTOPIC"]=self::$msgArr['BTOPIC'];
                $sortingArr["BLANG"]=self::$msgArr['BLANG'];
                $sortingArr["BTEXT"]=self::$msgArr['BTEXT'];
                $sortingArr["BFILETEXT"]=self::$msgArr['BFILETEXT'];

                try {
                    if (!empty($GLOBALS['debug'])) {
                        error_log("sortMessage: calling sorter service {$AIGENERAL} model {$AIGENERALmodel} (id {$AIGENERALmodelId})");
                    }
                    $answerJson = $AIGENERAL::sortingPrompt($sortingArr, self::$threadArr);
                } catch (Exception $err) {
                    if($GLOBALS["debug"]) error_log($err->getMessage());
                    $answerJson = '{}';
                    if(self::$stream) {
                        Frontend::statusToStream(self::$msgId, 'pre', 'Sorting unavailable. Continuing with defaults. ');
                    }
                }

                $answerJsonArr = json_decode($answerJson, true);
                if(!empty($GLOBALS['debug'])) {
                    $rawLen = is_string($answerJson) ? strlen($answerJson) : 0;
                    error_log("sortMessage: sorter raw length={$rawLen}");
                    if (is_array($answerJsonArr)) {
                        $dbgTopic = $answerJsonArr['BTOPIC'] ?? '';
                        $dbgLang  = $answerJsonArr['BLANG'] ?? '';
                        $dbgTextL = isset($answerJsonArr['BTEXT']) ? strlen((string)$answerJsonArr['BTEXT']) : 0;
                        error_log("sortMessage: decoded topic='{$dbgTopic}' lang='{$dbgLang}' btext.len={$dbgTextL}");
                        if (self::$stream) {
                            Frontend::statusToStream(self::$msgId, 'pre', '[DBG] Sorter → ' . ($dbgTopic ?: 'n/a') . ' / ' . ($dbgLang ?: 'n/a') . ' ');
                        }
                    } else {
                        error_log("sortMessage: sorter returned non-JSON; using fallbacks");
                    }
                }

                // Unified fallback if sorting failed or returned invalid structure
                $fallbackUsed = false;
                if(!is_array($answerJsonArr)) {
                    $fallbackUsed = true;
                    if($GLOBALS["debug"]) error_log("Sorting failed or returned invalid JSON: ".$answerJson);
                    $answerJsonArr = [];
                }

                // Preserve original BTEXT if not provided by sorter
                if(!isset($answerJsonArr['BTEXT'])) {
                    $answerJsonArr['BTEXT'] = $sortingArr["BTEXT"];
                }

                // Ensure topic and language defaults
                if(!isset($answerJsonArr['BTOPIC']) || !is_string($answerJsonArr['BTOPIC']) || strlen(trim($answerJsonArr['BTOPIC'])) === 0) {
                    $answerJsonArr['BTOPIC'] = 'general';
                    $fallbackUsed = true;
                }
                if(!isset($answerJsonArr['BLANG']) || !is_string($answerJsonArr['BLANG']) || strlen(trim($answerJsonArr['BLANG'])) === 0) {
                    $answerJsonArr['BLANG'] = 'en';
                    $fallbackUsed = true;
                }

                // Apply resolved values
                self::$msgArr['BTEXT']  = $answerJsonArr['BTEXT'];
                self::$msgArr['BTOPIC'] = $answerJsonArr['BTOPIC'];
                self::$msgArr['BLANG']  = $answerJsonArr['BLANG'];

                // Notify via stream if we had to fallback
                if ($fallbackUsed && self::$stream) {
                    Frontend::statusToStream(self::$msgId, 'pre', 'Sorting unavailable. Defaulted to general (en). ');
                }

                if(self::$stream) {
                    Frontend::statusToStream(self::$msgId, 'pre', 'Topic and language determined: '.self::$msgArr['BTOPIC'].' ('.self::$msgArr['BLANG'].'). ');
                }
                
                // BUSELOG logging will happen after rate limit checks
                
                // Convert tools:xyz to /xyz format and set processed flag
                // error_log('BTOPIC: '.self::$msgArr['BTOPIC']);
                if(substr(self::$msgArr['BTOPIC'], 0, 6) == 'tools:') {
                    self::$toolProcessed = true;
                    $toolCmd = str_replace('tools:', '/', self::$msgArr['BTOPIC']);
                    if(isset(self::$msgArr['BTEXT']) && strlen(trim(self::$msgArr['BTEXT'])) > 0) {
                        self::$msgArr['BTEXT'] = $toolCmd.' '.self::$msgArr['BTEXT'];
                    } else {
                        self::$msgArr['BTEXT'] = $toolCmd;
                    }
                    
                    // Stream once and return to prevent double processing
                    if(self::$stream) {
                        Frontend::statusToStream(self::$msgId, 'pre', 'Tool target converted: ' . self::$msgArr['BTOPIC'] . '. ');
                        if (!empty($GLOBALS['debug'])) {
                            Frontend::statusToStream(self::$msgId, 'pre', '[DBG] Converted to tool cmd ' . $toolCmd . ' ');
                        }
                    }
                }
                // count bytes
                XSControl::countBytes(self::$msgArr, 'SORT', self::$stream);
                XSControl::storeAIDetails(self::$msgArr, 'AISERVICE', $AIGENERAL, self::$stream);
                XSControl::storeAIDetails(self::$msgArr, 'AIMODEL', $AIGENERALmodel, self::$stream);
                XSControl::storeAIDetails(self::$msgArr, 'AIMODELID', $AIGENERALmodelId, self::$stream);
            }
        }

        // -----------------------------------------------------
        // ----------------------------------------------------- has it added a tool?
        if(substr(self::$msgArr['BTEXT'], 0, 1) == '/') {
            // -----------------------------------------------------
            // it is a tool request
            // -----------------------------------------------------
            if(self::$stream) {
                Frontend::statusToStream(self::$msgId, 'pre', 'Tool requested. ');
            }       

            // ************************* RATE LIMIT CHECK FOR TOOLS *************
            if (XSControl::isRateLimitingEnabled()) {
                $toolCmd = trim(self::$msgArr['BTEXT']);
                $operation = null;
                
                // Map tool commands to operations
                if (strpos($toolCmd, '/pic ') === 0) {
                    $operation = 'text2pic';
                } elseif (strpos($toolCmd, '/vid ') === 0) {
                    $operation = 'text2vid';
                } elseif (strpos($toolCmd, '/audio ') === 0) {
                    $operation = 'text2sound';
                }
                
                // Check tool-specific limits
                if ($operation) {
                    $toolLimitCheck = XSControl::checkOperationLimit(self::$msgArr, $operation);
                    if ($toolLimitCheck['limited']) {
                        // Create rate limit response
                        self::$msgArr['BTEXT'] = 'RATE_LIMIT_NOTIFICATION: ' . json_encode([
                            'error' => 'rate_limit_exceeded',
                            'message' => $toolLimitCheck['reason'],
                            'reset_time' => $toolLimitCheck['reset_time']
                        ]);
                        self::$msgArr['BDIRECT'] = 'OUT';
                        
                        if (self::$stream) {
                            Frontend::statusToStream(self::$msgId, 'ai', self::$msgArr['BTEXT']);
                        }
                        return; // Stop tool processing
                    }
                }
            }
            
            // ************************* CALL THE TOOL *************
            self::$toolAnswer = BasicAI::toolPrompt(self::$msgArr, self::$stream);

            // Normalize tool result text for OUT: prefer OUTTEXT -> CAPTION -> TEXT -> BTEXT
            if (is_array(self::$toolAnswer)) {
                $toolText = self::$toolAnswer['OUTTEXT'] ?? self::$toolAnswer['CAPTION'] ?? self::$toolAnswer['TEXT'] ?? self::$toolAnswer['BTEXT'] ?? '';
                self::$toolAnswer['BTEXT'] = $toolText;
            } elseif (is_string(self::$toolAnswer)) {
                self::$toolAnswer = [
                    'BTEXT'  => self::$toolAnswer,
                    'BTOPIC' => self::$msgArr['BTOPIC'] ?? 'general',
                    'BLANG'  => self::$msgArr['BLANG'] ?? 'en'
                ];
            }
            
            // Set tool processed flag unconditionally after tool execution
            self::$toolProcessed = true;
            
            // Preserve essential fields from original message when merging tool answer
            /*
            $originalTopic = self::$msgArr['BTOPIC'] ?? 'general';
            $originalId = self::$msgArr['BID'] ?? null;
            $originalUserId = self::$msgArr['BUSERID'] ?? null;
            $originalTrackId = self::$msgArr['BTRACKID'] ?? null;
            $originalLang = self::$msgArr['BLANG'] ?? 'en';
            $originalMethod = self::$msgArr['BMESSTYPE'] ?? '';
            $originalDirect = self::$msgArr['BDIRECT'] ?? 'IN';
            */

            self::$msgArr = self::preserveEssentialFields(self::$toolAnswer);
            
            // Restore essential fields if they're missing in the tool answer            
            // For tool responses, ensure BTOPIC is not a tools: prefixed value to prevent duplication
            if (substr(self::$msgArr['BTOPIC'], 0, 1) == '/') {
                // Mark that a tool was processed
                self::$toolProcessed = true;
                self::$msgArr['BTOPIC'] = substr(self::$msgArr['BTOPIC'], 1);
            }
            
            //error_log('msgArr: '. print_r(self::$msgArr, true));
            $posSpace = strpos(self::$msgArr['BTEXT'], ' ');
            self::$AIdetailArr['TARGET'] = ($posSpace === false) ? self::$msgArr['BTEXT'] : substr(self::$msgArr['BTEXT'], 0, $posSpace);
            XSControl::storeAIDetails(self::$msgArr, 'AITOOL', self::$AIdetailArr['TARGET'], self::$stream);
            // Centralized processing and streaming
            self::processMessage();
            return;
        }
        // -----------------------------------------------------
        // ----------------------------------------------------- maybe process it
        if(substr(self::$msgArr['BTEXT'], 0, 1) != '/') {
            self::processMessage();
        }
        return;
    }

   /**
 * Process the message based on topic and user intent
 *
 * @return void
 */
// ******************************************************************************************************
public static function processMessage(): void {
    $answerJsonArr = [];
    $answerSorted  = [];
    $ragArr        = [];

        // Topic-based Rate Limiting AFTER sorting (only for non-mediamaker topics)
        if (XSControl::isRateLimitingEnabled() && !empty(self::$msgArr['BTOPIC']) && 
            !in_array(self::$msgArr['BTOPIC'], ['mediamaker'], true)) {
            
            $topicLimitCheck = XSControl::checkTopicLimit(self::$msgArr);
            if ($topicLimitCheck['limited']) {
                // Create rate limit response that frontend will handle elegantly
                self::$msgArr['BTEXT'] = 'RATE_LIMIT_NOTIFICATION: ' . json_encode([
                    'error' => 'rate_limit_exceeded',
                    'message' => $topicLimitCheck['reason'],
                    'reset_time' => $topicLimitCheck['reset_time']
                ]);
                self::$msgArr['BDIRECT'] = 'OUT';
                
                if (self::$stream) {
                    Frontend::statusToStream(self::$msgId, 'ai', self::$msgArr['BTEXT']);
                }
                return; // Stop processing - elegant notification will be shown
            }
        }

        // Temporarily switch prompt/model resolution context to widget owner during processing
        $___savedUserProfile = null;
        $___usingOwnerCtx = false;
    if (isset($_SESSION["is_widget"]) && $_SESSION["is_widget"] === true) {
        $ownerId = intval($_SESSION["widget_owner_id"] ?? 0);
        if ($ownerId > 0) {
            $ownerArr = Central::getUsrById($ownerId);
            if ($ownerArr && is_array($ownerArr)) {
                $ownerArr["DETAILS"] = json_decode($ownerArr["BUSERDETAILS"], true);
                $___savedUserProfile = $_SESSION['USERPROFILE'] ?? null;
                $_SESSION['USERPROFILE'] = $ownerArr;
                $___usingOwnerCtx = true;
            }
        }
    }

    // --- File-Infos vorbereiten (nur Kontext, kein Stream) ---
    if (isset(self::$msgArr['BFILE']) && self::$msgArr['BFILE'] > 0) {
        if (self::$msgArr['BFILE'] == 2) {
            $storedInArr = self::$msgArr; // optional
        }
        $answerJsonArr['BFILE']      = 1;
        $answerJsonArr['BFILETEXT']  = self::$msgArr['BFILETEXT'];
        $answerJsonArr['BUNIXTIMES'] = self::$msgArr['BUNIXTIMES'];
    }

    // --- Forced Model für Again, sonst Defaults ---
    if (!empty($GLOBALS["IS_AGAIN"]) && !empty($GLOBALS["FORCED_AI_SERVICE"])) {
        $AIGENERAL      = $GLOBALS["FORCED_AI_SERVICE"];
        $AIGENERALmodel = $GLOBALS["FORCED_AI_MODEL"];
        $AIGENERALmodelId = $GLOBALS["FORCED_AI_MODELID"];

        // in globale Defaults spiegeln
        $GLOBALS["AI_CHAT"]["MODEL"]   = $AIGENERALmodel;
        $GLOBALS["AI_CHAT"]["MODELID"] = $AIGENERALmodelId;
        $GLOBALS["AI_CHAT"]["SERVICE"] = $AIGENERAL;
    } else {
        $AIGENERAL        = $GLOBALS["AI_CHAT"]["SERVICE"];
        $AIGENERALmodel   = $GLOBALS["AI_CHAT"]["MODEL"];
        $AIGENERALmodelId = $GLOBALS["AI_CHAT"]["MODELID"];
    }

    // --- Topic-Fallback ---
    if (empty(self::$msgArr['BTOPIC'])) {
        self::$msgArr['BTOPIC'] = 'general';
    }

    // --- Wenn bereits Tool verarbeitet wurde: Antwort übernehmen, später zentral streamen ---
    if (self::$toolProcessed) {
        $answerSorted = self::$msgArr; // fallthrough to centralized merge/stream
    } else {

    // --- Prompt-Settings einlesen ---
    $promptDetails  = BasicAI::getPromptDetails(self::$msgArr['BTOPIC']);
    $promptSettings = [];

    if (isset($promptDetails['SETTINGS']) && is_array($promptDetails['SETTINGS'])) {
        foreach ($promptDetails['SETTINGS'] as $setting) {
            if (!is_array($setting) || !isset($setting['BTOKEN'], $setting['BVALUE'])) continue;
            $promptSettings[$setting['BTOKEN']] = $setting['BVALUE'];

            // Internet-Tool vor Prompt
            if ($setting['BTOKEN'] === 'tool_internet' && $setting['BVALUE'] == '1') {
                $searchArr            = self::$msgArr;
                $searchArr['BTOPIC']  = "tools:search";
                $answerJsonArr        = $AIGENERAL::topicPrompt($searchArr, self::$threadArr);

                if (is_string($answerJsonArr)) {
                    if (self::$stream) {
                        Frontend::statusToStream(self::$msgId, 'pre', 'Internet search skipped due to error. ');
                    }
                } elseif (!empty($answerJsonArr['SEARCH_TERM'])) {
                    $searchArr = Tools::searchWeb($searchArr, $answerJsonArr['SEARCH_TERM']);
                    if (isset($searchArr['BTEXT'])) {
                        // Kontext anreichern ist ok – hat nichts mit AGAIN-OUT zu tun
                        self::$msgArr['BTEXT'] .= "\n\n\n---\n\n\n" . $searchArr['BTEXT'];
                    }
                    if (self::$stream) {
                        Frontend::statusToStream(self::$msgId, 'pre', 'Web search ' . $answerJsonArr['SEARCH_TERM'] . '. ');
                    }
                }
            }

            // Files (RAG) vor Prompt
            if ($setting['BTOKEN'] === 'tool_files' && $setting['BVALUE'] == '1') {
                $searchArr   = self::$msgArr;
                $ragArr      = Tools::searchRAG($searchArr);
                if (self::$stream) {
                    Frontend::statusToStream(self::$msgId, 'pre', 'RAG Search: ' . count($ragArr) . ' files... ');
                }
                self::$threadArr = array_merge(self::$threadArr, $ragArr);
            }

            // aiModel-Override pro Prompt
            if ($setting['BTOKEN'] === 'aiModel' && intval($setting['BVALUE']) > 0) {
                $modelDetails = BasicAI::getModelDetails(intval($setting['BVALUE']));
                if (!empty($modelDetails) && is_array($modelDetails)) {
                    // Align provider to model's service
                    if (!empty($modelDetails['BSERVICE'])) {
                        $AIGENERAL = "AI" . $modelDetails['BSERVICE'];
                    }
                    // Set model name/id from BMODELS (use BPROVID first, fallback to BNAME)
                    if (!empty($modelDetails['BPROVID']) || !empty($modelDetails['BNAME'])) {
                        $AIGENERALmodel   = !empty($modelDetails['BPROVID']) ? $modelDetails['BPROVID'] : $modelDetails['BNAME'];
                        $AIGENERALmodelId = intval($modelDetails['BID'] ?? 0);
                        // Mirror into globals so providers use the correct model
                        $GLOBALS["AI_CHAT"]["MODEL"]   = $AIGENERALmodel;
                        $GLOBALS["AI_CHAT"]["MODELID"] = $AIGENERALmodelId;
                        // Service global wird weiter unten ggf. nochmal normalisiert
                    }
                }
            }
        }
    }

    // --- Hauptaufruf (ausgenommen Spezial-Themen) ---
    $defaultPromptArr = ['analyzefile', 'mediamaker'];
    $previousCall     = false;

    if (!in_array(self::$msgArr['BTOPIC'], $defaultPromptArr, true)) {
        if (self::$stream) {
            Frontend::statusToStream(self::$msgId, 'pre', 'Calling standard ' . $AIGENERAL . '. ');
        }

        $answerSorted = $AIGENERAL::topicPrompt(self::$msgArr, self::$threadArr, self::$stream);

        if (is_string($answerSorted)) {
            $answerSorted = [
                'BTEXT'  => $answerSorted,
                'BTOPIC' => self::$msgArr['BTOPIC'],
                'BLANG'  => self::$msgArr['BLANG']
            ];
        }

        // Model-/Service-Details protokollieren (falls mitgeliefert)
        $usedModel   = $GLOBALS["AI_CHAT"]["MODEL"];
        $usedService = $AIGENERAL;
        if (is_array($answerSorted)) {
            if (isset($answerSorted['_USED_MODEL'])) { $usedModel   = $answerSorted['_USED_MODEL'];   unset($answerSorted['_USED_MODEL']); }
            if (isset($answerSorted['_AI_SERVICE'])) { $usedService = $answerSorted['_AI_SERVICE'];   unset($answerSorted['_AI_SERVICE']); }
        }
        XSControl::storeAIDetails(self::$msgArr, 'AISERVICE', $usedService, self::$stream);
        XSControl::storeAIDetails(self::$msgArr, 'AIMODEL',  $usedModel,   self::$stream);

        $previousCall = true;
    } else {
        if (self::$stream) {
            Frontend::statusToStream(self::$msgId, 'pre', 'Calling extra ' . self::$msgArr['BTOPIC'] . '. ');
        }
    }

    // --- Spezial: mediamaker (ohne IN-Text-Pre/Append – nur OUT-Payload) ---
    if(self::$msgArr['BTOPIC'] === 'mediamaker') {
        $originalPrompt = self::$msgArr['BTEXT'];
        $providerFailed = false;
        $improvedPrompt = null;

        // Guess requested media from prompt keywords (defaults handled below)
        if (!empty($GLOBALS['debug'])) {
            error_log("mediamaker: trigger for msgId=" . self::$msgId);
            if (self::$stream) {
                Frontend::statusToStream(self::$msgId, 'pre', '[DBG] mediamaker start ');
            }
        }
        $requestedMedia = '';
        $lowerPrompt = mb_strtolower($originalPrompt);
        if (preg_match('/\\b(audio|sound|musik|sprich)\\b/u', $lowerPrompt)) {
            $requestedMedia = 'audio';
        } elseif (preg_match('/\\b(video|film)\\b/u', $lowerPrompt)) {
            $requestedMedia = 'video';
        } elseif (preg_match('/\\b(bild|image|picture|foto|photo)\\b/u', $lowerPrompt)) {
            $requestedMedia = 'image';
        }
        

        // Priority: forced tag > requested keywords > default image
        $mediaType = 'image';
        if(!empty($GLOBALS['FORCED_AI_BTAG'])) {
            $forcedBtagLocal = $GLOBALS['FORCED_AI_BTAG'];
            $mediaType = ($forcedBtagLocal === 'text2vid') ? 'video' : (($forcedBtagLocal === 'text2sound') ? 'audio' : 'image');
        } elseif (!empty($requestedMedia)) {
            $mediaType = $requestedMedia;
        }
        
        // Determine operation type
        $operationType = ($mediaType === 'audio') ? 'text2sound' : 
                        (($mediaType === 'video') ? 'text2vid' : 'text2pic');
        
        // CRITICAL: Rate limit check BEFORE updating BUSELOG operation type
        if (XSControl::isRateLimitingEnabled()) {
            $operationToCheck = $operationType;
            
            $operationCheck = XSControl::checkOperationLimit(self::$msgArr, $operationToCheck);
            if ($operationCheck['limited']) {
                // Create rate limit response that frontend will handle
                self::$msgArr['BTEXT'] = 'RATE_LIMIT_NOTIFICATION: ' . json_encode([
                    'error' => 'rate_limit_exceeded',
                    'message' => $operationCheck['reason'],
                    'reset_time' => $operationCheck['reset_time']
                ]);
                self::$msgArr['BDIRECT'] = 'OUT';
                
                if (self::$stream) {
                    Frontend::statusToStream(self::$msgId, 'ai', self::$msgArr['BTEXT']);
                }
                return; // Stop mediamaker processing BEFORE AI generation
            }
        }
        
        // Update BUSELOG operation type AFTER successful rate limit check
        XSControl::updateOperationType(self::$msgArr['BUSERID'], self::$msgArr['BID'], $operationType);
        
        if (!empty($GLOBALS['debug'])) {
            error_log("mediamaker: decided mediaType={$mediaType} forcedTag=" . ($GLOBALS['FORCED_AI_BTAG'] ?? ''));            
            if (self::$stream) {
                Frontend::statusToStream(self::$msgId, 'pre', '[DBG] media=' . $mediaType . ' ');
            }
        }

        try {
            if (!empty($GLOBALS['debug'])) {
                error_log("mediamaker: calling topicPrompt on {$AIGENERAL}");
            }
            $answerSorted = $AIGENERAL::topicPrompt(self::$msgArr, [], false);
            if($GLOBALS["debug"]) error_log('answerSorted: '. print_r($answerSorted, true));
        } catch (Exception $err) {
            $providerFailed = true;
            if (self::$stream) {
                Frontend::statusToStream(self::$msgId, 'pre', 'Mediamaker provider failed — using fallback. ');
            }
            $answerSorted = null;
        }

        // Detect provider error/result surfaced as plain string
        if (!$providerFailed && is_string($answerSorted)) {
            $lower = strtolower($answerSorted);
            if (strpos($lower, 'error') !== false || strpos($lower, 'failed') !== false || strpos($lower, 'server') !== false) {
                $providerFailed = true;
                if (self::$stream) {
                    Frontend::statusToStream(self::$msgId, 'pre', 'Mediamaker provider failed — using fallback. ');
                }
                $answerSorted = null;
            } else {
                // robuste Normalisierung (respect computed $mediaType)
                $returnedText = $answerSorted;
                $answerSorted = [
                    'BTEXT'  => $returnedText,
                    'BTOPIC' => 'mediamaker',
                    'BLANG'  => self::$msgArr['BLANG'] ?? 'en',
                    'BMEDIA' => $mediaType
                ];
                $improvedPrompt = $returnedText;
            }
        }
        // Enforce media type to avoid flips to audio unless explicitly requested/forced
        if (is_array($answerSorted)) {
            $answerSorted['BMEDIA'] = $mediaType;
        }

        // Validate; fallback if invalid or provider failed
        $isValid = is_array($answerSorted)
            && !empty($answerSorted['BMEDIA'])
            && in_array($answerSorted['BMEDIA'], ['image','video','audio'], true)
            && isset($answerSorted['BTEXT']) && $answerSorted['BTEXT'] !== '';

        if (!$isValid || $providerFailed) {
            $fallbackArr = Tools::migrateArray(self::$msgArr, [
                'BTEXT'  => $originalPrompt,
                'BTOPIC' => 'mediamaker',
                'BLANG'  => self::$msgArr['BLANG'] ?? 'en',
                'BMEDIA' => $mediaType
            ]);
            $fallbackArr['BID'] = self::$msgId;

            if ($mediaType === 'image') {
                $fallbackArr['BTEXT'] = "/pic " . $fallbackArr['BTEXT'];
            } elseif ($mediaType === 'video') {
                $fallbackArr['BTEXT'] = "/vid " . $fallbackArr['BTEXT'];
            } else { // audio
                $fallbackArr['BTEXT'] = "/audio " . $fallbackArr['BTEXT'];
            }
            
            if (!empty($GLOBALS['debug'])) {
                error_log("mediamaker: fallback toolPrompt path for mediaType={$mediaType}");
            }
            $answerSorted = BasicAI::toolPrompt($fallbackArr, self::$stream);

            if(is_string($answerSorted)) {
                $answerSorted = [
                    'BTEXT'  => $answerSorted,
                    'BTOPIC' => 'mediamaker',
                    'BLANG'  => self::$msgArr['BLANG'] ?? 'en',
                    'BMEDIA' => $mediaType
                ];
            } elseif (is_array($answerSorted)) {
                // Prefer tool-provided text; fallback to original prompt only if tool gave nothing
                $toolText = $answerSorted['OUTTEXT'] ?? $answerSorted['CAPTION'] ?? $answerSorted['TEXT'] ?? $answerSorted['BTEXT'] ?? '';
                $answerSorted['BTEXT'] = ($toolText !== '') ? $toolText : $originalPrompt;
                // Enforce previously computed media type
                $answerSorted['BMEDIA'] = $mediaType;
            }

            // Tool wurde ausgeführt – ab jetzt zentral weiter
            self::$toolProcessed = true;
        } else {
            // Normal flow: migrate and execute tool without streaming
            $answerSorted = Tools::migrateArray(self::$msgArr, $answerSorted);
            $answerSorted['BID'] = self::$msgId;
            if (isset($answerSorted['BTEXT'])) { $improvedPrompt = $answerSorted['BTEXT']; }

            if ($mediaType === 'image') {
                $answerSorted['BTEXT'] = "/pic " . $answerSorted['BTEXT'];
                $answerSorted = BasicAI::toolPrompt($answerSorted, self::$stream);
                if (is_string($answerSorted)) {
                    $answerSorted = [
                        'BTEXT'  => $answerSorted,
                        'BTOPIC' => 'mediamaker',
                        'BLANG'  => self::$msgArr['BLANG'] ?? 'en',
                        'BMEDIA' => $mediaType
                    ];
                } elseif (is_array($answerSorted)) {
                    $toolText = $answerSorted['OUTTEXT'] ?? $answerSorted['CAPTION'] ?? $answerSorted['TEXT'] ?? $answerSorted['BTEXT'] ?? '';
                    $answerSorted['BTEXT'] = ($toolText !== '') ? $toolText : ($improvedPrompt ?? $originalPrompt);
                    $answerSorted['BMEDIA'] = $mediaType;
                }
            } elseif ($mediaType === 'video') {
                $answerSorted['BTEXT'] = "/vid " . $answerSorted['BTEXT'];
                $answerSorted = BasicAI::toolPrompt($answerSorted, self::$stream);
                if (is_string($answerSorted)) {
                    $answerSorted = [
                        'BTEXT'  => $answerSorted,
                        'BTOPIC' => 'mediamaker',
                        'BLANG'  => self::$msgArr['BLANG'] ?? 'en',
                        'BMEDIA' => $mediaType
                    ];
                } elseif (is_array($answerSorted)) {
                    $toolText = $answerSorted['OUTTEXT'] ?? $answerSorted['CAPTION'] ?? $answerSorted['TEXT'] ?? $answerSorted['BTEXT'] ?? '';
                    $answerSorted['BTEXT'] = ($toolText !== '') ? $toolText : ($improvedPrompt ?? $originalPrompt);
                    $answerSorted['BMEDIA'] = $mediaType;
                }
            } else { // audio
                $answerSorted['BTEXT'] = "/audio " . $answerSorted['BTEXT'];
                $answerSorted = BasicAI::toolPrompt($answerSorted, self::$stream);
                if (is_string($answerSorted)) {
                    $answerSorted = [
                        'BTEXT'  => $answerSorted,
                        'BTOPIC' => 'mediamaker',
                        'BLANG'  => self::$msgArr['BLANG'] ?? 'en',
                        'BMEDIA' => $mediaType
                    ];
                } elseif (is_array($answerSorted)) {
                    $toolText = $answerSorted['OUTTEXT'] ?? $answerSorted['CAPTION'] ?? $answerSorted['TEXT'] ?? $answerSorted['BTEXT'] ?? '';
                    $answerSorted['BTEXT'] = ($toolText !== '') ? $toolText : ($improvedPrompt ?? $originalPrompt);
                    $answerSorted['BMEDIA'] = $mediaType;
                }
            }

            // Tool wurde ausgeführt – ab jetzt zentral weiter
            self::$toolProcessed = true;
        }
        // (answerSorted enthält bereits das finale OUT-Payload für Merge/Stream unten)
    }

    // --- Spezial: officemaker ---
    if (self::$msgArr['BTOPIC'] === 'officemaker') {
        $previousCall = true;
        $task         = $answerSorted['BMEDIA'] ?? '';
        $answerSorted = Tools::migrateArray(self::$msgArr, $answerSorted);

        if (in_array($task, ['xls','ppt','doc'], true)) {
            $result = AIOpenAI::createOfficeFile($answerSorted, self::$usrArr, self::$stream);
            $feNote = 'No file created';

            if (!empty($result['success'])) {
                $answerSorted['BFILE']     = 1;
                $answerSorted['BFILEPATH'] = $result['filePath'] ?? '';
                $answerSorted['BFILETYPE'] = $result['fileType'] ?? '';
                if (!empty($result['textContent'])) {
                    $answerSorted['BTEXT'] = Tools::processComplexHtml($result['textContent']);
                }
                if (!empty($result['filePath'])) {
                    $feNote = 'File created successfully ';
                }
            }

            if (self::$stream) {
                Frontend::statusToStream(self::$msgId, 'pre', $feNote);
            }
            XSControl::storeAIDetails(self::$msgArr, 'AISERVICE', 'AIOpenAI',       self::$stream);
            XSControl::storeAIDetails(self::$msgArr, 'AIMODEL',   'CreateOfficeFile', self::$stream);
            XSControl::storeAIDetails(self::$msgArr, 'AIMODELID', '0',              self::$stream);
        }
    }

    // --- Spezial: analyzefile (PDF -> Text) ---
    if (
        self::$msgArr['BTOPIC'] === 'analyzefile' &&
        !empty(self::$msgArr['BFILE']) && self::$msgArr['BFILE'] == 1 &&
        !empty(self::$msgArr['BFILEPATH']) && substr(self::$msgArr['BFILEPATH'], -4) === '.pdf'
        // Skip analyzefile if we already have usable OCR/Tika text from pre-processing
        && (!isset(self::$msgArr['BFILETEXT']) || strlen(trim(self::$msgArr['BFILETEXT'])) === 0)
    ) {
        $previousCall = true;
        $answerSorted = Tools::migrateArray(self::$msgArr, $answerSorted);
        $answerSorted = AIGoogle::analyzeFile($answerSorted, self::$stream);

        if (is_string($answerSorted)) {
            if ($GLOBALS["debug"]) error_log($answerSorted);
            $answerSorted = ['BTEXT' => $answerSorted];
        }

        // Als Text-Antwort normalisieren (keine Datei echoen)
        $answerSorted['BFILE']     = 0;
        $answerSorted['BFILEPATH'] = '';
        $answerSorted['BFILETYPE'] = '';
        if (!empty($answerSorted['BFILETEXT'])) {
            $answerSorted['BTEXT']     = Tools::processComplexHtml($answerSorted['BFILETEXT']);
            $answerSorted['BFILETEXT'] = '';
        }

        XSControl::storeAIDetails(self::$msgArr, 'AISERVICE', 'AIGoogle',   self::$stream);
        XSControl::storeAIDetails(self::$msgArr, 'AIMODEL',   'AnalyzeFile', self::$stream);
        XSControl::storeAIDetails(self::$msgArr, 'AIMODELID', '0',          self::$stream);
    } elseif (
        self::$msgArr['BTOPIC'] === 'analyzefile' &&
        !empty(self::$msgArr['BFILE']) && self::$msgArr['BFILE'] == 1 &&
        !empty(self::$msgArr['BFILEPATH']) && substr(self::$msgArr['BFILEPATH'], -4) === '.pdf' &&
        isset(self::$msgArr['BFILETEXT']) && strlen(trim(self::$msgArr['BFILETEXT'])) > 0
    ) {
        // Debug only: the text is already extracted
        if (self::$stream) {
            Frontend::statusToStream(self::$msgId, 'pre', 'Analyzer skipped (text already extracted). ');
        }
    }

    // --- Websearch Quick Path (BFILE==10) ---
    if (!empty($answerSorted['BFILE']) && $answerSorted['BFILE'] == 10 &&
        !empty($answerSorted['BFILETEXT']) && strlen($answerSorted['BFILETEXT']) < 64
    ) {
        // direkt ersetzen, kein Prepend
        $answerSorted        = Tools::searchWeb(self::$msgArr, $answerSorted['BFILETEXT']);
        $answerSorted['BFILE'] = 0;
    }

    // --- Fallback Hauptaufruf falls noch nichts lief (außer mediamaker) ---
    if (!$previousCall && self::$msgArr['BTOPIC'] !== 'mediamaker') {
        self::$msgArr['BTOPIC'] = 'general';
        if (self::$stream) {
            Frontend::statusToStream(self::$msgId, 'pre', 'Calling ' . $AIGENERAL . '. ');
        }
        $answerSorted = $AIGENERAL::topicPrompt(self::$msgArr, self::$threadArr, self::$stream);
        if (is_string($answerSorted)) {
            $answerSorted = [
                'BTEXT'  => $answerSorted,
                'BTOPIC' => self::$msgArr['BTOPIC'],
                'BLANG'  => self::$msgArr['BLANG']
            ];
        }
           // Ensure UI shows the actual provider/model used for the final chat call
           $usedModel   = $GLOBALS["AI_CHAT"]["MODEL"];
           $usedService = $AIGENERAL;
           if (is_array($answerSorted)) {
               if (isset($answerSorted['_USED_MODEL'])) { $usedModel = $answerSorted['_USED_MODEL']; unset($answerSorted['_USED_MODEL']); }
               if (isset($answerSorted['_AI_SERVICE'])) { $usedService = $answerSorted['_AI_SERVICE']; unset($answerSorted['_AI_SERVICE']); }
           }
           XSControl::storeAIDetails(self::$msgArr, 'AISERVICE', $usedService, self::$stream);
           XSControl::storeAIDetails(self::$msgArr, 'AIMODEL',  $usedModel,   self::$stream);
    }
    } // end else for !self::$toolProcessed

    // **************************************************************************************************
    // ----------------------------- ZENTRAL: MERGE → STREAM → (später SAVE außerhalb) -------------------
    // **************************************************************************************************
    self::$msgArr = self::preserveEssentialFields($answerSorted);

    // Zentraler Stream: immer aus self::$msgArr (Single Source of Truth)
    if (self::$stream && empty(self::$msgArr['ALREADYSHOWN'])) {
        $outText = Tools::addMediaToText(self::$msgArr);
        Frontend::statusToStream(self::$msgId, 'ai', $outText);
        self::$msgArr['ALREADYSHOWN'] = true;
    }

    // WhatsApp TTS (optional) – only for audio targets; avoid overwriting non-mp3 files
    if (
        !empty(self::$answerMethod) && self::$answerMethod == 'WA' &&
        (
            (isset(self::$msgArr['BMEDIA']) && self::$msgArr['BMEDIA'] === 'audio') ||
            (isset(self::$AIdetailArr['BTAG']) && self::$AIdetailArr['BTAG'] === 'text2sound')
        )
    ) {
        $hasNonMp3File = (
            isset(self::$msgArr['BFILE']) && self::$msgArr['BFILE'] == 1 &&
            isset(self::$msgArr['BFILETYPE']) && self::$msgArr['BFILETYPE'] !== 'mp3'
        );
        if (!$hasNonMp3File && !empty(self::$msgArr['BTEXT'])) {
            $soundArr = AIOpenAI::textToSpeech(self::$msgArr, self::$usrArr);
            if (!empty($soundArr)) {
                self::$msgArr['BFILE']     = 1;
                self::$msgArr['BFILEPATH'] = $soundArr['BFILEPATH'];
                self::$msgArr['BFILETYPE'] = $soundArr['BFILETYPE'];
            }
        }
    }

    // WhatsApp Cleanup wenn keine Datei
    if (!empty(self::$answerMethod) && self::$answerMethod == 'WA' &&
        isset(self::$msgArr['BFILE']) && self::$msgArr['BFILE'] == 0
    ) {
        self::$msgArr['BFILEPATH'] = '';
        self::$msgArr['BFILETYPE'] = '';
    }

    // Einziger Reset-Punkt für Again
    if (!empty($GLOBALS['IS_AGAIN'])) {
        $GLOBALS['IS_AGAIN'] = false;
        unset(
            $GLOBALS['FORCED_AI_MODEL'],
            $GLOBALS['FORCED_AI_MODELID'],
            $GLOBALS['FORCED_AI_SERVICE'],
            $GLOBALS['FORCED_AI_BTAG']
        );
    }

    // Restore original user session context after processing (important for anonymous widgets)
    if ($___usingOwnerCtx) {
        if ($___savedUserProfile !== null) {
            $_SESSION['USERPROFILE'] = $___savedUserProfile;
        } else {
            unset($_SESSION['USERPROFILE']);
        }
    }
    return;
}


        // **************************************************************************************************
        // **************************************************************************************************
        // ----------------------------------------------------- DONE
        // **************************************************************************************************

    
    
    
    
    /**
     * Direct chat generation bypassing sorter (for Again functionality)
     * 
     * @return void
     */
    public static function directChatGeneration(): void {
        // For Again requests, process like a normal message but skip sorter
        // Set topic to general for direct chat
        self::$msgArr['BTOPIC'] = 'general';
        
        if(self::$stream) {
            Frontend::statusToStream(self::$msgId, 'pre', 'Calling standard AI (Again). ');
        }
        
        // Use the same logic as normal processing but skip sorter
        self::processMessage();
        
        return;
    }

    /**
     * BTAG-based dispatch for Again requests
     * Calls the appropriate generator function based on BTAG
     */
    public static function dispatchByBTag(string $btag): void {
        try {
            switch ($btag) {
                case 'text2pic':
                case 'text2vid':
                case 'text2sound':
                    $GLOBALS['IS_AGAIN'] = true;
                    $GLOBALS['FORCED_AI_BTAG'] = $btag;
                    // Use mediamaker flow for media generation - skip sorter for Again requests
                    self::$msgArr['BTOPIC'] = 'mediamaker';
                    if(self::$stream) {
                        Frontend::statusToStream(self::$msgId, 'pre', "Calling $btag generator (Again). ");
                    }
                    ProcessMethods::processMessage();
                    break;
                
                case 'pic2text':
                case 'sound2text':
                    // Use analyzefile flow for analysis tasks
                    self::$msgArr['BTOPIC'] = 'analyzefile';
                    if(self::$stream) {
                        Frontend::statusToStream(self::$msgId, 'pre', "Calling $btag analyzer (Again). ");
                    }
                    ProcessMethods::processMessage();
                    break;
                
                case 'chat':
                default:
                    // Use direct chat generation for chat models
                    if(self::$stream) {
                        Frontend::statusToStream(self::$msgId, 'pre', 'Calling chat generator (Again). ');
                    }
                    ProcessMethods::processMessage();
                    break;
            }
        } catch (\Throwable $e) {
            error_log("dispatchByBTag error for BTAG '$btag': " . $e->getMessage());
            throw $e; // Re-throw to be caught by caller
        }
    }
    
    /**
     * Save the answer to the database
     * 
     * @return int The last inserted ID
     */
    public static function saveAnswerToDB() {
        // **************************************************************************************************
        // get the incoming id
        $incomingId = self::$msgArr['BID'];
        // **************************************************************************************************
        // **************************************************************************************************
        $aiAnswer = self::$msgArr;
        $aiAnswer['BUNIXTIMES'] = time();
        $aiAnswer['BDATETIME'] = date("YmdHis");
        $aiAnswer['BID'] = 'DEFAULT';
        $aiAnswer['BLANG'] = self::$msgArr['BLANG'];
        $aiAnswer['BDIRECT'] = 'OUT';
        $aiAnswer['BSTATUS'] = '';

        // Handle anonymous widget messages
        if (isset($_SESSION["is_widget"]) && $_SESSION["is_widget"] === true) {
            // Prepend "WEBWIDGET: " to BTEXT for anonymous widget messages
            if (isset($aiAnswer['BTEXT'])) {
                $aiAnswer['BTEXT'] = "WEBWIDGET: " . $aiAnswer['BTEXT'];
            }
            
            // Use widget owner ID as BUSERID
            $aiAnswer['BUSERID'] = $_SESSION["widget_owner_id"];
            
            // Create unique tracking ID for anonymous session
            if (isset($_SESSION["anonymous_session_id"])) {
                // Convert MD5 hash to numeric value for BTRACKID (bigint)
                $trackingHash = $_SESSION["anonymous_session_id"];
                $numericTrackId = crc32($trackingHash); // Convert to 32-bit integer
                $aiAnswer['BTRACKID'] = $numericTrackId;
            }
        }

        // Process complex HTML in BTEXT before saving
        /*
        if (isset($aiAnswer['BTEXT'])) {
            $aiAnswer['BTEXT'] = $aiAnswer['BTEXT'];
        }
        */

        // Define valid BMESSAGES table columns
        $validColumns = [
            'BID', 'BUSERID', 'BTRACKID', 'BPROVIDX', 'BUNIXTIMES', 
            'BDATETIME', 'BMESSTYPE', 'BFILE', 'BFILEPATH', 'BFILETYPE', 
            'BTOPIC', 'BLANG', 'BTEXT', 'BDIRECT', 'BSTATUS', 'BFILETEXT'
        ];

        // Filter $aiAnswer to only include valid table columns
        $filteredAnswer = [];
        foreach ($validColumns as $column) {
            if (isset($aiAnswer[$column])) {
                $filteredAnswer[$column] = $aiAnswer[$column];
            }
        }

        // Prepare database fields and values
        foreach($filteredAnswer as $field => $val) {
            $fields[] = $field;
            if($field == 'BID') {
                $values[] = 'DEFAULT';
            } else {
                if(is_numeric($val)) {
                    $values[] = $val;
                } else {
                    if(is_string($val)) {
                        $values[] = "'" . DB::EscString($val) . "'";
                    } else {
                        $values[] = 0;
                    }
                }
            }
        }
        
        // Insert the processed message into the database
        $newSQL = "insert into BMESSAGES (" . implode(",", $fields) . ") values (" . implode(",", $values) . ")";
        $newRes = DB::Query($newSQL);
        $aiLastId = DB::LastId();

        // **************************************************************************************************
        // count bytes
        $aiAnswer['BID'] = $aiLastId;
        XSControl::countBytes($aiAnswer, 'ALL', self::$stream);
        // **************************************************************************************************
        // **************************************************************************************************
        XSControl::storeAIDetails($aiAnswer, 'AISYSPROMPT', self::$msgArr['BTOPIC'], self::$stream);

        // For Again requests, use forced AI model information; otherwise use incoming message data
        if (isset($GLOBALS["IS_AGAIN"]) && $GLOBALS["IS_AGAIN"] === true) {
            // Use forced AI model information for Again requests
            if (isset($GLOBALS["FORCED_AI_SERVICE"])) {
                XSControl::storeAIDetails($aiAnswer, 'AISERVICE', $GLOBALS["FORCED_AI_SERVICE"], self::$stream);
            }
            if (isset($GLOBALS["FORCED_AI_MODEL"])) {
                XSControl::storeAIDetails($aiAnswer, 'AIMODEL', $GLOBALS["FORCED_AI_MODEL"], self::$stream);
            }
            if (isset($GLOBALS["FORCED_AI_MODELID"])) {
                $modelId = intval($GLOBALS["FORCED_AI_MODELID"]);
                XSControl::storeAIDetails($aiAnswer, 'AIMODELID', strval($modelId), self::$stream);
                
                if ($modelId > 0) {
                    // Update BPROVIDX with the forced model ID for OUT messages
                    $updateSQL = "UPDATE BMESSAGES SET BPROVIDX = " . $modelId . " WHERE BID = " . $aiLastId;
                    DB::Query($updateSQL);
                    
                    // Store BTAG from final model for the original IN message
                    $modelSQL = "SELECT BTAG FROM BMODELS WHERE BID = " . $modelId . " LIMIT 1";
                    $modelRes = DB::Query($modelSQL);
                    $modelRow = DB::FetchArr($modelRes);
                    if ($modelRow && is_array($modelRow) && !empty($modelRow['BTAG'])) {
                        // Store BTAG for the original IN message (overwrite any existing)
                        $inMessageForBtag = ['BID' => $incomingId];
                        XSControl::storeAIDetails($inMessageForBtag, 'BTAG', $modelRow['BTAG'], self::$stream);
                    }
                } else {
                    // If forced model ID is invalid, leave BPROVIDX empty
                    $updateSQL = "UPDATE BMESSAGES SET BPROVIDX = '' WHERE BID = " . $aiLastId;
                    DB::Query($updateSQL);
                }
            }
            // Store Again flag
            XSControl::storeAIDetails($aiAnswer, 'IS_AGAIN', 'true', self::$stream);
        } else {
            // Fetch AI service and model information from incoming message for regular requests
            $serviceSQL = "SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ".intval($incomingId)." AND BTOKEN = 'AISERVICE' ORDER BY BID DESC LIMIT 1";
            $serviceRes = DB::Query($serviceSQL);
            $serviceArr = DB::FetchArr($serviceRes);
            if($serviceArr && is_array($serviceArr)) {
                XSControl::storeAIDetails($aiAnswer, 'AISERVICE', $serviceArr['BVALUE'], self::$stream);
            }
            //
            $modelSQL = "SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ".intval($incomingId)." AND BTOKEN = 'AIMODEL' ORDER BY BID DESC LIMIT 1";
            $modelRes = DB::Query($modelSQL);
            $modelArr = DB::FetchArr($modelRes);
            if($modelArr && is_array($modelArr)) {
                XSControl::storeAIDetails($aiAnswer, 'AIMODEL', $modelArr['BVALUE'], self::$stream);
            }
            
            // Fetch and set AIMODELID to BPROVIDX for OUT messages
            $modelIdSQL = "SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ".intval($incomingId)." AND BTOKEN = 'AIMODELID' ORDER BY BID DESC LIMIT 1";
            $modelIdRes = DB::Query($modelIdSQL);
            $modelIdArr = DB::FetchArr($modelIdRes);
            if($modelIdArr && is_array($modelIdArr)) {
                $modelId = intval($modelIdArr['BVALUE']);
                if ($modelId > 0) {
                    // Update BPROVIDX with the actual model ID for OUT messages
                    $updateSQL = "UPDATE BMESSAGES SET BPROVIDX = " . $modelId . " WHERE BID = " . $aiLastId;
                    DB::Query($updateSQL);
                    
                    XSControl::storeAIDetails($aiAnswer, 'AIMODELID', $modelIdArr['BVALUE'], self::$stream);
                    
                    // Store BTAG from final model for the IN message (overwrite any existing)
                    $modelSQL = "SELECT BTAG FROM BMODELS WHERE BID = " . $modelId . " LIMIT 1";
                    $modelRes = DB::Query($modelSQL);
                    $modelRow = DB::FetchArr($modelRes);
                    if ($modelRow && is_array($modelRow) && !empty($modelRow['BTAG'])) {
                        $inMessageForBtag = ['BID' => $incomingId];
                        XSControl::storeAIDetails($inMessageForBtag, 'BTAG', $modelRow['BTAG'], self::$stream);
                    }
                } else {
                    // If AIMODELID exists but is invalid (0 or negative), leave BPROVIDX empty
                    $updateSQL = "UPDATE BMESSAGES SET BPROVIDX = '' WHERE BID = " . $aiLastId;
                    DB::Query($updateSQL);
                    
                    XSControl::storeAIDetails($aiAnswer, 'AIMODELID', $modelIdArr['BVALUE'], self::$stream);
                }
            } else {
                // If no AIMODELID found, leave BPROVIDX empty for OUT messages
                $updateSQL = "UPDATE BMESSAGES SET BPROVIDX = '' WHERE BID = " . $aiLastId;
                DB::Query($updateSQL);
            }
        }
        // **************************************************************************************************
        // **************************************************************************************************

        return $aiLastId;
    }

    /**
     * Preserve essential fields when replacing message array
     * 
     * Ensures that critical fields like BID are not lost when replacing the message array
     * 
     * @param array $newMsgArr The new message array to use
     * @return array The new message array with essential fields preserved
     */
    private static function preserveEssentialFields($newMsgArr) {
        // Essential fields that should be preserved from the original message
        $essentialFields = ['BID', 'BUSERID', 'BTRACKID', 'BMESSTYPE', 'BDIRECT'];
        
        foreach ($essentialFields as $field) {
            if (isset(self::$msgArr[$field]) && !isset($newMsgArr[$field])) {
                $newMsgArr[$field] = self::$msgArr[$field];
            }
        }
        
        return $newMsgArr;
    }
}

