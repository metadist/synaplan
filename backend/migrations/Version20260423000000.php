<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reconcile baseline DB schema with current ORM entity definitions.
 *
 * Background: the baseline migration (Version20260417000000) was generated via
 * `dump-schema` from a legacy production database that had accumulated drift
 * over the years (missing column defaults, columns nullable when the entity
 * marks them required, JSON columns nullable when entities default to `[]`).
 * As a result, `doctrine:schema:validate` in CI had to be invoked with
 * `--skip-sync` to be useful at all.
 *
 * This migration brings every drifted column up to the entity-level definition
 * so a full `doctrine:schema:validate` (without `--skip-sync`) passes. After
 * this lands, CI can drop the `--skip-sync` flag and start failing on real
 * entity↔DB drift introduced by future PRs.
 *
 * Each NOT NULL transition is preceded by a defensive backfill UPDATE so the
 * migration is safe to run on production databases that may have legacy NULL
 * rows. The backfill values match the entity-level defaults exactly.
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
        // Reverse to the baseline state. We do NOT re-NULL the rows backfilled
        // in up() — there's no way to know which were originally NULL. We DO
        // backfill rows that became NULL while this migration was active (i.e.
        // for columns we made nullable in up() and now want to make NOT NULL
        // again), otherwise MariaDB strict mode aborts the ALTER.
        $this->addSql('ALTER TABLE messenger_messages CHANGE delivered_at delivered_at DATETIME DEFAULT NULL');

        $this->addSql("UPDATE BEMAILVERIFICATION SET BIPADDRESS = '' WHERE BIPADDRESS IS NULL");
        $this->addSql('ALTER TABLE BEMAILVERIFICATION CHANGE BIPADDRESS BIPADDRESS VARCHAR(45) NOT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE BMESSAGES
              CHANGE BMESSTYPE BMESSTYPE VARCHAR(4)   NOT NULL,
              CHANGE BTOPIC    BTOPIC    VARCHAR(255) NOT NULL,
              CHANGE BLANG     BLANG     VARCHAR(2)   NOT NULL,
              CHANGE BDIRECT   BDIRECT   VARCHAR(3)   NOT NULL
        SQL);

        $this->addSql("UPDATE BINBOUNDEMAILHANDLER SET BLASTCHECKED = '' WHERE BLASTCHECKED IS NULL");
        $this->addSql('UPDATE BINBOUNDEMAILHANDLER SET BCONFIG = JSON_OBJECT() WHERE BCONFIG IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE BINBOUNDEMAILHANDLER
              CHANGE BSTATUS      BSTATUS      VARCHAR(20) NOT NULL,
              CHANGE BDEPARTMENTS BDEPARTMENTS JSON DEFAULT NULL,
              CHANGE BLASTCHECKED BLASTCHECKED VARCHAR(20) NOT NULL,
              CHANGE BCONFIG      BCONFIG      JSON NOT NULL
        SQL);

        $this->addSql("UPDATE BCHATS SET BTITLE       = '' WHERE BTITLE       IS NULL");
        $this->addSql("UPDATE BCHATS SET BSHARETOKEN  = '' WHERE BSHARETOKEN  IS NULL");
        $this->addSql("UPDATE BCHATS SET BOGIMAGEPATH = '' WHERE BOGIMAGEPATH IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BCHATS
              CHANGE BTITLE       BTITLE       VARCHAR(255) NOT NULL,
              CHANGE BSHARETOKEN  BSHARETOKEN  VARCHAR(64)  NOT NULL,
              CHANGE BSOURCE      BSOURCE      VARCHAR(16)  NOT NULL,
              CHANGE BOGIMAGEPATH BOGIMAGEPATH VARCHAR(255) NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BWIDGETS
              CHANGE BCONFIG          BCONFIG          JSON DEFAULT NULL,
              CHANGE BALLOWED_DOMAINS BALLOWED_DOMAINS JSON DEFAULT NULL
        SQL);

        $this->addSql("UPDATE BGUEST_SESSIONS SET BIPADDRESS = '' WHERE BIPADDRESS IS NULL");
        $this->addSql("UPDATE BGUEST_SESSIONS SET BCOUNTRY = '' WHERE BCOUNTRY IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BGUEST_SESSIONS
              CHANGE BIPADDRESS BIPADDRESS VARCHAR(45) NOT NULL,
              CHANGE BCOUNTRY   BCOUNTRY   VARCHAR(2)  NOT NULL
        SQL);

        $this->addSql('UPDATE BUSELOG SET BPRICE_SNAPSHOT = JSON_OBJECT() WHERE BPRICE_SNAPSHOT IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE BUSELOG
              CHANGE BPROVIDER       BPROVIDER       VARCHAR(32)    NOT NULL,
              CHANGE BMODEL          BMODEL          VARCHAR(128)   NOT NULL,
              CHANGE BPRICE_SNAPSHOT BPRICE_SNAPSHOT JSON           NOT NULL,
              CHANGE BCOST           BCOST           NUMERIC(10, 6) NOT NULL,
              CHANGE BSTATUS         BSTATUS         VARCHAR(16)    NOT NULL,
              CHANGE BMETADATA       BMETADATA       JSON           DEFAULT NULL
        SQL);

        $this->addSql("UPDATE BWIDGET_SESSIONS SET BMODE = 'ai' WHERE BMODE IS NULL");
        $this->addSql("UPDATE BWIDGET_SESSIONS SET BLAST_MESSAGE_PREVIEW = '' WHERE BLAST_MESSAGE_PREVIEW IS NULL");
        $this->addSql("UPDATE BWIDGET_SESSIONS SET BCOUNTRY = '' WHERE BCOUNTRY IS NULL");
        $this->addSql("UPDATE BWIDGET_SESSIONS SET BTITLE = '' WHERE BTITLE IS NULL");
        $this->addSql('UPDATE BWIDGET_SESSIONS SET BCUSTOM_FIELD_VALUES = JSON_OBJECT() WHERE BCUSTOM_FIELD_VALUES IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE BWIDGET_SESSIONS
              CHANGE BMODE                 BMODE                 VARCHAR(16)  NOT NULL,
              CHANGE BLAST_MESSAGE_PREVIEW BLAST_MESSAGE_PREVIEW VARCHAR(255) NOT NULL,
              CHANGE BCOUNTRY              BCOUNTRY              VARCHAR(2)   NOT NULL,
              CHANGE BTITLE                BTITLE                VARCHAR(100) NOT NULL,
              CHANGE BCUSTOM_FIELD_VALUES  BCUSTOM_FIELD_VALUES  JSON         NOT NULL
        SQL);

        $this->addSql('UPDATE BMODEL_PRICE_HISTORY SET BCACHEPRICEIN = 0 WHERE BCACHEPRICEIN IS NULL');
        $this->addSql("UPDATE BMODEL_PRICE_HISTORY SET BVALID_TO = '1970-01-01 00:00:00' WHERE BVALID_TO IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BMODEL_PRICE_HISTORY
              CHANGE BINUNIT       BINUNIT       VARCHAR(24)    NOT NULL,
              CHANGE BOUTUNIT      BOUTUNIT      VARCHAR(24)    NOT NULL,
              CHANGE BCACHEPRICEIN BCACHEPRICEIN NUMERIC(10, 8) NOT NULL,
              CHANGE BSOURCE       BSOURCE       VARCHAR(32)    NOT NULL,
              CHANGE BVALID_TO     BVALID_TO     DATETIME       NOT NULL
        SQL);

        $this->addSql("UPDATE plugin_data SET data_key = '' WHERE data_key IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE plugin_data
              CHANGE data_key data_key VARCHAR(255) NOT NULL,
              CHANGE data     data     JSON         DEFAULT NULL
        SQL);

        $this->addSql('ALTER TABLE BMODELS CHANGE BJSON BJSON JSON DEFAULT NULL');

        $this->addSql("UPDATE BFILES SET BGROUPKEY = '' WHERE BGROUPKEY IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BFILES
              CHANGE BSTATUS   BSTATUS   VARCHAR(32)  NOT NULL,
              CHANGE BGROUPKEY BGROUPKEY VARCHAR(128) NOT NULL
        SQL);

        $this->addSql("UPDATE BSEARCHRESULTS SET BPUBLISHED = '' WHERE BPUBLISHED IS NULL");
        $this->addSql("UPDATE BSEARCHRESULTS SET BSOURCE = '' WHERE BSOURCE IS NULL");
        $this->addSql('UPDATE BSEARCHRESULTS SET BEXTRASNIPPETS = JSON_OBJECT() WHERE BEXTRASNIPPETS IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE BSEARCHRESULTS
              CHANGE BPUBLISHED     BPUBLISHED     VARCHAR(100) NOT NULL,
              CHANGE BSOURCE        BSOURCE        VARCHAR(255) NOT NULL,
              CHANGE BEXTRASNIPPETS BEXTRASNIPPETS JSON         NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BSESSIONS
              CHANGE BIPADDRESS BIPADDRESS VARCHAR(45)  NOT NULL,
              CHANGE BUSERAGENT BUSERAGENT VARCHAR(255) NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE BAPIKEYS
              CHANGE BSCOPES BSCOPES JSON         DEFAULT NULL,
              CHANGE BNAME   BNAME   VARCHAR(128) NOT NULL
        SQL);

        $this->addSql('ALTER TABLE BTOKENS CHANGE BIPADDRESS BIPADDRESS VARCHAR(45) NOT NULL');

        $this->addSql("UPDATE BWIDGET_SUMMARIES SET BAI_MODEL = '' WHERE BAI_MODEL IS NULL");
        $this->addSql('ALTER TABLE BWIDGET_SUMMARIES CHANGE BAI_MODEL BAI_MODEL VARCHAR(64) NOT NULL');

        $this->addSql('ALTER TABLE BPROMPTS CHANGE BLANG BLANG VARCHAR(2) NOT NULL');

        $this->addSql("UPDATE BSUBSCRIPTIONS SET BSTRIPE_MONTHLY_ID = '' WHERE BSTRIPE_MONTHLY_ID IS NULL");
        $this->addSql("UPDATE BSUBSCRIPTIONS SET BSTRIPE_YEARLY_ID = '' WHERE BSTRIPE_YEARLY_ID IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BSUBSCRIPTIONS
              CHANGE BCOST_BUDGET_MONTHLY BCOST_BUDGET_MONTHLY NUMERIC(10, 2) NOT NULL,
              CHANGE BCOST_BUDGET_YEARLY  BCOST_BUDGET_YEARLY  NUMERIC(10, 2) NOT NULL,
              CHANGE BSTRIPE_MONTHLY_ID   BSTRIPE_MONTHLY_ID   VARCHAR(128)   NOT NULL,
              CHANGE BSTRIPE_YEARLY_ID    BSTRIPE_YEARLY_ID    VARCHAR(128)   NOT NULL
        SQL);

        $this->addSql("UPDATE BUSER SET BPW = '' WHERE BPW IS NULL");
        $this->addSql(<<<'SQL'
            ALTER TABLE BUSER
              CHANGE BINTYPE         BINTYPE         VARCHAR(16) NOT NULL,
              CHANGE BPW             BPW             VARCHAR(64) NOT NULL,
              CHANGE BUSERLEVEL      BUSERLEVEL      VARCHAR(32) NOT NULL,
              CHANGE BUSERDETAILS    BUSERDETAILS    JSON DEFAULT NULL,
              CHANGE BPAYMENTDETAILS BPAYMENTDETAILS JSON DEFAULT NULL
        SQL);
    }
}
