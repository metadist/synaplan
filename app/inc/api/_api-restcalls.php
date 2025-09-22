<?php
// Legacy REST action switch moved from api.php

header('Content-Type: application/json; charset=UTF-8');
$apiAction = $_REQUEST['action'];

switch($apiAction) {
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
        if($GLOBALS["debug"]) error_log("API changeGroupOfFile called with fileId: $fileId, newGroup: '$newGroup'");
        $resArr = BasicAI::changeGroupOfFile($fileId, $newGroup);
        if($GLOBALS["debug"]) error_log("API changeGroupOfFile result: " . json_encode($resArr));
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
            if (!empty($result['success'])) { $target .= '?oauth=ok'; }
            else { $target .= '?oauth=error'; }
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
    default:
        $resArr = ['error' => 'Invalid action'];
        break;
}

echo json_encode($resArr);
exit;

