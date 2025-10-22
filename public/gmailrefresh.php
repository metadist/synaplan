<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

// Logging function
function logGmail($message)
{
    $logFile = __DIR__ . '/logs/gmailrefresh.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    // Also echo for immediate feedback
    echo "[$timestamp] $message\n";
}

logGmail("===========================================");
logGmail("Gmail Refresh Script Started");
logGmail("===========================================");

// ------------------------------------------------------
try {
    logGmail("[1/3] Refreshing OAuth token...");
    myGMail::refreshToken();
    logGmail("✓ Token refreshed successfully");

    logGmail("[2/3] Fetching emails from Gmail...");
    $processedMails = myGMail::getMail();

    if (is_array($processedMails)) {
        logGmail("✓ Found " . count($processedMails) . " email(s) to process");

        foreach ($processedMails as $idx => $mail) {
            logGmail("  Email " . ($idx + 1) . ":");
            logGmail("    From: " . ($mail['from'] ?? 'N/A'));
            logGmail("    Subject: " . ($mail['subject'] ?? 'N/A'));
            logGmail("    User: " . ($mail['usrArr']['BMAIL'] ?? 'N/A') . " (ID: " . ($mail['usrArr']['BID'] ?? 'N/A') . ")");
            logGmail("    Attachments: " . count($mail['attachments'] ?? []));
            logGmail("    Body length: " . strlen($mail['body'] ?? '') . " chars");
        }
    } else {
        logGmail("✗ No emails found or error occurred");
    }

    logGmail("[3/3] Saving to database and launching processors...");
    if (count($processedMails) > 0) {
        myGMail::saveToDatabase($processedMails);
        logGmail("✓ Successfully saved " . count($processedMails) . " email(s) to database");
        logGmail("✓ Preprocessor(s) launched in background");
    } else {
        logGmail("✓ No emails to save");
    }

    logGmail("===========================================");
    logGmail("✓ Gmail refresh completed successfully!");
    logGmail("===========================================");

} catch (Exception $e) {
    logGmail("===========================================");
    logGmail("✗ ERROR OCCURRED:");
    logGmail("===========================================");
    logGmail("Message: " . $e->getMessage());
    logGmail("File: " . $e->getFile());
    logGmail("Line: " . $e->getLine());
    logGmail("Stack Trace:");
    foreach (explode("\n", $e->getTraceAsString()) as $line) {
        logGmail($line);
    }
    logGmail("===========================================");

    // Also send admin notification
    EmailService::sendAdminNotification('Gmail Error', 'Error: '.$e->getMessage());
}
