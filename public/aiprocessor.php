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
require_once($root . '/inc/_coreincludes.php');

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

// Clean up aiprocessor PID file
$pidfile = __DIR__ . '/pids/p' . intval($msgId) . '.pid';
if (file_exists($pidfile)) {
    unlink($pidfile);
}

// Hand over to output processor
$aiId = intval($aiLastId);
$messageId = intval($msgId);
$logfile = __DIR__ . '/logs/outprocessor_' . $messageId . '.log';

// Build command with proper paths and logging
$cmd = 'cd ' . escapeshellarg(__DIR__) . ' && ' .
       PHP_BINARY . ' outprocessor.php ' . escapeshellarg($aiId) . ' ' . escapeshellarg($messageId) .
       ' >> ' . escapeshellarg($logfile) . ' 2>&1 &';

// Execute command
exec($cmd);
error_log("AIprocessor: Started outprocessor for AI message {$aiId}, original message {$messageId}");

exit;
