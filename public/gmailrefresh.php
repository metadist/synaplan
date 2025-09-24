<?php

require_once(__DIR__ . '/../vendor/autoload.php');

// ------------------------------------------------------ base config
require_once(__DIR__ . '/inc/_confsys.php');
require_once(__DIR__ . '/inc/_confdb.php');
require_once(__DIR__ . '/inc/_confdefaults.php');
require_once(__DIR__ . '/inc/_mail.php');
require_once(__DIR__ . '/inc/_tools.php');
require_once(__DIR__ . '/inc/_xscontrol.php');

// central tool
require_once(__DIR__ . '/inc/_central.php');
// basic ai tools
require_once(__DIR__ . '/inc/_basicai.php');
// myGMail
require_once(__DIR__ . '/inc/_myGMail.php');

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
