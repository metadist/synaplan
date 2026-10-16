<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\RAG\VectorSearchService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A public share token can read a cited passage and nothing else (#2400).
 */
final class SharedChatPassageTest extends WebTestCase
{
    private const CITED = 'cited-chunk-1';
    private const OTHER = 'other-user-chunk';
    private const PASSAGE = 'The secret passage text for the shared chat';
    private const FOREIGN = 'Another user library passage that must stay hidden';

    public function testShareTokenReadsOnlyACitedChunk(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $owner = $this->user($em, 'owner');
        $other = $this->user($em, 'other');

        $shared = $this->chat($em, $owner, true);
        $private = $this->chat($em, $other, true);
        $this->message($em, $shared, $owner, self::CITED);
        $this->message($em, $private, $other, self::OTHER);

        $ownerId = (int) $owner->getId();
        $search = $this->createStub(VectorSearchService::class);
        $search->method('passage')->willReturnCallback(
            static function (int $userId, string $chunkId) use ($ownerId): ?array {
                if ($userId === $ownerId && self::CITED === $chunkId) {
                    return self::passage(self::CITED, self::PASSAGE);
                }
                if (self::OTHER === $chunkId) {
                    return self::passage(self::OTHER, self::FOREIGN);
                }

                return null;
            }
        );
        $client->disableReboot();
        $client->getContainer()->set(VectorSearchService::class, $search);

        $token = (string) $shared->getShareToken();
        $client->request('GET', '/api/v1/chats/shared/'.$token);
        self::assertResponseIsSuccessful();
        $listed = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(self::CITED, $listed);
        self::assertStringNotContainsString(self::PASSAGE, $listed);

        $client->request('GET', '/api/v1/chats/shared/'.$token.'/rag/chunks/'.self::CITED);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertSame(self::PASSAGE, $body['text']);

        $client->request('GET', '/api/v1/chats/shared/'.$token.'/rag/chunks/'.self::OTHER);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(self::FOREIGN, (string) $client->getResponse()->getContent());

        $client->request('GET', '/api/v1/chats/shared/not-a-real-token/rag/chunks/'.self::CITED);
        self::assertResponseStatusCodeSame(404);
        $missing = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['success' => false, 'error' => 'Passage not found'], $missing);
    }

    /**
     * @return array{chunkId: string, fileId: int, fileName: string, groupKey: string, text: string, startLine: int, endLine: int}
     */
    private static function passage(string $chunkId, string $text): array
    {
        return [
            'chunkId' => $chunkId,
            'fileId' => 9,
            'fileName' => 'handbook.pdf',
            'groupKey' => 'docs',
            'text' => $text,
            'startLine' => 4,
            'endLine' => 8,
        ];
    }

    private function user(object $em, string $prefix): User
    {
        $user = new User();
        $user->setMail($prefix.bin2hex(random_bytes(4)).'@test.com');
        $user->setPw(password_hash('password', PASSWORD_BCRYPT));
        $user->setUserLevel('NEW');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setType('WEB');
        $user->setEmailVerified(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function chat(object $em, User $user, bool $public): Chat
    {
        $chat = new Chat();
        $chat->setUserId((int) $user->getId());
        $chat->setTitle('Shared sources');
        $chat->setIsPublic($public);
        $chat->generateShareToken();
        $em->persist($chat);
        $em->flush();

        return $chat;
    }

    private function message(object $em, Chat $chat, User $user, string $chunkId): void
    {
        $message = new Message();
        $message->setUserId((int) $user->getId());
        $message->setChat($chat);
        $message->setTrackingId(1);
        $message->setProviderIndex('openai');
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setText('Answer without the passage body');
        $message->setDirection('OUT');
        $message->setStatus('complete');
        $em->persist($message);
        $em->flush();

        $message->setMeta('rag_sources', json_encode([[
            'n' => 1,
            'chunkId' => $chunkId,
            'fileId' => 9,
            'fileName' => 'handbook.pdf',
            'groupKey' => 'docs',
            'score' => null,
            'startLine' => 4,
            'endLine' => 8,
        ]], JSON_THROW_ON_ERROR));
        $em->flush();
    }
}
