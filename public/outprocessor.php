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

// Log outprocessor start
error_log("Outprocessor: Started for AI message {$aiLastId}, original message {$msgId}");

$aiAnswer = Central::getMsgById($aiLastId);
if (!$aiAnswer || !isset($aiAnswer['BUSERID'])) {
    error_log("Outprocessor: Failed to get AI answer for ID {$aiLastId}");
    exit(1);
}

$usrArr = Central::getUsrById($aiAnswer['BUSERID']);
if (!$usrArr) {
    error_log("Outprocessor: Failed to get user for ID {$aiAnswer['BUSERID']}");
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
        error_log("Outprocessor: Failed to get WhatsApp details for message {$msgId}");
        exit(1);
    }

    // ******************************************************
    // SEND WA
    $waSender = new waSender($waDetailsArr);
    error_log("Outprocessor: Sending WhatsApp message to user {$usrArr['BPROVIDERID']}");

    if (!empty($GLOBALS['WAtoken'])) {
        try {
            if ($aiAnswer['BFILE'] > 0 and $aiAnswer['BFILETYPE'] != '' and str_contains($aiAnswer['BFILEPATH'], '/')) {
                if ($aiAnswer['BFILETYPE'] == 'png' or $aiAnswer['BFILETYPE'] == 'jpg' or $aiAnswer['BFILETYPE'] == 'jpeg') {
                    $waSender->sendImage($usrArr['BPROVIDERID'], $aiAnswer);
                } elseif ($aiAnswer['BFILETYPE'] == 'mp3') {
                    $waSender->sendAudio($usrArr['BPROVIDERID'], $aiAnswer);
                } else {
                    $myRes = $waSender->sendText($usrArr['BPROVIDERID'], $aiAnswer['BTEXT']);
                    error_log("Outprocessor: Sent WhatsApp text to {$usrArr['BPROVIDERID']}");
                }
            }
        } catch (Exception $e) {
            error_log('Outprocessor: WhatsApp send failed: ' . $e->getMessage());
        }
    } else {
        error_log('Outprocessor: Local dev mode - not sending WhatsApp message');
        error_log('Outprocessor: Would send: ' . json_encode($aiAnswer));
    }
}

// ------------------------------------------------------
// GMAIL
// ------------------------------------------------------
if ($answerMethod == 'MAIL') {
    error_log("Outprocessor: MAIL mode detected");
    error_log("Outprocessor: Sending to: " . $usrArr['DETAILS']['MAIL']);
    error_log("Outprocessor: Subject: Ralfs.AI - " . $aiAnswer['BTOPIC']);
    error_log("Outprocessor: Body length: " . strlen($aiAnswer['BTEXT']) . " chars");

    // send the answer to the user via metadist account, but reply-to is correct
    // $mailSender = new mailSender($usrArr["BPROVIDERID"]);
    // $mailSender->sendMail($aiAnswer);
    // print "MAIL\n";
    // print_r($aiAnswer);
    $htmlText = nl2br(htmlspecialchars(Tools::ensure_utf8($aiAnswer['BTEXT'])));
    $fileAttachment = rtrim(UPLOAD_DIR, '/').'/'.$aiAnswer['BFILEPATH'];
    // print $fileAttachment."\n";

    $sentRes = EmailService::sendEmail(
        $usrArr['DETAILS']['MAIL'],
        'Ralfs.AI - '.$aiAnswer['BTOPIC'],
        $htmlText,
        $htmlText,
        'smart@ralfs.ai'
    );

    if ($sentRes) {
        error_log("Outprocessor: ✓ Email sent successfully");
    } else {
        error_log("Outprocessor: ✗ Email sending FAILED");
    }
}
//-----
exit;
