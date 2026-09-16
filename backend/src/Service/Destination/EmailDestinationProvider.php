<?php

declare(strict_types=1);

namespace App\Service\Destination;

use App\Repository\UserRepository;
use App\Service\InternalEmailService;

final readonly class EmailDestinationProvider implements DestinationProvider
{
    public function __construct(
        private InternalEmailService $emailService,
        private UserRepository $users,
    ) {
    }

    public function id(): string
    {
        return 'email';
    }

    public function send(ShareableFile $file, array $params): DestinationResult
    {
        $user = $this->users->find($file->ownerId);
        if (null === $user) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, [
                'connection' => 'email',
            ]);
        }

        $address = trim($user->getMail());
        if ('' === $address || str_ends_with(strtolower($address), '@synaplan.local')) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized, [
                'connection' => 'email',
            ]);
        }

        if (!is_file($file->absolutePath)) {
            return DestinationResult::failure(DestinationFailureCode::NotFound, [
                'target' => $file->name,
                'connection' => 'email',
            ]);
        }

        $subject = is_string($params['subject'] ?? null) ? $params['subject'] : $file->name;
        $body = is_string($params['body'] ?? null) ? $params['body'] : $file->name;

        try {
            $this->emailService->sendTaskResultEmail($address, $subject, $body, [
                ['path' => $file->absolutePath, 'type' => null],
            ]);
        } catch (\Throwable) {
            return DestinationResult::failure(DestinationFailureCode::Unreachable, [
                'connection' => 'email',
            ]);
        }

        return DestinationResult::success($address, ['connection' => 'email']);
    }
}
