<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\InternalEmailService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\EmailMeRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmailMeRunnerTest extends TestCase
{
    private string $uploadDir;

    // Property (not param) on purpose: `parameter.unresolvableNativeType` for
    // a mocked final class is non-ignorable in PHPStan, the property variant
    // is allowlisted for tests/ in phpstan.neon (finals are bypassed at
    // runtime via dg/bypass-finals).
    private InternalEmailService&MockObject $emailService;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/emailme_test_'.uniqid();
        mkdir($this->uploadDir.'/1/000', 0777, true);
        $this->emailService = $this->createMock(InternalEmailService::class);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup of the temp uploads tree.
        foreach ([$this->uploadDir.'/1/000', $this->uploadDir.'/1', $this->uploadDir] as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    private function user(string $mail = 'alice@example.com', bool $verified = true): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getMail')->willReturn($mail);
        $user->method('isEmailVerified')->willReturn($verified);

        return $user;
    }

    private function repository(?User $user): UserRepository&MockObject
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('find')->willReturn($user);

        return $repo;
    }

    private function translator(): TranslatorInterface&MockObject
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => match ($id) {
                'email.task_result.subject' => 'Your Synaplan results',
                'email.task_result.sent_confirmation' => 'Sent to '.($params['%email%'] ?? '?'),
                default => $id,
            }
        );

        return $translator;
    }

    private function runner(?User $user): EmailMeRunner
    {
        return new EmailMeRunner(
            $this->emailService,
            $this->repository($user),
            $this->translator(),
            $this->createMock(LoggerInterface::class),
            $this->uploadDir,
        );
    }

    private function context(): NodeContext
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn('write a spring poem and mail it to me');
        $m->method('getFileText')->willReturn('');
        $m->method('getLanguage')->willReturn('en');
        $m->method('getFile')->willReturn(0);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection());

        return new NodeContext($m, [], 7, ['language' => 'en']);
    }

    private function emailNode(): TaskNode
    {
        return new TaskNode('n4', Capability::EmailMe, ['n1', 'n2', 'n3'], [
            'text' => '$n1.text',
            'attachments' => ['$n2.file', '$n3.file'],
        ]);
    }

    public function testSendsMultiMimeEmailAndStreamsMaskedConfirmation(): void
    {
        $mp3 = $this->uploadDir.'/1/000/poem.mp3';
        $png = $this->uploadDir.'/1/000/poem.png';
        file_put_contents($mp3, 'audio');
        file_put_contents($png, 'image');

        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok('THE POEM'));
        // n2 resolves via local_path, n3 via the static-serve URL fallback.
        $ctx->setResult('n2', NodeResult::ok(null, [['path' => '/api/v1/files/uploads/1/000/poem.mp3', 'type' => 'audio', 'local_path' => '1/000/poem.mp3']]));
        $ctx->setResult('n3', NodeResult::ok(null, [['path' => '/api/v1/files/uploads/1/000/poem.png', 'type' => 'image']]));

        $this->emailService->expects(self::once())
            ->method('sendTaskResultEmail')
            ->with(
                'alice@example.com',
                'Your Synaplan results',
                'THE POEM',
                [
                    ['path' => realpath($mp3), 'type' => 'audio'],
                    ['path' => realpath($png), 'type' => 'image'],
                ],
            );

        $chunks = [];
        $ctx->setChunkSink(function (string $nodeId, string $chunk) use (&$chunks): void {
            $chunks[] = [$nodeId, $chunk];
        });
        $ctx->beginNode('n4');

        $result = $this->runner($this->user())->run($this->emailNode(), $ctx);

        self::assertTrue($result->isSuccessful());
        self::assertSame('a***@example.com', $result->metadata['email_sent_to']);
        self::assertSame(2, $result->metadata['attachment_count']);
        // Confirmation reaches the task card without leaking the full address.
        self::assertSame([['n4', 'Sent to a***@example.com']], $chunks);
        self::assertStringNotContainsString('alice@example.com', (string) $result->text);
    }

    public function testFailsForPlaceholderChannelAddress(): void
    {
        $this->emailService->expects(self::never())->method('sendTaskResultEmail');

        $result = $this->runner($this->user('anonymous_abc@synaplan.local'))
            ->run($this->emailNode(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('no email address', (string) $result->error);
    }

    public function testFailsForUnverifiedAddress(): void
    {
        $this->emailService->expects(self::never())->method('sendTaskResultEmail');

        $result = $this->runner($this->user(verified: false))
            ->run($this->emailNode(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('not verified', (string) $result->error);
    }

    public function testFailsWhenOwnerMissing(): void
    {
        $result = $this->runner(null)->run($this->emailNode(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('owner not found', (string) $result->error);
    }

    public function testFailsWhenUpstreamProducedNothing(): void
    {
        $this->emailService->expects(self::never())->method('sendTaskResultEmail');

        // No upstream results set → text resolves null, attachments resolve empty.
        $result = $this->runner($this->user())->run($this->emailNode(), $this->context());

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('nothing to send', (string) $result->error);
    }

    public function testDeliveryFailureBecomesNodeFailure(): void
    {
        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok('THE POEM'));

        $this->emailService->method('sendTaskResultEmail')->willThrowException(new \RuntimeException('smtp down'));

        $result = $this->runner($this->user())->run($this->emailNode(), $ctx);

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('email delivery failed: smtp down', (string) $result->error);
    }

    public function testAttachmentsOutsideUploadsDirAreDropped(): void
    {
        // A real file that EXISTS one level above the uploads root — the
        // containment check (not mere existence) must reject the traversal.
        $outside = sys_get_temp_dir().'/outside_'.uniqid().'.txt';
        file_put_contents($outside, 'secret');

        $ctx = $this->context();
        $ctx->setResult('n1', NodeResult::ok('THE POEM'));
        // Traversal attempt + a descriptor with no resolvable path.
        $ctx->setResult('n2', NodeResult::ok(null, [[
            'path' => '/api/v1/files/uploads/'.basename($outside),
            'local_path' => '../'.basename($outside),
            'type' => 'document',
        ]]));
        $ctx->setResult('n3', NodeResult::ok(null, [['path' => '/somewhere/else/x.png', 'type' => 'image']]));

        $captured = null;
        $this->emailService->expects(self::once())
            ->method('sendTaskResultEmail')
            ->willReturnCallback(function (string $to, string $subject, string $markdown, array $attachments) use (&$captured): void {
                $captured = $attachments;
            });

        $result = $this->runner($this->user())->run($this->emailNode(), $ctx);

        self::assertTrue($result->isSuccessful());
        self::assertSame([], $captured, 'out-of-tree and unresolvable descriptors must be dropped');
        self::assertSame(0, $result->metadata['attachment_count']);

        @unlink($outside);
    }
}
