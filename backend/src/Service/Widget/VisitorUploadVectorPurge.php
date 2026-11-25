<?php

declare(strict_types=1);

namespace App\Service\Widget;

use App\Entity\File;
use App\Entity\WidgetSession;
use App\Repository\VisitorUploadRepository;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes the vector chunks of files that widget visitors uploaded.
 *
 * Visitor uploads used to be indexed into the widget's knowledge group.
 * Search no longer returns those chunks, but they still take up space in the
 * owner's index until this has run. The files themselves stay.
 */
final readonly class VisitorUploadVectorPurge
{
    private const PAGE_SIZE = 200;

    public function __construct(
        private VisitorUploadRepository $visitorUploads,
        private WidgetSessionRepository $sessions,
        private WidgetRepository $widgets,
        private VectorStorageFacade $vectorStorage,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{files: int, chunks: int, ownerless: int} files looked at,
     *                                                        chunks deleted, and files whose widget or session is gone
     */
    public function run(bool $dryRun = false): array
    {
        $summary = ['files' => 0, 'chunks' => 0, 'ownerless' => 0];
        $afterId = 0;

        while ([] !== $page = $this->visitorUploads->pageAfter($afterId, self::PAGE_SIZE)) {
            foreach ($page as $file) {
                $afterId = (int) $file->getId();
                ++$summary['files'];

                $ownerId = $this->ownerOf($file);
                if (null === $ownerId) {
                    ++$summary['ownerless'];
                    continue;
                }

                $summary['chunks'] += $dryRun
                    ? $this->vectorStorage->getFileChunkInfo($ownerId, $afterId)['chunks']
                    : $this->purge($file, $ownerId);
            }

            $this->em->flush();
            $this->em->clear();
        }

        return $summary;
    }

    private function purge(File $file, int $ownerId): int
    {
        $deleted = $this->vectorStorage->deleteByFile($ownerId, (int) $file->getId());

        if (File::VECTOR_STATE_VECTORIZED === $file->getVectorState()) {
            $file->setVectorState(File::VECTOR_STATE_NONE);
        }
        $file->setChunkCount(0);
        if ('vectorized' === $file->getStatus()) {
            $file->setStatus('extracted');
        }

        return $deleted;
    }

    /**
     * The chunks were stored under the widget's owner.
     */
    private function ownerOf(File $file): ?int
    {
        $session = $this->sessions->find($file->getUserSessionId());
        if (!$session instanceof WidgetSession) {
            return null;
        }

        return $this->widgets->findOneByWidgetId($session->getWidgetId())?->getOwnerId();
    }
}
