<?php

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
//==================================================================================
/*
 Preprocessor for Ralfs.AI messages
 written by puzzler - Ralf Schwoebel, rs(at)metadist.de

 Tasks of this file:
 . take the message ID handed over and process it
 . download files
 . parse files
 . hand over to the ai processor
*/
//==================================================================================
// core app files with relative paths
$root = __DIR__ . '/';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

// ****************************************************************
// process the message, download files and parse them
// ****************************************************************
$msgId = intval($argv[1]);
error_log("Preprocessor: Started for message ID: $msgId");

$msgArr = Central::getMsgById($msgId);
error_log("Preprocessor: Got message - BMESSTYPE=" . ($msgArr['BMESSTYPE'] ?? 'N/A') . ", User=" . ($msgArr['BUSERID'] ?? 'N/A'));

// print_r($msgArr);
if ($msgArr['BFILE'] > 0) {
    error_log("Preprocessor: Processing file: " . $msgArr['BFILEPATH']);
    $msgArr = Central::parseFile($msgArr);
    error_log("Preprocessor: File parsed, extracted text length: " . strlen($msgArr['BFILETEXT'] ?? '') . " chars");
    //print_r($msgArr);
} else {
    error_log("Preprocessor: No file to process");
}

// -----------------------------------------------------
// Clean up preprocessor PID file
$pidfile = __DIR__ . '/pids/m' . intval($msgId) . '.pid';
if (file_exists($pidfile)) {
    unlink($pidfile);
}

// -----------------------------------------------------
// Hand over to the AI processor
// -----------------------------------------------------

$messageId = intval($msgArr['BID']);
$logfile = __DIR__ . '/logs/aiprocessor_' . $messageId . '.log';
$pidfile = __DIR__ . '/pids/p' . $messageId . '.pid';

// Build command with proper paths and logging
$cmd = 'cd ' . escapeshellarg(__DIR__) . ' && ' .
       PHP_BINARY . ' aiprocessor.php ' . escapeshellarg($messageId) .
       ' >> ' . escapeshellarg($logfile) . ' 2>&1 & echo $!';

$cmd = 'php aiprocessor.php '.$msgArr['BID'].' > /dev/null 2>&1 &';
$pidfile = 'pids/p'.($msgArr['BID']).'.pid';
exec(sprintf('%s echo $! >> %s', $cmd, $pidfile));
error_log("Preprocessor: Launched aiprocessor for message ID: $msgId");

exit;
