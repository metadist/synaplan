<?php

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
