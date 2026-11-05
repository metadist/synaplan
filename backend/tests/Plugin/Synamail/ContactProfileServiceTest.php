<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Synamail;

use App\AI\Service\AiFacade;
use App\Repository\ConfigRepository;
use App\Service\ModelConfigService;
use App\Service\PluginDataService;
use PHPUnit\Framework\TestCase;
use Plugin\Synamail\Service\ContactProfileService;
use Plugin\Synamail\Service\ProfileException;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Attribute\Route;

final class ContactProfileServiceTest extends TestCase
{
    private const EMAIL = 'ada@example.com';

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $rows = [];

    /** @var array<string, string> */
    private array $config = [
        'enabled' => '1',
        'profile_language' => 'auto',
        'max_summary_words' => '150',
    ];

    /** @var list<array<string, mixed>> */
    private array $chatCalls = [];

    public function testStoredProfileIsReadAndListedNewestFirst(): void
    {
        $service = $this->service();
        $this->rows[ContactProfileService::DATA_TYPE][$this->key(self::EMAIL)] = $this->stored('Ada keeps the draft.', '2026-06-01T00:00:00+00:00');
        $this->rows[ContactProfileService::DATA_TYPE][$this->key('bob@example.com')] = [
            'email' => 'bob@example.com',
            'summary' => 'Bob asked for a date.',
            'updatedAt' => '2026-06-02T00:00:00+00:00',
        ];

        $found = $service->get(7, self::EMAIL);
        self::assertIsArray($found);
        self::assertSame('Ada keeps the draft.', $found['summary']);

        $listed = $service->list(7);
        self::assertSame('bob@example.com', $listed[0]['email']);
        self::assertSame(self::EMAIL, $listed[1]['email']);
    }

    public function testUpdateMergesTheModelAndKeepsCountsInPhp(): void
    {
        $service = $this->service(<<<'JSON'
            {"summary":"Ada owes the draft.","tone":"direct","facts":["Works at Example"],"openLoops":["them: send the draft"],"emailCount":99}
            JSON);

        $profile = $service->update(7, 'Ada%40example.com', [
            'subject' => 'Draft',
            'body' => 'I will send the draft tomorrow.',
            'date' => '2026-06-10T14:32:00Z',
            'direction' => 'inbound',
            'name' => 'Ada Example',
        ]);

        self::assertSame(self::EMAIL, $profile['email']);
        self::assertSame('Ada owes the draft.', $profile['summary']);
        self::assertSame('direct', $profile['tone']);
        self::assertSame(['Works at Example'], $profile['facts']);
        self::assertSame(['them: send the draft'], $profile['openLoops']);
        self::assertSame(1, $profile['emailCount']);
        self::assertSame('example.com', $profile['org']);
        self::assertSame('Ada Example', $profile['name']);
        self::assertSame('2026-06-10T14:32:00+00:00', $profile['firstSeen']);
        self::assertSame('2026-06-10T14:32:00+00:00', $profile['lastInbound']);
        self::assertArrayNotHasKey('lastOutbound', $profile);
        self::assertArrayHasKey($this->key(self::EMAIL), $this->rows[ContactProfileService::DATA_TYPE]);

        $prompt = (string) $this->chatCalls[0]['messages'][1]['content'];
        self::assertStringContainsString('MERGE, never restart', $prompt);
        self::assertStringContainsString('I will send the draft tomorrow.', $prompt);

        $again = $service->update(7, self::EMAIL, [
            'body' => 'Sent.',
            'direction' => 'outbound',
            'date' => '2026-06-11T09:00:00Z',
        ]);
        self::assertSame(2, $again['emailCount']);
        self::assertSame('2026-06-10T14:32:00+00:00', $again['firstSeen']);
        self::assertSame('2026-06-11T09:00:00+00:00', $again['lastOutbound']);
    }

    public function testUpdateCapsFactsAndIgnoresAFreemailDomain(): void
    {
        $facts = [];
        for ($i = 1; $i <= 12; ++$i) {
            $facts[] = 'Fact '.$i;
        }
        $service = $this->service((string) json_encode([
            'summary' => 'A long list.',
            'tone' => 'brief',
            'facts' => $facts,
            'openLoops' => ['me: one', 'them: two'],
        ]));

        $profile = $service->update(7, 'ada@gmail.com', ['body' => 'Hello from Gmail.']);

        self::assertCount(10, $profile['facts']);
        self::assertSame('Fact 1', $profile['facts'][0]);
        self::assertNull($profile['org']);
    }

    public function testFencedModelJsonIsAccepted(): void
    {
        $service = $this->service(<<<'JSON'
            Here you go:
            ```json
            {"summary":"Ada confirmed the meeting.","tone":"warm","facts":[],"openLoops":[]}
            ```
            JSON);

        $profile = $service->update(7, self::EMAIL, ['body' => 'See you Tuesday.']);

        self::assertSame('Ada confirmed the meeting.', $profile['summary']);
    }

    public function testUnusableModelOutputStoresNothing(): void
    {
        $service = $this->service('not json');

        try {
            $service->update(7, self::EMAIL, ['body' => 'Hello.']);
            self::fail('An unusable model reply must be refused.');
        } catch (ProfileException $e) {
            self::assertSame(502, $e->httpStatus);
            self::assertSame('AI returned an unusable response', $e->getMessage());
        }

        self::assertSame([], $this->rows);
    }

    public function testModelFailureStoresNothing(): void
    {
        $ai = $this->createStub(AiFacade::class);
        $ai->method('chat')->willThrowException(new \RuntimeException('provider down'));
        $service = $this->service(ai: $ai);

        try {
            $service->update(7, self::EMAIL, ['body' => 'Hello.']);
            self::fail('A failed model call must be refused.');
        } catch (ProfileException $e) {
            self::assertSame(502, $e->httpStatus);
        }

        self::assertSame([], $this->rows);
    }

    public function testEmptyBodyAndABadAddressNeverCallTheModel(): void
    {
        $service = $this->service('{"summary":"unused"}');

        try {
            $service->update(7, self::EMAIL, ['body' => '   ']);
            self::fail('A blank body must be refused.');
        } catch (ProfileException $e) {
            self::assertSame(400, $e->httpStatus);
        }

        try {
            $service->get(7, 'not-an-email');
            self::fail('A bad address must be refused.');
        } catch (ProfileException $e) {
            self::assertSame(400, $e->httpStatus);
        }

        self::assertSame([], $this->chatCalls);
    }

    public function testDeleteRemovesTheStoredProfile(): void
    {
        $service = $this->service();
        $this->rows[ContactProfileService::DATA_TYPE][$this->key(self::EMAIL)] = $this->stored('Gone soon.', '2026-06-01T00:00:00+00:00');

        self::assertTrue($service->delete(7, self::EMAIL));
        self::assertNull($service->get(7, self::EMAIL));
        self::assertFalse($service->delete(7, self::EMAIL));
    }

    public function testManifestRoutesMatchTheController(): void
    {
        $manifestPath = '/plugins/synamail/manifest.json';
        if (!is_file($manifestPath)) {
            $manifestPath = \dirname(__DIR__, 4).'/plugins/synamail/manifest.json';
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        self::assertIsArray($manifest);

        $class = new \ReflectionClass(\Plugin\Synamail\Controller\SynamailController::class);
        $classRoute = $class->getAttributes(Route::class)[0]->newInstance();
        $prefix = is_string($classRoute->path) ? $classRoute->path : '';
        $actual = [];
        foreach ($class->getMethods() as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $route = $attribute->newInstance();
                $path = $prefix.(is_string($route->path) ? $route->path : '');
                foreach ($route->methods as $http) {
                    $actual[] = $http.' '.$path;
                }
            }
        }
        sort($actual);

        $expected = [];
        foreach ($manifest['routes'] as $route) {
            self::assertIsArray($route);
            $expected[] = $route['method'].' '.$route['path'];
        }
        sort($expected);

        self::assertSame($expected, $actual);
        self::assertSame('synamail', $manifest['id']);
        self::assertSame('synamail', $manifest['name']);
        self::assertSame('https://docs.synaplan.com/synamail', $manifest['homepage']);
    }

    private function service(?string $content = null, ?AiFacade $ai = null): ContactProfileService
    {
        if (!$ai instanceof AiFacade) {
            $reply = $content ?? '{"summary":"unused"}';
            $ai = $this->createStub(AiFacade::class);
            $ai->method('chat')->willReturnCallback(function (array $messages, ?int $userId, array $options) use ($reply): array {
                $this->chatCalls[] = ['messages' => $messages, 'userId' => $userId, 'options' => $options];

                return ['content' => $reply];
            });
        }

        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(
            fn (int $owner, string $group, string $setting): ?string => $this->config[$setting] ?? null,
        );

        $models = $this->createStub(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(4);
        $models->method('getModelName')->willReturn('test-chat');
        $models->method('getProviderForModel')->willReturn('test');

        return new ContactProfileService($this->pluginData(), $config, $models, $ai, new NullLogger());
    }

    private function pluginData(): PluginDataService
    {
        $data = $this->createStub(PluginDataService::class);
        $data->method('get')->willReturnCallback(
            fn (int $user, string $plugin, string $type, string $key): ?array => $this->rows[$type][$key] ?? null,
        );
        $data->method('set')->willReturnCallback(
            function (int $user, string $plugin, string $type, string $key, array $row): void {
                $this->rows[$type][$key] = $row;
            },
        );
        $data->method('list')->willReturnCallback(
            fn (int $user, string $plugin, string $type): array => $this->rows[$type] ?? [],
        );
        $data->method('delete')->willReturnCallback(
            function (int $user, string $plugin, string $type, string $key): bool {
                $had = isset($this->rows[$type][$key]);
                unset($this->rows[$type][$key]);

                return $had;
            },
        );

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $summary, string $updatedAt): array
    {
        return [
            'email' => self::EMAIL,
            'summary' => $summary,
            'updatedAt' => $updatedAt,
            'emailCount' => 1,
            'firstSeen' => $updatedAt,
        ];
    }

    private function key(string $email): string
    {
        return 'p_'.sha1($email);
    }
}
