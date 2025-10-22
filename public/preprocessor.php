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
$msgArr = Central::getMsgById($msgId);

// print_r($msgArr);
if ($msgArr['BFILE'] > 0) {
    $msgArr = Central::parseFile($msgArr);
    //print_r($msgArr);
}

//error_log(__FILE__.": msgArr: ".json_encode($msgArr), 3, "/wwwroot/bridgeAI/customphp.log");
// -----------------------------------------------------
// delete the pid file
$pidfile = 'pids/m'.($msgId).'.pid';
if (file_exists($pidfile)) {
    unlink($pidfile);
}
// -----------------------------------------------------
// -----------------------------------------------------
// hand over to the ai processor

// Note: countThis() is now called directly in _frontend.php after successful message insert

$cmd = 'php aiprocessor.php '.$msgArr['BID'].' > /dev/null 2>&1 &';
$pidfile = 'pids/p'.($msgArr['BID']).'.pid';
exec(sprintf('%s echo $! >> %s', $cmd, $pidfile));

exit;
