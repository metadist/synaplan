<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\User;
use App\Observability\EventRingStore;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract of the admin logs endpoint: admins can query the redacted event
 * ring (recent + summary), non-admins cannot, and free text stays scrubbed.
 */
final class AdminLogsControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EventRingStore $store;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $store = $this->client->getContainer()->get(EventRingStore::class);
        self::assertInstanceOf(EventRingStore::class, $store);
        $this->store = $store;
        $this->store->clear();

        // Within the queryable window — the endpoint caps `since_minutes` at the
        // ring's 7-day retention, so fixed epoch timestamps would never show up.
        $now = time();
        $this->store->record(['event' => 'log', 'level' => 'warning', 'message' => 'slow query', 'route' => 'chat_send', 'ts' => $now - 120]);
        $this->store->record(['event' => 'exception', 'level' => 'error', 'exception_class' => 'RuntimeException', 'exception_message' => 'failed for admin@synaplan.com', 'route' => 'chat_send', 'ts' => $now - 60]);
    }

    protected function tearDown(): void
    {
        $this->store->clear();
        parent::tearDown();
    }

    private function authenticateAs(string $mail): bool
    {
        $user = $this->client->getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['mail' => $mail]);
        if (!$user instanceof User) {
            return false;
        }
        $token = $this->authenticateClient($this->client, $user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        return true;
    }

    public function testRecentReturnsEventsNewestFirst(): void
    {
        if (!$this->authenticateAs('admin@synaplan.com')) {
            self::markTestSkipped('admin@synaplan.com not found. Run fixtures first.');
        }

        $this->client->request('GET', '/api/v1/admin/logs');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertSame('recent', $data['mode']);
        self::assertCount(2, $data['events']);
        self::assertSame('exception', $data['events'][0]['event']);
        // Free text stays scrubbed end-to-end.
        self::assertStringNotContainsString('admin@synaplan.com', (string) $data['events'][0]['exception_message']);
    }

    public function testLevelFilter(): void
    {
        if (!$this->authenticateAs('admin@synaplan.com')) {
            self::markTestSkipped('admin@synaplan.com not found. Run fixtures first.');
        }

        $this->client->request('GET', '/api/v1/admin/logs?level=error');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertCount(1, $data['events']);
        self::assertSame('error', $data['events'][0]['level']);
    }

    public function testSummaryMode(): void
    {
        if (!$this->authenticateAs('admin@synaplan.com')) {
            self::markTestSkipped('admin@synaplan.com not found. Run fixtures first.');
        }

        $this->client->request('GET', '/api/v1/admin/logs?mode=summary&since_minutes=60');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('summary', $data['mode']);
        self::assertSame(2, $data['summary']['total']);
        self::assertSame(1, $data['summary']['by_level']['error']);
        self::assertSame(1, $data['summary']['by_level']['warning']);
        self::assertSame(2, $data['summary']['by_route']['chat_send']);
    }

    /**
     * Regression: an unbounded window made `time() - $minutes * 60` overflow to
     * a float, which under strict_types hit a TypeError in the store and turned
     * the request into a 500. The window is clamped to the ring's retention.
     */
    public function testOversizedWindowIsClampedInsteadOfFailing(): void
    {
        if (!$this->authenticateAs('admin@synaplan.com')) {
            self::markTestSkipped('admin@synaplan.com not found. Run fixtures first.');
        }

        foreach (['recent', 'summary'] as $mode) {
            $this->client->request('GET', '/api/v1/admin/logs?mode='.$mode.'&since_minutes='.\PHP_INT_MAX);

            self::assertSame(
                Response::HTTP_OK,
                $this->client->getResponse()->getStatusCode(),
                "mode={$mode} should clamp the window, not fail.",
            );
        }
    }

    public function testNonAdminIsForbidden(): void
    {
        if (!$this->authenticateAs('demo@synaplan.com')) {
            self::markTestSkipped('demo@synaplan.com not found. Run fixtures first.');
        }

        $this->client->request('GET', '/api/v1/admin/logs');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->client->request('GET', '/api/v1/admin/logs');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }
}
