<?php

/**
 * Gmail Email Processing with Keyword-Based Routing
 * 
 * This script processes incoming emails sent to smart+KEYWORD@synaplan.com
 * 
 * Flow:
 * 1. Extract keyword from email address (e.g., smart+support@synaplan.com → "support")
 * 2. Look up keyword owner in BCONFIG table (BGROUP='GMAILKEY')
 * 3. Route to keyword owner's user ID (or system user ID 2 if invalid/not found)
 * 4. Process email as anonymous sender chatting with keyword owner (like widget)
 * 
 * Rules:
 * - No keyword OR keyword < 4 chars → routes to system user (ID 2)
 * - Keyword not in BCONFIG → routes to system user (ID 2)
 * - Valid keyword → routes to keyword owner's user ID
 * - NO user registration/creation - all senders are anonymous
 * 
 * Similar to widgetloader.php: Anonymous user interacts with settings of keyword owner
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

// ------------------------------------------------------
try {
    // Refresh OAuth token
    myGMail::refreshToken();

    // Get emails with keyword-based routing
    $processedMails = myGMail::getMail();

    // Save to database and process (creates anonymous sessions per sender)
    if (count($processedMails) > 0) {
        myGMail::saveToDatabase($processedMails);
    }
} catch (Exception $e) {
    EmailService::sendAdminNotification('Gmail Error', 'Error: '.$e->getMessage());
}
