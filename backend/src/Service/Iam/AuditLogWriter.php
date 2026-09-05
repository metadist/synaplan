<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\AuditLogEntry;
use App\Repository\AuditLogEntryRepository;

final readonly class AuditLogWriter
{
    public function __construct(
        private AuditLogEntryRepository $auditLogEntryRepository,
    ) {
    }

    /**
     * @param array<string, mixed>|null $subject
     */
    public function record(
        int $actorId,
        string $action,
        string $resourceKind = '',
        string $resourceId = '',
        ?array $subject = null,
        string $ip = '',
    ): void {
        $entry = new AuditLogEntry();
        $entry->setActorId($actorId);
        $entry->setAction($action);
        $entry->setResourceKind($resourceKind);
        $entry->setResourceId($resourceId);
        $entry->setSubject($subject);
        $entry->setIp($ip);

        $this->auditLogEntryRepository->save($entry);
    }
}
