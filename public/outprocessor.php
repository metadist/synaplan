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
    // SENDING BACK: check the way in and choose the right out.
    // get the phone number from the database, that was receiving the message
    // use it to send the answer back
    $detRes = db::Query('select BWAPHONENO, BWAPHONEID from BWAIDS where BMID = '.$msgId);
    $waDetailsArr = db::FetchArr($detRes);

    if (!$waDetailsArr || !isset($waDetailsArr['BWAPHONEID'])) {
        exit(1);
    }

    // ******************************************************
    // SEND WA
    $waSender = new waSender($waDetailsArr);

    if (!empty($GLOBALS['WAtoken'])) {
        try {
            if ($aiAnswer['BFILE'] > 0 and $aiAnswer['BFILETYPE'] != '' and str_contains($aiAnswer['BFILEPATH'], '/')) {
                if ($aiAnswer['BFILETYPE'] == 'png' or $aiAnswer['BFILETYPE'] == 'jpg' or $aiAnswer['BFILETYPE'] == 'jpeg') {
                    $waSender->sendImage($usrArr['BPROVIDERID'], $aiAnswer);
                } elseif ($aiAnswer['BFILETYPE'] == 'mp3') {
                    $waSender->sendAudio($usrArr['BPROVIDERID'], $aiAnswer);
                } else {
                    $myRes = $waSender->sendText($usrArr['BPROVIDERID'], $aiAnswer['BTEXT']);
                }
            }
        } catch (Exception $e) {
            // Silent fail
        }
    }
}

// ------------------------------------------------------
// GMAIL
// ------------------------------------------------------
if ($answerMethod == 'MAIL') {
    // send the answer to the user via metadist account, but reply-to is correct
    $htmlText = nl2br(htmlspecialchars(Tools::ensure_utf8($aiAnswer['BTEXT'])));
    $plainText = strip_tags($aiAnswer['BTEXT']);

    // Build file attachment path if file exists
    $fileAttachment = '';
    if ($aiAnswer['BFILE'] > 0 && !empty($aiAnswer['BFILEPATH'])) {
        $fileAttachment = rtrim(UPLOAD_DIR, '/').'/'.$aiAnswer['BFILEPATH'];
        // Verify file exists before attaching
        if (!file_exists($fileAttachment)) {
            $fileAttachment = '';
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
}
//-----
exit;
