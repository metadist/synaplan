<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Connection;
use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract of the admin Dropbox reset: one call wipes every user's Dropbox
 * connection (rows + token pointers) so the OAuth registration can be redone
 * freshly, while connections of other types stay untouched.
 */
class AdminDropboxConnectionControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private ConnectionRepository $connections;
    private User $admin;
    private User $member;

    /** @var list<int> */
    private array $createdConnectionIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $doctrine = $this->client->getContainer()->get('doctrine');
        $this->connections = $doctrine->getRepository(Connection::class);

        $users = $doctrine->getRepository(User::class);
        $admin = $users->findOneBy(['mail' => 'admin@synaplan.com']);
        $member = $users->findOneBy(['mail' => 'demo@synaplan.com']);

        if (!$admin || !$member) {
            self::markTestSkipped('Test users admin@/demo@synaplan.com not found. Run fixtures first.');
        }

        $this->admin = $admin;
        $this->member = $member;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdConnectionIds as $id) {
            $connection = $this->connections->find($id);
            if ($connection) {
                $this->connections->remove($connection);
            }
        }

        parent::tearDown();
    }

    public function testResetRemovesEveryDropboxConnectionButNothingElse(): void
    {
        $adminDropbox = $this->createConnection((int) $this->admin->getId(), Connection::TYPE_DROPBOX, 'admin@dropbox.example');
        $memberDropbox = $this->createConnection((int) $this->member->getId(), Connection::TYPE_DROPBOX, 'member@dropbox.example');
        $memberWebdav = $this->createConnection((int) $this->member->getId(), 'webdav', 'Member Nextcloud');

        $token = $this->authenticateClient($this->client, $this->admin);
        $this->client->request('POST', '/api/v1/admin/connections/dropbox/reset', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertGreaterThanOrEqual(2, $data['removed']);

        self::assertNull($this->connections->find($adminDropbox));
        self::assertNull($this->connections->find($memberDropbox));
        self::assertNotNull($this->connections->find($memberWebdav));
    }

    public function testResetIsForbiddenForRegularUsers(): void
    {
        $token = $this->authenticateClient($this->client, $this->member);
        $this->client->request('POST', '/api/v1/admin/connections/dropbox/reset', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    private function createConnection(int $ownerId, string $type, string $name): int
    {
        $connection = new Connection($ownerId, $type, $name);
        $this->connections->save($connection);

        $id = (int) $connection->getId();
        $this->createdConnectionIds[] = $id;

        return $id;
    }
}
