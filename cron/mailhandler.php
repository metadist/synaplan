<?php
// Simple CLI cron script to test mail handler routing per user
// Usage (CLI): php cron/mailhandler.php

// Bootstrap
require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../app/inc/_coreincludes.php');

// Ensure this runs only via CLI for now
if (php_sapi_name() !== 'cli') {
	echo "This script must be run from the command line.\n";
	exit(1);
}

// CLI args: optional user id and optional debug flag
// Supports: php cron/mailhandler.php 2 DEBUG
//           php cron/mailhandler.php --user=2 --debug
//           php cron/mailhandler.php -u 2 -d
$DEBUG_CRON = false;
$targetUserId = null;

if (isset($argv) && is_array($argv)) {
	for ($i = 1; $i < count($argv); $i++) {
		$arg = (string)$argv[$i];
		$lower = strtolower($arg);
		if (is_numeric($arg)) {
			$targetUserId = (int)$arg;
			continue;
		}
		if ($lower === 'debug' || $lower === '--debug' || $lower === '-d') {
			$DEBUG_CRON = true;
			continue;
		}
		if (substr($lower, 0, 7) === '--user=') {
			$targetUserId = (int)substr($arg, 7);
			continue;
		}
		if ($lower === '--user' || $lower === '-u') {
			if (($i + 1) < count($argv) && is_numeric($argv[$i + 1])) {
				$targetUserId = (int)$argv[++$i];
			}
			continue;
		}
		if ($lower === '--help' || $lower === '-h' || $lower === 'help') {
			echo "Usage: php cron/mailhandler.php [--user=ID|-u ID|ID] [--debug|-d|DEBUG]\n";
			echo "If --debug is not provided, the script runs silently.\n";
			exit(0);
		}
	}
}

// debug output is handled via Tools::debugCronLog()

// Prevent concurrent runs across machines using BCONFIG (BOWNERID=0, BGROUP='CRON', BSETTING='MAILHANDLER')
if (Tools::cronRunCheck('MAILHANDLER')) {
	Tools::debugCronLog("MAILHANDLER cron is already running (" . Tools::cronTime('MAILHANDLER') . ").\n");
	exit(0);
}

// Ensure cleanup on normal exit
register_shutdown_function(function() {
	Tools::deleteCron('MAILHANDLER');
});

Tools::debugCronLog("Starting mailhandler cron\n");

// 1) Get all users with active mail handler settings
$users = mailHandler::getUsersWithMailhandler();
// Filter to specific user if requested
if ($targetUserId !== null) {
	$users = array_values(array_filter($users, function($uid) use ($targetUserId) { return (int)$uid === (int)$targetUserId; }));
}
// if no users with mail handler configuration found, exit
if (count($users) === 0) {
	Tools::debugCronLog("No users with mail handler configuration found.\n");
	exit(0);
}


Tools::debugCronLog("Found ".count($users)." user(s) with mail handler configured.\n");

foreach ($users as $uid) {
	Tools::debugCronLog("\n---\nUser ID: $uid\n");
	$res = mailHandler::processNewEmailsForUser((int)$uid, 25);
	Tools::debugCronLog("Processed: ".$res['processed']." message(s).\n");
	if (!empty($res['errors'])) { Tools::debugCronLog("Errors: ".json_encode($res['errors'])."\n"); }
	// Touch heartbeat so other runners can see it's active
	Tools::updateCron('MAILHANDLER');
}


Tools::debugCronLog("\nMailhandler cron finished.\n");

// Remove cron lock so the next run can start
Tools::deleteCron('MAILHANDLER');


