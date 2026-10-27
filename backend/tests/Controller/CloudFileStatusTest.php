<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\File;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\FileRepository;
use App\Service\File\CloudFilesConfig;
use App\Service\File\CloudSourceEvents;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bird state, source events and the 7-day removal of a deleted cloud file.
 */
final class CloudFileStatusTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testTheStatusCallIsAbsentUntilTheFeatureIsOn(): void
    {
        $user = (new User())
            ->setMail('off-'.bin2hex(random_bytes(3)).'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('local')
            ->setUserLevel('PRO');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->authenticateClient($this->client, $user);

        $this->client->request('POST', '/api/v1/cloud/status', server: ['CONTENT_TYPE' => 'application/json'], content: '{"items":[{"id":"1","version":"v"}]}');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('feature_not_configured', $this->json()['error']);
        self::assertSame('cloud_files', $this->json()['module']);
    }

    public function testStatusKeepsQuotedVersionsAndCachesTheFolder(): void
    {
        $this->enable();
        $cloud = $this->cloud('status');
        $quoted = '"'.str_repeat('e', 250).'"';
        $file = $this->file($cloud['user'], $cloud['instance_id'], '5678', '"v1"', true);
        $file->setVectorState(File::VECTOR_STATE_VECTORIZED);
        $file->setGroupKey('Specs');
        $this->em->flush();

        $body = json_encode(['items' => [
            ['id' => '5678', 'version' => '"v1"'],
            ['id' => '5678', 'version' => '"v2"'],
            ['id' => $quoted, 'version' => $quoted],
        ]], JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/api/v1/cloud/status', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame('miss', $this->client->getResponse()->headers->get('X-Synaplan-Cache'));
        $items = $this->json()['items'];
        self::assertSame('searchable', $items[0]['state']);
        self::assertSame('updating', $items[1]['state']);
        self::assertSame(['key' => 'Specs', 'name' => 'Specs'], $items[0]['knowledge_folder']);
        self::assertTrue($items[0]['kept_up_to_date']);
        self::assertSame('none', $items[2]['state']);
        self::assertSame($quoted, $items[2]['id']);
        self::assertSame([], $this->json()['watches']);

        $this->client->request('POST', '/api/v1/cloud/status', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertSame('hit', $this->client->getResponse()->headers->get('X-Synaplan-Cache'));

        $tooMany = json_encode(['items' => array_fill(0, 501, ['id' => '1', 'version' => 'v'])], JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/api/v1/cloud/status', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: $tooMany);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->client->request('GET', '/api/v1/cloud/files/'.$file->getId(), server: $cloud['server']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($file->getId(), $this->json()['file_id']);
        self::assertSame('searchable', $this->json()['state']);
    }

    public function testSourceEventsFollowOnlyThisCloudAndTheReaperRemovesTheGraceCopy(): void
    {
        $this->enable();
        $cloud = $this->cloud('events');
        $kept = $this->file($cloud['user'], $cloud['instance_id'], 'shared', 'old', true);
        $other = $this->cloud('other');
        $foreign = $this->file($other['user'], $other['instance_id'], 'shared', 'old', true);
        $moved = $this->file($cloud['user'], $cloud['instance_id'], 'move', 'old', false);
        $deleted = $this->file($cloud['user'], $cloud['instance_id'], 'gone', 'old', false);
        $restored = $this->file($cloud['user'], $cloud['instance_id'], 'back', 'old', false);
        $restored->setSourceDeletedAt(time() - 3600);
        $expired = $this->file($cloud['user'], $cloud['instance_id'], 'old', 'old', false);
        $expired->setSourceDeletedAt(time() - CloudSourceEvents::GRACE_SECONDS - 10);
        $this->em->flush();

        $this->client->request('POST', '/api/v1/platform-links/sources/events', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INSTANCE_ID' => $cloud['instance_id'],
            'HTTP_X_INSTANCE_SECRET' => $cloud['secret'],
        ], content: (string) json_encode(['events' => [
            ['id' => 'shared', 'event' => 'changed', 'version' => '"next"', 'path' => ''],
            ['id' => 'move', 'event' => 'moved', 'version' => '', 'path' => 'Specs/renamed.txt'],
            ['id' => 'gone', 'event' => 'deleted', 'version' => '', 'path' => ''],
            ['id' => 'back', 'event' => 'restored', 'version' => '', 'path' => ''],
        ]]));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame(4, $this->json()['applied']);
        self::assertSame([['external_id' => 'ada', 'id' => 'shared']], $this->json()['reupload']);

        $this->em->clear();
        $kept = $this->em->find(File::class, $kept->getId());
        $foreign = $this->em->find(File::class, $foreign->getId());
        $moved = $this->em->find(File::class, $moved->getId());
        self::assertTrue($kept?->isStale());
        self::assertFalse($foreign?->isStale());
        self::assertSame('renamed.txt', $moved?->getFileName());

        $repository = $this->em->getRepository(File::class);
        self::assertInstanceOf(FileRepository::class, $repository);
        $listed = $repository->findByUserPaginated((int) $cloud['user']->getId(), null, 0, 50);
        $listedIds = array_map(static fn (File $file): int => (int) $file->getId(), $listed['files']);
        self::assertNotContains($deleted->getId(), $listedIds);
        self::assertContains($kept->getId(), $listedIds);

        $filters = $this->em->getFilters();
        $filters->disable('source_deleted');
        $back = $this->em->find(File::class, $restored->getId());
        self::assertNull($back?->getSourceDeletedAt());
        self::assertSame(File::VECTOR_STATE_PENDING, $back?->getVectorState());
        $filters->enable('source_deleted');

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:cloud-files:reap'));
        $tester->execute(['--now' => (string) time()]);
        $filters->disable('source_deleted');
        self::assertNull($this->em->find(File::class, $expired->getId()));
    }

    public function testCapabilitiesNameTheFeatureOnlyWhileItIsOn(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, '1');
        $this->em->flush();
        $cloud = $this->cloud('caps');
        $this->client->request('GET', '/api/v1/platform-links/instances/self', server: [
            'HTTP_X_INSTANCE_ID' => $cloud['instance_id'],
            'HTTP_X_INSTANCE_SECRET' => $cloud['secret'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertNotContains('cloud_status', $this->json()['capabilities']);

        $this->enable();
        $this->client->request('GET', '/api/v1/platform-links/instances/self', server: [
            'HTTP_X_INSTANCE_ID' => $cloud['instance_id'],
            'HTTP_X_INSTANCE_SECRET' => $cloud['secret'],
        ]);
        self::assertContains('cloud_status', $this->json()['capabilities']);
        self::assertContains('source_events', $this->json()['capabilities']);
    }

    private function enable(): void
    {
        static::getContainer()->get(ConfigRepository::class)->setValue(0, CloudFilesConfig::GROUP, CloudFilesConfig::ENABLED, '1');
        $this->em->flush();
    }

    /**
     * @return array{user: User, instance_id: string, secret: string, server: array<string, string>}
     */
    private function cloud(string $prefix): array
    {
        $user = (new User())
            ->setMail($prefix.'-'.bin2hex(random_bytes(3)).'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('local')
            ->setUserLevel('PRO');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        $instanceId = 'pi_'.$prefix.bin2hex(random_bytes(3));
        $secret = 'sec-'.$prefix;
        $instance = new PlatformInstance('nextcloud', $instanceId, $prefix.'.example', ['https://'.$prefix.'.example/callback'], PlatformInstance::STATUS_ACTIVE);
        $instance->setSecretHash(password_hash($secret, \PASSWORD_DEFAULT));
        $this->em->persist($instance);

        $plain = 'sk_'.bin2hex(random_bytes(29));
        $key = new ApiKey();
        $key->setOwner($user);
        $key->setKey($plain);
        $key->setName('cloud');
        $key->setStatus('active');
        $key->setScopes(['files']);
        $this->em->persist($key);
        $this->em->flush();

        $identity = (new ExternalIdentity())
            ->setUserId((int) $user->getId())
            ->setSource('nextcloud')
            ->setInstanceId($instanceId)
            ->setExternalId('ada')
            ->setApiKeyId((int) $key->getId())
            ->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $this->em->persist($identity);
        $this->em->flush();

        return [
            'user' => $user,
            'instance_id' => $instanceId,
            'secret' => $secret,
            'server' => ['HTTP_X_API_KEY' => $plain],
        ];
    }

    private function file(User $user, string $instanceId, string $sourceId, string $version, bool $keepSynced): File
    {
        $file = new File();
        $file->setUserId((int) $user->getId());
        $file->setFileName($sourceId.'.txt');
        $file->setFilePath('/uploads/'.$sourceId.'.txt');
        $file->setFileType('txt');
        $file->setFileSize(8);
        $file->setFileMime('text/plain');
        $file->setStatus('uploaded');
        $file->setSource('nextcloud');
        $file->setSourceId($sourceId);
        $file->setSourceInstance($instanceId);
        $file->setSourceEtag($version);
        $file->setKeepSynced($keepSynced);
        $file->setVectorState(File::VECTOR_STATE_VECTORIZED);
        $this->em->persist($file);

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, (string) $this->client->getResponse()->getContent());

        return $decoded;
    }
}
