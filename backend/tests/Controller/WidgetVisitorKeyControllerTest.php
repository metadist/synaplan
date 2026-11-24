<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Widget;
use App\Service\Widget\WidgetVisitorIdentity;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WidgetVisitorKeyControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private User $other;
    private Widget $widget;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $users = $this->em->getRepository(User::class);
        $owner = $users->findOneBy(['mail' => 'admin@synaplan.com']);
        $other = $users->findOneBy(['mail' => 'demo@synaplan.com']);
        if (!$owner instanceof User || !$other instanceof User) {
            self::markTestSkipped('Fixture users admin@synaplan.com and demo@synaplan.com are required.');
        }
        $this->owner = $owner;
        $this->other = $other;

        $this->widget = new Widget();
        $this->widget->setOwnerId((int) $owner->getId());
        $this->widget->setOwner($owner);
        $this->widget->setName('Visitor key test');
        $this->widget->setTaskPromptTopic('general');
        $this->widget->setConfig(['primaryColor' => '#123456']);
        $this->em->persist($this->widget);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $stored = $em->getRepository(Widget::class)->findOneBy(['widgetId' => $this->widget->getWidgetId()]);
        if ($stored instanceof Widget) {
            $em->remove($stored);
            $em->flush();
        }
        parent::tearDown();
    }

    public function testOnlyTheOwnerReadsTheKey(): void
    {
        $path = '/api/v1/widgets/'.$this->widget->getWidgetId().'/visitor-key';

        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->authenticateClient($this->client, $this->other);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('POST', $path.'/rotate');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request('POST', $path.'/require-signature');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->authenticateClient($this->client, $this->owner);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = $this->body();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['key']);
        self::assertSame(WidgetVisitorIdentity::MAX_LIFETIME_SECONDS, $body['maxLifetimeSeconds']);
        self::assertTrue($body['signatureRequired']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->request('GET', '/api/v1/widgets/wdg_does_not_exist/visitor-key');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testARotatedKeyIsNotBroughtBackBySavingTheConfig(): void
    {
        $path = '/api/v1/widgets/'.$this->widget->getWidgetId();
        $this->authenticateClient($this->client, $this->owner);

        $this->client->request('GET', $path.'/visitor-key');
        $first = $this->body()['key'];
        $this->client->request('POST', $path.'/visitor-key/rotate');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $second = $this->body()['key'];
        self::assertNotSame($first, $second);

        $this->client->request(
            'PUT',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['config' => ['primaryColor' => '#654321', WidgetVisitorIdentity::KEY_VERSION => 1]], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->request('GET', $path.'/visitor-key');
        self::assertSame($second, $this->body()['key']);
    }

    public function testTheOwnerTurnsSigningOnAndNoSavedConfigTurnsItOff(): void
    {
        $this->widget->setConfigValue(WidgetVisitorIdentity::UNSIGNED_ALLOWED, true);
        $this->em->flush();
        $path = '/api/v1/widgets/'.$this->widget->getWidgetId();
        $this->authenticateClient($this->client, $this->owner);

        $this->client->request('GET', $path.'/visitor-key');
        self::assertFalse($this->body()['signatureRequired']);

        $this->saveConfig($path, ['primaryColor' => '#654321']);
        $this->client->request('GET', $path.'/visitor-key');
        self::assertFalse($this->body()['signatureRequired'], 'saving the form keeps a widget from before signing as it is');

        $this->client->request('POST', $path.'/visitor-key/require-signature');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertTrue($this->body()['signatureRequired']);

        $this->saveConfig($path, [WidgetVisitorIdentity::UNSIGNED_ALLOWED => true]);
        $this->client->request('GET', $path.'/visitor-key');
        self::assertTrue($this->body()['signatureRequired']);
    }

    public function testANewWidgetCannotBeCreatedWithTheMark(): void
    {
        $this->authenticateClient($this->client, $this->owner);
        $this->client->request(
            'POST',
            '/api/v1/widgets',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Visitor key mark test', 'config' => [WidgetVisitorIdentity::UNSIGNED_ALLOWED => true]], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $widgetId = $this->body()['widget']['widgetId'];

        $this->client->request('GET', '/api/v1/widgets/'.$widgetId.'/visitor-key');
        self::assertTrue($this->body()['signatureRequired']);

        $this->client->request('DELETE', '/api/v1/widgets/'.$widgetId);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function saveConfig(string $path, array $config): void
    {
        $this->client->request(
            'PUT',
            $path,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['config' => $config], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
