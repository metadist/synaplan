<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sign-up codes for admission mode (AS1): an administrator hands out a code,
 * whoever holds it may create an account without paying. The code is stored
 * as a SHA-256 hash with a four-character prefix for recognition; every
 * redemption is one row in BSIGNUP_CODE_USES and survives the code's
 * revocation and the account's deletion.
 *
 * Galera-safe: raw addSql only, no Schema API.
 */
final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BSIGNUP_CODES and BSIGNUP_CODE_USES for admission-mode sign-up.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS BSIGNUP_CODES (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BCODEHASH VARCHAR(64) NOT NULL,
            BPREFIX VARCHAR(8) NOT NULL,
            BLABEL VARCHAR(120) NOT NULL,
            BLEVEL VARCHAR(16) NOT NULL,
            BMAXUSES INT DEFAULT NULL,
            BUSES INT NOT NULL DEFAULT 0,
            BEXPIRES BIGINT DEFAULT NULL,
            BACTIVE TINYINT(1) NOT NULL DEFAULT 1,
            BCREATEDBY BIGINT NOT NULL,
            BCREATED BIGINT NOT NULL,
            UNIQUE INDEX uniq_signup_code_hash (BCODEHASH),
            INDEX idx_signup_code_live (BACTIVE, BEXPIRES),
            PRIMARY KEY (BID)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS BSIGNUP_CODE_USES (
            BID BIGINT AUTO_INCREMENT NOT NULL,
            BCODEID BIGINT NOT NULL,
            BUSERID BIGINT NOT NULL,
            BLEVEL VARCHAR(16) NOT NULL,
            BUSEDAT BIGINT NOT NULL,
            INDEX idx_signup_code_use_code (BCODEID),
            INDEX idx_signup_code_use_user (BUSERID),
            PRIMARY KEY (BID)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS BSIGNUP_CODE_USES');
        $this->addSql('DROP TABLE IF EXISTS BSIGNUP_CODES');
    }
}
