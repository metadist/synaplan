<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\OutboundWebhookRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\SavedTask\Graph\StepInputResolver;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OutboundWebhookRunnerTest extends TestCase
{
    public function testRejectsHttpAndBlockedHosts(): void
    {
        $ssrf = $this->createMock(SsrfGuard::class);
        $ssrf->method('isBlockedUrl')->willReturn(true);
        $runner = new OutboundWebhookRunner(new MockHttpClient(), $ssrf, new StepInputResolver(), new NullLogger());
        $plain = $runner->run(
            new TaskNode('w1', Capability::OutboundWebhook, [], [], ['url' => 'http://example.com/hook']),
            $this->context(),
        );
        self::assertFalse($plain->isSuccessful());

        $blocked = $runner->run(
            new TaskNode('w1', Capability::OutboundWebhook, [], [], ['url' => 'https://127.0.0.1/hook']),
            $this->context(),
        );
        self::assertFalse($blocked->isSuccessful());
    }

    public function testPostsSignedBodyWithoutCredentials(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $ssrf = $this->createMock(SsrfGuard::class);
        $ssrf->method('isBlockedUrl')->willReturn(false);
        $runner = new OutboundWebhookRunner($client, $ssrf, new StepInputResolver(), new NullLogger());
        $result = $runner->run(
            new TaskNode('w1', Capability::OutboundWebhook, [], [], [
                'url' => 'https://hooks.example/in',
                'secret' => 'abc',
                'inputs' => ['text' => ['literal' => 'done']],
            ]),
            $this->context(),
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame('POST', $captured['method']);
        $body = is_string($captured['options']['body'] ?? null) ? $captured['options']['body'] : '';
        self::assertStringContainsString('"result"', $body);
        self::assertStringNotContainsString('abc', $body);
        self::assertStringNotContainsString('sk_', $body);
    }

    public function testWithoutAMappingItSendsWhatTheEarlierStepsProduced(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = is_string($options['body'] ?? null) ? $options['body'] : '';

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $ssrf = $this->createMock(SsrfGuard::class);
        $ssrf->method('isBlockedUrl')->willReturn(false);
        $runner = new OutboundWebhookRunner($client, $ssrf, new StepInputResolver(), new NullLogger());
        $context = $this->context();
        $context->setResult('n1', NodeResult::ok('Weekly digest ready', [], ['tool' => 'custom:digest']));

        $result = $runner->run(
            new TaskNode('w1', Capability::OutboundWebhook, ['n1'], [], ['url' => 'https://hooks.example/in']),
            $context,
        );

        self::assertTrue($result->isSuccessful());
        $decoded = json_decode($captured, true);
        self::assertIsArray($decoded);
        self::assertSame('Weekly digest ready', $decoded['result']['n1']['text'] ?? null);
        self::assertSame('custom:digest', $decoded['result']['n1']['metadata']['tool'] ?? null);
    }

    private function context(): NodeContext
    {
        $message = new Message();
        $message->setUserId(1);

        return new NodeContext($message, [], 1, ['language' => 'en'], [
            'saved_task_id' => 3,
            'saved_task_run_id' => 8,
        ]);
    }
}
