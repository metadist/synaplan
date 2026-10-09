<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\AiResponseSanitizer;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Session projections hide an AI scratchpad and leave a visitor's literal
 * <think> text alone (#2426).
 */
final class WidgetSessionScratchpadTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const VISITOR = '<think>example</think> keep me';
    private const AI = '<think>User says hello. Be brief.</think>Hi there! How can I help you today?';

    public function testListAndDetailAreRoleAware(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $user = new User();
        $user->setMail('scratchpad'.bin2hex(random_bytes(4)).'@test.com');
        $user->setPw(password_hash('password', PASSWORD_BCRYPT));
        $user->setUserLevel('NEW');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setType('WEB');
        $user->setEmailVerified(true);
        $em->persist($user);
        $em->flush();

        $widget = new Widget();
        $widget->setOwnerId((int) $user->getId());
        $widget->setOwner($user);
        $widget->setName('Scratchpad');
        $widget->setTaskPromptTopic('general');
        $em->persist($widget);

        $visitorChat = new Chat();
        $visitorChat->setUserId((int) $user->getId());
        $visitorChat->setTitle('visitor');
        $aiChat = new Chat();
        $aiChat->setUserId((int) $user->getId());
        $aiChat->setTitle('ai');
        $em->persist($visitorChat);
        $em->persist($aiChat);
        $em->flush();

        $this->message($em, $visitorChat, $user, self::VISITOR, 'IN', '');
        $this->message($em, $aiChat, $user, self::VISITOR, 'IN', '');
        $this->message($em, $aiChat, $user, self::AI, 'OUT', 'openai');

        $visitorSession = $this->session($widget, $visitorChat);
        $aiSession = $this->session($widget, $aiChat);
        $visitorSession->setLastMessagePreview(self::VISITOR);
        $aiSession->setLastMessagePreview(AiResponseSanitizer::stripForDisplay(self::AI));
        $em->persist($visitorSession);
        $em->persist($aiSession);
        $em->flush();

        $em->refresh($visitorSession);
        self::assertStringContainsString('example', (string) $visitorSession->getLastMessagePreview());
        self::assertStringNotContainsString('<think>', (string) $aiSession->getLastMessagePreview());
        self::assertStringContainsString('Hi there!', (string) $aiSession->getLastMessagePreview());

        $this->authenticateClient($client, $user);
        $client->request('GET', '/api/v1/widgets/'.$widget->getWidgetId().'/sessions');
        self::assertResponseIsSuccessful();
        $listed = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $bySession = [];
        foreach ($listed['sessions'] as $row) {
            $bySession[$row['sessionId']] = $row['lastMessagePreview'];
        }
        self::assertStringContainsString('<think>example</think>', (string) $bySession[$visitorSession->getSessionId()]);
        self::assertSame('Hi there! How can I help you today?', $bySession[$aiSession->getSessionId()]);

        $client->request('GET', '/api/v1/widgets/'.$widget->getWidgetId().'/sessions/'.$aiSession->getSessionId());
        self::assertResponseIsSuccessful();
        $detail = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $texts = array_column($detail['messages'], 'text', 'sender');
        self::assertSame(self::VISITOR, $texts['user']);
        self::assertSame('Hi there! How can I help you today?', $texts['ai']);
        self::assertSame('Hi there! How can I help you today?', $detail['session']['lastMessagePreview']);
    }

    private function message(object $em, Chat $chat, User $user, string $text, string $direction, string $provider): void
    {
        $message = new Message();
        $message->setUserId((int) $user->getId());
        $message->setChat($chat);
        $message->setTrackingId(1);
        $message->setProviderIndex($provider);
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setText($text);
        $message->setDirection($direction);
        $message->setStatus('complete');
        $em->persist($message);
        $em->flush();
    }

    private function session(Widget $widget, Chat $chat): WidgetSession
    {
        $session = new WidgetSession();
        $session->setWidgetId($widget->getWidgetId());
        $session->setSessionId('ses_'.bin2hex(random_bytes(6)));
        $session->setChatId($chat->getId());
        $session->setLastMessage(time());

        return $session;
    }
}
