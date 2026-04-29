<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reconcile DB schema with current ORM entity definitions.
 *
 * Background: the dev database and legacy production databases pre-date the
 * baseline migration (Version20260417000000). Both were created with
 * `doctrine:schema:update --force` (or hand-maintained SQL) and have drifted
 * from the canonical entity-level state over the years — missing column
 * defaults, columns nullable where the entity marks them required, JSON
 * columns nullable where entities default to `[]`. Fresh databases created via
 * the baseline migration already have the canonical state, so for those this
 * migration is a no-op at the SQL level; it only does real work on legacy
 * installations.
 *
 * Each NOT NULL transition is preceded by a defensive backfill UPDATE so the
 * migration is safe to run on legacy databases that may have NULL rows. The
 * backfill values match the entity-level defaults exactly.
 *
 * Historical note: when this migration first landed, CI still ran
 * `doctrine:schema:validate --skip-sync` because the "phantom diff" problem on
 * string defaults was assumed to be a DBAL 3.x comparator bug that required a
 * DBAL 4.x upgrade to fix. The DBAL 4.x upgrade (#781) turned out to be
 * necessary but not sufficient — the real root cause was `doctrine.yaml`
 * declaring `server_version: '11.8'`, which DBAL's MariaDB-detection regex
 * does not match, so introspection ran through the MySQL platform instead of
 * the MariaDB platform. Setting `server_version: 'mariadb-11.8.2'` plus the
 * follow-up reconciliation `Version20260429000000` (stale `DC2Type` column
 * comments) closed the gap, and CI now runs `doctrine:schema:validate`
 * without `--skip-sync`. See #824 for the full post-mortem.
 *
 * Trade-off: this migration is intentionally large (touches ~20 tables) but
 * only changes column metadata — no data is rewritten beyond NULL backfills.
 * On MariaDB 11.8 most of these `ALTER TABLE` statements complete in
 * milliseconds because they only touch the `.frm`/data dictionary.
 */
final class Version20260423000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reconcile DB schema with ORM entity defaults/nullability so full schema:validate passes';
    }

    public function isTransactional(): bool
    {
        // MariaDB does not support DDL inside transactions.
        return false;
    }

    public function up(Schema $schema): void
    {
        // ---- BUSER ---------------------------------------------------------
        $this->addSql("UPDATE BUSER SET BINTYPE = 'WEB' WHERE BINTYPE IS NULL");
        $this->addSql("UPDATE BUSER SET BUSERLEVEL = 'NEW' WHERE BUSERLEVEL IS NULL");
        $this->addSql('UPDATE BUSER SET BUSERDETAILS = JSON_OBJECT() WHERE BUSERDETAILS IS NULL');
        $this->addSql('UPDATE BUSER SET BPAYMENTDETAILS = JSON_OBJECT() WHERE BPAYMENTDETAILS IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE BUSER
              CHANGE BINTYPE         BINTYPE         VARCHAR(16) DEFAULT 'WEB' NOT NULL,
              CHANGE BPW             BPW             VARCHAR(64) DEFAULT NULL,
              CHANGE BUSERLEVEL      BUSERLEVEL      VARCHAR(32) DEFAULT 'NEW' NOT NULL,
              CHANGE BUSERDETAILS    BUSERDETAILS    JSON NOT NULL,
              CHANGE BPAYMENTDETAILS BPAYMENTDETAILS JSON NOT NULL
        SQL);

        // ---- BSUBSCRIPTIONS ------------------------------------------------
        $this->addSql('UPDATE BSUBSCRIPTIONS SET BCOST_BUDGET_MONTHLY = 0 WHERE BCOST_BUDGET_MONTHLY IS NULL');
        $this->addSql('UPDATE BSUBSCRIPTIONS SET BCOST_BUDGET_YEARLY = 0 WHERE BCOST_BUDGET_YEARLY IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE BSUBSCRIPTIONS
              CHANGE BCOST_BUDGET_MONTHLY BCOST_BUDGET_MONTHLY NUMERIC(10, 2) DEFAULT '0' NOT NULL,
              CHANGE BCOST_BUDGET_YEARLY  BCOST_BUDGET_YEARLY  NUMERIC(10, 2) DEFAULT '0' NOT NULL,
              CHANGE BSTRIPE_MONTHLY_ID   BSTRIPE_MONTHLY_ID   VARCHAR(128) DEFAULT NULL,
              CHANGE BSTRIPE_YEARLY_ID    BSTRIPE_YEARLY_ID    VARCHAR(128) DEFAULT NULL
        SQL);

        // ---- BPROMPTS ------------------------------------------------------
        $this->addSql("UPDATE BPROMPTS SET BLANG = 'en' WHERE BLANG IS NULL OR BLANG = ''");
        $this->addSql("ALTER TABLE BPROMPTS CHANGE BLANG BLANG VARCHAR(2) DEFAULT 'en' NOT NULL");

        // ---- BWIDGET_SUMMARIES --------------------------------------------
        $this->addSql('ALTER TABLE BWIDGET_SUMMARIES CHANGE BAI_MODEL BAI_MODEL VARCHAR(64) DEFAULT NULL');

        // ---- BTOKENS -------------------------------------------------------
        $this->addSql("UPDATE BTOKENS SET BIPADDRESS = '' WHERE BIPADDRESS IS NULL");
        $this->addSql("ALTER TABLE BTOKENS CHANGE BIPADDRESS BIPADDRESS VARCHAR(45) DEFAULT '' NOT NULL");

        // ---- BAPIKEYS ------------------------------------------------------
        $this->addSql('UPDATE BAPIKEYS SET BSCOPES = JSON_ARRAY() WHERE BSCOPES IS NULL');
        $this->addSql("UPDATE BAPIKEYS SET BNAME = '' WHERE BNAME IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BAPIKEYS
              CHANGE BSCOPES BSCOPES JSON NOT NULL,
              CHANGE BNAME   BNAME   VARCHAR(128) DEFAULT '' NOT NULL
        SQL);

        // ---- BSESSIONS -----------------------------------------------------
        $this->addSql("UPDATE BSESSIONS SET BIPADDRESS = '' WHERE BIPADDRESS IS NULL");
        $this->addSql("UPDATE BSESSIONS SET BUSERAGENT = '' WHERE BUSERAGENT IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BSESSIONS
              CHANGE BIPADDRESS BIPADDRESS VARCHAR(45)  DEFAULT '' NOT NULL,
              CHANGE BUSERAGENT BUSERAGENT VARCHAR(255) DEFAULT '' NOT NULL
        SQL);

        // ---- BSEARCHRESULTS -----------------------------------------------
        $this->addSql(<<<'SQL'
            ALTER TABLE BSEARCHRESULTS
              CHANGE BPUBLISHED     BPUBLISHED     VARCHAR(100) DEFAULT NULL,
              CHANGE BSOURCE        BSOURCE        VARCHAR(255) DEFAULT NULL,
              CHANGE BEXTRASNIPPETS BEXTRASNIPPETS JSON DEFAULT NULL
        SQL);

        // ---- BFILES --------------------------------------------------------
        $this->addSql("UPDATE BFILES SET BSTATUS = 'uploaded' WHERE BSTATUS IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BFILES
              CHANGE BSTATUS   BSTATUS   VARCHAR(32) DEFAULT 'uploaded' NOT NULL,
              CHANGE BGROUPKEY BGROUPKEY VARCHAR(128) DEFAULT NULL
        SQL);

        // ---- BMODELS -------------------------------------------------------
        $this->addSql('UPDATE BMODELS SET BJSON = JSON_OBJECT() WHERE BJSON IS NULL');
        $this->addSql('ALTER TABLE BMODELS CHANGE BJSON BJSON JSON NOT NULL');

        // ---- plugin_data ---------------------------------------------------
        $this->addSql('UPDATE plugin_data SET data = JSON_OBJECT() WHERE data IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE plugin_data
              CHANGE data_key data_key VARCHAR(255) DEFAULT NULL,
              CHANGE data     data     JSON NOT NULL
        SQL);

        // ---- BMODEL_PRICE_HISTORY -----------------------------------------
        $this->addSql("UPDATE BMODEL_PRICE_HISTORY SET BINUNIT = 'per1M' WHERE BINUNIT IS NULL");
        $this->addSql("UPDATE BMODEL_PRICE_HISTORY SET BOUTUNIT = 'per1M' WHERE BOUTUNIT IS NULL");
        $this->addSql("UPDATE BMODEL_PRICE_HISTORY SET BSOURCE = 'manual' WHERE BSOURCE IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BMODEL_PRICE_HISTORY
              CHANGE BINUNIT       BINUNIT       VARCHAR(24)    DEFAULT 'per1M'  NOT NULL,
              CHANGE BOUTUNIT      BOUTUNIT      VARCHAR(24)    DEFAULT 'per1M'  NOT NULL,
              CHANGE BCACHEPRICEIN BCACHEPRICEIN NUMERIC(10, 8) DEFAULT NULL,
              CHANGE BSOURCE       BSOURCE       VARCHAR(32)    DEFAULT 'manual' NOT NULL,
              CHANGE BVALID_TO     BVALID_TO     DATETIME       DEFAULT NULL
        SQL);

        // ---- BWIDGET_SESSIONS ---------------------------------------------
        $this->addSql(<<<'SQL'
            ALTER TABLE BWIDGET_SESSIONS
              CHANGE BMODE                 BMODE                 VARCHAR(16)  DEFAULT 'ai',
              CHANGE BLAST_MESSAGE_PREVIEW BLAST_MESSAGE_PREVIEW VARCHAR(255) DEFAULT NULL,
              CHANGE BCOUNTRY              BCOUNTRY              VARCHAR(2)   DEFAULT NULL,
              CHANGE BTITLE                BTITLE                VARCHAR(100) DEFAULT NULL,
              CHANGE BCUSTOM_FIELD_VALUES  BCUSTOM_FIELD_VALUES  JSON         DEFAULT NULL
        SQL);

        // ---- BUSELOG -------------------------------------------------------
        $this->addSql("UPDATE BUSELOG SET BPROVIDER = '' WHERE BPROVIDER IS NULL");
        $this->addSql("UPDATE BUSELOG SET BMODEL = '' WHERE BMODEL IS NULL");
        $this->addSql('UPDATE BUSELOG SET BCOST = 0 WHERE BCOST IS NULL');
        $this->addSql("UPDATE BUSELOG SET BSTATUS = 'success' WHERE BSTATUS IS NULL");
        $this->addSql('UPDATE BUSELOG SET BMETADATA = JSON_OBJECT() WHERE BMETADATA IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE BUSELOG
              CHANGE BPROVIDER       BPROVIDER       VARCHAR(32)    DEFAULT ''        NOT NULL,
              CHANGE BMODEL          BMODEL          VARCHAR(128)   DEFAULT ''        NOT NULL,
              CHANGE BPRICE_SNAPSHOT BPRICE_SNAPSHOT JSON           DEFAULT NULL,
              CHANGE BCOST           BCOST           NUMERIC(10, 6) DEFAULT '0'       NOT NULL,
              CHANGE BSTATUS         BSTATUS         VARCHAR(16)    DEFAULT 'success' NOT NULL,
              CHANGE BMETADATA       BMETADATA       JSON                             NOT NULL
        SQL);

        // ---- BGUEST_SESSIONS ----------------------------------------------
        $this->addSql(<<<'SQL'
            ALTER TABLE BGUEST_SESSIONS
              CHANGE BIPADDRESS BIPADDRESS VARCHAR(45) DEFAULT NULL,
              CHANGE BCOUNTRY   BCOUNTRY   VARCHAR(2)  DEFAULT NULL
        SQL);

        // ---- BWIDGETS ------------------------------------------------------
        $this->addSql('UPDATE BWIDGETS SET BCONFIG = JSON_OBJECT() WHERE BCONFIG IS NULL');
        $this->addSql('UPDATE BWIDGETS SET BALLOWED_DOMAINS = JSON_ARRAY() WHERE BALLOWED_DOMAINS IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE BWIDGETS
              CHANGE BCONFIG          BCONFIG          JSON NOT NULL,
              CHANGE BALLOWED_DOMAINS BALLOWED_DOMAINS JSON NOT NULL
        SQL);

        // ---- BCHATS --------------------------------------------------------
        $this->addSql("UPDATE BCHATS SET BSOURCE = 'web' WHERE BSOURCE IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BCHATS
              CHANGE BTITLE        BTITLE        VARCHAR(255) DEFAULT NULL,
              CHANGE BSHARETOKEN   BSHARETOKEN   VARCHAR(64)  DEFAULT NULL,
              CHANGE BSOURCE       BSOURCE       VARCHAR(16)  DEFAULT 'web' NOT NULL,
              CHANGE BOGIMAGEPATH  BOGIMAGEPATH  VARCHAR(255) DEFAULT NULL
        SQL);

        // ---- BINBOUNDEMAILHANDLER -----------------------------------------
        $this->addSql("UPDATE BINBOUNDEMAILHANDLER SET BSTATUS = 'inactive' WHERE BSTATUS IS NULL");
        $this->addSql('UPDATE BINBOUNDEMAILHANDLER SET BDEPARTMENTS = JSON_ARRAY() WHERE BDEPARTMENTS IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE BINBOUNDEMAILHANDLER
              CHANGE BSTATUS      BSTATUS      VARCHAR(20) DEFAULT 'inactive' NOT NULL,
              CHANGE BDEPARTMENTS BDEPARTMENTS JSON                            NOT NULL,
              CHANGE BLASTCHECKED BLASTCHECKED VARCHAR(20) DEFAULT NULL,
              CHANGE BCONFIG      BCONFIG      JSON        DEFAULT NULL
        SQL);

        // ---- BMESSAGES -----------------------------------------------------
        $this->addSql("UPDATE BMESSAGES SET BMESSTYPE = 'WA' WHERE BMESSTYPE IS NULL");
        $this->addSql("UPDATE BMESSAGES SET BTOPIC = 'UNKNOWN' WHERE BTOPIC IS NULL");
        $this->addSql("UPDATE BMESSAGES SET BLANG = 'NN' WHERE BLANG IS NULL");
        $this->addSql("UPDATE BMESSAGES SET BDIRECT = 'OUT' WHERE BDIRECT IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BMESSAGES
              CHANGE BMESSTYPE BMESSTYPE VARCHAR(4)   DEFAULT 'WA'      NOT NULL,
              CHANGE BTOPIC    BTOPIC    VARCHAR(255) DEFAULT 'UNKNOWN' NOT NULL,
              CHANGE BLANG     BLANG     VARCHAR(2)   DEFAULT 'NN'      NOT NULL,
              CHANGE BDIRECT   BDIRECT   VARCHAR(3)   DEFAULT 'OUT'     NOT NULL
        SQL);

        // ---- BEMAILVERIFICATION -------------------------------------------
        $this->addSql('ALTER TABLE BEMAILVERIFICATION CHANGE BIPADDRESS BIPADDRESS VARCHAR(45) DEFAULT NULL');

        // ---- messenger_messages -------------------------------------------
        // Doctrine ORM expects a `(DC2Type:datetime_immutable)` comment on the
        // `delivered_at` column so it re-hydrates as DateTimeImmutable. The
        // baseline migration omitted the comment; the column type itself is
        // already correct.
        $this->addSql("ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        // Restore each touched column to its **baseline** definition
        // (Version20260417000000). For columns where up() was a no-op against
        // the baseline-created schema, this down() is also a no-op — but it
        // still emits the ALTER so the migration is a faithful "round trip"
        // against any starting state (legacy DB or canonical baseline DB).
        //
        // The only column where up() introduced a real change against the
        // baseline is BAPIKEYS.BNAME (baseline has no DEFAULT; up() added
        // DEFAULT ''). All other ALTERs here just re-assert the baseline.
        //
        // We do NOT re-NULL rows that up() backfilled — there's no way to
        // know which were originally NULL, and the entity types tolerate the
        // empty string / empty JSON values either way.
        $this->addSql(<<<'SQL'
            ALTER TABLE BAPIKEYS
              CHANGE BSCOPES BSCOPES JSON         NOT NULL,
              CHANGE BNAME   BNAME   VARCHAR(128) NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BCHATS
              CHANGE BTITLE       BTITLE       VARCHAR(255) DEFAULT NULL,
              CHANGE BSHARETOKEN  BSHARETOKEN  VARCHAR(64)  DEFAULT NULL,
              CHANGE BSOURCE      BSOURCE      VARCHAR(16)  DEFAULT 'web' NOT NULL,
              CHANGE BOGIMAGEPATH BOGIMAGEPATH VARCHAR(255) DEFAULT NULL
        SQL);

        $this->addSql('ALTER TABLE BEMAILVERIFICATION CHANGE BIPADDRESS BIPADDRESS VARCHAR(45) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE BFILES
              CHANGE BSTATUS   BSTATUS   VARCHAR(32)  DEFAULT 'uploaded' NOT NULL,
              CHANGE BGROUPKEY BGROUPKEY VARCHAR(128) DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BGUEST_SESSIONS
              CHANGE BIPADDRESS BIPADDRESS VARCHAR(45) DEFAULT NULL,
              CHANGE BCOUNTRY   BCOUNTRY   VARCHAR(2)  DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BINBOUNDEMAILHANDLER
              CHANGE BSTATUS      BSTATUS      VARCHAR(20) DEFAULT 'inactive' NOT NULL,
              CHANGE BDEPARTMENTS BDEPARTMENTS JSON                            NOT NULL,
              CHANGE BLASTCHECKED BLASTCHECKED VARCHAR(20) DEFAULT NULL,
              CHANGE BCONFIG      BCONFIG      JSON        DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BMESSAGES
              CHANGE BMESSTYPE BMESSTYPE VARCHAR(4)   DEFAULT 'WA'      NOT NULL,
              CHANGE BTOPIC    BTOPIC    VARCHAR(255) DEFAULT 'UNKNOWN' NOT NULL,
              CHANGE BLANG     BLANG     VARCHAR(2)   DEFAULT 'NN'      NOT NULL,
              CHANGE BDIRECT   BDIRECT   VARCHAR(3)   DEFAULT 'OUT'     NOT NULL
        SQL);

        $this->addSql('ALTER TABLE BMODELS CHANGE BJSON BJSON JSON NOT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE BMODEL_PRICE_HISTORY
              CHANGE BINUNIT       BINUNIT       VARCHAR(24)    DEFAULT 'per1M'  NOT NULL,
              CHANGE BOUTUNIT      BOUTUNIT      VARCHAR(24)    DEFAULT 'per1M'  NOT NULL,
              CHANGE BCACHEPRICEIN BCACHEPRICEIN NUMERIC(10, 8) DEFAULT NULL,
              CHANGE BSOURCE       BSOURCE       VARCHAR(32)    DEFAULT 'manual' NOT NULL,
              CHANGE BVALID_TO     BVALID_TO     DATETIME       DEFAULT NULL
        SQL);

        $this->addSql('ALTER TABLE BPROMPTS CHANGE BLANG BLANG VARCHAR(2) DEFAULT \'en\' NOT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE BSEARCHRESULTS
              CHANGE BPUBLISHED     BPUBLISHED     VARCHAR(100) DEFAULT NULL,
              CHANGE BSOURCE        BSOURCE        VARCHAR(255) DEFAULT NULL,
              CHANGE BEXTRASNIPPETS BEXTRASNIPPETS JSON         DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BSESSIONS
              CHANGE BIPADDRESS BIPADDRESS VARCHAR(45)  DEFAULT '' NOT NULL,
              CHANGE BUSERAGENT BUSERAGENT VARCHAR(255) DEFAULT '' NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BSUBSCRIPTIONS
              CHANGE BCOST_BUDGET_MONTHLY BCOST_BUDGET_MONTHLY NUMERIC(10, 2) DEFAULT '0' NOT NULL,
              CHANGE BCOST_BUDGET_YEARLY  BCOST_BUDGET_YEARLY  NUMERIC(10, 2) DEFAULT '0' NOT NULL,
              CHANGE BSTRIPE_MONTHLY_ID   BSTRIPE_MONTHLY_ID   VARCHAR(128)   DEFAULT NULL,
              CHANGE BSTRIPE_YEARLY_ID    BSTRIPE_YEARLY_ID    VARCHAR(128)   DEFAULT NULL
        SQL);

        $this->addSql("ALTER TABLE BTOKENS CHANGE BIPADDRESS BIPADDRESS VARCHAR(45) DEFAULT '' NOT NULL");

        $this->addSql(<<<'SQL'
            ALTER TABLE BUSELOG
              CHANGE BPROVIDER       BPROVIDER       VARCHAR(32)    DEFAULT ''        NOT NULL,
              CHANGE BMODEL          BMODEL          VARCHAR(128)   DEFAULT ''        NOT NULL,
              CHANGE BPRICE_SNAPSHOT BPRICE_SNAPSHOT JSON           DEFAULT NULL,
              CHANGE BCOST           BCOST           NUMERIC(10, 6) DEFAULT '0'       NOT NULL,
              CHANGE BSTATUS         BSTATUS         VARCHAR(16)    DEFAULT 'success' NOT NULL,
              CHANGE BMETADATA       BMETADATA       JSON                             NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BUSER
              CHANGE BINTYPE         BINTYPE         VARCHAR(16) DEFAULT 'WEB' NOT NULL,
              CHANGE BPW             BPW             VARCHAR(64) DEFAULT NULL,
              CHANGE BUSERLEVEL      BUSERLEVEL      VARCHAR(32) DEFAULT 'NEW' NOT NULL,
              CHANGE BUSERDETAILS    BUSERDETAILS    JSON                      NOT NULL,
              CHANGE BPAYMENTDETAILS BPAYMENTDETAILS JSON                      NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BWIDGETS
              CHANGE BCONFIG          BCONFIG          JSON NOT NULL,
              CHANGE BALLOWED_DOMAINS BALLOWED_DOMAINS JSON NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BWIDGET_SESSIONS
              CHANGE BMODE                 BMODE                 VARCHAR(16)  DEFAULT 'ai',
              CHANGE BLAST_MESSAGE_PREVIEW BLAST_MESSAGE_PREVIEW VARCHAR(255) DEFAULT NULL,
              CHANGE BCOUNTRY              BCOUNTRY              VARCHAR(2)   DEFAULT NULL,
              CHANGE BTITLE                BTITLE                VARCHAR(100) DEFAULT NULL,
              CHANGE BCUSTOM_FIELD_VALUES  BCUSTOM_FIELD_VALUES  JSON         DEFAULT NULL
        SQL);

        $this->addSql('ALTER TABLE BWIDGET_SUMMARIES CHANGE BAI_MODEL BAI_MODEL VARCHAR(64) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE plugin_data
              CHANGE data_key data_key VARCHAR(255) DEFAULT NULL,
              CHANGE data     data     JSON         NOT NULL
        SQL);

        // Drop the entity-required `(DC2Type:datetime_immutable)` comment to
        // restore the baseline state which omitted it.
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT NULL');
    }
}
