<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin catalog never hides rows — it flags them: every serialized model
 * carries the availability verdict so the UI can grey unavailable providers
 * and badge unpulled Ollama models.
 */
final class AdminModelsControllerAvailabilityTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function loginAs(string $mail): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['mail' => $mail]);
        if (!$user) {
            self::markTestSkipped("Fixture user $mail not found. Run fixtures first.");
        }

        $this->authenticateClient($this->client, $user);
    }

    public function testEveryRowCarriesTheAvailabilityVerdict(): void
    {
        $this->loginAs('admin@synaplan.com');

        $this->client->request('GET', '/api/v1/admin/models');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertNotEmpty($data['models']);

        foreach ($data['models'] as $row) {
            self::assertArrayHasKey('providerAvailable', $row);
            self::assertIsBool($row['providerAvailable']);
            if ($row['providerAvailable']) {
                self::assertNull($row['unavailableReason']);
            } else {
                self::assertContains($row['unavailableReason'], ['provider_unavailable', 'not_pulled']);
            }
        }
    }

    public function testRegularUsersGetNoAdminCatalog(): void
    {
        $this->loginAs('demo@synaplan.com');

        $this->client->request('GET', '/api/v1/admin/models');
        self::assertResponseStatusCodeSame(403);
    }
}
