<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\CloudWatch;
use App\Entity\ExternalIdentity;
use App\Entity\File;
use App\Entity\PlatformInstance;
use App\Entity\SavedPrompt;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\CloudWatch\WatchIndexCursor;
use App\Service\CloudWatch\WatchLifecycleEffect;
use App\Service\File\CloudFilesConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watches: the person's API, the instance index, and what a lifecycle event does.
 */
final class CloudWatchTest extends WebTestCase
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

    public function testWatchesAreAbsentUntilTheFeatureIsOn(): void
    {
        $user = $this->person('off');
        $this->authenticateClient($this->client, $user);

        $this->client->request('GET', '/api/v1/cloud/watches');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('feature_not_configured', $this->json()['error']);

        $this->client->request('GET', '/api/v1/cloud/watches/options');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAPersonCanWatchAFolderPauseItAndStopIt(): void
    {
        $this->enable();
        $cloud = $this->cloud('watch');
        $this->client->request('POST', '/api/v1/cloud/watches', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'target_kind' => 'folder',
            'target_id' => '1234',
            'target_path' => 'Incoming',
            'preset' => 'keep_searchable',
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        $id = $this->json()['id'];
        self::assertSame('active', $this->json()['state']);
        self::assertSame('keep_searchable', $this->json()['preset']);

        $this->client->request('GET', '/api/v1/cloud/watches', server: $cloud['server']);
        self::assertSame($id, $this->json()['items'][0]['id']);
        self::assertSame(0, $this->json()['paused_count']);

        $this->client->request('PATCH', '/api/v1/cloud/watches/'.$id, server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: '{"state":"paused"}');
        self::assertSame('paused', $this->json()['state']);
        self::assertSame('by_owner', $this->json()['reason']);

        $this->client->request('PATCH', '/api/v1/cloud/watches/'.$id, server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: '{"state":"active","handle_waiting":true}');
        self::assertSame('active', $this->json()['state']);
        self::assertSame(0, $this->json()['waiting']);

        $this->client->request('DELETE', '/api/v1/cloud/watches/'.$id, server: $cloud['server']);
        self::assertSame('stopped', $this->json()['state']);
        $this->client->request('GET', '/api/v1/cloud/watches', server: $cloud['server']);
        self::assertSame([], $this->json()['items']);
    }

    public function testTheDialogOptionsAreThisPersonsShortcutsAndFolders(): void
    {
        $this->enable();
        $cloud = $this->cloud('options');
        $ownerId = (int) $cloud['user']->getId();
        $prompt = (new SavedPrompt())
            ->setUserId($ownerId)
            ->setName('Vendor line')
            ->setCommand('vendor')
            ->setBody('Extract the vendor, the date and the total.');
        $this->em->persist($prompt);
        $file = new File();
        $file->setUserId($ownerId);
        $file->setFileName('inv.txt');
        $file->setFilePath('/uploads/inv.txt');
        $file->setFileType('txt');
        $file->setFileSize(8);
        $file->setFileMime('text/plain');
        $file->setStatus('uploaded');
        $file->setGroupKey('Invoices');
        $this->em->persist($file);
        $year = new File();
        $year->setUserId($ownerId);
        $year->setFileName('2026.txt');
        $year->setFilePath('/uploads/2026.txt');
        $year->setFileType('txt');
        $year->setFileSize(4);
        $year->setFileMime('text/plain');
        $year->setStatus('uploaded');
        $year->setGroupKey('2026');
        $this->em->persist($year);
        $other = $this->person('other-options');
        $foreign = (new SavedPrompt())
            ->setUserId((int) $other->getId())
            ->setName('Secret')
            ->setCommand('secret')
            ->setBody('Not yours.');
        $this->em->persist($foreign);
        $this->em->flush();

        $this->client->request('GET', '/api/v1/cloud/watches/options', server: $cloud['server']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        $body = $this->json();
        self::assertSame(['en', 'de', 'es', 'fr', 'tr'], $body['languages']);
        self::assertSame([], $body['tasks']);
        self::assertSame('Vendor line', $body['shortcuts'][0]['name']);
        self::assertSame('vendor', $body['shortcuts'][0]['command']);
        self::assertSame('Extract the vendor, the date and the total.', $body['shortcuts'][0]['body']);
        $invoices = null;
        $yearCount = null;
        foreach ($body['folders'] as $folder) {
            self::assertIsString($folder['group_key']);
            if ('Invoices' === $folder['group_key']) {
                $invoices = $folder['file_count'];
            }
            if ('2026' === $folder['group_key']) {
                $yearCount = $folder['file_count'];
            }
        }
        self::assertSame(1, $invoices);
        self::assertSame(1, $yearCount);
        self::assertCount(1, $body['shortcuts']);
        self::assertCount(2, $body['folders']);
    }

    public function testTheCapRefusesAnotherWatch(): void
    {
        $this->enable();
        static::getContainer()->get(ConfigRepository::class)->setValue(0, CloudFilesConfig::GROUP, CloudFilesConfig::WATCHES_PER_PERSON, '1');
        $cloud = $this->cloud('cap');
        $body = json_encode(['target_kind' => 'folder', 'target_id' => '1', 'target_path' => 'A', 'preset' => 'keep_searchable'], JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/api/v1/cloud/watches', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->client->request('POST', '/api/v1/cloud/watches', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['target_kind' => 'file', 'target_id' => '2', 'target_path' => 'B', 'preset' => 'keep_searchable'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('watch_cap', $this->json()['error']);
        self::assertStringContainsString('the most allowed', $this->json()['message']);
    }

    public function testAFileKeyCannotWatch(): void
    {
        $this->enable();
        $cloud = $this->cloud('filescope', ['files']);
        $this->client->request('POST', '/api/v1/cloud/watches', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: '{"target_kind":"folder","target_id":"1","preset":"keep_searchable"}');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testTheSameVersionIsAlreadyHandledAndReconcileSortsTheRest(): void
    {
        $this->enable();
        $cloud = $this->cloud('file');
        $this->client->request('POST', '/api/v1/cloud/watches', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'target_kind' => 'file',
            'target_id' => '5678',
            'target_path' => 'contract.docx',
            'preset' => 'keep_searchable',
        ], JSON_THROW_ON_ERROR));
        $id = $this->json()['id'];
        $path = tempnam(sys_get_temp_dir(), 'watch');
        self::assertIsString($path);
        file_put_contents($path, 'hello');
        $upload = new UploadedFile($path, 'contract.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $this->client->request('POST', '/api/v1/cloud/watches/'.$id.'/files', ['id' => '999', 'version' => 'v1', 'name' => 'other.docx', 'event' => 'created'], ['file' => $upload], $cloud['server']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $upload = new UploadedFile($path, 'contract.docx', 'text/plain', null, true);
        $this->client->request('POST', '/api/v1/cloud/watches/'.$id.'/files', ['id' => '5678', 'version' => 'v1', 'name' => 'contract.docx', 'event' => 'created'], ['file' => $upload], $cloud['server']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        self::assertSame('waiting', $this->json()['state']);

        $this->client->request('POST', '/api/v1/cloud/watches/'.$id.'/files', ['id' => '5678', 'version' => 'v1', 'name' => 'contract.docx', 'event' => 'created'], ['file' => new UploadedFile($path, 'contract.docx', 'text/plain', null, true)], $cloud['server']);
        self::assertSame('already_handled', $this->json()['state']);

        $this->client->request('POST', '/api/v1/cloud/watches/'.$id.'/reconcile', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['items' => [
            ['id' => '5678', 'version' => 'v1'],
            ['id' => '5679', 'version' => 'v2'],
        ]], JSON_THROW_ON_ERROR));
        self::assertSame(['5679'], $this->json()['process']);
        self::assertSame(['5678'], $this->json()['forget']);
    }

    public function testTheIndexPagesChangesAndExpiresACursor(): void
    {
        $this->enable();
        $cloud = $this->cloud('index');
        $this->client->request('POST', '/api/v1/cloud/watches', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: '{"target_kind":"folder","target_id":"a","target_path":"A","preset":"keep_searchable"}');
        $first = $this->json()['id'];
        $this->client->request('POST', '/api/v1/cloud/watches', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: '{"target_kind":"folder","target_id":"b","target_path":"B","preset":"keep_searchable"}');
        $headers = ['HTTP_X_INSTANCE_ID' => $cloud['instance_id'], 'HTTP_X_INSTANCE_SECRET' => $cloud['secret']];

        $this->client->request('GET', '/api/v1/platform-links/watches?limit=1', server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        $page = $this->json();
        self::assertCount(1, $page['items']);
        $cursor = $page['next_cursor'];
        $this->client->request('GET', '/api/v1/platform-links/watches?limit=1&cursor='.rawurlencode((string) $cursor), server: $headers);
        $second = $this->json();
        self::assertCount(1, $second['items']);
        self::assertNotSame($page['items'][0]['id'], $second['items'][0]['id']);

        $this->client->request('GET', '/api/v1/platform-links/watches?limit=1&cursor='.rawurlencode((string) $second['next_cursor']), server: $headers);
        $done = $this->json();
        self::assertSame([], $done['items']);
        $since = $done['next_cursor'];
        $this->client->request('GET', '/api/v1/platform-links/watches?since='.rawurlencode((string) $since), server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);

        $this->client->request('DELETE', '/api/v1/cloud/watches/'.$first, server: $cloud['server']);
        $this->client->request('GET', '/api/v1/platform-links/watches?since='.rawurlencode((string) $since), server: $headers);
        self::assertContains($first, $this->json()['tombstones']);

        $expired = WatchIndexCursor::since(time() - WatchIndexCursor::TTL_SECONDS - 5, 0);
        $this->client->request('GET', '/api/v1/platform-links/watches?since='.rawurlencode($expired), server: $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    public function testDisablingTheCloudPausesTheWatch(): void
    {
        $this->enable();
        $cloud = $this->cloud('life');
        $this->client->request('POST', '/api/v1/cloud/watches', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: '{"target_kind":"folder","target_id":"z","target_path":"Z","preset":"keep_searchable"}');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $id = $this->json()['id'];
        $cloud['identity']->setAccess(true, false);
        $this->em->flush();
        static::getContainer()->get(WatchLifecycleEffect::class)->onPaused($cloud['identity']);
        $this->em->clear();
        $watch = $this->em->find(CloudWatch::class, $id);
        self::assertInstanceOf(CloudWatch::class, $watch);
        self::assertSame(CloudWatch::STATE_PAUSED, $watch->getState());
        self::assertSame(CloudWatch::REASON_PLATFORM_USER_DISABLED, $watch->getReason());

        $freshIdentity = $this->em->find(ExternalIdentity::class, $cloud['identity']->getId());
        self::assertInstanceOf(ExternalIdentity::class, $freshIdentity);
        static::getContainer()->get(WatchLifecycleEffect::class)->onDeleted($freshIdentity);
        $this->em->clear();
        $stopped = $this->em->find(CloudWatch::class, $id);
        self::assertInstanceOf(CloudWatch::class, $stopped);
        self::assertSame(CloudWatch::STATE_STOPPED, $stopped->getState());
        self::assertSame(CloudWatch::REASON_IDENTITY_DELETED, $stopped->getReason());
    }

    public function testBindingAManualTaskSwitchesItsTrigger(): void
    {
        $this->enable();
        $cloud = $this->cloud('task');
        $task = new SavedTask((int) $cloud['user']->getId(), 1, 'Contract check');
        $this->em->persist($task);
        $this->em->flush();
        $this->client->request('POST', '/api/v1/cloud/watches', server: $cloud['server'] + ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'target_kind' => 'folder',
            'target_id' => 'in',
            'target_path' => 'Incoming',
            'preset' => 'task',
            'task_id' => $task->getId(),
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $this->client->getResponse()->getContent());
        $watchId = $this->json()['id'];
        $this->em->clear();
        $fresh = $this->em->find(SavedTask::class, $task->getId());
        self::assertInstanceOf(SavedTask::class, $fresh);
        self::assertSame(SavedTask::TRIGGER_FILE_EVENT, $fresh->getTriggerType());

        $this->client->request('DELETE', '/api/v1/cloud/watches/'.$watchId, server: $cloud['server']);
        $this->em->clear();
        $again = $this->em->find(SavedTask::class, $task->getId());
        self::assertInstanceOf(SavedTask::class, $again);
        self::assertSame(SavedTask::TRIGGER_MANUAL, $again->getTriggerType());
    }

    private function enable(): void
    {
        static::getContainer()->get(ConfigRepository::class)->setValue(0, CloudFilesConfig::GROUP, CloudFilesConfig::ENABLED, '1');
        $this->em->flush();
    }

    /**
     * @param list<string> $scopes
     *
     * @return array{user: User, identity: ExternalIdentity, instance_id: string, secret: string, server: array<string, string>}
     */
    private function cloud(string $prefix, array $scopes = ['cloud:watch', 'files']): array
    {
        $user = $this->person($prefix);
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
        $key->setScopes($scopes);
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
            'identity' => $identity,
            'instance_id' => $instanceId,
            'secret' => $secret,
            'server' => ['HTTP_X_API_KEY' => $plain],
        ];
    }

    private function person(string $prefix): User
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

        return $user;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, (string) $this->client->getResponse()->getContent());

        return $decoded;
    }
}
