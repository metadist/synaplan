<?php
/**
 * Anonymous Widget Data Cleanup Script
 *
 * This script cleans up old anonymous widget data to comply with GDPR requirements.
 * It deletes anonymous messages and RAG entries older than 4 weeks (28 days).
 *
 * Usage: php cleanup_anonymous_data.php
 * Recommended: Run daily via cron job
 */

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/inc/_coreincludes.php');

// Configuration
$retentionDays = 28; // 4 weeks
$cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
$cutoffDate = date('Y-m-d H:i:s', $cutoffTime);

echo "=== Anonymous Widget Data Cleanup ===\n";
echo "Cutoff date: $cutoffDate\n";
echo "Retention period: $retentionDays days\n\n";

// 1. Clean up RAG entries for anonymous widget files FIRST
echo "1. Cleaning up old anonymous RAG entries...\n";

$deleteRagSQL = "DELETE BRAG FROM BRAG 
                 INNER JOIN BMESSAGES ON BRAG.BMID = BMESSAGES.BID 
                 WHERE BRAG.BGROUPKEY = 'WIDGET' 
                 AND BMESSAGES.BTEXT LIKE 'WEBWIDGET: %'
                 AND BMESSAGES.BUNIXTIMES < $cutoffTime";

$result = db::Query($deleteRagSQL);
$deletedRagEntries = db::AffectedRows();

echo "   Deleted $deletedRagEntries anonymous RAG entries\n";

// 2. Clean up anonymous messages (those with "WEBWIDGET: " prefix)
echo "2. Cleaning up old anonymous messages...\n";

$deleteMessagesSQL = "DELETE FROM BMESSAGES 
                     WHERE BTEXT LIKE 'WEBWIDGET: %' 
                     AND BUNIXTIMES < $cutoffTime";

$result = db::Query($deleteMessagesSQL);
$deletedMessages = db::AffectedRows();

echo "   Deleted $deletedMessages anonymous messages\n";

// 3. Clean up orphaned RAG entries (in case messages were deleted but RAG entries remained)
echo "3. Cleaning up orphaned RAG entries...\n";

$deleteOrphanedRagSQL = "DELETE FROM BRAG 
                         WHERE BGROUPKEY = 'WIDGET' 
                         AND BMID NOT IN (SELECT BID FROM BMESSAGES)";

$result = db::Query($deleteOrphanedRagSQL);
$deletedOrphanedRag = db::AffectedRows();

echo "   Deleted $deletedOrphanedRag orphaned RAG entries\n";

// 4. Clean up old anonymous session data from sessions table (if using database sessions)
// Note: This assumes you're using database sessions. If using file sessions, this won't apply.
if (function_exists('session_gc')) {
    echo "4. Running session garbage collection...\n";
    $collected = session_gc();
    echo "   Collected $collected expired sessions\n";
}

// Summary
$totalDeleted = $deletedMessages + $deletedRagEntries + $deletedOrphanedRag;
echo "\n=== Cleanup Summary ===\n";
echo "Total items deleted: $totalDeleted\n";
echo "Anonymous RAG entries: $deletedRagEntries\n";
echo "Anonymous messages: $deletedMessages\n";
echo "Orphaned RAG entries: $deletedOrphanedRag\n";
echo 'Cleanup completed at: ' . date('Y-m-d H:i:s') . "\n";

// Log the cleanup for audit purposes
$logMessage = date('Y-m-d H:i:s') . " - Anonymous data cleanup: $deletedMessages messages, $deletedRagEntries RAG entries, $deletedOrphanedRag orphaned entries deleted\n";
file_put_contents(__DIR__ . '/logs/cleanup_anonymous.log', $logMessage, FILE_APPEND | LOCK_EX);

echo "\nCleanup completed successfully!\n";
?> 