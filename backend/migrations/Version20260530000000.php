<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create BWIDGET_EVENTS — the shared, cluster-wide transport for widget
 * real-time events (SSE backing store).
 *
 * Why a table (and not a cache): production runs multiple web nodes behind a
 * round-robin load balancer. An event published while handling a POST on one
 * node must reach the SSE stream held open on another node. The previous
 * node-local filesystem cache could not do that, so operator/visitor messages
 * during a human takeover only appeared after a manual page reload (the reload
 * reads the shared DB). The Galera-replicated table is visible to every node.
 *
 * Rows are append-only and short-lived (BEXPIRES); each publish is one INSERT,
 * which also removes the read-modify-write race that silently dropped
 * concurrent events under the cache approach.
 *
 * Column shapes mirror what Doctrine emits for {@see \App\Entity\WidgetEvent}
 * so `doctrine:schema:validate` stays green on a freshly migrated DB.
 *
 * Down() drops the table — events are ephemeral operational data, not
 * irreplaceable user content (the chat messages themselves live in BMESSAGES).
 */
final class Version20260530000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create BWIDGET_EVENTS shared event store for cluster-wide widget SSE delivery';
    }

    public function isTransactional(): bool
    {
        // MariaDB does not support DDL inside transactions.
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('BWIDGET_EVENTS')) {
            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE BWIDGET_EVENTS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BWIDGETID VARCHAR(64) NOT NULL,
              BSESSIONID VARCHAR(128) NOT NULL,
              BTYPE VARCHAR(32) NOT NULL,
              BPAYLOAD JSON NOT NULL,
              BCREATED BIGINT NOT NULL,
              BEXPIRES BIGINT NOT NULL,
              INDEX idx_widget_event_stream (BWIDGETID, BSESSIONID, BID),
              INDEX idx_widget_event_expires (BEXPIRES),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BWIDGET_EVENTS');
    }
}
