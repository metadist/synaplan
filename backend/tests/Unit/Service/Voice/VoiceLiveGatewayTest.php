<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Voice;

use App\AI\Credential\ProviderKeyStore;
use App\DTO\CostResult;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Exception\VoiceLiveRejectedException;
use App\Module\Probe\SidecarHealthProbeInterface;
use App\Module\Sidecar\LiveVoiceModule;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\CostCalculationService;
use App\Service\Infrastructure\RedisService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\Voice\VoiceLiveCompatibleEndpoint;
use App\Service\Voice\VoiceLiveConcurrency;
use App\Service\Voice\VoiceLiveDelegationService;
use App\Service\Voice\VoiceLiveInstructions;
use App\Service\Voice\VoiceLiveInternalAuth;
use App\Service\Voice\VoiceLiveTicketService;
use App\Service\Voice\VoiceLiveUsageService;
use App\Service\Voice\VoiceTranscriptWriter;
use App\Service\Voice\VoiceTurnSegmenter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class VoiceLiveGatewayTest extends TestCase
{
    public function testTurnsSplitOnSilenceAndSpeakerChanges(): void
    {
        $turns = (new VoiceTurnSegmenter())->segment([
            ['role' => 'user', 'text' => 'Hello', 'startMs' => 0, 'endMs' => 400],
            ['role' => 'user', 'text' => 'there', 'startMs' => 500, 'endMs' => 800],
            ['role' => 'assistant', 'text' => 'Hi', 'startMs' => 900, 'endMs' => 1200],
            ['role' => 'user', 'text' => 'Later', 'startMs' => 4000, 'endMs' => 4500],
        ]);

        self::assertSame('Hello there', $turns[0]['text']);
        self::assertSame('assistant', $turns[1]['role']);
        self::assertSame('Later', $turns[2]['text']);
        self::assertCount(3, $turns);
    }

    public function testASpokenTurnThatExtendsTheStoredQuestionIsTheSameTurn(): void
    {
        $segmenter = new VoiceTurnSegmenter();

        self::assertSame('Wie ist der Verkehr, bitte?', $segmenter->sameTurn('wie ist der  Verkehr', 'Wie ist der Verkehr, bitte?'));
        self::assertSame('Wie ist der Verkehr, bitte?', $segmenter->sameTurn('Wie ist der Verkehr, bitte?', 'Wie ist der Verkehr'));
        self::assertNull($segmenter->sameTurn('Wie ist der Verkehr?', 'Danke, tschüss'));
        self::assertNull($segmenter->sameTurn('', 'Hallo'));
    }

    public function testATicketCanBeRedeemedOnce(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('setIfAbsent')->willReturnOnConsecutiveCalls(true, false);
        $tickets = new VoiceLiveTicketService($redis, 'secret-secret-secret-secret-32b!!');

        $token = $tickets->mint(4, 9);
        self::assertSame(['userId' => 4, 'sessionId' => 9], $tickets->redeem($token));

        $this->expectException(VoiceLiveRejectedException::class);
        $tickets->redeem($token);
    }

    public function testInternalHmacRejectsABadSignature(): void
    {
        $auth = new VoiceLiveInternalAuth('secret-secret-secret-secret-32b!!');
        $body = '{"ok":true}';
        $request = Request::create('/internal/voice/tickets/redeem', 'POST', content: $body);
        $request->headers->set('X-Voice-Timestamp', (string) time());
        $request->headers->set('X-Voice-Signature', hash_hmac('sha256', 'nope', 'secret-secret-secret-secret-32b!!'));

        $this->expectException(VoiceLiveRejectedException::class);
        $auth->assertValid($request);
    }

    public function testInternalHmacAcceptsTheBodyAndTimestamp(): void
    {
        $secret = 'secret-secret-secret-secret-32b!!';
        $auth = new VoiceLiveInternalAuth($secret);
        $body = '{"ok":true}';
        $timestamp = (string) time();
        $request = Request::create('/internal/voice/tickets/redeem', 'POST', content: $body);
        $request->headers->set('X-Voice-Timestamp', $timestamp);
        $request->headers->set('X-Voice-Signature', hash_hmac('sha256', $timestamp."\n".$body, $secret));

        $this->expectNotToPerformAssertions();
        $auth->assertValid($request);
    }

    public function testTheModuleStaysOffUntilTheGatewayAndAModelAreReady(): void
    {
        $probe = $this->createStub(SidecarHealthProbeInterface::class);
        $models = $this->createStub(ModelRepository::class);
        $keys = $this->createStub(ProviderKeyStore::class);
        $off = new LiveVoiceModule($probe, '', '', $models, $keys);
        self::assertFalse($off->isConfigured());

        $model = (new Model())->setService('OpenAI')->setTag('voice2voice')->setActive(1);
        $readyModels = $this->createStub(ModelRepository::class);
        $readyModels->method('findActiveByTag')->willReturn([$model]);
        $readyKeys = $this->createStub(ProviderKeyStore::class);
        $readyKeys->method('getStatus')->willReturn([
            'configured' => true,
            'source' => 'env',
            'origin' => null,
            'maskedKey' => 'sk-secret',
            'hasSecret' => false,
        ]);
        $on = new LiveVoiceModule($probe, 'http://voice:8096', 'secret', $readyModels, $readyKeys);

        self::assertTrue($on->isConfigured());
    }

    public function testACompatibleSocketCountsWithoutAProviderKey(): void
    {
        $probe = $this->createStub(SidecarHealthProbeInterface::class);
        $model = (new Model())->setService('Compatible')->setTag('voice2voice')->setActive(1);
        $models = $this->createStub(ModelRepository::class);
        $models->method('findActiveByTag')->willReturn([$model]);
        $keys = $this->createStub(ProviderKeyStore::class);
        $keys->method('getStatus')->willReturn([
            'configured' => false,
            'source' => 'none',
            'origin' => null,
            'maskedKey' => '',
            'hasSecret' => false,
        ]);
        $hidden = new LiveVoiceModule($probe, 'http://voice:8096', 'secret', $models, $keys, new VoiceLiveCompatibleEndpoint('https://gpu.example'));
        self::assertFalse($hidden->isConfigured());
        $shown = new LiveVoiceModule($probe, 'http://voice:8096', 'secret', $models, $keys, new VoiceLiveCompatibleEndpoint('wss://gpu.example/v1/realtime'));
        self::assertTrue($shown->isConfigured());
    }

    public function testACompatibleGpuAllowsFourSockets(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('increment')->willReturn(5);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturn(null);

        $this->expectException(VoiceLiveRejectedException::class);
        (new VoiceLiveConcurrency($redis, $config))->acquire('compatible');
    }

    public function testConcurrencyRefusesTheFiftyFirstOpenAiSocket(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('increment')->willReturn(51);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturn(null);

        $this->expectException(VoiceLiveRejectedException::class);
        (new VoiceLiveConcurrency($redis, $config))->acquire('OpenAI');
    }

    public function testDelegationChargesTheVoiceRowAndSkipsAMessagesRowPath(): void
    {
        $processor = $this->createMock(MessageProcessor::class);
        $processor->expects($this->once())->method('process')->with(
            $this->isInstanceOf(Message::class),
            self::callback(static fn (array $options): bool => true === ($options['incognito'] ?? false) && 'live_voice' === ($options['source'] ?? '')),
        )->willReturn([
            'success' => true,
            'response' => [
                'content' => 'It is four.',
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4],
                'metadata' => ['model_id' => 5, 'provider' => 'groq', 'model' => 'llama'],
            ],
        ]);

        $usage = $this->createMock(VoiceLiveUsageService::class);
        $usage->method('isIncognito')->willReturn(true);
        $usage->expects($this->once())->method('addAnswer')->with(
            $this->isInstanceOf(VoiceSession::class),
            '0.010000',
            3,
            4,
        );

        $costs = $this->createStub(CostCalculationService::class);
        $costs->method('calculateCost')->willReturn(new CostResult('0.010000', '0.004000', '0.006000', '0.000000', [], 3));

        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn((new User())->setMail('voice@synaplan.internal'));

        $service = new VoiceLiveDelegationService(
            $processor,
            $usage,
            $this->createStub(VoiceTranscriptWriter::class),
            $costs,
            $this->createStub(ModelConfigService::class),
            $users,
            new NullLogger(),
        );

        $session = (new VoiceSession())->setUserId(4)->setStatus('live');
        $answer = $service->answer($session, 'What time is it?');

        self::assertSame('It is four.', $answer['text']);
        self::assertSame([], $answer['facts']);
    }

    public function testDelegationKeepsOnlyTheQuestionAndLeavesTheReplyToTheSpokenTranscript(): void
    {
        $processor = $this->createStub(MessageProcessor::class);
        $processor->method('process')->willReturn([
            'success' => true,
            'response' => ['content' => 'It is four.', 'usage' => [], 'metadata' => ['model_id' => 5]],
        ]);
        $usage = $this->createStub(VoiceLiveUsageService::class);
        $usage->method('isIncognito')->willReturn(false);
        $costs = $this->createStub(CostCalculationService::class);
        $costs->method('calculateCost')->willReturn(new CostResult('0', '0', '0', '0', [], 0));
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn((new User())->setMail('voice@synaplan.internal'));

        $transcripts = $this->createMock(VoiceTranscriptWriter::class);
        $transcripts->expects($this->once())->method('record')->with(
            $this->isInstanceOf(VoiceSession::class),
            self::callback(static fn (array $turns): bool => 1 === count($turns) && 'user' === $turns[0]['role']),
        )->willReturn(null);

        $service = new VoiceLiveDelegationService($processor, $usage, $transcripts, $costs, $this->createStub(ModelConfigService::class), $users, new NullLogger());
        $answer = $service->answer((new VoiceSession())->setUserId(4)->setStatus('live'), 'What time is it?');

        self::assertSame('It is four.', $answer['text']);
    }

    public function testInstructionsNameThePersonAndRefuseADirectAnswer(): void
    {
        $user = (new User())->setMail('ada@synaplan.internal');
        $text = (new VoiceLiveInstructions())->build($user, 'User: Hello');

        self::assertStringContainsString('ada@synaplan.internal', $text);
        self::assertStringContainsString('Ask Synaplan', $text);
        self::assertStringContainsString('User: Hello', $text);
    }
}
