<?php

// Legacy REST action switch moved from api.php

header('Content-Type: application/json; charset=UTF-8');
$apiAction = $_REQUEST['action'];

switch ($apiAction) {
    case 'userRegister':
        // WordPress plugin user registration with site verification
        $email = isset($_REQUEST['email']) ? trim($_REQUEST['email']) : '';
        $password = isset($_REQUEST['password']) ? trim($_REQUEST['password']) : '';
        $language = isset($_REQUEST['language']) ? trim($_REQUEST['language']) : 'en';
        $verification_token = isset($_REQUEST['verification_token']) ? trim($_REQUEST['verification_token']) : '';
        $verification_url = isset($_REQUEST['verification_url']) ? trim($_REQUEST['verification_url']) : '';
        $site_url = isset($_REQUEST['site_url']) ? trim($_REQUEST['site_url']) : '';

        if (empty($email) || empty($password) || empty($verification_token) || empty($verification_url)) {
            $resArr = [
                'success' => false,
                'error' => 'Missing required fields'
            ];
            break;
        }

        // Verify WordPress site by calling the verification endpoint
        $verification_data = [
            'token' => $verification_token
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $verification_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verification_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $verification_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($verification_response === false || $http_code !== 200) {
            $resArr = [
                'success' => false,
                'error' => 'WordPress site verification failed: Unable to reach verification endpoint'
            ];
            break;
        }

        $verification_data = json_decode($verification_response, true);

        if (!$verification_data || !$verification_data['success'] || !$verification_data['verified']) {
            $resArr = [
                'success' => false,
                'error' => 'WordPress site verification failed: Invalid token or site not verified'
            ];
            break;
        }

        // Create user account (simplified for now)
        $user_id = 'wp_user_' . time() . '_' . substr(md5($email), 0, 8);

        $resArr = [
            'success' => true,
            'data' => [
                'user_id' => $user_id,
                'email' => $email,
                'site_info' => $verification_data['site_info'],
                'message' => 'User registered successfully with WordPress site verification'
            ]
        ];
        break;

    case 'createApiKey':
        // Create API key for WordPress plugin
        $name = isset($_REQUEST['name']) ? trim($_REQUEST['name']) : 'WordPress Plugin';

        $api_key = 'sk_' . bin2hex(random_bytes(24));

        $resArr = [
            'success' => true,
            'data' => [
                'key' => $api_key,
                'name' => $name,
                'message' => 'API key created successfully'
            ]
        ];
        break;

    case 'saveWidget':
        // Save widget configuration
        $config = $_REQUEST;

        $widget_id = 'widget_' . time() . '_' . substr(md5(json_encode($config)), 0, 8);

        $resArr = [
            'success' => true,
            'data' => [
                'widget_id' => $widget_id,
                'config' => $config,
                'message' => 'Widget configuration saved successfully'
            ]
        ];
        break;

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
    default:
        $resArr = ['error' => 'Invalid action'];
        break;
}

echo json_encode($resArr);
exit;
