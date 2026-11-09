<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Exception\ChatFailureReason;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\Auth\ProviderDiagnosticsAccess;
use App\Service\File\DataUrlFixer;
use App\Service\Message\MessageApiFormatter;
use PHPUnit\Framework\TestCase;

/**
 * The stored provider dump is part of the history payload only when the
 * viewer is allowed to see it. The sentence the account already saw stays
 * either way.
 */
final class MessageApiFormatterDiagnosticsTest extends TestCase
{
    public function testAccountOwnerGetsTheSentenceWithoutTheDump(): void
    {
        $row = $this->formatter(maySee: false)->format($this->failedMessage());

        self::assertSame('The video could not be created.', $row['text']);
        self::assertNull($row['errorDebug']);
        self::assertSame(ChatFailureReason::Unknown->value, $row['errorReason']);
    }

    public function testImpersonatingAdministratorGetsTheDump(): void
    {
        $row = $this->formatter(maySee: true)->format($this->failedMessage());

        self::assertSame('The video could not be created.', $row['text']);
        self::assertSame('Provider: google status 500', $row['errorDebug']);
    }

    public function testTaskCardDumpFollowsTheSameGate(): void
    {
        $message = $this->failedMessage();
        $message->setMeta('task_plan', (string) json_encode([
            'reply_node' => 'video',
            'cards' => [[
                'nodeId' => 'video',
                'capability' => 'video',
                'kind' => 'media',
                'state' => 'failed',
                'error' => "The video could not be created.\n\n---\n**Admin diagnostics (visible to you only)**\nupstream timeout",
            ]],
        ], \JSON_THROW_ON_ERROR));
        $message->setMeta('node_error_debug', (string) json_encode([
            'video' => 'job 91 failed',
        ], \JSON_THROW_ON_ERROR));

        $hidden = $this->formatter(maySee: false)->format($message)['taskPlan'];
        self::assertIsArray($hidden);
        self::assertSame('The video could not be created.', $hidden['cards'][0]['error']);
        self::assertArrayNotHasKey('errorDebug', $hidden['cards'][0]);

        $shown = $this->formatter(maySee: true)->format($message)['taskPlan'];
        self::assertIsArray($shown);
        self::assertSame('job 91 failed', $shown['cards'][0]['errorDebug']);
    }

    private function formatter(bool $maySee): MessageApiFormatter
    {
        $gate = $this->createStub(ProviderDiagnosticsAccess::class);
        $gate->method('viewerMaySee')->willReturn($maySee);

        return new MessageApiFormatter(
            $this->createStub(MessageRepository::class),
            $this->createStub(SearchResultRepository::class),
            $this->createStub(DataUrlFixer::class),
            $gate,
        );
    }

    private function failedMessage(): Message
    {
        $message = new Message();
        $message->setDirection('OUT');
        $message->setTopic('ERROR');
        $message->setText("The video could not be created.\n\n---\n**Admin diagnostics (visible to you only)**\nembedded dump");
        $message->setUnixTimestamp(1_720_000_000);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, 4242);
        $message->setMeta('error_reason', ChatFailureReason::Unknown->value);
        $message->setMeta('error_debug', 'Provider: google status 500');

        return $message;
    }
}
