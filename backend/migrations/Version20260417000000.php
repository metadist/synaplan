<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline migration: full schema snapshot as of 2026-04-17.
 *
 * On existing databases (legacy production), this migration is marked as already
 * applied via the docker entrypoint bootstrap (manual `doctrine_migration_versions`
 * INSERT IGNORE) and never executed against existing tables.
 *
 * On fresh databases (dev, test, CI), this creates the entire schema in one shot.
 *
 * Targets MariaDB 11.7+ (BRAG.BEMBED requires native VECTOR(1024) support).
 * All tables use InnoDB + utf8mb4 + utf8mb4_unicode_ci.
 */
final class Version20260417000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline schema (initial migration capturing all entities + messenger_messages)';
    }

    public function isTransactional(): bool
    {
        // MariaDB does not support DDL inside transactions.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE BAPIKEYS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BOWNERID BIGINT NOT NULL,
              BKEY VARCHAR(64) NOT NULL,
              BSTATUS VARCHAR(16) NOT NULL,
              BLASTUSED BIGINT DEFAULT 0 NOT NULL,
              BSCOPES JSON NOT NULL,
              BCREATED BIGINT NOT NULL,
              BNAME VARCHAR(128) DEFAULT '' NOT NULL,
              UNIQUE INDEX UNIQ_16D420BFF32F7F9B (BKEY),
              INDEX idx_apikey_key (BKEY),
              INDEX idx_apikey_owner (BOWNERID),
              INDEX idx_apikey_status (BSTATUS),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BCHATS (
              BID INT AUTO_INCREMENT NOT NULL,
              BUSERID INT NOT NULL,
              BTITLE VARCHAR(255) DEFAULT NULL,
              BCREATEDAT DATETIME NOT NULL,
              BUPDATEDAT DATETIME NOT NULL,
              BSHARETOKEN VARCHAR(64) DEFAULT NULL,
              BISPUBLIC TINYINT(1) DEFAULT 0 NOT NULL,
              BSOURCE VARCHAR(16) DEFAULT 'web' NOT NULL,
              BOGIMAGEPATH VARCHAR(255) DEFAULT NULL,
              UNIQUE INDEX UNIQ_EF4C2766B263B832 (BSHARETOKEN),
              INDEX idx_chat_user (BUSERID),
              INDEX idx_chat_share (BSHARETOKEN),
              INDEX idx_chat_source (BSOURCE),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BCONFIG (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BOWNERID BIGINT NOT NULL,
              BGROUP VARCHAR(64) NOT NULL,
              BSETTING VARCHAR(96) NOT NULL,
              BVALUE VARCHAR(250) NOT NULL,
              INDEX idx_config_lookup (BOWNERID, BGROUP, BSETTING),
              INDEX idx_group (BGROUP),
              INDEX idx_setting (BSETTING),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BEMAILVERIFICATION (
              BID INT AUTO_INCREMENT NOT NULL,
              BEMAIL VARCHAR(255) NOT NULL,
              BATTEMPTS INT DEFAULT 1 NOT NULL,
              BLASTATTEMPTAT DATETIME NOT NULL,
              BCREATEDAT DATETIME NOT NULL,
              BIPADDRESS VARCHAR(45) DEFAULT NULL,
              INDEX idx_email (BEMAIL),
              INDEX idx_created (BCREATEDAT),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BFILES (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BUSERID BIGINT NOT NULL,
              BUSERSESSIONID BIGINT DEFAULT NULL,
              BFILEPATH VARCHAR(255) NOT NULL,
              BFILETYPE VARCHAR(16) NOT NULL,
              BFILENAME VARCHAR(255) NOT NULL,
              BFILESIZE INT NOT NULL,
              BFILEMIME VARCHAR(128) NOT NULL,
              BFILETEXT LONGTEXT NOT NULL,
              BSTATUS VARCHAR(32) DEFAULT 'uploaded' NOT NULL,
              BGROUPKEY VARCHAR(128) DEFAULT NULL,
              BCREATEDAT BIGINT NOT NULL,
              INDEX idx_file_user (BUSERID),
              INDEX idx_file_session (BUSERSESSIONID),
              INDEX idx_file_type (BFILETYPE),
              INDEX idx_file_status (BSTATUS),
              INDEX idx_file_groupkey (BGROUPKEY),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BGUEST_SESSIONS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BSESSIONID VARCHAR(64) NOT NULL,
              BMESSAGECOUNT INT NOT NULL,
              BMAXMESSAGES INT NOT NULL,
              BCHATID BIGINT DEFAULT NULL,
              BIPADDRESS VARCHAR(45) DEFAULT NULL,
              BCOUNTRY VARCHAR(2) DEFAULT NULL,
              BCREATED BIGINT NOT NULL,
              BEXPIRES BIGINT NOT NULL,
              UNIQUE INDEX UNIQ_E8861D3E77DA24FE (BSESSIONID),
              INDEX idx_guest_session_id (BSESSIONID),
              INDEX idx_guest_expires (BEXPIRES),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BINBOUNDEMAILHANDLER (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BUSERID BIGINT NOT NULL,
              BNAME VARCHAR(255) NOT NULL,
              BMAILSERVER VARCHAR(255) NOT NULL,
              BPORT INT NOT NULL,
              BPROTOCOL VARCHAR(10) NOT NULL,
              BSECURITY VARCHAR(20) NOT NULL,
              BUSERNAME VARCHAR(255) NOT NULL,
              BPASSWORD LONGTEXT NOT NULL,
              BCHECKINTERVAL INT NOT NULL,
              BDELETEAFTER TINYINT(1) DEFAULT 0 NOT NULL,
              BSTATUS VARCHAR(20) DEFAULT 'inactive' NOT NULL,
              BDEPARTMENTS JSON NOT NULL,
              BLASTCHECKED VARCHAR(20) DEFAULT NULL,
              BCREATED VARCHAR(20) NOT NULL,
              BUPDATED VARCHAR(20) NOT NULL,
              BCONFIG JSON DEFAULT NULL,
              INDEX idx_user (BUSERID),
              INDEX idx_status (BSTATUS),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BMESSAGEMETA (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BMESSAGEID BIGINT NOT NULL,
              BMETAKEY VARCHAR(64) NOT NULL,
              BMETAVALUE LONGTEXT NOT NULL,
              BCREATED BIGINT NOT NULL,
              INDEX idx_messagemeta_message (BMESSAGEID),
              INDEX idx_messagemeta_key (BMETAKEY),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BMESSAGES (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BUSERID BIGINT NOT NULL,
              BCHATID INT DEFAULT NULL,
              BTRACKID BIGINT NOT NULL,
              BPROVIDX VARCHAR(96) NOT NULL,
              BUNIXTIMES BIGINT NOT NULL,
              BDATETIME VARCHAR(20) NOT NULL,
              BMESSTYPE VARCHAR(4) DEFAULT 'WA' NOT NULL,
              BFILE SMALLINT NOT NULL,
              BFILEPATH LONGTEXT NOT NULL,
              BFILETYPE VARCHAR(8) NOT NULL,
              BTOPIC VARCHAR(255) DEFAULT 'UNKNOWN' NOT NULL,
              BLANG VARCHAR(2) DEFAULT 'NN' NOT NULL,
              BTEXT LONGTEXT NOT NULL,
              BDIRECT VARCHAR(3) DEFAULT 'OUT' NOT NULL,
              BSTATUS VARCHAR(24) NOT NULL,
              BFILETEXT LONGTEXT NOT NULL,
              INDEX BUSERID (BUSERID),
              INDEX BTRACKID (BTRACKID),
              INDEX BMESSTYPE (BMESSTYPE),
              INDEX BFILE (BFILE),
              INDEX BDIRECT (BDIRECT),
              INDEX BLANG (BLANG),
              INDEX BTOPIC (BTOPIC),
              INDEX idx_message_chat (BCHATID),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BMESSAGE_FILE_ATTACHMENTS (
              BMESSAGEID BIGINT NOT NULL,
              BFILEID BIGINT NOT NULL,
              INDEX IDX_BE254C38EA4931D2 (BMESSAGEID),
              INDEX IDX_BE254C383583B10C (BFILEID),
              PRIMARY KEY(BMESSAGEID, BFILEID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BMODELS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BSERVICE VARCHAR(32) NOT NULL,
              BNAME VARCHAR(48) NOT NULL,
              BTAG VARCHAR(24) NOT NULL,
              BSELECTABLE INT NOT NULL,
              BPROVID VARCHAR(96) NOT NULL,
              BPRICEIN DOUBLE PRECISION NOT NULL,
              BINUNIT VARCHAR(24) NOT NULL,
              BPRICEOUT DOUBLE PRECISION NOT NULL,
              BOUTUNIT VARCHAR(24) NOT NULL,
              BQUALITY DOUBLE PRECISION NOT NULL,
              BRATING DOUBLE PRECISION NOT NULL,
              BISDEFAULT INT DEFAULT 0 NOT NULL,
              BACTIVE INT DEFAULT 1 NOT NULL,
              BSHOWWHENFREE INT DEFAULT 0 NOT NULL,
              BDESCRIPTION LONGTEXT DEFAULT NULL,
              BJSON JSON NOT NULL,
              INDEX idx_tag (BTAG),
              INDEX idx_service (BSERVICE),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BMODEL_PRICE_HISTORY (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BPRICEIN NUMERIC(10, 8) NOT NULL,
              BINUNIT VARCHAR(24) DEFAULT 'per1M' NOT NULL,
              BPRICEOUT NUMERIC(10, 8) NOT NULL,
              BOUTUNIT VARCHAR(24) DEFAULT 'per1M' NOT NULL,
              BCACHEPRICEIN NUMERIC(10, 8) DEFAULT NULL,
              BSOURCE VARCHAR(32) DEFAULT 'manual' NOT NULL,
              BVALID_FROM DATETIME NOT NULL,
              BVALID_TO DATETIME DEFAULT NULL,
              BCREATED_AT DATETIME NOT NULL,
              BMODEL_ID BIGINT NOT NULL,
              INDEX idx_mph_model (BMODEL_ID),
              INDEX idx_mph_valid_from (BVALID_FROM),
              INDEX idx_mph_valid_to (BVALID_TO),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BPROMPTMETA (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BPROMPTID BIGINT NOT NULL,
              BMETAKEY VARCHAR(64) NOT NULL,
              BMETAVALUE LONGTEXT NOT NULL,
              BCREATED BIGINT NOT NULL,
              INDEX idx_promptmeta_prompt (BPROMPTID),
              INDEX idx_promptmeta_key (BMETAKEY),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BPROMPTS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BOWNERID BIGINT NOT NULL,
              BLANG VARCHAR(2) DEFAULT 'en' NOT NULL,
              BTOPIC VARCHAR(64) NOT NULL,
              BSHORTDESC LONGTEXT NOT NULL,
              BPROMPT LONGTEXT NOT NULL,
              BSELECTION_RULES LONGTEXT DEFAULT NULL,
              INDEX BTOPIC (BTOPIC),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BRAG (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BUID BIGINT NOT NULL,
              BMID BIGINT NOT NULL,
              BGROUPKEY VARCHAR(64) NOT NULL,
              BTYPE INT NOT NULL,
              BSTART INT NOT NULL,
              BEND INT NOT NULL,
              BTEXT LONGTEXT NOT NULL,
              BEMBED VECTOR(1024) NOT NULL COMMENT '(DC2Type:vector)',
              BCREATED BIGINT NOT NULL,
              INDEX idx_rag_user (BUID),
              INDEX idx_rag_message (BMID),
              INDEX idx_rag_group (BGROUPKEY),
              INDEX idx_rag_type (BTYPE),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BRATELIMITS_CONFIG (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BSCOPE VARCHAR(64) NOT NULL,
              BPLAN VARCHAR(32) NOT NULL,
              BLIMIT INT NOT NULL,
              BWINDOW INT NOT NULL,
              BDESCRIPTION LONGTEXT NOT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              INDEX idx_ratelimit_scope (BSCOPE),
              INDEX idx_ratelimit_plan (BPLAN),
              UNIQUE INDEX unique_scope_plan (BSCOPE, BPLAN),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BSEARCHRESULTS (
              BID INT AUTO_INCREMENT NOT NULL,
              BQUERY VARCHAR(500) NOT NULL,
              BTITLE VARCHAR(500) NOT NULL,
              BURL LONGTEXT NOT NULL,
              BDESCRIPTION LONGTEXT DEFAULT NULL,
              BPUBLISHED VARCHAR(100) DEFAULT NULL,
              BSOURCE VARCHAR(255) DEFAULT NULL,
              BTHUMBNAIL LONGTEXT DEFAULT NULL,
              BPOSITION INT NOT NULL,
              BEXTRASNIPPETS JSON DEFAULT NULL,
              BCREATEDAT DATETIME NOT NULL,
              BMESSAGEID BIGINT NOT NULL,
              INDEX idx_message (BMESSAGEID),
              INDEX idx_query (BQUERY),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BSESSIONS (
              BID VARCHAR(128) NOT NULL,
              BUSERID BIGINT DEFAULT NULL,
              BTOKEN VARCHAR(128) NOT NULL,
              BDATA LONGTEXT NOT NULL,
              BCREATED BIGINT NOT NULL,
              BLASTACTIVITY BIGINT NOT NULL,
              BEXPIRES BIGINT NOT NULL,
              BIPADDRESS VARCHAR(45) DEFAULT '' NOT NULL,
              BUSERAGENT VARCHAR(255) DEFAULT '' NOT NULL,
              UNIQUE INDEX UNIQ_2F1CE1199D139E52 (BTOKEN),
              INDEX idx_session_user (BUSERID),
              INDEX idx_session_token (BTOKEN),
              INDEX idx_session_expires (BEXPIRES),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BSUBSCRIPTIONS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BNAME VARCHAR(64) NOT NULL,
              BLEVEL VARCHAR(32) NOT NULL,
              BPRICE_MONTHLY NUMERIC(10, 2) NOT NULL,
              BPRICE_YEARLY NUMERIC(10, 2) NOT NULL,
              BDESCRIPTION LONGTEXT NOT NULL,
              BACTIVE TINYINT(1) DEFAULT 1 NOT NULL,
              BCOST_BUDGET_MONTHLY NUMERIC(10, 2) DEFAULT '0' NOT NULL,
              BCOST_BUDGET_YEARLY NUMERIC(10, 2) DEFAULT '0' NOT NULL,
              BSTRIPE_MONTHLY_ID VARCHAR(128) DEFAULT NULL,
              BSTRIPE_YEARLY_ID VARCHAR(128) DEFAULT NULL,
              INDEX BLEVEL (BLEVEL),
              INDEX BACTIVE (BACTIVE),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BTOKENS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BUSERID BIGINT NOT NULL,
              BTOKEN VARCHAR(255) NOT NULL,
              BTYPE VARCHAR(32) NOT NULL,
              BCREATED BIGINT NOT NULL,
              BEXPIRES BIGINT NOT NULL,
              BUSED TINYINT(1) DEFAULT 0 NOT NULL,
              BUSEDDATE BIGINT DEFAULT NULL,
              BIPADDRESS VARCHAR(45) DEFAULT '' NOT NULL,
              UNIQUE INDEX UNIQ_A598CC859D139E52 (BTOKEN),
              INDEX idx_token_user (BUSERID),
              INDEX idx_token_token (BTOKEN),
              INDEX idx_token_type (BTYPE),
              INDEX idx_token_expires (BEXPIRES),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BUSELOG (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BUSERID BIGINT NOT NULL,
              BUNIXTIMES BIGINT NOT NULL,
              BACTION VARCHAR(64) NOT NULL,
              BPROVIDER VARCHAR(32) DEFAULT '' NOT NULL,
              BMODEL VARCHAR(128) DEFAULT '' NOT NULL,
              BTOKENS INT DEFAULT 0 NOT NULL,
              BPROMPT_TOKENS INT DEFAULT 0 NOT NULL,
              BCOMPLETION_TOKENS INT DEFAULT 0 NOT NULL,
              BCACHED_TOKENS INT DEFAULT 0 NOT NULL,
              BCACHE_CREATION_TOKENS INT DEFAULT 0 NOT NULL,
              BESTIMATED TINYINT(1) DEFAULT 0 NOT NULL,
              BPRICE_SNAPSHOT JSON DEFAULT NULL,
              BCOST NUMERIC(10, 6) DEFAULT '0' NOT NULL,
              BLATENCY INT DEFAULT 0 NOT NULL,
              BSTATUS VARCHAR(16) DEFAULT 'success' NOT NULL,
              BERROR LONGTEXT NOT NULL,
              BMETADATA JSON NOT NULL,
              BMODEL_ID BIGINT DEFAULT NULL,
              INDEX idx_uselog_user (BUSERID),
              INDEX idx_uselog_time (BUNIXTIMES),
              INDEX idx_uselog_action (BACTION),
              INDEX idx_uselog_provider (BPROVIDER),
              INDEX idx_uselog_model (BMODEL_ID),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BUSER (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BCREATED VARCHAR(20) NOT NULL,
              BINTYPE VARCHAR(16) DEFAULT 'WEB' NOT NULL,
              BMAIL VARCHAR(128) NOT NULL,
              BPW VARCHAR(64) DEFAULT NULL,
              BPROVIDERID VARCHAR(32) NOT NULL,
              BUSERLEVEL VARCHAR(32) DEFAULT 'NEW' NOT NULL,
              BEMAILVERIFIED TINYINT(1) DEFAULT 0 NOT NULL,
              BUSERDETAILS JSON NOT NULL,
              BPAYMENTDETAILS JSON NOT NULL,
              INDEX BMAIL (BMAIL),
              INDEX BINTYPE (BINTYPE),
              INDEX BPROVIDERID (BPROVIDERID),
              INDEX BUSERLEVEL (BUSERLEVEL),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BVERIFICATION_TOKENS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BUID BIGINT NOT NULL,
              BTOKEN VARCHAR(64) NOT NULL,
              BTYPE VARCHAR(32) NOT NULL,
              BCREATED BIGINT NOT NULL,
              BEXPIRES BIGINT NOT NULL,
              BUSED TINYINT(1) DEFAULT 0 NOT NULL,
              UNIQUE INDEX UNIQ_54AC53F9D139E52 (BTOKEN),
              INDEX IDX_54AC53F2A24D234 (BUID),
              INDEX idx_token (BTOKEN),
              INDEX idx_expires (BEXPIRES),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BWIDGETS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BOWNERID BIGINT NOT NULL,
              BWIDGETID VARCHAR(64) NOT NULL,
              BTASKPROMPT VARCHAR(128) NOT NULL,
              BNAME VARCHAR(128) NOT NULL,
              BSTATUS VARCHAR(16) NOT NULL,
              BCONFIG JSON NOT NULL,
              BALLOWED_DOMAINS JSON NOT NULL,
              BCREATED BIGINT NOT NULL,
              BUPDATED BIGINT NOT NULL,
              UNIQUE INDEX UNIQ_31EBDF5C717266DF (BWIDGETID),
              INDEX idx_widget_id (BWIDGETID),
              INDEX idx_widget_owner (BOWNERID),
              INDEX idx_widget_status (BSTATUS),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BWIDGET_SESSIONS (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BWIDGETID VARCHAR(64) NOT NULL,
              BSESSIONID VARCHAR(64) NOT NULL,
              BMESSAGECOUNT INT NOT NULL,
              BFILECOUNT INT NOT NULL,
              BLASTMESSAGE BIGINT NOT NULL,
              BCHATID BIGINT DEFAULT NULL,
              BCREATED BIGINT NOT NULL,
              BEXPIRES BIGINT NOT NULL,
              BMODE VARCHAR(16) DEFAULT 'ai',
              BHUMAN_OPERATOR_ID BIGINT DEFAULT NULL,
              BLAST_HUMAN_ACTIVITY BIGINT DEFAULT NULL,
              BLAST_MESSAGE_PREVIEW VARCHAR(255) DEFAULT NULL,
              BIS_FAVORITE TINYINT(1) DEFAULT 0,
              BCOUNTRY VARCHAR(2) DEFAULT NULL,
              BTITLE VARCHAR(100) DEFAULT NULL,
              BCUSTOM_FIELD_VALUES JSON DEFAULT NULL,
              INDEX idx_session_widget (BWIDGETID),
              INDEX idx_session_expires (BEXPIRES),
              INDEX idx_session_mode (BMODE),
              UNIQUE INDEX uk_widget_session (BWIDGETID, BSESSIONID),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE BWIDGET_SUMMARIES (
              BID BIGINT AUTO_INCREMENT NOT NULL,
              BWIDGETID VARCHAR(64) NOT NULL,
              BDATE INT NOT NULL,
              BSESSION_COUNT INT NOT NULL,
              BMESSAGE_COUNT INT NOT NULL,
              BTOPICS LONGTEXT NOT NULL,
              BFAQS LONGTEXT NOT NULL,
              BSENTIMENT LONGTEXT NOT NULL,
              BISSUES LONGTEXT NOT NULL,
              BRECOMMENDATIONS LONGTEXT NOT NULL,
              BSUMMARY_TEXT LONGTEXT NOT NULL,
              BPROMPT_SUGGESTIONS LONGTEXT DEFAULT NULL,
              BSENTIMENT_MESSAGES LONGTEXT DEFAULT NULL,
              BFROM_DATE INT DEFAULT NULL,
              BTO_DATE INT DEFAULT NULL,
              BAI_MODEL VARCHAR(64) DEFAULT NULL,
              BCREATED BIGINT NOT NULL,
              INDEX idx_summary_widget (BWIDGETID),
              INDEX idx_summary_date (BDATE),
              PRIMARY KEY(BID)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE plugin_data (
              id BIGINT AUTO_INCREMENT NOT NULL,
              user_id BIGINT NOT NULL,
              plugin_name VARCHAR(64) NOT NULL,
              data_type VARCHAR(64) NOT NULL,
              data_key VARCHAR(255) DEFAULT NULL,
              data JSON NOT NULL,
              created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              INDEX idx_user_plugin (user_id, plugin_name),
              INDEX idx_plugin_type (plugin_name, data_type),
              UNIQUE INDEX idx_user_plugin_type_key (
                user_id, plugin_name, data_type, data_key
              ),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
              id BIGINT AUTO_INCREMENT NOT NULL,
              body LONGTEXT NOT NULL,
              headers LONGTEXT NOT NULL,
              queue_name VARCHAR(190) NOT NULL,
              created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
              delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
              INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (
                queue_name, available_at, delivered_at,
                id
              ),
              PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BAPIKEYS
            ADD
              CONSTRAINT FK_16D420BFD969E21A FOREIGN KEY (BOWNERID) REFERENCES BUSER (BID)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BMESSAGEMETA
            ADD
              CONSTRAINT FK_F2A54B27EA4931D2 FOREIGN KEY (BMESSAGEID) REFERENCES BMESSAGES (BID)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BMESSAGES
            ADD
              CONSTRAINT FK_6E7E629CB7A01895 FOREIGN KEY (BCHATID) REFERENCES BCHATS (BID) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BMESSAGE_FILE_ATTACHMENTS
            ADD
              CONSTRAINT FK_BE254C38EA4931D2 FOREIGN KEY (BMESSAGEID) REFERENCES BMESSAGES (BID) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BMESSAGE_FILE_ATTACHMENTS
            ADD
              CONSTRAINT FK_BE254C383583B10C FOREIGN KEY (BFILEID) REFERENCES BFILES (BID) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BMODEL_PRICE_HISTORY
            ADD
              CONSTRAINT FK_DE18BF0BF4448D0D FOREIGN KEY (BMODEL_ID) REFERENCES BMODELS (BID)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BPROMPTMETA
            ADD
              CONSTRAINT FK_D44C7DE359DEE83D FOREIGN KEY (BPROMPTID) REFERENCES BPROMPTS (BID)
        SQL);
        $this->addSql('ALTER TABLE BRAG ADD CONSTRAINT FK_7EBB1F032A24D234 FOREIGN KEY (BUID) REFERENCES BUSER (BID)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BSEARCHRESULTS
            ADD
              CONSTRAINT FK_E7266274EA4931D2 FOREIGN KEY (BMESSAGEID) REFERENCES BMESSAGES (BID) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BSESSIONS
            ADD
              CONSTRAINT FK_2F1CE119FEF0B465 FOREIGN KEY (BUSERID) REFERENCES BUSER (BID)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BTOKENS
            ADD
              CONSTRAINT FK_A598CC85FEF0B465 FOREIGN KEY (BUSERID) REFERENCES BUSER (BID)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BUSELOG
            ADD
              CONSTRAINT FK_271BCC23FEF0B465 FOREIGN KEY (BUSERID) REFERENCES BUSER (BID)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BUSELOG
            ADD
              CONSTRAINT FK_271BCC23F4448D0D FOREIGN KEY (BMODEL_ID) REFERENCES BMODELS (BID)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BVERIFICATION_TOKENS
            ADD
              CONSTRAINT FK_54AC53F2A24D234 FOREIGN KEY (BUID) REFERENCES BUSER (BID)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              BWIDGETS
            ADD
              CONSTRAINT FK_31EBDF5CD969E21A FOREIGN KEY (BOWNERID) REFERENCES BUSER (BID)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE BAPIKEYS DROP FOREIGN KEY FK_16D420BFD969E21A');
        $this->addSql('ALTER TABLE BMESSAGEMETA DROP FOREIGN KEY FK_F2A54B27EA4931D2');
        $this->addSql('ALTER TABLE BMESSAGES DROP FOREIGN KEY FK_6E7E629CB7A01895');
        $this->addSql('ALTER TABLE BMESSAGE_FILE_ATTACHMENTS DROP FOREIGN KEY FK_BE254C38EA4931D2');
        $this->addSql('ALTER TABLE BMESSAGE_FILE_ATTACHMENTS DROP FOREIGN KEY FK_BE254C383583B10C');
        $this->addSql('ALTER TABLE BMODEL_PRICE_HISTORY DROP FOREIGN KEY FK_DE18BF0BF4448D0D');
        $this->addSql('ALTER TABLE BPROMPTMETA DROP FOREIGN KEY FK_D44C7DE359DEE83D');
        $this->addSql('ALTER TABLE BRAG DROP FOREIGN KEY FK_7EBB1F032A24D234');
        $this->addSql('ALTER TABLE BSEARCHRESULTS DROP FOREIGN KEY FK_E7266274EA4931D2');
        $this->addSql('ALTER TABLE BSESSIONS DROP FOREIGN KEY FK_2F1CE119FEF0B465');
        $this->addSql('ALTER TABLE BTOKENS DROP FOREIGN KEY FK_A598CC85FEF0B465');
        $this->addSql('ALTER TABLE BUSELOG DROP FOREIGN KEY FK_271BCC23FEF0B465');
        $this->addSql('ALTER TABLE BUSELOG DROP FOREIGN KEY FK_271BCC23F4448D0D');
        $this->addSql('ALTER TABLE BVERIFICATION_TOKENS DROP FOREIGN KEY FK_54AC53F2A24D234');
        $this->addSql('ALTER TABLE BWIDGETS DROP FOREIGN KEY FK_31EBDF5CD969E21A');
        $this->addSql('DROP TABLE BAPIKEYS');
        $this->addSql('DROP TABLE BCHATS');
        $this->addSql('DROP TABLE BCONFIG');
        $this->addSql('DROP TABLE BEMAILVERIFICATION');
        $this->addSql('DROP TABLE BFILES');
        $this->addSql('DROP TABLE BGUEST_SESSIONS');
        $this->addSql('DROP TABLE BINBOUNDEMAILHANDLER');
        $this->addSql('DROP TABLE BMESSAGEMETA');
        $this->addSql('DROP TABLE BMESSAGES');
        $this->addSql('DROP TABLE BMESSAGE_FILE_ATTACHMENTS');
        $this->addSql('DROP TABLE BMODELS');
        $this->addSql('DROP TABLE BMODEL_PRICE_HISTORY');
        $this->addSql('DROP TABLE BPROMPTMETA');
        $this->addSql('DROP TABLE BPROMPTS');
        $this->addSql('DROP TABLE BRAG');
        $this->addSql('DROP TABLE BRATELIMITS_CONFIG');
        $this->addSql('DROP TABLE BSEARCHRESULTS');
        $this->addSql('DROP TABLE BSESSIONS');
        $this->addSql('DROP TABLE BSUBSCRIPTIONS');
        $this->addSql('DROP TABLE BTOKENS');
        $this->addSql('DROP TABLE BUSELOG');
        $this->addSql('DROP TABLE BUSER');
        $this->addSql('DROP TABLE BVERIFICATION_TOKENS');
        $this->addSql('DROP TABLE BWIDGETS');
        $this->addSql('DROP TABLE BWIDGET_SESSIONS');
        $this->addSql('DROP TABLE BWIDGET_SUMMARIES');
        $this->addSql('DROP TABLE plugin_data');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
