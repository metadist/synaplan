<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Credential\ProviderDefaultsService;
use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use App\AI\Messages\Translator\ChatCompletionsUpstreams;
use App\AI\Service\ProviderRegistry;
use App\Entity\User;
use App\Service\Admin\SystemConfig\Tab\AiTab;
use App\Service\Auth\TokenService;
use App\Service\Egress\EgressPolicy;
use App\Tests\Support\FixturePluginsKernel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Plugin\AcmeAi\AcmeAiProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Journey J-AU-4 (`S3_providers_as_units.md`, AU22): "Acme AI" is one class
 * with a descriptor, loaded as a plugin, named nowhere in `backend/src` or
 * `config/`. Each test is one table the provider has to reach; the first
 * table that still keeps its own list fails here by name.
 *
 * Each test runs in its own process: the second kernel (a full container
 * next to the suite's) does not fit into the memory the long-lived PHPUnit
 * process has left after a thousand tests. The cache directory is fixed, so
 * only the first boot of a run compiles the container; debug mode
 * recompiles it when a source file changed.
 */
#[RunTestsInSeparateProcesses]
final class AddProviderJourneyTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/plugins/provider';
    private const KEY = 'acme-fixture-key';

    // Spelled out here: the plugin's autoloader is registered by the kernel,
    // so the class is not loadable before the boot in setUp().
    private const NAME = 'acme';
    private const ENV_VAR = 'ACME_API_KEY';
    private const BASE_URI = 'https://api.acme.example/v1';

    private ?FixturePluginsKernel $kernel = null;

    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        putenv(self::ENV_VAR.'='.self::KEY);
        $_ENV[self::ENV_VAR] = $_SERVER[self::ENV_VAR] = self::KEY;
        $this->kernel = new FixturePluginsKernel(self::FIXTURES, 'acme-journey');
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        // A separate process is outside the suite's DAMA transaction.
        if ([] !== $this->users) {
            $em = $this->service(EntityManagerInterface::class);
            foreach ($this->users as $user) {
                $managed = $em->find(User::class, $user->getId());
                if (null !== $managed) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }
        $this->kernel?->shutdown();
        $this->kernel = null;
        putenv(self::ENV_VAR);
        unset($_ENV[self::ENV_VAR], $_SERVER[self::ENV_VAR]);
    }

    public function testTheCatalogKnowsAcmeFromItsClassAlone(): void
    {
        $catalog = $this->service(ProviderCatalog::class);

        self::assertTrue($catalog->has(self::NAME));
        self::assertSame('Acme AI', $catalog->get(self::NAME)->displayName);
        self::assertContains('api.acme.example', $catalog->endpoints());
        self::assertStringNotContainsString('acme', (string) file_get_contents(\dirname(__DIR__, 3).'/config/services.yaml'), 'the provider is not wired by hand');
    }

    public function testTheKeyStoreTakesAcmeKeyFromItsDeclaredVariable(): void
    {
        $keyCatalog = $this->service(ProviderKeyCatalog::class);
        self::assertTrue($keyCatalog->has(self::NAME));
        self::assertContains(self::ENV_VAR, $keyCatalog->managedEnvVars());

        $store = $this->service(ProviderKeyStore::class);
        self::assertTrue($store->supports(self::NAME));
        self::assertSame(self::KEY, $store->getKey(self::NAME), 'the pass built the environment map from the descriptor');
    }

    public function testTheDefaultsAndThePreferenceOrderIncludeAcme(): void
    {
        $defaults = $this->service(ProviderDefaultsService::class);

        self::assertTrue($defaults->supports(self::NAME));
        $order = $defaults->preferenceOrder();
        self::assertSame(self::NAME, end($order), 'rank 99 is last');
    }

    public function testTheUpstreamsResolveAcme(): void
    {
        $upstreams = $this->service(ChatCompletionsUpstreams::class);

        self::assertTrue($upstreams->supports(self::NAME));
        self::assertSame(self::BASE_URI.'/chat/completions', $upstreams->fixedUrl(self::NAME));
    }

    public function testTheRegistryServesAcmeChat(): void
    {
        $registry = $this->service(ProviderRegistry::class);

        self::assertContains(self::NAME, $registry->getRegisteredProviderNames('chat'), 'the pass tagged the adapter from its descriptor');
        self::assertInstanceOf(AcmeAiProvider::class, $registry->getProvidersForCapability('chat')[self::NAME] ?? null);
        self::assertTrue($registry->getProvidersForCapability('chat')[self::NAME]->isAvailable(), 'the key from the environment makes it available');
        // Serving a chat turn also needs the provider's model rows (BMODELS):
        // the second half of "one class + model rows", which the scaffold
        // prints and this fixture leaves out on purpose.
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("Provider 'acme' does not support capability 'chat' (not in DB)");
        $registry->getChatProvider(self::NAME);
    }

    public function testTheAdminAiTabShowsTheAcmeKeyField(): void
    {
        $fields = [];
        foreach ($this->service(AiTab::class)->sections() as $sectionId => $section) {
            foreach ($section->fields as $envVar => $field) {
                $fields[$envVar] = [$sectionId, $field->description];
            }
        }

        self::assertSame(['cloud', 'Acme AI API key — fixture provider'], $fields[self::ENV_VAR] ?? null);
    }

    public function testThePublicCatalogEndpointListsAcme(): void
    {
        $response = $this->request('/api/v1/ai/providers', $this->signedInUser('NEW'));

        self::assertSame(200, $response['status']);
        $providers = array_column($response['body']['providers'], null, 'name');
        self::assertArrayHasKey(self::NAME, $providers);
        self::assertSame('Acme AI', $providers[self::NAME]['displayName']);
        self::assertSame('acme', $providers[self::NAME]['helpKey']);
        self::assertSame(self::ENV_VAR, $providers[self::NAME]['key']['envVar']);
    }

    public function testTheEgressAllowlistFollowsTheCatalog(): void
    {
        $policy = $this->service(EgressPolicy::class);

        self::assertContains('api.acme.example', $policy->publishedRules(), 'the allowlist is built from the catalog, not a static list');
        self::assertTrue($policy->decide(self::BASE_URI.'/chat/completions')->allowed);
    }

    public function testTheExportedSpecNeedsNoChangeForAcme(): void
    {
        $response = $this->request('/api/doc.json', $this->signedInUser('ADMIN'));

        self::assertSame(200, $response['status']);
        self::assertStringNotContainsString('acme', $response['raw'], 'a provider is not an OpenAPI change');
        foreach ($response['body']['paths'] as $path => $operations) {
            foreach ($operations as $operation) {
                foreach (\is_array($operation) ? ($operation['parameters'] ?? []) : [] as $parameter) {
                    if ('provider' === ($parameter['name'] ?? null)) {
                        self::assertArrayNotHasKey('enum', $parameter['schema'] ?? [], $path.' keeps no provider-name list');
                    }
                }
            }
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = $this->container()->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    private function container(): ContainerInterface
    {
        \assert(null !== $this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    private function signedInUser(string $level): User
    {
        $em = $this->service(EntityManagerInterface::class);
        $user = (new User())
            ->setMail('acme-journey-'.bin2hex(random_bytes(4)).'@functional.test')
            ->setCreated(date('YmdHis'))
            ->setType('WEB')
            ->setProviderId('local')
            ->setUserLevel($level);
        $em->persist($user);
        $em->flush();
        $this->users[] = $user;

        return $user;
    }

    /**
     * @return array{status: int, raw: string, body: array<string, mixed>}
     */
    private function request(string $path, User $user): array
    {
        \assert(null !== $this->kernel);
        $request = Request::create($path);
        $request->cookies->set(TokenService::ACCESS_COOKIE, $this->service(TokenService::class)->generateAccessToken($user));
        $response = $this->kernel->handle($request);
        $raw = (string) $response->getContent();
        $body = json_decode($raw, true);

        return ['status' => $response->getStatusCode(), 'raw' => $raw, 'body' => \is_array($body) ? $body : []];
    }
}
