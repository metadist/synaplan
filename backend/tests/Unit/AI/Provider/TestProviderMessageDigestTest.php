<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\TestProvider;
use App\AI\StructuredOutput\Schema\MessageDigestSchema;
use PHPUnit\Framework\TestCase;

final class TestProviderMessageDigestTest extends TestCase
{
    public function testRememberLineBecomesDigestForThatMessage(): void
    {
        $batch = <<<'TEXT'
Message batch (each line starts with [#id direction channel date]):
[#42 user web 2026-10-05] Please remember: office rent letter
[#43 assistant web 2026-10-05] Hello there
TEXT;

        $decoded = $this->digestResponse($batch, withSchema: true);

        self::assertSame([
            'digests' => [
                ['title' => 'office rent letter', 'message_id' => 42],
            ],
        ], $decoded);
    }

    public function testAssistantEchoOfARememberLineIsIgnored(): void
    {
        $batch = <<<'TEXT'
Message batch (each line starts with [#id direction channel date]):
[#50 user web 2026-10-05] Please remember: office rent letter
[#51 assistant web 2026-10-05] You said: Please remember: office rent letter
TEXT;

        $decoded = $this->digestResponse($batch, withSchema: true);

        self::assertSame([
            'digests' => [
                ['title' => 'office rent letter', 'message_id' => 50],
            ],
        ], $decoded);
    }

    public function testBatchWithoutRememberReturnsEmptyList(): void
    {
        $batch = <<<'TEXT'
Message batch (each line starts with [#id direction channel date]):
[#7 user web 2026-10-05] Just saying hello
TEXT;

        $decoded = $this->digestResponse($batch, withSchema: true);

        self::assertSame(['digests' => []], $decoded);
    }

    public function testWithoutSchemaReturnsBareArray(): void
    {
        $batch = <<<'TEXT'
Message batch (each line starts with [#id direction channel date]):
[#9 user email 2026-10-05] remember: quarterly budget note
TEXT;

        $decoded = $this->digestResponse($batch, withSchema: false);

        self::assertSame([
            ['title' => 'quarterly budget note', 'message_id' => 9],
        ], $decoded);
    }

    public function testMemorizeContractStillCreatesAMemory(): void
    {
        $result = (new TestProvider())->chat([
            ['role' => 'user', 'content' => "Current Message (from the user):\nmemorize: favorite_color = teal\n\n\"action\": \"create\""],
        ]);

        $decoded = json_decode($result['content'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('create', $decoded[0]['action']);
        self::assertSame('favorite_color', $decoded[0]['key']);
        self::assertSame('teal', $decoded[0]['value']);
    }

    /**
     * @return array<mixed>
     */
    private function digestResponse(string $batch, bool $withSchema): array
    {
        $options = [];
        if ($withSchema) {
            $options['structured_output'] = MessageDigestSchema::build();
        }

        $result = (new TestProvider())->chat([
            ['role' => 'system', 'content' => "You index a user's message history"],
            ['role' => 'user', 'content' => $batch],
        ], $options);

        $decoded = json_decode($result['content'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
