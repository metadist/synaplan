<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Message\MessageVersionService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The public page of a shared chat describes the conversation a reader sees.
 * A question the owner edited away is not part of it.
 */
final class SharedChatPageSupersededTest extends WebTestCase
{
    private const EDITED_AWAY = 'my password is HUNTER2-edited-away, summarise';
    private const CURRENT = 'Please summarise the quarterly report';

    public function testAnEditedAwayQuestionIsNotInThePage(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $owner = $this->user($em);
        $chat = $this->chat($em, $owner);
        $this->message($em, $chat, $owner, self::EDITED_AWAY, 100, [
            MessageVersionService::EDIT_GROUP => '1',
            MessageVersionService::EDIT_INDEX => '1',
            MessageVersionService::EDIT_SELECTED => '0',
        ]);
        $this->message($em, $chat, $owner, self::CURRENT, 200, [
            MessageVersionService::EDIT_GROUP => '1',
            MessageVersionService::EDIT_INDEX => '2',
            MessageVersionService::EDIT_SELECTED => '1',
        ]);

        $client->request('GET', '/shared/'.$chat->getShareToken());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('HUNTER2', $html);
        self::assertStringContainsString(self::CURRENT, $html);
    }

    public function testAnInactiveBranchIsNotInThePage(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $owner = $this->user($em);
        $chat = $this->chat($em, $owner);
        $this->message($em, $chat, $owner, self::EDITED_AWAY, 100, [
            MessageVersionService::BRANCH_INACTIVE => '1',
        ]);
        $this->message($em, $chat, $owner, self::CURRENT, 200, []);

        $client->request('GET', '/shared/'.$chat->getShareToken());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('HUNTER2', $html);
        self::assertStringContainsString(self::CURRENT, $html);
    }

    private function user(object $em): User
    {
        $user = new User();
        $user->setMail('share'.bin2hex(random_bytes(4)).'@test.com');
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

    private function chat(object $em, User $user): Chat
    {
        $chat = new Chat();
        $chat->setUserId((int) $user->getId());
        $chat->setTitle('New Chat');
        $chat->setIsPublic(true);
        $chat->generateShareToken();
        $em->persist($chat);
        $em->flush();

        return $chat;
    }

    /**
     * @param array<string, string> $meta
     */
    private function message(object $em, Chat $chat, User $user, string $text, int $offset, array $meta): void
    {
        $message = new Message();
        $message->setUserId((int) $user->getId());
        $message->setChat($chat);
        $message->setTrackingId(1);
        $message->setProviderIndex('openai');
        $message->setUnixTimestamp(time() - 1000 + $offset);
        $message->setDateTime(date('YmdHis'));
        $message->setText($text);
        $message->setDirection('IN');
        $message->setStatus('complete');
        $em->persist($message);
        $em->flush();

        foreach ($meta as $key => $value) {
            $message->setMeta($key, $value);
        }
        $em->flush();
    }
}
