<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Email\Turn;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Email\EmailChatService;
use App\Service\Email\InboundEmailAttachmentStore;
use App\Service\Email\Turn\EmailConversation;
use App\Service\Email\Turn\EmailThread;
use App\Service\Email\Turn\InboundEmail;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class EmailConversationTest extends TestCase
{
    /** @var list<mixed> */
    private array $steps = [];

    public function testTheSubjectLeadsTheStoredText(): void
    {
        self::assertSame("Subject: Opening hours\n\nWhen?", $this->store($this->email(subject: 'Opening hours'))->getText());
        self::assertSame('When?', $this->store($this->email(subject: '(no subject)'))->getText());
        self::assertSame('When?', $this->store($this->email(subject: ''))->getText());
    }

    public function testTheMailsAddressesAndThreadBecomeMetadata(): void
    {
        $message = $this->store($this->email(keyword: 'support', externalId: 'm1'));

        self::assertSame(
            ['email', 'ada@example.com', 'smart+support@synaplan.net', 'fp', 'support', 'Opening hours', 'm1', null],
            array_map($message->getMeta(...), ['channel', 'from_email', 'to_email', 'email_fingerprint', 'email_keyword', 'email_subject', 'external_id', 'has_attachments']),
        );
        self::assertSame([null, null], [$this->store($this->email())->getMeta('email_keyword'), $this->store($this->email())->getMeta('external_id')]);
    }

    public function testAttachmentsAreStoredAndAnAudioOneAsksForASpokenReply(): void
    {
        $audio = $this->store($this->email(attachments: [['filename' => 'a.pdf', 'content_type' => 'application/pdf'], ['filename' => 'n.ogg', 'content_type' => 'audio/ogg']]));
        $document = $this->store($this->email(attachments: [['filename' => 'a.pdf', 'content_type' => 'application/pdf']]));

        self::assertSame(['true', '1', 'true', null], [$audio->getMeta('has_attachments'), $audio->getMeta('voice_reply'), $document->getMeta('has_attachments'), $document->getMeta('voice_reply')]);
        self::assertSame([['attach', 2], ['attach', 1]], array_values(array_filter($this->steps, static fn (array $step): bool => 'attach' === $step[0])));
    }

    public function testTheMessageIsStoredBeforeItsMetadata(): void
    {
        $this->store($this->email());

        self::assertSame([['chat', 'Opening hours'], ['persist', 'IN', 'processing'], ['flush'], ['flush']], $this->steps);
    }

    private function store(InboundEmail $email): Message
    {
        $chats = $this->createStub(EmailChatService::class);
        $chats->method('findOrCreateChatContext')->willReturnCallback(function (User $user, ?string $keyword, ?string $subject): Chat {
            $this->steps[] = ['chat', $subject];

            return new Chat();
        });
        $attachments = $this->createStub(InboundEmailAttachmentStore::class);
        $attachments->method('attach')->willReturnCallback(function (Message $message, User $user, array $items): array {
            $this->steps[] = ['attach', count($items)];

            return [];
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Message) {
                $this->steps[] = ['persist', $entity->getDirection(), $entity->getStatus()];
            }
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->steps[] = ['flush'];
        });

        return (new EmailConversation($chats, $attachments, $em))->store($email)->message;
    }

    /**
     * @param array<mixed> $attachments
     */
    private function email(string $subject = 'Opening hours', ?string $keyword = null, ?string $externalId = null, array $attachments = []): InboundEmail
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(4);

        return new InboundEmail('ada@example.com', 'smart+support@synaplan.net', $subject, 'When?', new EmailThread(null, null, $externalId), $keyword, 'fp', $attachments, $user, false);
    }
}
