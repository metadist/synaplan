<?php

// Legacy REST action switch moved from api.php

header('Content-Type: application/json; charset=UTF-8');
$apiAction = $_REQUEST['action'];

switch ($apiAction) {
    case 'snippetTranslate':
        $sourceText = isset($_REQUEST['source_text']) ? trim($_REQUEST['source_text']) : '';
        $sourceLang = isset($_REQUEST['source_lang']) ? trim($_REQUEST['source_lang']) : 'en';
        $destLang = isset($_REQUEST['dest_lang']) ? trim($_REQUEST['dest_lang']) : '';
        $resArr = Frontend::translateSnippet($sourceText, $sourceLang, $destLang);
        break;
    case 'messageNew':
        $resArr = Frontend::saveWebMessages();
        break;

    case 'messageAgain':
        // Determine user ID for rate limiting (Widget owner or regular user)
        $userId = 0;
        if (isset($_SESSION['is_widget']) && $_SESSION['is_widget'] === true) {
            $userId = intval($_SESSION['widget_owner_id'] ?? 0);
        } elseif (isset($_SESSION['USERPROFILE']['BID'])) {
            $userId = intval($_SESSION['USERPROFILE']['BID']);
        }

        // Smart rate limiting for Again requests - only check relevant operation limits
        // Skip rate limiting for widgets to avoid errors - they use owner limits anyway
        if (XSControl::isRateLimitingEnabled() && $userId > 0 && !isset($_SESSION['is_widget'])) {
            $inId = intval($_REQUEST['in_id'] ?? 0);

            // Get the original message to determine what operation to limit
            if ($inId > 0) {
                $originalSQL = 'SELECT BTOPIC FROM BMESSAGES WHERE BID = ' . $inId . ' LIMIT 1';
                $originalRes = db::Query($originalSQL);
                $originalArr = db::FetchArr($originalRes);

                if ($originalArr && !empty($originalArr['BTOPIC'])) {
                    $originalTopic = $originalArr['BTOPIC'];

                    // Only check limits relevant to the original operation
                    $testMsg = ['BUSERID' => $userId, 'BTOPIC' => $originalTopic];
                    $againLimitCheck = XSControl::checkTopicLimit($testMsg);

                    if ($againLimitCheck['limited']) {
                        $resArr = [
                            'error' => 'rate_limit_exceeded',
                            'message' => $againLimitCheck['reason'],
                            'reset_time' => $againLimitCheck['reset_time'] ?? 0
                        ];
                        break;
                    }
                }
            }
        }

        // Log Again request to BUSELOG using original message ID (tracks operation type)
        if ($userId > 0) {
            $inId = intval($_REQUEST['in_id'] ?? 0);
            if ($inId > 0) {
                XSControl::countThis($userId, $inId);
            }
        }

        $resArr = AgainLogic::prepareAgain($_REQUEST);
        break;
    case 'againOptions':
        $resArr = AgainLogic::againOptionsForCurrentSession();
        break;
    case 'ragUpload':
        $resArr = FileManager::saveRAGFiles();
        break;
    case 'chatStream':
        $resArr = Frontend::chatStream();
        exit;
    case 'docSum':
        $resArr = BasicAI::doDocSum();
        break;
    case 'promptLoad':
        $resArr = BasicAI::getAprompt($_REQUEST['promptKey'], $_REQUEST['lang'], [], false);
        break;
    case 'promptUpdate':
        $resArr = BasicAI::updatePrompt($_REQUEST['promptKey']);
        break;
    case 'deletePrompt':
        $resArr = BasicAI::deletePrompt($_REQUEST['promptKey']);
        break;
    case 'getPromptDetails':
        $resArr = BasicAI::getPromptDetails($_REQUEST['promptKey']);
        break;
    case 'enablePromptFileSearch':
        $promptKey = isset($_REQUEST['promptKey']) ? trim($_REQUEST['promptKey']) : '';
        $groupKey = isset($_REQUEST['groupKey']) ? trim($_REQUEST['groupKey']) : '';
        $resArr = BasicAI::enablePromptFileSearch($promptKey, $groupKey);
        break;
    case 'updatePromptFileSearchFilter':
        $promptKey = isset($_REQUEST['promptKey']) ? trim($_REQUEST['promptKey']) : '';
        $groupKey = isset($_REQUEST['groupKey']) ? trim($_REQUEST['groupKey']) : '';
        $resArr = BasicAI::updatePromptFileSearchFilter($promptKey, $groupKey);
        break;
    case 'debugPromptSettings':
        // Debug endpoint to check actual database state
        $promptKey = isset($_REQUEST['promptKey']) ? trim($_REQUEST['promptKey']) : 'general';
        $userId = $_SESSION['USERPROFILE']['BID'];

        // Get prompt
        $sql = "SELECT BID, BOWNERID, BTOPIC FROM BPROMPTS WHERE BTOPIC = '" . db::EscString($promptKey) . "' AND BOWNERID = " . $userId . ' LIMIT 1';
        $res = db::Query($sql);
        $prompt = db::FetchArr($res);

        // Get settings
        $settings = [];
        if ($prompt) {
            $sql = 'SELECT BTOKEN, BVALUE FROM BPROMPTMETA WHERE BPROMPTID = ' . $prompt['BID'] . ' ORDER BY BTOKEN';
            $res = db::Query($sql);
            while ($row = db::FetchArr($res)) {
                $settings[] = $row;
            }
        }

        $resArr = [
            'success' => true,
            'prompt' => $prompt,
            'settings' => $settings,
            'userId' => $userId
        ];
        break;
    case 'getMessageFiles':
        $messageId = intval($_REQUEST['messageId']);
        $files = FileManager::getMessageFiles($messageId);
        $resArr = ['success' => true, 'files' => $files];
        break;
    case 'getFileGroups':
        $groups = BasicAI::getAllFileGroups();
        $resArr = ['success' => true, 'groups' => $groups];
        break;
    case 'changeGroupOfFile':
        $fileId = intval($_REQUEST['fileId']);
        $newGroup = isset($_REQUEST['newGroup']) ? trim($_REQUEST['newGroup']) : '';
        if ($GLOBALS['debug']) {
            error_log("API changeGroupOfFile called with fileId: $fileId, newGroup: '$newGroup'");
        }
        $resArr = BasicAI::changeGroupOfFile($fileId, $newGroup);
        if ($GLOBALS['debug']) {
            error_log('API changeGroupOfFile result: ' . json_encode($resArr));
        }
        break;
    case 'getProfile':
        $resArr = Frontend::getProfile();
        break;
    case 'loadChatHistory':
        $amount = isset($_REQUEST['amount']) ? intval($_REQUEST['amount']) : 10;
        $resArr = Frontend::loadChatHistory($amount);
        break;
    case 'getWidgets':
        $resArr = Frontend::getWidgets();
        break;
    case 'saveWidget':
        $resArr = Frontend::saveWidget();
        break;
    case 'deleteWidget':
        $resArr = Frontend::deleteWidget();
        break;
    case 'getApiKeys':
        $resArr = ApiKeyManager::getApiKeys();
        break;
    case 'createApiKey':
        $resArr = ApiKeyManager::createApiKey();
        break;
    case 'setApiKeyStatus':
        $resArr = ApiKeyManager::setApiKeyStatus();
        break;
    case 'deleteApiKey':
        $resArr = ApiKeyManager::deleteApiKey();
        break;
    case 'userRegister':
        $resArr = UserRegistration::registerNewUser();
        break;
    case 'wpWizardComplete':
        // Complete WordPress wizard setup with verification, user creation, API key, files, prompt, and widget
        // LEGACY: kept for backward compatibility
        $resArr = WordPressWizard::completeWizardSetup();
        break;
    // WordPress Wizard - Step-by-step API (recommended for WordPress HTTP API compatibility)
    case 'wpStep1VerifyAndCreateUser':
        // STEP 1: Verify WordPress site and create user
        $resArr = WordPressWizard::wpStep1VerifyAndCreateUser();
        break;
    case 'wpStep2CreateApiKey':
        // STEP 2: Create API key for user
        $resArr = WordPressWizard::wpStep2CreateApiKey();
        break;
    case 'wpStep3UploadFile':
        // STEP 3: Upload and process single RAG file
        $resArr = WordPressWizard::wpStep3UploadFile();
        break;
    case 'wpStep4EnableFileSearch':
        // STEP 4: Enable file search on general prompt
        $resArr = WordPressWizard::wpStep4EnableFileSearch();
        break;
    case 'wpStep5SaveWidget':
        // STEP 5: Save widget configuration
        $resArr = WordPressWizard::wpStep5SaveWidget();
        break;
    case 'lostPassword':
        $resArr = UserRegistration::lostPassword();
        break;
    case 'getMailhandler':
        $resArr = Frontend::getMailhandler();
        break;
    case 'saveMailhandler':
        $resArr = Frontend::saveMailhandler();
        break;
    case 'mailOAuthStart':
        $resArr = Frontend::mailOAuthStart();
        break;
    case 'mailOAuthCallback':
        $result = Frontend::mailOAuthCallback();
        if (!isset($_REQUEST['ui']) || $_REQUEST['ui'] !== 'json') {
            $target = $GLOBALS['baseUrl'] . 'index.php/mailhandler';
            if (!empty($result['success'])) {
                $target .= '?oauth=ok';
            } else {
                $target .= '?oauth=error';
            }
            header('Content-Type: text/html; charset=UTF-8');
            header('Location: ' . $target);
            echo '<html><head><meta http-equiv="refresh" content="0;url='.$target.'"></head><body>Redirecting...</body></html>';
            exit;
        }
        $resArr = $result;
        break;
    case 'mailOAuthStatus':
        $resArr = Frontend::mailOAuthStatus();
        break;
    case 'mailOAuthDisconnect':
        $resArr = Frontend::mailOAuthDisconnect();
        break;
    case 'mailTestConnection':
        $resArr = Frontend::mailTestConnection();
        break;
    case 'getChatHistoryLog':
        $page = isset($_REQUEST['page']) ? intval($_REQUEST['page']) : 1;
        $filters = [
            'keyword' => isset($_REQUEST['keyword']) ? trim($_REQUEST['keyword']) : '',
            'hasAttachments' => isset($_REQUEST['hasAttachments']) ? $_REQUEST['hasAttachments'] : '',
            'dateFrom' => isset($_REQUEST['dateFrom']) ? $_REQUEST['dateFrom'] : '',
            'dateTo' => isset($_REQUEST['dateTo']) ? $_REQUEST['dateTo'] : ''
        ];
        $userId = isset($_SESSION['USERPROFILE']['BID']) ? $_SESSION['USERPROFILE']['BID'] : 0;
        $resArr = MessageHistory::getUserPrompts($userId, $page, 15, $filters);
        break;
    case 'getAnswersForPrompt':
        $promptId = isset($_REQUEST['promptId']) ? intval($_REQUEST['promptId']) : 0;
        $userId = isset($_SESSION['USERPROFILE']['BID']) ? $_SESSION['USERPROFILE']['BID'] : 0;
        $resArr = MessageHistory::getAnswersForPrompt($userId, $promptId);
        break;
    case 'getAnswerDetails':
        $answerId = isset($_REQUEST['answerId']) ? intval($_REQUEST['answerId']) : 0;
        $userId = isset($_SESSION['USERPROFILE']['BID']) ? $_SESSION['USERPROFILE']['BID'] : 0;
        $resArr = MessageHistory::getAnswerDetails($userId, $answerId);
        break;
    case 'getUserStats':
        $userId = isset($_SESSION['USERPROFILE']['BID']) ? $_SESSION['USERPROFILE']['BID'] : 0;
        $resArr = MessageHistory::getUserStats($userId);
        break;
    case 'wpWizardComplete':
        $resArr = WordPressWizard::completeWizardSetup();
        break;
    case 'checkGmailKeyword':
        // Check if a Gmail keyword is available for use
        $keyword = isset($_REQUEST['keyword']) ? trim($_REQUEST['keyword']) : '';
        $resArr = empty($keyword)
            ? ['available' => false, 'message' => 'Please provide a keyword', 'type' => 'error']
            : InboundConf::testKeywordAvailability($keyword);
        break;
    case 'debugGmailKeywords':
        // Debug endpoint to see all GMAILKEY entries in BCONFIG
        $resArr = InboundConf::getAllGmailKeywords();
        break;
    default:
        $resArr = ['error' => 'Invalid action'];
        break;
}

echo json_encode($resArr);
exit;
