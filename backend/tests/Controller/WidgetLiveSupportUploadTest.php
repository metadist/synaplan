<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * POST /api/v1/widgets/{widgetId}/sessions/{sessionId}/upload: the widget
 * owner's file is stored as a pending file of theirs, and another account
 * is refused without a stored file.
 */
final class WidgetLiveSupportUploadTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;

    /** @var list<int> */
    private array $uploaders = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $uploadDir = (string) static::getContainer()->getParameter('kernel.project_dir').'/var/uploads';
        foreach ($this->em->getRepository(File::class)->findBy(['userId' => $this->uploaders]) as $file) {
            @unlink($uploadDir.'/'.$file->getFilePath());
            $this->em->remove($file);
        }
        $this->em->flush();
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testTheOwnersFileIsStoredAsAPendingFileOfTheirs(): void
    {
        $owner = $this->createUser('support-owner');
        [$widget, $session] = $this->humanSession($owner);
        $this->authenticateClient($this->client, $owner);

        $this->upload($widget, $session);

        self::assertResponseIsSuccessful();
        $fileId = json_decode((string) $this->client->getResponse()->getContent(), true)['fileId'] ?? null;
        $this->em->clear();
        $file = $this->em->find(File::class, $fileId);
        self::assertNotNull($file);
        self::assertSame([$owner->getId(), 'pending', 'notes.txt'], [$file->getUserId(), $file->getStatus(), $file->getFileName()]);
    }

    public function testAnotherAccountIsRefusedWithoutAStoredFile(): void
    {
        [$widget, $session] = $this->humanSession($this->createUser('support-owner'));
        $stranger = $this->createUser('support-stranger');
        $this->authenticateClient($this->client, $stranger);

        $this->upload($widget, $session);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->em->getRepository(File::class)->findBy(['userId' => $stranger->getId()]));
    }

    private function upload(Widget $widget, WidgetSession $session): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'support');
        file_put_contents($path, 'Operator notes for the visitor.');
        $this->client->request(
            'POST',
            sprintf('/api/v1/widgets/%s/sessions/%s/upload', $widget->getWidgetId(), $session->getSessionId()),
            [],
            ['file' => new UploadedFile($path, 'notes.txt', 'text/plain', null, true)],
        );
    }

    /**
     * @return array{Widget, WidgetSession}
     */
    private function humanSession(User $owner): array
    {
        $this->uploaders[] = (int) $owner->getId();
        $widget = new Widget();
        $widget->setOwnerId((int) $owner->getId());
        $widget->setOwner($owner);
        $widget->setName('Live support');
        $widget->setTaskPromptTopic('general');
        $this->persistEntity($widget);

        $session = new WidgetSession();
        $session->setWidgetId($widget->getWidgetId());
        $session->setSessionId('ses_'.bin2hex(random_bytes(6)));
        $session->setMode(WidgetSession::MODE_HUMAN);

        return [$widget, $this->persistEntity($session)];
    }
}
