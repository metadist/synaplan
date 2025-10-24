<?php

/**
 * AI Processor for Ralfs.AI Messages
 *
 * This file handles the processing of messages by taking the message ID and processing it
 * through various AI services and tools.
 *
 * @author Ralf Schwoebel (rs@metadist.de)
 * @package AIProcessor
 */

// Set execution time limit to 6 minutes
set_time_limit(360);

// core app files with relative paths
$root = __DIR__ . '/';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

// Process the message using the provided message ID
$msgId = $argv[1];
ProcessMethods::init($msgId);

// Handle file translation if needed
ProcessMethods::fileTranslation();

// Retrieve and process message thread
$timeSeconds = 1200;
ProcessMethods::$threadArr = Central::getThread(ProcessMethods::$msgArr, $timeSeconds);

// Sort and process the message (sort is calling the processor to split tools and topics)
ProcessMethods::sortMessage();

// Prepare AI answer for database storage
$aiLastId = ProcessMethods::saveAnswerToDB();

// Debug logging (only if APP_DEBUG=true)
if (!empty($GLOBALS['debug'])) {
    $debugFile = __DIR__ . '/debug_websearch.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($debugFile, "[$timestamp] aiprocessor: Calling outprocessor with aiLastId=" . $aiLastId . " msgId=" . $msgId . "\n", FILE_APPEND);
}

// Clean up aiprocessor PID file
$pidfile = __DIR__ . '/pids/p' . intval($msgId) . '.pid';
if (file_exists($pidfile)) {
    unlink($pidfile);
}

// Hand over to output processor
$cmd = 'php outprocessor.php '.($aiLastId).' '.($msgId).' > /dev/null 2>&1 &';
exec($cmd);

if (!empty($GLOBALS['debug'])) {
    $debugFile = __DIR__ . '/debug_websearch.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($debugFile, "[$timestamp] aiprocessor: outprocessor command executed\n", FILE_APPEND);
}

exit;
