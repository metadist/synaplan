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
error_log("AIProcessor: Started for message ID: $msgId");

ProcessMethods::init($msgId);
error_log("AIProcessor: Initialized - BMESSTYPE=" . (ProcessMethods::$msgArr['BMESSTYPE'] ?? 'N/A'));

// Handle file translation if needed
ProcessMethods::fileTranslation();

// Retrieve and process message thread
$timeSeconds = 1200;
ProcessMethods::$threadArr = Central::getThread(ProcessMethods::$msgArr, $timeSeconds);
error_log("AIProcessor: Got thread with " . count(ProcessMethods::$threadArr) . " messages");

// Sort and process the message (sort is calling the processor to split tools and topics)
ProcessMethods::sortMessage();
error_log("AIProcessor: Sorted - Topic=" . (ProcessMethods::$msgArr['BTOPIC'] ?? 'N/A'));

// Prepare AI answer for database storage
$aiLastId = ProcessMethods::saveAnswerToDB();
error_log("AIProcessor: AI answer saved with ID: $aiLastId");

// Clean up aiprocessor PID file
$pidfile = __DIR__ . '/pids/p' . intval($msgId) . '.pid';
if (file_exists($pidfile)) {
    unlink($pidfile);
}

// Hand over to output processor
$cmd = 'php outprocessor.php '.($aiLastId).' '.($msgId).' > /dev/null 2>&1 &';
exec($cmd);
error_log("AIProcessor: Launched outprocessor for AI answer ID: $aiLastId, original message ID: $msgId");

exit;
