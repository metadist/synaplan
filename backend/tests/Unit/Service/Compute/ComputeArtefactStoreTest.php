<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\Contract\ComputeArtefact;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ComputeArtefactStoreTest extends TestCase
{
    public function testRejectsDisallowedMime(): void
    {
        $store = $this->store($this->createStub(ComputeClient::class));

        $this->assertFalse($store->allowsMime('application/octet-stream'));
        $this->assertFalse($store->allowsMime('application/x-executable'));
        $this->assertTrue($store->allowsMime('image/png'));
        $this->assertTrue($store->allowsMime('text/csv'));
    }

    public function testSetsComputeProvenanceAndNeverVectorizesDirectly(): void
    {
        $client = $this->createStub(ComputeClient::class);
        $client->method('listArtefacts')->willReturn([
            new ComputeArtefact('chart.png', 4, 'image/png', 'aa'),
            new ComputeArtefact('evil.bin', 4, 'application/octet-stream', 'bb', 'mime_not_allowed'),
        ]);
        $client->method('downloadArtefact')->willReturn('PNG!');
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $dir = sys_get_temp_dir().'/compute-artefact-'.bin2hex(random_bytes(4));
        $store = new ComputeArtefactStore($client, new UserUploadPathBuilder(), $em, new NullLogger(), $dir);
        $message = $this->createStub(Message::class);
        $message->method('getUserId')->willReturn(13);
        $message->method('getId')->willReturn(99);

        $files = $store->ingest('run1', $message, 1024);

        $this->assertCount(1, $files);
        $this->assertCount(1, $persisted);
        $file = $files[0];
        $this->assertSame('compute', $file->getSource());
        $this->assertSame('artefact', $file->getOriginKind());
        $this->assertSame(File::VECTOR_STATE_NONE, $file->getVectorState());
        $this->assertSame(99, $file->getMessageId());
    }

    private function store(ComputeClient $client): ComputeArtefactStore
    {
        return new ComputeArtefactStore(
            $client,
            new UserUploadPathBuilder(),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            sys_get_temp_dir(),
        );
    }
}
