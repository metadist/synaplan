<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The widget session writes of the owner: deleting sessions removes them,
 * an AI session becomes internal while a human one stays human, and
 * another account deletes nothing.
 */
final class WidgetSessionWritesTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testTheOwnerDeletesSessions(): void
    {
        $owner = $this->createUser('sessions-owner');
        $widget = $this->widgetOf($owner);
        $first = $this->session($widget, WidgetSession::MODE_AI);
        $second = $this->session($widget, WidgetSession::MODE_AI);
        $this->authenticateClient($this->client, $owner);

        $this->json('DELETE', '/api/v1/widgets/'.$widget->getWidgetId().'/sessions', ['sessionIds' => [$first->getSessionId(), $second->getSessionId()]]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, json_decode((string) $this->client->getResponse()->getContent(), true)['deleted'] ?? null);
        self::assertSame([], $this->stored($widget));
    }

    public function testAnAiSessionBecomesInternalAndAHumanOneStaysHuman(): void
    {
        $owner = $this->createUser('sessions-owner');
        $widget = $this->widgetOf($owner);
        $ai = $this->session($widget, WidgetSession::MODE_AI);
        $human = $this->session($widget, WidgetSession::MODE_HUMAN);
        $this->authenticateClient($this->client, $owner);

        $this->json('POST', '/api/v1/widgets/'.$widget->getWidgetId().'/sessions/'.$ai->getSessionId().'/init-internal', []);
        self::assertResponseIsSuccessful();
        $this->json('POST', '/api/v1/widgets/'.$widget->getWidgetId().'/sessions/'.$human->getSessionId().'/init-internal', []);
        self::assertResponseIsSuccessful();

        self::assertSame([$ai->getSessionId() => WidgetSession::MODE_INTERNAL, $human->getSessionId() => WidgetSession::MODE_HUMAN], $this->stored($widget));
    }

    public function testAnotherAccountDeletesNothing(): void
    {
        $widget = $this->widgetOf($this->createUser('sessions-owner'));
        $session = $this->session($widget, WidgetSession::MODE_AI);
        $this->authenticateClient($this->client, $this->createUser('sessions-stranger'));

        $this->json('DELETE', '/api/v1/widgets/'.$widget->getWidgetId().'/sessions', ['sessionIds' => [$session->getSessionId()]]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([$session->getSessionId() => WidgetSession::MODE_AI], $this->stored($widget));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    private function widgetOf(User $owner): Widget
    {
        $widget = new Widget();
        $widget->setOwnerId((int) $owner->getId());
        $widget->setOwner($owner);
        $widget->setName('Sessions');
        $widget->setTaskPromptTopic('general');

        return $this->persistEntity($widget);
    }

    private function session(Widget $widget, string $mode): WidgetSession
    {
        $session = new WidgetSession();
        $session->setWidgetId($widget->getWidgetId());
        $session->setSessionId('ses_'.bin2hex(random_bytes(6)));
        $session->setMode($mode);

        return $this->persistEntity($session);
    }

    /**
     * @return array<string, string> session id => mode, as stored
     */
    private function stored(Widget $widget): array
    {
        $this->em->clear();
        $modes = [];
        foreach ($this->em->getRepository(WidgetSession::class)->findBy(['widgetId' => $widget->getWidgetId()], ['id' => 'ASC']) as $session) {
            $modes[$session->getSessionId()] = $session->getMode();
        }

        return $modes;
    }
}
