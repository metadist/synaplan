<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/inc/_coreincludes.php';

// ------------------------------------------------------

try {
    // Refresh token
    myGMail::refreshToken();

    // Get emails with attachments
    $processedMails = myGMail::getMail();

    // Save to database and download attachments
    // echo "Successfully processed " . count($processedMails) . " emails\n";
    // set answer method to GMAIL
    myGMail::saveToDatabase($processedMails);
} catch (Exception $e) {
    EmailService::sendAdminNotification('Gmail Error', 'Error: '.$e->getMessage());
}
