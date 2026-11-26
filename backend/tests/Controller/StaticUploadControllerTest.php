<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\AnonymousMediaGate;
use App\Service\Media\MediaAccessTokenService;
use App\Service\Media\PublicMediaName;
use App\Tests\Trait\AuthenticatedTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression tests for issue #976: WhatsApp voice messages must be
 * playable in the web chat the same way regular uploads are.
 *
 * Before the fix `WhatsAppService::handleMediaDownload` only set
 * `Message::filePath` to the raw relative path, while
 * `StaticUploadController` only matched the prefixed
 * `/api/v1/files/uploads/...` form or AI filename patterns. The fix
 * persists WhatsApp media as a `File` entity (mirroring web uploads)
 * and teaches `StaticUploadController` how to resolve both shapes for
 * legacy and new messages.
 *
 * These tests boot the kernel and exercise the controller through the
 * HTTP stack so the Doctrine query, security, and serving paths all
 * run end-to-end.
 */
class StaticUploadControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private \Doctrine\ORM\EntityManagerInterface $em;
    private User $user;
    private string $authToken;

    /**
     * Files we created on disk during the test, removed in tearDown.
     *
     * @var list<string>
     */
    private array $diskPaths = [];

    /**
     * Directories we created during the test, removed in tearDown.
     *
     * @var list<string>
     */
    private array $diskDirs = [];

    /**
     * Database rows we created during the test, removed in tearDown.
     *
     * @var list<File>
     */
    private array $createdFiles = [];

    /**
     * @var list<Message>
     */
    private array $createdMessages = [];

    /**
     * The `LEGACY_PUBLIC_NAMES_UNTIL` value before a test changed it, kept as
     * a one-element list so that "no row" (null) can be restored too.
     *
     * @var array{0: string|null}|null
     */
    private ?array $legacyNamesUntilBefore = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => 'demo@synaplan.com']);
        if (!$user instanceof User) {
            $this->markTestSkipped('Test user demo@synaplan.com not found. Run fixtures first.');
        }
        $this->user = $user;
        $this->authToken = $this->authenticateClient($this->client, $this->user);
    }

    protected function tearDown(): void
    {
        if (!isset($this->em) || !$this->em->isOpen()) {
            self::bootKernel();
            $this->em = self::getContainer()->get('doctrine')->getManager();
        }

        foreach ($this->createdMessages as $message) {
            $managed = $this->em->find(Message::class, $message->getId());
            if ($managed) {
                $this->em->remove($managed);
            }
        }
        foreach ($this->createdFiles as $file) {
            $managed = $this->em->find(File::class, $file->getId());
            if ($managed) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();

        if (null !== $this->legacyNamesUntilBefore) {
            $this->writeLegacyNamesUntil($this->legacyNamesUntilBefore[0]);
            $this->legacyNamesUntilBefore = null;
        }

        foreach ($this->diskPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (array_reverse($this->diskDirs) as $dir) {
            @rmdir($dir);
        }

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    /**
     * Issue #976: A WhatsApp voice file persisted via the new pipeline
     * (File entity attached to a Message, no `Message::filePath` set)
     * must be servable via `/api/v1/files/uploads/{path}` to its owner.
     */
    public function testServesFileEntityAttachedToMessage(): void
    {
        $relativePath = $this->createUploadOnDisk('whatsapp_'.bin2hex(random_bytes(4)).'.ogg', 'voice-bytes');
        $file = $this->persistFile($relativePath, 'audio', 'audio/ogg');
        $message = $this->persistMessage();
        $message->addFile($file);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/v1/files/uploads/'.$relativePath,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken],
        );

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertStringContainsString('audio/ogg', (string) $response->headers->get('Content-Type'));
    }

    /**
     * Backward compatibility: messages created BEFORE the fix stored the
     * raw relative path in `Message::filePath` (no prefix, no File
     * entity). Those legacy rows must keep working — otherwise upgrading
     * production would break every existing WhatsApp voice message in
     * the chat history.
     */
    public function testServesLegacyMessageWithRawRelativeFilePath(): void
    {
        $relativePath = $this->createUploadOnDisk('whatsapp_'.bin2hex(random_bytes(4)).'.ogg', 'legacy-voice');
        $message = $this->persistMessage();
        $message->setFile(1);
        $message->setFilePath($relativePath); // raw, NOT prefixed — pre-#976 shape
        $message->setFileType('audio');
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/v1/files/uploads/'.$relativePath,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken],
        );

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Anonymous requests for a privately-owned File entity must be
     * rejected. Without this guard the File-entity fallback would
     * silently bypass the auth check that the legacy `Message::filePath`
     * branch performs.
     */
    public function testRejectsAnonymousRequestForPrivateFileEntity(): void
    {
        $relativePath = $this->createUploadOnDisk('whatsapp_'.bin2hex(random_bytes(4)).'.ogg', 'private-bytes');
        $file = $this->persistFile($relativePath, 'audio', 'audio/ogg');
        $message = $this->persistMessage();
        $message->addFile($file);
        $this->em->flush();

        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A media element cannot send an Authorization header, so an owner may
     * also identify itself with the read-only media token in the query string.
     */
    public function testServesPrivateFileToOwnerWithMediaToken(): void
    {
        $relativePath = $this->createUploadOnDisk('whatsapp_'.bin2hex(random_bytes(4)).'.ogg', 'token-bytes');
        $file = $this->persistFile($relativePath, 'audio', 'audio/ogg');
        $message = $this->persistMessage();
        $message->addFile($file);
        $this->em->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request(
            'GET',
            '/api/v1/files/uploads/'.$relativePath.'?'.MediaAccessTokenService::QUERY_PARAM.'='.urlencode($this->mediaToken()),
        );

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString(
            'private',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    public function testRejectsAnInvalidMediaToken(): void
    {
        $relativePath = $this->createUploadOnDisk('whatsapp_'.bin2hex(random_bytes(4)).'.ogg', 'token-bytes');
        $file = $this->persistFile($relativePath, 'audio', 'audio/ogg');
        $message = $this->persistMessage();
        $message->addFile($file);
        $this->em->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request(
            'GET',
            '/api/v1/files/uploads/'.$relativePath.'?'.MediaAccessTokenService::QUERY_PARAM.'=not-a-token',
        );

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    /**
     * `ai_i2vsrc_*` is handed to image-to-video providers, which fetch it with
     * no credential. It must stay anonymously readable.
     */
    public function testServesImageToVideoSourceAnonymously(): void
    {
        $relativePath = $this->createUploadOnDisk(PublicMediaName::remoteSource($this->user->getId(), 'jpg'), 'i2v-source');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString(
            'public',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }

    /**
     * The `ai_` bypass is narrowed to the one prefix that needs it. Any other
     * `ai_*` name must go through the ownership check, which has no database
     * row for it and therefore 404s.
     */
    public function testDoesNotServeArbitraryAiPrefixedFilesAnonymously(): void
    {
        $relativePath = $this->createUploadOnDisk('ai_'.bin2hex(random_bytes(4)).'.png', 'not-published');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /**
     * `generated_*` had no producer left in the codebase, so the bypass no
     * longer honours it.
     */
    public function testDoesNotServeLegacyGeneratedPrefixAnonymously(): void
    {
        $relativePath = $this->createUploadOnDisk('generated_'.bin2hex(random_bytes(4)).'.png', 'legacy');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Meta's WhatsApp servers fetch the TTS `link` we hand them, so a speech
     * file has to stay anonymously readable.
     */
    public function testServesTtsAudioAnonymously(): void
    {
        $relativePath = $this->createUploadOnDisk(PublicMediaName::speech('mp3'), 'spoken');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Copies published via OutboundChannelMedia::publishCopy are fetched by
     * Meta without auth.
     */
    public function testServesPublishedDocumentCopyAnonymously(): void
    {
        $relativePath = $this->createUploadOnDisk(
            PublicMediaName::generated($this->user->getId(), 'document', 'docx'),
            'docx-bytes',
        );

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A link handed out before the upgrade keeps working: the time in the
     * old-style name lies before the recorded moment.
     *
     * @param callable(int, int): string $name
     */
    #[DataProvider('oldStyleNames')]
    public function testKeepsServingAnOldStyleNameFromBeforeTheUpgrade(callable $name): void
    {
        $upgradedAt = time() - 3600;
        $this->setLegacyNamesUntil((string) $upgradedAt);
        $relativePath = $this->createUploadOnDisk($name($this->user->getId(), $upgradedAt - 60), 'old-bytes');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A file that only looks like an old-style name, built after the
     * upgrade, is not public: its name can be worked out from ids and the
     * clock.
     *
     * @param callable(int, int): string $name
     */
    #[DataProvider('oldStyleNames')]
    public function testDoesNotServeAnOldStyleNameFromAfterTheUpgrade(callable $name): void
    {
        $upgradedAt = time() - 3600;
        $this->setLegacyNamesUntil((string) $upgradedAt);
        $relativePath = $this->createUploadOnDisk($name($this->user->getId(), $upgradedAt + 60), 'new-bytes');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Without the recorded moment no old-style name is public.
     *
     * @param callable(int, int): string $name
     */
    #[DataProvider('oldStyleNames')]
    public function testDoesNotServeAnOldStyleNameWithoutTheRecordedMoment(callable $name): void
    {
        $this->setLegacyNamesUntil(null);
        $relativePath = $this->createUploadOnDisk($name($this->user->getId(), time() - 86400), 'old-bytes');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{0: callable(int, int): string}>
     */
    public static function oldStyleNames(): iterable
    {
        yield 'speech' => [static fn (int $userId, int $time): string => 'tts_'.dechex($time).substr(bin2hex(random_bytes(3)), 0, 5).'.mp3'];
        yield 'generated media' => [static fn (int $userId, int $time): string => random_int(100000, 999999).'_openai_'.$time.'.png'];
        yield 'published copy' => [static fn (int $userId, int $time): string => $userId.'_document_'.$time.random_int(100, 999).'.docx'];
        yield 'image-to-video source' => [static fn (int $userId, int $time): string => 'ai_i2vsrc_'.$userId.'_'.$time.'_'.bin2hex(random_bytes(6)).'.jpg'];
    }

    /**
     * `og/` and `widget-icons/` skip the ownership check. A dot segment after
     * either of them must not reach a private upload that answers 401 on its
     * own path: the request is refused and the private bytes never leave.
     */
    #[DataProvider('publicPrefixesClimbingOut')]
    public function testAPublicPrefixDoesNotReachAPrivateUpload(string $climb): void
    {
        // Random so that a debug error page quoting this source cannot match.
        $marker = bin2hex(random_bytes(16));
        $relativePath = $this->createUploadOnDisk('private_'.bin2hex(random_bytes(4)).'.txt', $marker);
        $file = $this->persistFile($relativePath, 'text', 'text/plain');
        $message = $this->persistMessage();
        $message->addFile($file);
        $this->em->flush();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode(), 'The private path itself needs a credential.');

        $this->client->request('GET', '/api/v1/files/uploads/'.$climb.$relativePath);

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString($marker, (string) $this->client->getInternalResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPrefixesClimbingOut(): iterable
    {
        yield 'widget icons, raw dots' => ['widget-icons/../'];
        yield 'OG images, raw dots' => ['og/../'];
        yield 'widget icons, encoded dots' => ['widget-icons/%2e%2e/'];
        yield 'OG images, encoded dots and slash' => ['og/%2e%2e%2f'];
        yield 'widget icons, through a current-directory segment' => ['widget-icons/./../'];
    }

    /**
     * Crawlers fetch the OG preview of a shared chat and third-party pages
     * embed the widget icon, both without a credential.
     */
    #[DataProvider('publicDirectories')]
    public function testServesAPublicAssetAnonymously(string $directory): void
    {
        $relativePath = $this->writeUpload($directory.'/t'.bin2hex(random_bytes(4)).'/asset.png', 'public-asset');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/files/uploads/'.$relativePath);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertSame('public-asset', $this->client->getInternalResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicDirectories(): iterable
    {
        yield 'OG preview of a shared chat' => ['og'];
        yield 'chat widget icon' => ['widget-icons'];
    }

    /**
     * Unknown paths must still return 404 — the new fallback must not
     * accidentally widen the surface to anything on disk.
     */
    public function testReturns404ForUnknownPath(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/files/uploads/does/not/exist/'.bin2hex(random_bytes(4)).'.ogg',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->authToken],
        );

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    private function mediaToken(): string
    {
        $service = $this->client->getContainer()->get(MediaAccessTokenService::class);

        return $service->generate($this->user);
    }

    private function setLegacyNamesUntil(?string $value): void
    {
        if (null === $this->legacyNamesUntilBefore) {
            $this->legacyNamesUntilBefore = [
                $this->configRepository()->getValue(0, AnonymousMediaGate::CONFIG_GROUP, AnonymousMediaGate::CONFIG_SETTING),
            ];
        }

        $this->writeLegacyNamesUntil($value);
    }

    private function writeLegacyNamesUntil(?string $value): void
    {
        if (null === $value) {
            $this->configRepository()->deleteValue(0, AnonymousMediaGate::CONFIG_GROUP, AnonymousMediaGate::CONFIG_SETTING);

            return;
        }

        $this->configRepository()->setValue(0, AnonymousMediaGate::CONFIG_GROUP, AnonymousMediaGate::CONFIG_SETTING, $value);
    }

    private function configRepository(): ConfigRepository
    {
        return static::getContainer()->get(ConfigRepository::class);
    }

    private function createUploadOnDisk(string $filename, string $contents): string
    {
        // Use the production sharding helper instead of duplicating its
        // modulo math — drift between the test fixture and the real
        // pipeline would silently make the regression test miss real
        // bugs (e.g. if the layout ever changes for large user IDs).
        $pathBuilder = $this->client->getContainer()->get(UserUploadPathBuilder::class);
        $userBase = $pathBuilder->buildUserBaseRelativePath($this->user->getId());

        return $this->writeUpload($userBase.'/'.date('Y').'/'.date('m').'/'.$filename, $contents);
    }

    private function writeUpload(string $relativePath, string $contents): string
    {
        $uploadDir = $this->client->getContainer()->getParameter('app.upload_dir');
        if (!is_string($uploadDir)) {
            $this->fail('app.upload_dir must be a string.');
        }

        $absolute = $uploadDir.'/'.$relativePath;
        $missing = [];
        for ($dir = dirname($absolute); !is_dir($dir); $dir = dirname($dir)) {
            $missing[] = $dir;
        }
        foreach (array_reverse($missing) as $dir) {
            mkdir($dir, 0o775);
            $this->diskDirs[] = $dir;
        }
        file_put_contents($absolute, $contents);
        $this->diskPaths[] = $absolute;

        return $relativePath;
    }

    private function persistFile(string $relativePath, string $type, string $mime): File
    {
        // Read the actual byte size from disk so the persisted File row
        // matches what real uploads look like — using strlen($relativePath)
        // would store the path length in BFILESIZE and trip up any UI or
        // logic that reads it as the body size.
        $uploadDir = $this->client->getContainer()->getParameter('app.upload_dir');
        $absolute = $uploadDir.'/'.$relativePath;
        $bytes = is_file($absolute) ? (int) filesize($absolute) : 0;

        $file = new File();
        $file->setUserId($this->user->getId());
        $file->setFilePath($relativePath);
        $file->setFileType($type);
        $file->setFileName(basename($relativePath));
        $file->setFileSize($bytes);
        $file->setFileMime($mime);
        $file->setStatus('uploaded');
        $this->em->persist($file);
        $this->em->flush();
        $this->createdFiles[] = $file;

        return $file;
    }

    private function persistMessage(): Message
    {
        $message = new Message();
        $message->setUserId($this->user->getId());
        $message->setTrackingId(0);
        $message->setProviderIndex('test_'.bin2hex(random_bytes(4)));
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('Y-m-d H:i:s'));
        $message->setMessageType('WA');
        $message->setText('test message for #976');
        $message->setDirection('IN');
        $message->setStatus('NEW');
        $this->em->persist($message);
        $this->em->flush();
        $this->createdMessages[] = $message;

        return $message;
    }
}
