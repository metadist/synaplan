<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Service\Admin\ModelSqlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelSqlValidatorTest extends TestCase
{
    public function testAcceptsInsertUpdateDeleteForBmodels(): void
    {
        $v = new ModelSqlValidator();
        $sql = <<<SQL
INSERT INTO BMODELS (BSERVICE, BTAG, BPROVID, BNAME) VALUES ('OpenAI','chat','gpt-4.1','GPT-4.1');
UPDATE BMODELS SET BPRICEIN=1.23 WHERE BSERVICE='OpenAI' AND BTAG='chat' AND BPROVID='gpt-4.1';
DELETE FROM BMODELS WHERE BSERVICE='OpenAI' AND BTAG='chat' AND BPROVID='old';
SQL;

        $res = $v->validateAndSplit($sql, allowDelete: true);
        self::assertSame([], $res['errors']);
        self::assertCount(3, $res['statements']);
    }

    public function testRejectsNonDml(): void
    {
        $v = new ModelSqlValidator();
        $res = $v->validateAndSplit('SELECT * FROM BMODELS;');
        self::assertNotEmpty($res['errors']);
    }

    public function testRejectsUpdateWithoutUniqueWhere(): void
    {
        $v = new ModelSqlValidator();
        $res = $v->validateAndSplit("UPDATE BMODELS SET BPRICEIN=1 WHERE BSERVICE='OpenAI';");
        self::assertNotEmpty($res['errors']);
        self::assertStringContainsString('BSERVICE, BTAG and BPROVID', implode(' ', $res['errors']));
    }

    public function testRejectsOtherTables(): void
    {
        $v = new ModelSqlValidator();
        $res = $v->validateAndSplit('DELETE FROM USERS WHERE 1=1;');
        self::assertNotEmpty($res['errors']);
    }

    public function testRejectsMultiTableDelete(): void
    {
        $v = new ModelSqlValidator();
        $res = $v->validateAndSplit("DELETE FROM BMODELS, USERS WHERE BSERVICE='x' AND BTAG='y' AND BPROVID='z';", allowDelete: true);
        self::assertNotEmpty($res['errors']);
        self::assertStringContainsString('multiple tables', implode(' ', $res['errors']));
    }

    public function testRejectsMultiTableUpdate(): void
    {
        $v = new ModelSqlValidator();
        $res = $v->validateAndSplit("UPDATE BMODELS, USERS SET BPRICEIN=1 WHERE BSERVICE='x' AND BTAG='y' AND BPROVID='z';");
        self::assertNotEmpty($res['errors']);
        self::assertStringContainsString('multiple tables', implode(' ', $res['errors']));
    }

    public function testRejectsDeleteWithUsing(): void
    {
        $v = new ModelSqlValidator();
        $res = $v->validateAndSplit("DELETE FROM BMODELS USING BMODELS JOIN USERS WHERE BSERVICE='x' AND BTAG='y' AND BPROVID='z';", allowDelete: true);
        self::assertNotEmpty($res['errors']);
    }

    public function testRejectsJoin(): void
    {
        $v = new ModelSqlValidator();
        $res = $v->validateAndSplit("UPDATE BMODELS JOIN USERS ON 1=1 SET BPRICEIN=1 WHERE BSERVICE='x' AND BTAG='y' AND BPROVID='z';");
        self::assertNotEmpty($res['errors']);
        self::assertStringContainsString('other tables', implode(' ', $res['errors']));
    }

    private const string KEYS = "BSERVICE='OpenAI' AND BTAG='chat' AND BPROVID='gpt-4.1'";

    /** @return array<string, array{0: string}> */
    public static function refusedStatements(): array
    {
        return [
            'required columns only inside a string' => ["UPDATE plugin_data SET data_key = data_key WHERE 1 = 0 AND 'UPDATE BMODELS BSERVICE BTAG BPROVID' <> ''"],
            'table name only inside a string' => ["UPDATE messenger_messages SET body = 'x' WHERE 'FROM BMODELS' <> '' AND ".self::KEYS],
            'other table without a B prefix' => ['DELETE FROM doctrine_migration_versions WHERE '.self::KEYS],
            'other table in the WHERE' => ['UPDATE BMODELS SET BNAME = plugin_data.data_key WHERE '.self::KEYS],
            'subquery' => ['UPDATE BMODELS SET BNAME = (SELECT BMAIL FROM BUSER) WHERE '.self::KEYS],
            'function call' => ["UPDATE BMODELS SET BDESCRIPTION = LOAD_FILE('/etc/passwd') WHERE ".self::KEYS],
            'sleep' => ['UPDATE BMODELS SET BACTIVE = SLEEP(5) WHERE '.self::KEYS],
            'server variable' => ['UPDATE BMODELS SET BNAME = @@version WHERE '.self::KEYS],
            'insert from select' => ['INSERT INTO BMODELS (BNAME) SELECT BMAIL FROM BUSER'],
            'insert into another table' => ["INSERT INTO plugin_data (data_key) VALUES ('x')"],
            'insert set form' => ["INSERT INTO BMODELS SET BNAME = 'x'"],
            'keys compared with themselves' => ['UPDATE BMODELS SET BACTIVE = 0 WHERE BSERVICE = BSERVICE AND BTAG = BTAG AND BPROVID = BPROVID'],
            'OR widens the match' => ['UPDATE BMODELS SET BACTIVE = 0 WHERE '.self::KEYS.' OR 1 = 1'],
            'key column missing' => ["UPDATE BMODELS SET BACTIVE = 0 WHERE BSERVICE = 'OpenAI' AND BTAG = 'chat'"],
            'second WHERE' => ['UPDATE BMODELS SET BACTIVE = 0 WHERE '.self::KEYS.' WHERE 1 = 1'],
            'backslash escape' => ["UPDATE BMODELS SET BNAME = 'a\\' WHERE ".self::KEYS." AND BNAME <> '"],
            'double-quoted string' => ['UPDATE BMODELS SET BNAME = "x" WHERE '.self::KEYS],
            'unterminated string' => ["UPDATE BMODELS SET BNAME = 'x WHERE ".self::KEYS],
            'hex literal' => ['UPDATE BMODELS SET BNAME = 0x41 WHERE '.self::KEYS],
            'charset introducer' => ["UPDATE BMODELS SET BNAME = _utf8'x' WHERE ".self::KEYS],
            'comment' => ['UPDATE BMODELS SET BACTIVE = 0 /* x */ WHERE '.self::KEYS],
            'unknown B column' => ['UPDATE BMODELS SET BUSERID = 1 WHERE '.self::KEYS],
        ];
    }

    #[DataProvider('refusedStatements')]
    public function testRefusesAStatementThatReachesBeyondTheModelTable(string $sql): void
    {
        $res = (new ModelSqlValidator())->validateAndSplit($sql, allowDelete: true);

        self::assertNotEmpty($res['errors']);
        self::assertSame([], $res['statements']);
    }

    /** @return array<string, array{0: string}> */
    public static function acceptedStatements(): array
    {
        return [
            'SQL words inside a value' => ["UPDATE BMODELS SET BDESCRIPTION = 'SELECT the best; DROP -- nothing #1 \"quoted\"' WHERE ".self::KEYS],
            'doubled quote' => ["UPDATE BMODELS SET BNAME = 'Claude''s model' WHERE ".self::KEYS],
            'JSON value' => ["UPDATE BMODELS SET BJSON = '{\"description\":\"fast\",\"params\":{\"model\":\"gpt-4.1\"}}' WHERE ".self::KEYS],
            'backticks' => ["UPDATE `BMODELS` SET `BPRICEIN` = 1.25, `BPRICEOUT` = -1 WHERE `BSERVICE` = 'OpenAI' AND `BTAG` = 'chat' AND `BPROVID` = 'gpt-4.1'"],
            'lower case' => ["update bmodels set bactive = 0 where bservice = 'OpenAI' and btag = 'chat' and bprovid = 'gpt-4.1'"],
            'IN list for a key' => ["UPDATE BMODELS SET BACTIVE = 0 WHERE BSERVICE = 'OpenAI' AND BTAG IN ('chat', 'sort') AND BPROVID = 'gpt-4.1'"],
            'extra condition' => ['UPDATE BMODELS SET BACTIVE = 0 WHERE '.self::KEYS.' AND BACTIVE = 1 AND BDESCRIPTION IS NOT NULL'],
            'multi-row insert' => ["INSERT INTO BMODELS (BSERVICE, BTAG, BPROVID, BNAME, BACTIVE) VALUES ('A', 'chat', 'a-1', 'A 1', 1), ('A', 'sort', 'a-1', 'A 1', 1)"],
            'upsert' => ["INSERT INTO BMODELS (BSERVICE, BTAG, BPROVID, BNAME) VALUES ('A', 'chat', 'a-1', 'A 1') ON DUPLICATE KEY UPDATE BNAME = VALUES(BNAME)"],
            'value spanning lines' => ["UPDATE BMODELS SET BDESCRIPTION = 'line one\nline two' WHERE ".self::KEYS],
        ];
    }

    #[DataProvider('acceptedStatements')]
    public function testAcceptsAPlainStatementOnTheModelTable(string $sql): void
    {
        $res = (new ModelSqlValidator())->validateAndSplit($sql);

        self::assertSame([], $res['errors']);
        self::assertSame([$sql.';'], $res['statements']);
    }

    public function testASemicolonInsideAValueDoesNotSplitTheStatement(): void
    {
        $sql = "UPDATE BMODELS SET BNAME = 'a; b' WHERE ".self::KEYS.';';

        $res = (new ModelSqlValidator())->validateAndSplit($sql);

        self::assertSame([], $res['errors']);
        self::assertSame([$sql], $res['statements']);
    }

    public function testOneRefusedStatementRefusesTheWholeScript(): void
    {
        $sql = 'UPDATE BMODELS SET BACTIVE = 0 WHERE '.self::KEYS."; UPDATE plugin_data SET data_key = 'x' WHERE ".self::KEYS;

        $res = (new ModelSqlValidator())->validateAndSplit($sql);

        self::assertSame([], $res['statements']);
        self::assertStringContainsString('Statement 2', implode(' ', $res['errors']));
    }
}
