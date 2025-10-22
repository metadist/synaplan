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
require_once($root . '/inc/_coreincludes.php');

// ****************************************************************
// process the message, download files and parse them
// ****************************************************************
$msgId = intval($argv[1]);
$msgArr = Central::getMsgById($msgId);

// print_r($msgArr);
if ($msgArr['BFILE'] > 0) {
    $msgArr = Central::parseFile($msgArr);
    //print_r($msgArr);
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

// Execute and capture PID
$output = [];
exec($cmd, $output);
$pid = isset($output[0]) ? trim($output[0]) : 0;

// Save PID to file
if ($pid > 0) {
    file_put_contents($pidfile, $pid);
    error_log("Preprocessor: Started aiprocessor for message {$messageId}, PID: {$pid}");
} else {
    error_log("Preprocessor: Failed to start aiprocessor for message {$messageId}, cmd: {$cmd}");
}

exit;
