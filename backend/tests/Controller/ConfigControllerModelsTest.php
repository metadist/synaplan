<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /api/v1/config/models is availability-filtered: regular users only ever see
 * models whose provider is configured on this installation, admins can opt in
 * to the full list with unavailable rows flagged.
 *
 * Which providers hold credentials depends on the environment the suite runs
 * in, so the assertions are structural (the response must be self-consistent)
 * rather than pinning concrete providers.
 */
final class ConfigControllerModelsTest extends WebTestCase
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

    /**
     * The provider entries are deliberately untyped ("array<string, mixed>"):
     * the tests assert their shape at runtime, which PHPStan would otherwise
     * flag as always-true.
     *
     * @return array{models: array<string, list<array<string, mixed>>>, providers: list<array<string, mixed>>}
     */
    private function fetchModels(string $query = ''): array
    {
        $this->client->request('GET', '/api/v1/config/models'.$query);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('models', $data);
        self::assertArrayHasKey('providers', $data);

        return $data;
    }

    /** @return list<array<string, mixed>> every model row across all capabilities */
    private static function allRows(array $data): array
    {
        $rows = [];
        foreach ($data['models'] as $capabilityRows) {
            foreach ($capabilityRows as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function testRegularUsersOnlySeeAvailableModels(): void
    {
        $this->loginAs('demo@synaplan.com');
        $data = $this->fetchModels();

        foreach (self::allRows($data) as $row) {
            self::assertTrue($row['available'], sprintf('Model "%s" (%s) is unavailable and must be hidden from regular users.', $row['name'], $row['service']));
            self::assertNull($row['unavailableReason']);
        }
    }

    public function testIncludeUnavailableIsSilentlyIgnoredForRegularUsers(): void
    {
        $this->loginAs('demo@synaplan.com');
        $data = $this->fetchModels('?includeUnavailable=1');

        foreach (self::allRows($data) as $row) {
            self::assertTrue($row['available'], 'A regular user must never receive unavailable models, even when asking for them.');
        }
    }

    public function testAdminIncludeUnavailableFlagsEveryRowConsistently(): void
    {
        $this->loginAs('admin@synaplan.com');
        $filtered = $this->fetchModels();
        $full = $this->fetchModels('?includeUnavailable=1');

        self::assertGreaterThanOrEqual(
            count(self::allRows($filtered)),
            count(self::allRows($full)),
            'The unfiltered admin view can never contain fewer rows than the filtered one.'
        );

        foreach (self::allRows($full) as $row) {
            self::assertIsBool($row['available']);
            if ($row['available']) {
                self::assertNull($row['unavailableReason']);
            } else {
                self::assertContains($row['unavailableReason'], ['provider_unavailable', 'not_pulled']);
            }
        }
    }

    public function testProvidersBlockListsRegisteredProvidersWithoutTheTestFixture(): void
    {
        $this->loginAs('demo@synaplan.com');
        $data = $this->fetchModels();

        self::assertNotEmpty($data['providers']);
        foreach ($data['providers'] as $provider) {
            self::assertNotSame('test', $provider['name']);
            self::assertIsString($provider['displayName']);
            self::assertNotSame('', $provider['displayName']);
            self::assertIsBool($provider['available']);
            self::assertIsBool($provider['requiresKey']);
        }

        // The key wizard set must be flagged as key-based — the frontend uses
        // this to gate "Select suggested models" on "all keys present".
        $requiresKey = array_column($data['providers'], 'requiresKey', 'name');
        foreach (['anthropic', 'openai', 'groq', 'google'] as $keyProvider) {
            if (array_key_exists($keyProvider, $requiresKey)) {
                self::assertTrue((bool) $requiresKey[$keyProvider], "$keyProvider must report requiresKey=true");
            }
        }
        if (array_key_exists('ollama', $requiresKey)) {
            self::assertFalse((bool) $requiresKey['ollama'], 'ollama is URL-based, not key-based');
        }
    }

    public function testVisibleModelsBelongToAvailableProviders(): void
    {
        $this->loginAs('demo@synaplan.com');
        $data = $this->fetchModels();

        $availability = [];
        foreach ($data['providers'] as $provider) {
            $availability[(string) $provider['name']] = (bool) $provider['available'];
        }

        foreach (self::allRows($data) as $row) {
            $service = strtolower((string) $row['service']);
            // Ollama rows are judged per pulled model, not per provider — the
            // provider flag additionally requires the default chat model.
            if ('ollama' === $service || !array_key_exists($service, $availability)) {
                continue;
            }

            self::assertTrue(
                $availability[$service],
                sprintf('Model "%s" is visible although its provider "%s" reports unavailable.', $row['name'], $service)
            );
        }
    }
}
