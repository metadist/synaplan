<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\File;
use App\Service\Media\ContentCredentialsMark;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Every generated file row is marked once, including paths whose registrar
 * cannot grow. The model name can be refined afterwards.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postFlush)]
final class GeneratedFileMarkListener
{
    /** @var list<File> */
    private array $pending = [];

    private bool $marking = false;

    public function __construct(private readonly ContentCredentialsMark $marks)
    {
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $file = $event->getObject();
        if ($file instanceof File && 'generated' === $file->getSource() && 'generated' === $file->getStatus() && !$file->isGenerated()) {
            $this->pending[] = $file;
        }
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        if ($this->marking || [] === $this->pending) {
            return;
        }

        $batch = $this->pending;
        $this->pending = [];
        $this->marking = true;
        try {
            foreach ($batch as $file) {
                if ($file->isGenerated()) {
                    continue;
                }
                $this->marks->apply($file);
            }
        } finally {
            $this->marking = false;
        }
        unset($event);
    }
}
