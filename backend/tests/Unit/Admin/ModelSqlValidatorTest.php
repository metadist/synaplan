<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Service\Admin\ModelSqlValidator;
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

        $res = $v->validateAndSplit($sql);
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
}
