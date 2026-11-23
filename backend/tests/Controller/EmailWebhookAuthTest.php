<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Email\InboundEmailRelayAuth;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The inbound email webhook answers as the account behind `from`. Only the
 * instance's own relay may name that sender: an administrator's API key or
 * the mailbox poller's signature.
 */
final class EmailWebhookAuthTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testAStrangerCannotSpeakForARegisteredAccount(): void
    {
        $victim = $this->user('email-relay-victim@synaplan.internal', 'PRO');
        $before = $this->messagesOf($victim);

        $this->deliver($this->mail('email-relay-victim@synaplan.internal'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Authentication required'], $this->json());
        self::assertSame($before, $this->messagesOf($victim));
    }

    public function testAStrangerCannotCreateAnAccount(): void
    {
        $sender = sprintf('email-relay-stranger-%s@example.com', bin2hex(random_bytes(4)));

        $this->deliver($this->mail($sender));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['mail' => $sender]));
    }

    public function testAForgedOrStaleSignatureIsRefused(): void
    {
        $mail = $this->mail('email-relay-victim@synaplan.internal');
        $stale = $this->relay()->headers($mail, time() - 300);

        foreach ([[InboundEmailRelayAuth::TIMESTAMP_HEADER => (string) time(), InboundEmailRelayAuth::SIGNATURE_HEADER => str_repeat('a', 64)], $stale] as $headers) {
            $this->deliver($mail, $this->server($headers));
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        }
    }

    public function testAnAccountThatIsNotAnAdministratorCannotSpeakForAnother(): void
    {
        $victim = $this->user('email-relay-victim@synaplan.internal', 'PRO');
        $before = $this->messagesOf($victim);
        $key = $this->keyOf($this->user('email-relay-member@synaplan.internal', 'PRO'));

        $this->deliver($this->mail('email-relay-victim@synaplan.internal'), ['HTTP_X_API_KEY' => $key]);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->messagesOf($victim));
    }

    public function testTheRelayOfTheInstanceGetsPastTheCheck(): void
    {
        // An empty mail is answered by the payload check, which comes after the caller check.
        $this->deliver('{}', ['HTTP_X_API_KEY' => $this->keyOf($this->user('email-relay-admin@synaplan.internal', 'ADMIN'))]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());

        $this->deliver('{}', $this->server($this->relay()->headers('{}')));
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, string> $server
     */
    private function deliver(string $content, array $server = []): void
    {
        $this->client->request('POST', '/api/v1/webhooks/email', server: ['CONTENT_TYPE' => 'application/json'] + $server, content: $content);
    }

    private function mail(string $from): string
    {
        return json_encode(['from' => $from, 'to' => 'smart@synaplan.net', 'subject' => 'Hi', 'body' => 'What do you remember about me?'], JSON_THROW_ON_ERROR);
    }

    private function relay(): InboundEmailRelayAuth
    {
        return new InboundEmailRelayAuth((string) static::getContainer()->getParameter('kernel.secret'));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    private function messagesOf(User $user): int
    {
        return $this->em->getRepository(Message::class)->count(['userId' => $user->getId()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'Response was not JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
    }

    private function user(string $email, string $level): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('email-relay-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function keyOf(User $owner): string
    {
        $secret = 'sk_'.bin2hex(random_bytes(20));
        $this->em->persist((new ApiKey())
            ->setOwner($owner)
            ->setKey($secret)
            ->setStatus('active')
            ->setName('Email relay test')
            ->setScopes(['webhooks:email']));
        $this->em->flush();

        return $secret;
    }
}
