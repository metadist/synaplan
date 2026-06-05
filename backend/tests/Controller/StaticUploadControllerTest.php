<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\UserUploadPathBuilder;
use App\Tests\Trait\AuthenticatedTestTrait;
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
     * Database rows we created during the test, removed in tearDown.
     *
     * @var list<File>
     */
    private array $createdFiles = [];

    /**
     * @var list<Message>
     */
    private array $createdMessages = [];

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

        foreach ($this->diskPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
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

    private function createUploadOnDisk(string $filename, string $contents): string
    {
        $uploadDir = $this->client->getContainer()->getParameter('app.upload_dir');
        if (!is_string($uploadDir)) {
            $this->fail('app.upload_dir must be a string.');
        }

        // Use the production sharding helper instead of duplicating its
        // modulo math — drift between the test fixture and the real
        // pipeline would silently make the regression test miss real
        // bugs (e.g. if the layout ever changes for large user IDs).
        $pathBuilder = $this->client->getContainer()->get(UserUploadPathBuilder::class);
        $userBase = $pathBuilder->buildUserBaseRelativePath($this->user->getId());

        $relativePath = $userBase.'/'.date('Y').'/'.date('m').'/'.$filename;
        $absolute = $uploadDir.'/'.$relativePath;

        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0o775, true);
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
