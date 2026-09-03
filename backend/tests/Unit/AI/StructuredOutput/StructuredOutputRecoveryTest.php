<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput;

use App\AI\Exception\StructuredOutputViolationException;
use App\AI\StructuredOutput\Schema\FileGenerationSchema;
use App\AI\StructuredOutput\Schema\SortClassificationSchema;
use App\AI\StructuredOutput\StructuredOutputRecovery;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;

final class StructuredOutputRecoveryTest extends TestCase
{
    /**
     * Verbatim `failed_generation` Groq returned for gpt-oss-120b on
     * 2026-09-03 (strict: true requested): a complete, valid classification
     * with the sorter's INPUT fields echoed next to it.
     */
    private const ECHOED_SORT_GENERATION = '{"BDATETIME":"20260903124200","BDURATION":null,"BFILE":0,"BFILEPATH":"","BFILETEXT":"","BINPUTMODE":null,"BLANG":"de","BMEDIA":null,"BMULTI":false,"BRESOLUTION":null,"BTEXT":"ok, mach mir ein PDF mit dem Bild","BTOPIC":"general","BWEBSEARCH":false}';

    private StructuredOutputRecovery $recovery;

    protected function setUp(): void
    {
        $this->recovery = new StructuredOutputRecovery();
    }

    private static function sortSchema(): StructuredOutputSchema
    {
        return SortClassificationSchema::build(['general', 'mediamaker', 'officemaker', 'docsummary'], ['de', 'en']);
    }

    // ===========================================
    // salvage()
    // ===========================================

    public function testSalvageDropsTheEchoedInputFieldsAndKeepsTheClassification(): void
    {
        $salvaged = $this->recovery->salvage(self::ECHOED_SORT_GENERATION, self::sortSchema());

        self::assertNotNull($salvaged);
        $data = json_decode($salvaged, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['BDURATION', 'BINPUTMODE', 'BLANG', 'BMEDIA', 'BMULTI', 'BRESOLUTION', 'BTOPIC', 'BWEBSEARCH'],
            array_keys($data),
            'exactly the schema keys survive, in the order the model emitted them',
        );
        self::assertSame('general', $data['BTOPIC']);
        self::assertSame('de', $data['BLANG']);
        self::assertFalse($data['BWEBSEARCH']);
        self::assertFalse($data['BMULTI']);
        self::assertNull($data['BMEDIA']);
    }

    public function testSalvageNeverInventsAMissingRequiredField(): void
    {
        // Echoed input fields AND a missing BLANG: pruning alone cannot make
        // this conform, and a made-up language would be worse than a retry.
        $generation = '{"BTEXT":"hi","BTOPIC":"general","BWEBSEARCH":false,"BMULTI":null,"BMEDIA":null,"BINPUTMODE":null,"BDURATION":null,"BRESOLUTION":null}';

        self::assertNull($this->recovery->salvage($generation, self::sortSchema()));
    }

    public function testSalvageRejectsAnOutOfEnumValue(): void
    {
        $generation = str_replace('"BTOPIC":"general"', '"BTOPIC":"made_up_topic"', self::ECHOED_SORT_GENERATION);

        self::assertNull($this->recovery->salvage($generation, self::sortSchema()));
    }

    public function testSalvageRejectsAWrongType(): void
    {
        // BWEBSEARCH must be a boolean; the prose-era "0"/"1" strings are
        // exactly what the schema exists to rule out.
        $generation = str_replace('"BWEBSEARCH":false', '"BWEBSEARCH":"0"', self::ECHOED_SORT_GENERATION);

        self::assertNull($this->recovery->salvage($generation, self::sortSchema()));
    }

    public function testSalvageFailsClosedOnATypeItCannotCheck(): void
    {
        // A type name outside the JSON Schema primitives (a typo in a schema
        // class, say) must not turn salvage into a rubber stamp: the value is
        // unverified, so the caller has to take the corrective retry instead.
        $schema = new StructuredOutputSchema('typo', [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['count' => ['type' => 'interger']],
            'required' => ['count'],
        ]);

        self::assertNull($this->recovery->salvage('{"count":3}', $schema));
        self::assertNull($this->recovery->salvage('{"count":"three"}', $schema));
    }

    public function testSalvageStillMatchesAUnionWhenOneCandidateIsUnknown(): void
    {
        // Fail-closed is per candidate: the known members of a type list keep
        // matching, only a value that fits none of them is rejected.
        $schema = new StructuredOutputSchema('union', [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['id' => ['type' => ['integer', 'null', 'uuid']]],
            'required' => ['id'],
        ]);

        self::assertSame('{"id":7}', $this->recovery->salvage('{"id":7}', $schema));
        self::assertSame('{"id":null}', $this->recovery->salvage('{"id":null}', $schema));
        self::assertNull($this->recovery->salvage('{"id":"6f1c"}', $schema));
    }

    public function testSalvageAcceptsANullableUnionAndItsEnum(): void
    {
        $generation = str_replace(
            ['"BMEDIA":null', '"BDURATION":null', '"BRESOLUTION":null'],
            ['"BMEDIA":"video"', '"BDURATION":8', '"BRESOLUTION":"1080p"'],
            self::ECHOED_SORT_GENERATION,
        );

        $salvaged = $this->recovery->salvage($generation, self::sortSchema());

        self::assertNotNull($salvaged);
        $data = json_decode($salvaged, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('video', $data['BMEDIA']);
        self::assertSame(8, $data['BDURATION']);
        self::assertSame('1080p', $data['BRESOLUTION']);
    }

    public function testSalvageToleratesFencesAndProseAroundTheObject(): void
    {
        $generation = "Here is the classification:\n```json\n".self::ECHOED_SORT_GENERATION."\n```";

        self::assertNotNull($this->recovery->salvage($generation, self::sortSchema()));
    }

    public function testSalvageReturnsNullWithoutAPayload(): void
    {
        self::assertNull($this->recovery->salvage(null, self::sortSchema()));
        self::assertNull($this->recovery->salvage('   ', self::sortSchema()));
        self::assertNull($this->recovery->salvage('not json at all', self::sortSchema()));
    }

    public function testSalvagePrunesNestedClosedObjectsInsideArrays(): void
    {
        $schema = new StructuredOutputSchema('actions', [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['create', 'delete']],
                            'id' => ['type' => ['integer', 'null']],
                        ],
                        'required' => ['type', 'id'],
                    ],
                ],
            ],
            'required' => ['actions'],
        ]);

        $generation = '{"actions":[{"type":"create","id":null,"reasoning":"because"},{"type":"delete","id":7,"echo":"x"}],"BTEXT":"input"}';

        $salvaged = $this->recovery->salvage($generation, $schema);

        self::assertSame('{"actions":[{"type":"create","id":null},{"type":"delete","id":7}]}', $salvaged);
    }

    public function testSalvageKeepsExtraKeysWhereTheSchemaLeavesTheObjectOpen(): void
    {
        $schema = new StructuredOutputSchema('open', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ]);

        self::assertSame('{"name":"x","extra":1}', $this->recovery->salvage('{"name":"x","extra":1}', $schema));
    }

    public function testSalvageHandlesTheOfficemakerEnvelope(): void
    {
        // A model that echoes the prompt's field explanations as extra keys
        // still produced the two strings the envelope needs.
        $generation = '{"BFILEPATH":"report.docx","BFILETEXT":"# Report","BEXPORT":"pdf","BNOTE":"done"}';

        $salvaged = $this->recovery->salvage($generation, FileGenerationSchema::build());

        self::assertSame('{"BFILEPATH":"report.docx","BFILETEXT":"# Report","BEXPORT":"pdf"}', $salvaged);
    }

    // ===========================================
    // repairMessages()
    // ===========================================

    public function testRepairMessagesAppendTheRejectedAnswerAndACorrectionNamingTheAllowedKeys(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'classify'],
            ['role' => 'user', 'content' => '{"BTEXT":"hi"}'],
        ];
        $violation = new StructuredOutputViolationException(
            'groq',
            "additionalProperties 'BTEXT' not allowed",
            '{"BTEXT":"hi","BTOPIC":"general"}',
            'sort_classification',
        );

        $repaired = $this->recovery->repairMessages($messages, $violation, self::sortSchema());

        self::assertCount(4, $repaired);
        self::assertSame($messages, array_slice($repaired, 0, 2), 'the original conversation is untouched');
        self::assertSame(['role' => 'assistant', 'content' => '{"BTEXT":"hi","BTOPIC":"general"}'], $repaired[2]);
        self::assertSame('user', $repaired[3]['role']);
        self::assertStringContainsString("additionalProperties 'BTEXT' not allowed", $repaired[3]['content']);
        self::assertStringContainsString('"BTOPIC", "BLANG", "BWEBSEARCH", "BMULTI", "BMEDIA", "BINPUTMODE", "BDURATION", "BRESOLUTION"', $repaired[3]['content']);
        self::assertStringContainsString('Do not repeat or echo any field', $repaired[3]['content']);
    }

    public function testRepairMessagesWorkWithoutAFailedGenerationPayload(): void
    {
        $violation = new StructuredOutputViolationException('groq', 'schema mismatch');

        $repaired = $this->recovery->repairMessages([['role' => 'user', 'content' => 'x']], $violation, self::sortSchema());

        self::assertSame('assistant', $repaired[1]['role']);
        self::assertStringContainsString('did not match the required JSON schema', $repaired[1]['content']);
    }
}
