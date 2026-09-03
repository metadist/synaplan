<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Exception\StructuredOutputViolationException;
use App\AI\Health\FailureKind;
use App\AI\Health\ModelHealthRecorder;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Service\AiFacade;
use App\AI\Service\ProviderRegistry;
use App\AI\StructuredOutput\Schema\SortClassificationSchema;
use App\AI\StructuredOutput\StructuredOutputRecovery;
use App\Service\CircuitBreaker;
use App\Service\DiscordNotificationService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\InternalEmailService;
use App\Service\ModelConfigService;
use App\Service\Usage\TranscriptionUsageRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The self-healing loop for a schema-rejected generation: salvage first, one
 * corrective retry second, typed failure third — and never more than one
 * extra provider call.
 */
final class AiFacadeStructuredOutputRecoveryTest extends TestCase
{
    private const VALID = '{"BTOPIC":"general","BLANG":"de","BWEBSEARCH":false,"BMULTI":false,"BMEDIA":null,"BINPUTMODE":null,"BDURATION":null,"BRESOLUTION":null}';

    /** The valid answer with the sorter's input fields echoed beside it — salvageable. */
    private const ECHOED = '{"BDATETIME":"20260903124200","BTEXT":"ok","BFILE":0,"BTOPIC":"general","BLANG":"de","BWEBSEARCH":false,"BMULTI":false,"BMEDIA":null,"BINPUTMODE":null,"BDURATION":null,"BRESOLUTION":null}';

    /** Echoed input AND a missing required key — pruning cannot fix this. */
    private const BROKEN = '{"BTEXT":"ok","BTOPIC":"general","BWEBSEARCH":false}';

    /** @var list<array<string, mixed>> */
    private const MESSAGES = [
        ['role' => 'system', 'content' => 'classify'],
        ['role' => 'user', 'content' => '{"BTEXT":"ok"}'],
    ];

    private ChatProviderInterface&MockObject $provider;

    private AiFacade $facade;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ChatProviderInterface::class);
        $this->provider->method('getName')->willReturn('groq');
        $this->provider->method('getDefaultModels')->willReturn([]);

        $registry = $this->createMock(ProviderRegistry::class);
        $registry->method('getChatProvider')->willReturn($this->provider);

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('execute')->willReturnCallback(static fn (callable $callback) => $callback());

        // The recorder returns an enum, which PHPUnit cannot double on its own.
        $health = $this->createMock(ModelHealthRecorder::class);
        $health->method('recordFailure')->willReturn(FailureKind::UserError);

        $this->facade = new AiFacade(
            $registry,
            $this->createMock(ModelConfigService::class),
            $circuitBreaker,
            new NullLogger(),
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(DiscordNotificationService::class),
            $this->createMock(InternalEmailService::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(HiggsfieldCredentialResolver::class),
            $this->createMock(TranscriptionUsageRecorder::class),
            $health,
            '/tmp',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(): array
    {
        return [
            'provider' => 'groq',
            'model' => 'openai/gpt-oss-120b',
            'structured_output' => SortClassificationSchema::build(['general', 'officemaker'], ['de', 'en']),
        ];
    }

    private static function violation(string $failedGeneration): StructuredOutputViolationException
    {
        return new StructuredOutputViolationException('groq', "additionalProperties 'BTEXT' not allowed", $failedGeneration, 'sort_classification');
    }

    public function testSalvagesTheRejectedGenerationWithoutASecondProviderCall(): void
    {
        $this->provider->expects(self::once())->method('chat')
            ->willThrowException(self::violation(self::ECHOED));

        $result = $this->facade->chat(self::MESSAGES, 1, self::options());

        self::assertSame(StructuredOutputRecovery::RECOVERY_SALVAGED, $result[StructuredOutputRecovery::RESPONSE_KEY]);
        self::assertSame(
            ['BTOPIC' => 'general', 'BLANG' => 'de', 'BWEBSEARCH' => false, 'BMULTI' => false, 'BMEDIA' => null, 'BINPUTMODE' => null, 'BDURATION' => null, 'BRESOLUTION' => null],
            json_decode($result['content'], true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, $result['usage']['total_tokens'], 'a 400 carries no usage; nothing is guessed');
        self::assertSame('groq', $result['provider']);
        self::assertSame('openai/gpt-oss-120b', $result['model']);
    }

    public function testRetriesOnceWithACorrectiveTurnWhenSalvageIsImpossible(): void
    {
        $seenMessages = [];
        $this->provider->expects(self::exactly(2))->method('chat')
            ->willReturnCallback(static function (array $messages) use (&$seenMessages): array {
                $seenMessages[] = $messages;
                if (1 === count($seenMessages)) {
                    throw self::violation(self::BROKEN);
                }

                return ['content' => self::VALID, 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]];
            });

        $result = $this->facade->chat(self::MESSAGES, 1, self::options());

        self::assertSame(StructuredOutputRecovery::RECOVERY_REPAIRED, $result[StructuredOutputRecovery::RESPONSE_KEY]);
        self::assertSame(self::VALID, $result['content']);
        self::assertSame(15, $result['usage']['total_tokens']);

        self::assertSame(self::MESSAGES, $seenMessages[0]);
        self::assertCount(4, $seenMessages[1], 'original turns + rejected answer + correction');
        self::assertSame(['role' => 'assistant', 'content' => self::BROKEN], $seenMessages[1][2]);
        self::assertSame('user', $seenMessages[1][3]['role']);
        self::assertStringContainsString("additionalProperties 'BTEXT' not allowed", $seenMessages[1][3]['content']);
    }

    public function testSalvagesTheRetrysRejectedGenerationToo(): void
    {
        $calls = 0;
        $this->provider->expects(self::exactly(2))->method('chat')
            ->willReturnCallback(static function () use (&$calls): array {
                ++$calls;
                throw self::violation(1 === $calls ? self::BROKEN : self::ECHOED);
            });

        $result = $this->facade->chat(self::MESSAGES, 1, self::options());

        self::assertSame(StructuredOutputRecovery::RECOVERY_SALVAGED, $result[StructuredOutputRecovery::RESPONSE_KEY]);
        self::assertSame('general', json_decode($result['content'], true, 512, JSON_THROW_ON_ERROR)['BTOPIC']);
    }

    public function testGivesUpTypedAfterExactlyOneRetry(): void
    {
        $this->provider->expects(self::exactly(2))->method('chat')
            ->willThrowException(self::violation(self::BROKEN));

        $this->expectException(StructuredOutputViolationException::class);

        $this->facade->chat(self::MESSAGES, 1, self::options());
    }

    public function testDoesNotRetryWithoutASchemaToValidateAgainst(): void
    {
        $this->provider->expects(self::once())->method('chat')
            ->willThrowException(self::violation(self::ECHOED));

        $this->expectException(StructuredOutputViolationException::class);

        $this->facade->chat(self::MESSAGES, 1, ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b']);
    }

    public function testAFirstTrySuccessCarriesNoRecoveryMarker(): void
    {
        $this->provider->expects(self::once())->method('chat')
            ->willReturn(['content' => self::VALID, 'usage' => []]);

        $result = $this->facade->chat(self::MESSAGES, 1, self::options());

        self::assertArrayNotHasKey(StructuredOutputRecovery::RESPONSE_KEY, $result);
        self::assertSame(self::VALID, $result['content']);
    }
}
