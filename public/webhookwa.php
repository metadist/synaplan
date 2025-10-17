<?php

//==================================================================================
/*
 WebHook for WhatsApp 1.0
 written by puzzler - Ralf Schwoebel, rs(at)metadist.de

 Tasks of this file:
 . include the whatsapp cloud api
 . retrieve all the messages, files, cards and possible WhatsApp messages
 . decide, if the user is new, continuing or changing the conversation
 . save the conversation in the database
 . hand over to processing
*/
//==================================================================================
// core app files with relative paths
$root = __DIR__ . '/';
// Use Composer autoload and new app core includes
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

// Initialize the API
$GLOBALS['WAtoken'] = ApiKeys::getWhatsApp();

// verification call - only needed once in a while
// Check if the request is a verification request
if (isset($_REQUEST['hub_mode']) && $_REQUEST['hub_mode'] === 'subscribe' &&
    isset($_REQUEST['hub_verify_token']) && $_REQUEST['hub_verify_token'] === $GLOBALS['WAtoken']) {

    // Respond with the hub_challenge parameter
    echo $_REQUEST['hub_challenge'];
    exit;
}

// Handle incoming messages
// Simulate receiving a webhook request
$request = json_decode(file_get_contents('php://input'), true);

if ($request) {
    // can be more than one message in the request
    foreach ($request['entry'] as $entry) {
        foreach ($entry['changes'] as $change) {
            // preformat the message
            $formattedMessages = processWAMessage($change);
            logMessage($formattedMessages);

            // handle messages
            // examples => https://deskfiler.atlassian.net/wiki/spaces/RAGroll/pages/2444820481/Messages+in+Array+Format
            foreach ($formattedMessages['messages'] as $message) {
                if (isset($message['from'])) {
                    // FORMAT the message to match the table BMESSAGES
                    // set the file details depending the path
                    $hasFile = 0;
                    $fileType = '';
                    // check for file
                    if (isset($message['content']['file_details']['file_path']) and $message['content']['file_details']['file_path'] != '') {
                        $hasFile = 1;
                        $fileType = Tools::getFileExtension($message['content']['file_details']['mime_type']);
                    }
                    // or json in the content - CONTACTS
                    if (isset($message['content']['media_type']) and $message['content']['media_type'] == 'contacts') {

                    }
                    // location info

                    // fill the single message array
                    $inMessageArr = [];

                    // fill for sorting first
                    $inMessageArr['BUSERID'] = Central::getUserByPhoneNumber($message['from'])['BID'];
                    $inMessageArr['BTEXT'] = $message['content']['text'];
                    $inMessageArr['BUNIXTIMES'] = $message['timestamp'];
                    $inMessageArr['BDATETIME'] = (string) date('YmdHis');
                    $inMessageArr['BTOPIC'] = 'general';

                    $convArr = Central::searchConversation($inMessageArr);
                    if (is_array($convArr) && isset($convArr['BID']) && $convArr['BID'] > 0) {
                        $inMessageArr['BTRACKID'] = $convArr['BTRACKID'];
                        $inMessageArr['BTOPIC'] = $convArr['BTOPIC'];
                        $inMessageArr['BLANG'] = $convArr['BLANG'];
                    } else {
                        $inMessageArr['BTRACKID'] = (int) (microtime(true) * 1000000);
                        $inMessageArr['BLANG'] = Central::getLanguageByCountryCode($message['from']);
                        $inMessageArr['BTOPIC'] = 'general';
                    }

                    // now the rest of the message
                    $inMessageArr['BID'] = 'DEFAULT';
                    $inMessageArr['BPROVIDX'] = $message['id'];
                    $inMessageArr['BMESSTYPE'] = 'WA';
                    $inMessageArr['BFILE'] = $hasFile;
                    $inMessageArr['BFILEPATH'] = $message['content']['file_details']['file_path'];
                    $inMessageArr['BFILETYPE'] = $fileType;
                    $inMessageArr['BDIRECT'] = 'IN';
                    $inMessageArr['BSTATUS'] = 'NEW';
                    $inMessageArr['BFILETEXT'] = '';

                    // Rate limiting check - only block if MESSAGES limit reached
                    if (XSControl::isRateLimitingEnabled()) {
                        $limitCheck = XSControl::checkMessagesLimit($inMessageArr['BUSERID']);
                        if (is_array($limitCheck) && $limitCheck['limited']) {
                            // Send rate limit notification via WhatsApp
                            $limitMessage = "⏳ Usage Limit Reached\n" . $limitCheck['message'] . "\nNext available in: " . $limitCheck['reset_time_formatted'] . "\nNeed higher limits? 🚀 Upgrade your plan";
                            
                            // Get WhatsApp details to send reply
                            if (isset($message['metadata']['phone_number_id'])) {
                                $waDetailsArr = [
                                    'BWAPHONEID' => $message['metadata']['phone_number_id']
                                ];
                                $waSender = new waSender($waDetailsArr);
                                $waSender->sendText($message['from'], $limitMessage);
                            }
                            exit;
                        }
                    }

                    // ****************************************************************
                    // save the message to the DB
                    $resArr = Central::handleInMessage($inMessageArr);

                    // save the number that the message was sent to
                    // ----------------------------------------------------------------
                    $msgDBID = intval($resArr['lastId']);
                    if ($msgDBID > 0 and isset($message['metadata'])) {
                        $msgToPhone = intval($message['metadata']['display_phone_number']);
                        $msgToId = intval($message['metadata']['phone_number_id']);
                        $updateSQL = 'insert into BWAIDS (BID, BMID, BWAPHONEID, BWAPHONENO) values (DEFAULT, '.$msgDBID.', '.$msgToId.', '.$msgToPhone.')';
                        $idRes = db::query($updateSQL);
                    }

                    // ****************************************************************
                    // PREPROCESSOR
                    // if the message was saved successfully, start the preprocessor
                    // ****************************************************************
                    //error_log(__FILE__.": inMessageArr: ".json_encode($inMessageArr));

                    if ($resArr['lastId'] > 0) {
                        // log the message to the DB
                        XSControl::countThis($inMessageArr['BUSERID'], $resArr['lastId']);
                        // ---- sorting, detailing the message for further processing
                        // (1) check, if there is a conversation open
                        $inMessageArr['BID'] = $resArr['lastId'];
                        // count bytes
                        XSControl::countBytes($inMessageArr, 'BOTH', false);
                        // (2) start the preprocessor and monitor the pid in the pids folder
                        // Ensure pids directory exists
                        if (!is_dir('pids')) {
                            mkdir('pids', 0755, true);
                        }
                        $cmd = 'nohup php preprocessor.php '.$inMessageArr['BID'].' > /dev/null 2>&1 &';
                        $pidfile = 'pids/m'.($inMessageArr['BID']).'.pid';
                        //error_log(__FILE__.": execute : ".$cmd);
                        exec(sprintf('%s echo $! >> %s', $cmd, $pidfile));
                    }
                    // ****************************************************************
                }
            }

            // handle status updates
            foreach ($formattedMessages['status'] as $status) {
                if (isset($status['id'])) {
                    Central::handleStatus($status);
                }
            }
        }
        // logMessage($entry);
    }
}

// **********************************************************************************
// **********************************************************************************
// **********************************************************************************
// logging function for now, disable in production
// **********************************************************************************

function logMessage($arrMessage)
{
    $logFile = 'debug.log';
    $logContent = '';
    
    // Read existing log if it exists
    if (file_exists($logFile)) {
        $logContent = file_get_contents($logFile);
    }
    
    $logContent = '<pre>'.json_encode($arrMessage, JSON_PRETTY_PRINT). "</pre>\n****\n" . substr($logContent, 0, 16384) . "...\n****\n";
    $fhd = fopen($logFile, 'w');
    if ($fhd) {
        fwrite($fhd, $logContent);
        fclose($fhd);
    }
}

// --------------------------------------------------------------------------------
// handle all other incoming messages, besides an authentication request
// --------------------------------------------------------------------------------

/*
    return message will be formatted as an array.
*/

function processWAMessage($messageBlock): array
{
    // https://graph.facebook.com/v21.0/
    $mediaDownloadUrl = 'https://graph.facebook.com/v21.0/';
    $processedData = [
        'messages' => [],
        'status' => []
    ];

    // now handle the message
    $value = $messageBlock['value'];

    // Extract common metadata
    $metadata = [
        'messaging_product' => $value['messaging_product'] ?? null,
        'display_phone_number' => $value['metadata']['display_phone_number'] ?? null,
        'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
    ];

    // Handle messages
    if (isset($value['messages'])) {
        foreach ($value['messages'] as $message) {
            $messageDetails = [
                'type' => $message['type'] ?? '',
                'from' => $message['from'] ?? '',
                'id' => $message['id'] ?? '',
                'timestamp' => $message['timestamp'] ?? '',
                'content' => [],
                'metadata' => $metadata,
            ];

            // Handle specific message types
            // FILES
            if (in_array($message['type'], ['image', 'video', 'document', 'audio'])) {
                // there is a file coming
                $mediaId = $message[$message['type']]['id'];
                $mediaInfo = downloadMediaInfo($mediaId, $mediaDownloadUrl, $GLOBALS['WAtoken']);
                $mediaInfo['userPhoneNo'] = $message['from'];
                // get file
                $mediaFileDetails = downloadMediaFile($mediaInfo, $GLOBALS['WAtoken']);
                $caption = '';
                if (isset($message[$message['type']]['caption'])) {
                    $caption = $message[$message['type']]['caption'];
                }
                if (isset($message['text']['body'])) {
                    if ($caption != '') {
                        $caption .= ' ';
                    }
                    $caption = $caption . $message['text']['body'];
                }

                $messageDetails['content'] = [
                    'media_type' => $message['type'],
                    'mime_type' => $mediaInfo['mime_type'] ?? '',
                    'sha256' => $message[$message['type']]['sha256'] ?? '',
                    'file_details' => $mediaFileDetails,
                    'text' => $caption,
                ];
                // SIMPLE TEXT
            } elseif ($message['type'] === 'text') {
                $messageDetails['content'] = [
                    'text' => $message['text']['body'] ?? '',
                    'media_type' => '',
                    'mime_type' => '',
                    'sha256' => '',
                    'file_details' => ['file_path' => '', 'mime_type' => ''],
                ];
                // Contacts
            } elseif ($message['type'] === 'contacts') {
                $saveTo = '';
                $mimeType = '';
                if (is_array($message['contacts'])) {
                    $messageDetails['contactsJson'] = json_encode($message['contacts'], JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES);
                    $userPhoneNo = $message['from'];
                    // ---
                    if (strlen($userPhoneNo) > 5) {
                        $savePath = substr($userPhoneNo, -5, 3) . '/' . substr($userPhoneNo, -2, 2) . '/' . date('Ym');
                        $dlError = '';
                    } else {
                        $savePath = '';
                        $dlError = 'User phone number is not valid.';
                    }
                    //
                    if (strlen($savePath) > 0) {
                        $GLOBALS['filesystem']->createDirectory($savePath);
                        // log the media array to the apache error log on demand
                        // error_log("savePath: " . print_r($mediaInfo, true));
                        $fileName = 'wa_contacts' . date('YmdHis') . '.json';
                        $saveTo = $savePath . '/' . $fileName;
                        $GLOBALS['filesystem']->createDirectory($savePath);
                        file_put_contents('./up/'.$saveTo, $messageDetails['contactsJson']);
                        $mimeType = 'json';
                    }
                }
                $messageDetails['content'] = [
                    'text' => $message['text']['body'] ?? 'Contact(s) in JSON attached.',
                    'media_type' => 'contacts',
                    'mime_type' => 'json',
                    'sha256' => '',
                    'file_details' => ['file_path' => $saveTo, 'mime_type' => $mimeType],
                ];
                // REACTIONS
            } elseif ($message['type'] === 'reaction') {
                $messageDetails['content'] = [
                    'reaction_emoji' => $message['reaction']['emoji'] ?? '',
                    'reacted_to_message_id' => $message['reaction']['message_id'] ?? '',
                    'text' => $message['text']['body'] ?? '',
                    'media_type' => '',
                    'mime_type' => '',
                    'sha256' => '',
                    'file_details' => ['file_path' => '', 'mime_type' => ''],
                ];
            }
            $processedData['messages'][] = $messageDetails;
        }
    }

    // Handle statuses
    if (isset($value['statuses'])) {
        foreach ($value['statuses'] as $status) {
            $statusDetails = [
                'id' => $status['id'] ?? '',
                'status' => $status['status'] ?? '',
                'timestamp' => $status['timestamp'] ?? '',
                'recipient_id' => $status['recipient_id'] ?? '',
                'metadata' => $metadata,
            ];
            $processedData['status'][] = $statusDetails;
        }
    }

    // return the formatted message as an array
    return $processedData;
}

// media handling
function downloadMediaInfo(string $mediaId, string $mediaDownloadUrl, string $accessToken): array
{
    $url = $mediaDownloadUrl . $mediaId;
    $headers = [
        "Authorization: Bearer $accessToken",
    ];

    $response = httpRequest('GET', $url, $headers);

    // Log the request data
    // logMessage(['FILE:' . $response]);
    return json_decode($response, true);
}
// create
// download the media file with a curl request via shell!
function downloadMediaFile(array $mediaInfo, string $accessToken): array
{
    if (!isset($mediaInfo['url'])) {
        throw new Exception('Media URL is missing.');
    }
    // curl -X GET "URL" -H "Authorization: Bearer TOKEN" -o output_file.jpg
    $url = $mediaInfo['url'];
    $token = $accessToken;
    $userPhoneNo = $mediaInfo['userPhoneNo'];
    // ---
    if (strlen($userPhoneNo) > 5) {
        $savePath = substr($userPhoneNo, -5, 3) . '/' . substr($userPhoneNo, -2, 2) . '/' . date('Ym');
        $dlError = '';
    } else {
        $savePath = '';
        $dlError = 'User phone number is not valid.';
    }
    //
    $GLOBALS['filesystem']->createDirectory($savePath);
    // log the media array to the apache error log on demand
    // error_log("savePath: " . print_r($mediaInfo, true));
    $fileName = 'wa_'.$mediaInfo['id'] . '.' . Tools::getFileExtension($mediaInfo['mime_type']);
    $saveTo = $savePath . '/' . $fileName;
    $GLOBALS['filesystem']->createDirectory($savePath);

    // execute CURL
    if ($savePath != '') {
        $exRes = exec("curl -X GET \"$url\" -H \"Authorization: Bearer $token\" -o \"./up/$saveTo\"");
        if (substr($saveTo, -3) == 'ogg' && file_exists('./up/' . $saveTo)) {
            // convert to mp3
            set_time_limit(360);
            $saveToNew = substr($saveTo, 0, -3) . 'mp3';
            unlink('./up/'.$saveToNew);

            $exRes = exec("ffmpeg -loglevel panic -hide_banner -i \"./up/$saveTo\" -acodec libmp3lame -ab 128k \"./up/$saveToNew\"");
            unlink("./up/$saveTo");
            $saveTo = $saveToNew;
            $mediaInfo['mime_type'] = 'audio/mpeg';
        }

        // Security: Sanitize HTML files to prevent malicious landing pages
        $fileExt = Tools::getFileExtension($mediaInfo['mime_type']);
        if (in_array(strtolower($fileExt), ['html', 'htm']) && file_exists('./up/' . $saveTo)) {
            $sanitizeResult = Central::sanitizeHtmlUpload('./up/' . $saveTo, $fileExt);
            if ($sanitizeResult['converted']) {
                // Replace HTML file with sanitized text version
                $saveToNew = preg_replace('/\.(html?|htm)$/i', '.txt', $saveTo);
                file_put_contents('./up/' . $saveToNew, $sanitizeResult['content']);
                @unlink('./up/' . $saveTo); // Remove original HTML file
                $saveTo = $saveToNew;
                $mediaInfo['mime_type'] = 'text/plain';
            }
        }
    } else {
        $dlError .= ' - not executed -';
    }

    // Return file metadata
    return [
        'file_path' => $saveTo,
        'mime_type' => $mediaInfo['mime_type'],
        'sha256' => $mediaInfo['sha256'],
        'file_size' => $mediaInfo['file_size'],
        'media_id' => $mediaInfo['id'],
        'error' => $dlError,
        'userPhoneNo' => $userPhoneNo,
        'curl_result' => $exRes,
    ];
}

// define the file extension for the mime type
// simple curl request to dump the message info
function httpRequest(string $method, string $url, array $headers = [], array $data = []): string
{
    $ch = curl_init();

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true, // Follow redirects
        CURLOPT_SSL_VERIFYHOST => 0,    // Ignore SSL issues (use only for testing)
        CURLOPT_SSL_VERIFYPEER => 0,    // Ignore SSL issues (use only for testing)
    ];

    if (!empty($data)) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    if (curl_errno($ch)) {
        // Log the request data
        logMessage(['CURL Error: ' . curl_error($ch)]);
        throw new Exception('CURL Error: ' . curl_error($ch));
    }

    curl_close($ch);
    return $response;
}
