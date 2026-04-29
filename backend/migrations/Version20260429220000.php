<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill BWIDGET_SESSIONS.BMESSAGECOUNT from the actual BMESSAGES table.
 *
 * Background
 * ----------
 * Before this migration, BMESSAGECOUNT served two unrelated purposes that conflicted
 * with each other:
 *
 *   1. Visitor quota counter — only incremented for IN-direction messages from
 *      end-users in 'ai' mode, decremented on stream failure, reset to 0 when an
 *      expired session was resumed with the same sessionId. This was deliberately
 *      kept low so it could be compared against the per-widget messageLimit.
 *
 *   2. Conversation-length metric — read by the dashboard, the Excel/CSV/JSON
 *      exports, and the WidgetSummary aggregator as if it were the true number
 *      of messages on the chat.
 *
 * Because the two intents fought, real-world counters drifted to (or stayed at)
 * zero in five distinct ways:
 *
 *   a) Visitor messages in 'human' / 'waiting' / 'internal' mode were never counted
 *      (skipped by WidgetPublicController::sendMessage).
 *   b) Operator replies via HumanTakeoverService::sendHumanMessage were never counted
 *      ("human messages are free" — explicitly documented in code).
 *   c) Takeover/handback system messages persisted by createSystemMessage() were never
 *      counted.
 *   d) WidgetSessionService::getOrCreateSession() reset the counter to 0 every time an
 *      expired session was resumed with the same sessionId, while the underlying
 *      BMESSAGES rows for the chat remained — so heavy reusers (returning visitors,
 *      operators with sticky sessionIds) regularly ended up with counter=0 alongside
 *      dozens of historical messages.
 *   e) decrementMessageCount() rolled the counter back on stream failures even though
 *      the failed BMESSAGES row stayed in the table with status='failed'.
 *
 * The accompanying code change repurposes BMESSAGECOUNT as the *true* total of
 * persisted messages on the session's chat (visitor + AI + operator + system + welcome)
 * and moves the visitor-quota check to a live count of IN-direction messages within
 * the SESSION_EXPIRY_HOURS window (computed via MessageRepository::countByChatId).
 *
 * What this migration does
 * ------------------------
 * Re-derives BMESSAGECOUNT for every row in BWIDGET_SESSIONS that currently has a
 * BCHATID, by counting the rows in BMESSAGES on that chat (excluding `failed`, since
 * the new live quota also excludes them, keeping the two views consistent). Sessions
 * without a chat (BCHATID IS NULL) keep BMESSAGECOUNT = 0, which already matches the
 * new semantics ("no chat → no messages").
 *
 * Idempotency
 * -----------
 * The UPDATE is fully idempotent: re-running it converges to the same target value
 * even if new messages have been inserted in the meantime. After the application
 * starts maintaining the counter at every persist site (which is what the code change
 * in this revision ensures), this migration acts as the one-shot reconciliation that
 * gets the database to the new steady state.
 *
 * Down
 * ----
 * Intentionally a no-op. There is no meaningful "old" value to restore to — the old
 * semantics produced inconsistent counters across modes, and rolling back to those
 * counters would re-introduce the data-quality problem this migration was written to
 * solve. Operators who need the old behaviour should redeploy the previous code,
 * which would then independently overwrite the counter on its own schedule.
 */
final class Version20260429220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill BWIDGET_SESSIONS.BMESSAGECOUNT from real BMESSAGES counts (closes the "messageCount=0 with messages in DB" data-quality issue).';
    }

    public function isTransactional(): bool
    {
        // Defensive: the UPDATE itself is fine inside a transaction on InnoDB, but
        // disabling the implicit one keeps behaviour identical on MariaDB clusters
        // where DDL/large updates are routinely run outside of transactions.
        return false;
    }

    public function up(Schema $schema): void
    {
        // Skip on installs that never ran the widget feature (fresh provisioning before
        // 20260417 baseline, or a custom deployment that disabled widgets).
        if (!$schema->hasTable('BWIDGET_SESSIONS') || !$schema->hasTable('BMESSAGES')) {
            return;
        }

        // Reconcile BMESSAGECOUNT against the actual chat history.
        //
        // - Excludes BSTATUS = 'failed' so the cached counter and the new live quota
        //   window (which also excludes failed rows) report the same number.
        // - Uses a correlated subquery rather than UPDATE..JOIN so it works on every
        //   MariaDB / MySQL version we ship against without engine-specific tuning.
        // - COALESCE handles the (rare) case where BCHATID points at a chat whose
        //   BMESSAGES rows have all been removed: counter goes to 0, matching the
        //   new "no messages → 0" semantic.
        $this->addSql(<<<'SQL'
            UPDATE BWIDGET_SESSIONS s
               SET s.BMESSAGECOUNT = COALESCE(
                   (SELECT COUNT(*)
                      FROM BMESSAGES m
                     WHERE m.BCHATID = s.BCHATID
                       AND m.BSTATUS <> 'failed'),
                   0
               )
             WHERE s.BCHATID IS NOT NULL
        SQL);

        // Sessions without a chat must report 0, regardless of any historical drift.
        $this->addSql(<<<'SQL'
            UPDATE BWIDGET_SESSIONS
               SET BMESSAGECOUNT = 0
             WHERE BCHATID IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Intentional no-op — see class docblock.
    }
}
