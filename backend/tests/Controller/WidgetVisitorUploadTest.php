<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Repository\VisitorUploadRepository;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RAG\VectorStorage\VisitorUploadFilter;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * POST /api/v1/widget/{widgetId}/upload: a visitor's file stays with that
 * visitor's session. It is not written to the widget's knowledge index, and
 * a chunk of it that was indexed earlier is not returned by search.
 */
final class WidgetVisitorUploadTest extends WebTestCase
{
    use CreatesTestEntities;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $uploadDir = (string) static::getContainer()->getParameter('kernel.project_dir').'/var/uploads';
        foreach ($this->createdEntities as $entity) {
            if ($entity instanceof WidgetSession) {
                foreach ($this->em->getRepository(File::class)->findBy(['userSessionId' => $entity->getId()]) as $file) {
                    @unlink($uploadDir.'/'.$file->getFilePath());
                    $this->em->remove($file);
                }
            }
        }
        $this->em->flush();
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testAVisitorsFileIsBoundToTheirSessionAndNotIndexed(): void
    {
        $owner = $this->createUser('upload-owner');
        [$widget, $session] = $this->widgetWithSession($owner);

        $fileId = $this->upload($widget, $session);

        $this->em->clear();
        $file = $this->em->find(File::class, $fileId);
        self::assertNotNull($file);
        self::assertSame(
            [0, $session->getId(), null, 'extracted', File::VECTOR_STATE_NONE],
            [$file->getUserId(), $file->getUserSessionId(), $file->getGroupKey(), $file->getStatus(), $file->getVectorState()],
        );
        self::assertStringContainsString('Order 4711', $file->getFileText());

        $storage = static::getContainer()->get(VectorStorageFacade::class);
        self::assertSame(0, $storage->getFileChunkInfo((int) $owner->getId(), $fileId)['chunks']);

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('vectorized', $body);
        self::assertArrayNotHasKey('chunks_created', $body['file']);
    }

    public function testAChunkIndexedBeforeTheFixIsNotReturnedToAnotherVisitor(): void
    {
        $owner = $this->createUser('upload-owner');
        [$widget, $session] = $this->widgetWithSession($owner);
        $fileId = $this->upload($widget, $session);
        $group = 'WIDGET:'.$widget->getWidgetId();

        self::assertSame([$fileId], static::getContainer()->get(VisitorUploadRepository::class)->idsAmong([$fileId, 0]));

        $kept = static::getContainer()->get(VisitorUploadFilter::class)->withoutVisitorUploads([
            new SearchResult('doc_1', $fileId, $group, 'Order 4711 belongs to visitor A.', 1.0, 0, 1),
        ]);

        self::assertSame([], $kept);
    }

    public function testUploadIsRefusedWhenTheWidgetDoesNotAllowIt(): void
    {
        $owner = $this->createUser('upload-owner');
        [$widget, $session] = $this->widgetWithSession($owner, false);

        $this->request($widget, $session);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->em->getRepository(File::class)->findBy(['userSessionId' => $session->getId()]));
    }

    private function upload(Widget $widget, WidgetSession $session): int
    {
        $this->request($widget, $session);
        self::assertResponseIsSuccessful();

        return (int) json_decode((string) $this->client->getResponse()->getContent(), true)['file']['id'];
    }

    private function request(Widget $widget, WidgetSession $session): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'visitor');
        file_put_contents($path, 'Order 4711 belongs to visitor A.');
        $this->client->request(
            'POST',
            sprintf('/api/v1/widget/%s/upload', $widget->getWidgetId()),
            [],
            ['file' => new UploadedFile($path, 'order.txt', 'text/plain', null, true)],
            ['HTTP_X_WIDGET_SESSION' => $session->getSessionId()],
        );
    }

    /**
     * @return array{Widget, WidgetSession}
     */
    private function widgetWithSession(User $owner, bool $allowFileUpload = true): array
    {
        $widget = new Widget();
        $widget->setOwnerId((int) $owner->getId());
        $widget->setOwner($owner);
        $widget->setName('Upload widget');
        $widget->setTaskPromptTopic('general');
        $widget->setConfig(['allowFileUpload' => $allowFileUpload]);
        $this->persistEntity($widget);

        $session = new WidgetSession();
        $session->setWidgetId($widget->getWidgetId());
        $session->setSessionId('ses_'.bin2hex(random_bytes(6)));

        return [$widget, $this->persistEntity($session)];
    }
}
