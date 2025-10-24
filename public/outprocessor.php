<?php

//==================================================================================
/*
 AOutprocessorf for Ralfs.AI messages
 written by puzzler - Ralf Schwoebel, rs(at)metadist.de

 Tasks of this file:
 . take the message ID handed over and decide how to send it out
*/
//==================================================================================
set_time_limit(360);

// core app files with relative paths
$root = __DIR__ . '/';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

// ------------------------------------------------------
// Called by the AI processor, when the answer is ready
// called like this: $aiLastId is the created answer and $msgId is the processed incoming message
// $cmd = "nohup php outprocessor.php ".($aiLastId)." ".($msgId)." > /dev/null 2>&1 &";
// ------------------------------------------------------
// Initialize the API
// ------------------------------------------------------
$GLOBALS['WAtoken'] = ApiKeys::getWhatsApp();

// ------------------------------------------------------
$aiLastId = intval($argv[1]);
$msgId = intval($argv[2]);

$aiAnswer = Central::getMsgById($aiLastId);
if (!$aiAnswer || !isset($aiAnswer['BUSERID'])) {
    exit(1);
}

$usrArr = Central::getUsrById($aiAnswer['BUSERID']);
if (!$usrArr) {
    exit(1);
}
$usrArr['DETAILS'] = json_decode($usrArr['BUSERDETAILS'], true);


// set the answer method
$answerMethod = $aiAnswer['BMESSTYPE'];

//error_log(__FILE__.": arr: ".json_encode($aiAnswer), 3, "/wwwroot/bridgeAI/customphp.log");

// ------------------------------------------------------
// WHATSAPP
// ------------------------------------------------------
if ($answerMethod == 'WA') {
    // Retrieve AI service and model information from BMESSAGEMETA
    $aiService = 'AI';
    $aiModel = 'Unknown';
    $aiTopic = $aiAnswer['BTOPIC'] ?? 'general';

    $serviceSQL = 'SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ' . intval($aiLastId) . " AND BTOKEN = 'AISERVICE' ORDER BY BID DESC LIMIT 1";
    $serviceRes = db::Query($serviceSQL);
    if ($serviceRow = db::FetchArr($serviceRes)) {
        $fullService = $serviceRow['BVALUE'];
        if (substr($fullService, 0, 2) === 'AI' && strlen($fullService) > 2) {
            $aiService = substr($fullService, 2);
        } else {
            $aiService = $fullService;
        }
    }

    $modelSQL = 'SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ' . intval($aiLastId) . " AND BTOKEN = 'AIMODEL' ORDER BY BID DESC LIMIT 1";
    $modelRes = db::Query($modelSQL);
    if ($modelRow = db::FetchArr($modelRes)) {
        $aiModel = $modelRow['BVALUE'];
    }

    // Add footer with service, model, and topic
    $waFooter = "\n\n---\n";
    $waFooter .= $aiService . ' (' . $aiModel . ') | Topic: ' . $aiTopic;

    // Append footer to message text
    $aiAnswer['BTEXT'] .= $waFooter;

    // Debug logging (only if APP_DEBUG=true)
    if (!empty($GLOBALS['debug'])) {
        $debugFile = __DIR__ . '/debug_websearch.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($debugFile, "[$timestamp] outprocessor WA: Service=$aiService | Model=$aiModel | Topic=$aiTopic | BTEXT length=" . strlen($aiAnswer['BTEXT'] ?? '') . "\n", FILE_APPEND);
    }

    // SENDING BACK: check the way in and choose the right out.
    // get the phone number from the database, that was receiving the message
    // use it to send the answer back
    $detRes = db::Query('select BWAPHONENO, BWAPHONEID from BWAIDS where BMID = '.$msgId);
    $waDetailsArr = db::FetchArr($detRes);

    if (!$waDetailsArr || !isset($waDetailsArr['BWAPHONEID'])) {
        if (!empty($GLOBALS['debug'])) {
            file_put_contents($debugFile, "[$timestamp] outprocessor WA ERROR: No BWAIDS found for msgId=$msgId\n", FILE_APPEND);
        }
        exit(1);
    }

    // ******************************************************
    // SEND WA
    $waSender = new waSender($waDetailsArr);

    if (!empty($GLOBALS['WAtoken'])) {
        if ($aiAnswer['BFILE'] > 0 and $aiAnswer['BFILETYPE'] != '' and str_contains($aiAnswer['BFILEPATH'], '/')) {
            if ($aiAnswer['BFILETYPE'] == 'png' or $aiAnswer['BFILETYPE'] == 'jpg' or $aiAnswer['BFILETYPE'] == 'jpeg') {
                $waSender->sendImage($usrArr['BPROVIDERID'], $aiAnswer);
                if (!empty($GLOBALS['debug'])) {
                    error_log("Outprocessor: Sent WhatsApp image to {$usrArr['BPROVIDERID']}");
                }
            } elseif ($aiAnswer['BFILETYPE'] == 'mp3') {
                $waSender->sendAudio($usrArr['BPROVIDERID'], $aiAnswer);
                if (!empty($GLOBALS['debug'])) {
                    error_log("Outprocessor: Sent WhatsApp audio to {$usrArr['BPROVIDERID']}");
                }
            } else {
                $myRes = $waSender->sendText($usrArr['BPROVIDERID'], $aiAnswer['BTEXT']);
                if (!empty($GLOBALS['debug'])) {
                    error_log("Outprocessor: Sent WhatsApp text (unsupported file type) to {$usrArr['BPROVIDERID']}");
                }
            }
        } else {
            // No file - send text message with footer
            $myRes = $waSender->sendText($usrArr['BPROVIDERID'], $aiAnswer['BTEXT']);
            if (!empty($GLOBALS['debug'])) {
                file_put_contents($debugFile, "[$timestamp] outprocessor WA: Sent text to {$usrArr['BPROVIDERID']}\n", FILE_APPEND);
            }
        }
    } else {
        if (!empty($GLOBALS['debug'])) {
            error_log('Outprocessor: WhatsApp token not configured');
        }
    }
}

// ------------------------------------------------------
// GMAIL
// ------------------------------------------------------
if ($answerMethod == 'MAIL') {
    // send the answer to the user via metadist account, but reply-to is correct

    // Retrieve AI service and model information from BMESSAGEMETA
    $aiService = 'AI';
    $aiModel = 'Unknown';

    $serviceSQL = 'SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ' . intval($aiLastId) . " AND BTOKEN = 'AISERVICE' ORDER BY BID DESC LIMIT 1";
    $serviceRes = db::Query($serviceSQL);
    if ($serviceRow = db::FetchArr($serviceRes)) {
        // Remove 'AI' prefix only (e.g., 'AIOpenAI' -> 'OpenAI', 'AIGroq' -> 'Groq')
        $fullService = $serviceRow['BVALUE'];
        if (substr($fullService, 0, 2) === 'AI' && strlen($fullService) > 2) {
            $aiService = substr($fullService, 2); // Remove first 2 characters ('AI')
        } else {
            $aiService = $fullService;
        }
    }

    $modelSQL = 'SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ' . intval($aiLastId) . " AND BTOKEN = 'AIMODEL' ORDER BY BID DESC LIMIT 1";
    $modelRes = db::Query($modelSQL);
    if ($modelRow = db::FetchArr($modelRes)) {
        $aiModel = $modelRow['BVALUE'];
    }

    // Add footer with service, model, topic, and link information
    $footer = "\n\n---\n";
    $footer .= 'Generated by ' . $aiService . ' (' . $aiModel . ') | Topic: ' . ($aiAnswer['BTOPIC'] ?? 'general') . "\n";
    $footer .= 'Learn more: http://www.synaplan.com/';

    $htmlFooter = "<br><br><hr style='border: 1px solid #ccc; margin: 20px 0;'>\n";
    $htmlFooter .= "<p style='color: #666; font-size: 12px;'>\n";
    $htmlFooter .= 'Generated by <strong>' . htmlspecialchars($aiService) . '</strong> (' . htmlspecialchars($aiModel) . ') | Topic: ' . htmlspecialchars($aiAnswer['BTOPIC'] ?? 'general') . "<br>\n";
    $htmlFooter .= "Learn more: <a href='http://www.synaplan.com/'>www.synaplan.com</a>\n";
    $htmlFooter .= '</p>';

    // Append footer to both plain text and HTML versions
    $plainText = strip_tags($aiAnswer['BTEXT']) . $footer;
    $htmlText = nl2br(htmlspecialchars(Tools::ensure_utf8($aiAnswer['BTEXT']))) . $htmlFooter;

    // Build file attachment path if file exists
    $fileAttachment = '';
    if ($aiAnswer['BFILE'] > 0 && !empty($aiAnswer['BFILEPATH'])) {
        $fileAttachment = rtrim(UPLOAD_DIR, '/').'/'.$aiAnswer['BFILEPATH'];
        // Verify file exists before attaching
        if (!file_exists($fileAttachment)) {
            error_log('outprocessor.php: File attachment not found: ' . $fileAttachment);
            $fileAttachment = '';
        } else {
            error_log('outprocessor.php: Attaching file: ' . $fileAttachment . ' (size: ' . filesize($fileAttachment) . ' bytes)');
        }
    }

    // Get the original sender email from BMESSAGEMETA (for keyword-based anonymous emails)
    // This is stored when processing incoming emails via smart+keyword@synaplan.com
    $recipientEmail = $usrArr['DETAILS']['MAIL'] ?? ''; // Default to user's email

    $senderMetaSQL = 'SELECT BVALUE FROM BMESSAGEMETA WHERE BMESSID = ' . intval($msgId) . " AND BTOKEN = 'SENDER_EMAIL' LIMIT 1";
    $senderMetaRes = db::Query($senderMetaSQL);
    if ($senderMetaRow = db::FetchArr($senderMetaRes)) {
        // Use the original sender's email (anonymous email sender)
        $recipientEmail = $senderMetaRow['BVALUE'];
    }

    // Skip if no valid recipient email
    if (empty($recipientEmail)) {
        error_log("outprocessor.php: No recipient email found for msgId $msgId");
        exit(1);
    }

    // Log email details (only if APP_DEBUG=true)
    if (!empty($GLOBALS['debug'])) {
        error_log("outprocessor.php: Sending MAIL response - To: $recipientEmail, Service: $aiService, Model: $aiModel, HasAttachment: " . ($fileAttachment ? 'YES' : 'NO'));
    }

    // Use _mymail directly to support attachments
    $sentRes = _mymail(
        'info@metadist.de',              // From
        $recipientEmail,                  // To (original sender for anonymous emails)
        'Ralfs.AI - '.$aiAnswer['BTOPIC'], // Subject
        $htmlText,                        // HTML body
        $plainText,                       // Plain text body
        'smart@ralfs.ai',                 // Reply-to
        $fileAttachment                   // File attachment
    );

    // Log send result (only if APP_DEBUG=true)
    if (!empty($GLOBALS['debug'])) {
        if ($sentRes) {
            error_log("outprocessor.php: Email sent successfully to $recipientEmail");
        } else {
            error_log("outprocessor.php: Failed to send email to $recipientEmail");
        }
    }
}
//-----
exit;
