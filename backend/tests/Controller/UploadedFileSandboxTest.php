<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\EventSubscriber\UploadedFileSandboxSubscriber;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A widget icon and a shared file are public URLs on the application's own
 * origin. Opened directly, an SVG among them renders as a document, so it is
 * served with the sandbox policy; a raster icon is served as before.
 */
final class UploadedFileSandboxTest extends WebTestCase
{
    private const SCRIPTED_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><script>document.title = "ran"</script></svg>';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    /** @var list<string> */
    private array $diskPaths = [];

    /** @var list<string> */
    private array $diskDirs = [];

    /** @var list<Message> */
    private array $messages = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => 'demo@synaplan.com']);
        if (!$user instanceof User) {
            $this->markTestSkipped('Test user demo@synaplan.com not found. Run fixtures first.');
        }
        $this->user = $user;
    }

    protected function tearDown(): void
    {
        foreach ($this->messages as $message) {
            $managed = $this->em->find(Message::class, $message->getId());
            if (null !== $managed) {
                $this->em->remove($managed);
            }
        }
        $this->em->flush();

        array_map('unlink', array_filter($this->diskPaths, 'is_file'));
        foreach (array_reverse($this->diskDirs) as $dir) {
            @rmdir($dir);
        }

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testAnSvgWidgetIconIsSandboxed(): void
    {
        $path = $this->writeUpload('widget-icons/'.$this->monthDirectory().'/wicon_'.bin2hex(random_bytes(8)).'.svg', self::SCRIPTED_SVG);

        $this->client->request('GET', '/api/v1/files/uploads/'.$path);

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertSame(UploadedFileSandboxSubscriber::POLICY, $response->headers->get('Content-Security-Policy'));
    }

    public function testARasterWidgetIconIsServedAsBefore(): void
    {
        $path = $this->writeUpload('widget-icons/'.$this->monthDirectory().'/wicon_'.bin2hex(random_bytes(8)).'.png', 'png-bytes');

        $this->client->request('GET', '/api/v1/files/uploads/'.$path);

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function testASharedSvgFileIsSandboxed(): void
    {
        $path = $this->writeUpload($this->monthDirectory().'/shared_'.bin2hex(random_bytes(8)).'.svg', self::SCRIPTED_SVG);
        $this->persistSharedMessage($path);

        $this->client->request('GET', '/up/'.$path);

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertSame(UploadedFileSandboxSubscriber::POLICY, $response->headers->get('Content-Security-Policy'));
    }

    private function monthDirectory(): string
    {
        $userBase = $this->client->getContainer()->get(UserUploadPathBuilder::class)->buildUserBaseRelativePath((int) $this->user->getId());

        return $userBase.'/'.date('Y').'/'.date('m');
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

    private function persistSharedMessage(string $relativePath): void
    {
        $message = new Message();
        $message->setUserId((int) $this->user->getId());
        $message->setTrackingId(0);
        $message->setProviderIndex('test_'.bin2hex(random_bytes(4)));
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('Y-m-d H:i:s'));
        $message->setMessageType('WEB');
        $message->setText('shared file');
        $message->setDirection('IN');
        $message->setStatus('NEW');
        $message->setFile(1);
        $message->setFilePath($relativePath);
        $message->setFileType('svg');
        $message->setPublic(true);
        $this->em->persist($message);
        $this->em->flush();
        $this->messages[] = $message;
    }
}
