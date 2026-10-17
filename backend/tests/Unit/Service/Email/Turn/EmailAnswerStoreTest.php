<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email\Turn;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Email\Turn\EmailAnswerStore;
use App\Service\Email\Turn\EmailThread;
use App\Service\Email\Turn\InboundEmail;
use App\Service\Email\Turn\StoredInboundEmail;
use App\Service\Media\GeneratedFileMetadataNormalizer;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class EmailAnswerStoreTest extends TestCase
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $usage = [];
    private ?Message $answer = null;

    public function testTheAnswerAndTheMediaItMadeCountAsUsage(): void
    {
        $this->store()->recordUsage($this->email(), $this->stored(), 'Here', ['provider' => 'test', 'media_type' => 'video', 'media_usage' => ['seconds' => 8]], 0.5);
        $this->store()->recordUsage($this->email(), $this->stored(), 'Here', ['media_type' => ['not', 'a', 'type']], 0.5);

        self::assertSame(['MESSAGES', 'VIDEOS', 'MESSAGES'], array_column($this->usage, 0));
        self::assertSame([500, 'EMAIL', 'Question'], [$this->usage[0][1]['latency'], $this->usage[0][1]['source'], $this->usage[0][1]['input_text']]);
        self::assertSame(['seconds' => 8], $this->usage[1][1]['media_usage']);
    }

    public function testTheStoredAnswerCarriesItsMediaCostAndEveryExtraUsage(): void
    {
        $stored = $this->stored();
        $stored->message->setMeta('ai_transcription_usage', '{"seconds":3}');

        $this->store()->store($this->email(), $stored, 'Here is your cat.', [
            'classification' => ['topic' => 'general', 'language' => 'de', 'sorting_usage' => ['prompt_tokens' => 5], 'sorting_provider' => 'test', 'sorting_model_name' => 'sorter'],
            'response' => ['metadata' => [
                'provider' => 'test',
                'model' => 'm',
                'model_id' => 12,
                'usage' => ['total' => 7],
                'file' => ['path' => '/api/v1/files/uploads/7/cat.png', 'type' => 'image'],
                'planning_usage' => ['steps' => 2],
            ]],
        ], new RecordedUsage('0.001000', '0.000800', 3, 4, 7));

        self::assertSame(['general', 'de', 'complete'], [$stored->message->getTopic(), $stored->message->getLanguage(), $stored->message->getStatus()]);
        $answer = $this->answer;
        self::assertNotNull($answer);
        self::assertSame([1, '/api/v1/files/uploads/7/cat.png', 'image', 'de'], [$answer->getFile(), $answer->getFilePath(), $answer->getFileType(), $answer->getLanguage()]);
        self::assertSame(['test', 'm', '{"total":7}', '12', '0.001000'], array_map($answer->getMeta(...), ['ai_chat_provider', 'ai_chat_model', 'ai_chat_usage', 'ai_chat_model_id', 'ai_chat_cost']));
        $extra = json_decode((string) $answer->getMeta('ai_usage_extra'), true);
        self::assertIsArray($extra);
        self::assertCount(3, $extra);
        self::assertSame([['steps' => 2], ['seconds' => 3]], array_slice($extra, 1));
    }

    private function store(): EmailAnswerStore
    {
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata): RecordedUsage {
            $this->usage[] = [$action, $metadata];

            return new RecordedUsage('0', '0', 0, 0, 0);
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->answer = $entity instanceof Message ? $entity : $this->answer;
        });

        return new EmailAnswerStore($limits, new GeneratedFileMetadataNormalizer(), $this->createStub(ConversationSummaryRefreshDispatcher::class), $em);
    }

    private function stored(): StoredInboundEmail
    {
        return new StoredInboundEmail(new Chat(), (new Message())->setText('Question')->setTrackingId(1)->setLanguage('en'));
    }

    private function email(): InboundEmail
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(4);

        return new InboundEmail('ada@example.com', 'smart@synaplan.net', 'Hi', 'Question', new EmailThread(null, null, null), null, 'fp', [], $user, false);
    }
}
