<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Entity\Chat;
use App\Entity\DesktopJob;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Posts the "done" note back into the chat that queued a desktop job, so a
 * result computed on the user's laptop re-enters the account like any other
 * channel's message.
 *
 * Provenance is stamped ({@see DesktopJobContract::RESULT_SOURCE}) because this
 * text originates on an untrusted device — it is content, never an instruction
 * the pipeline should act on.
 */
final readonly class DesktopJobResultNotifier
{
    private const DEVICE_TEXT_MAX = 400;

    public function __construct(
        private ChatRepository $chatRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Post a completion (or failure) note into the job's chat, if it has one.
     * Never throws — a failure to notify must not fail the device's report.
     */
    public function notify(DesktopJob $job): void
    {
        $chatId = $job->getChatId();
        if (null === $chatId) {
            return;
        }

        try {
            $chat = $this->chatRepository->find($chatId);
            if (!$chat instanceof Chat || $chat->getUserId() !== $job->getOwnerId()) {
                return;
            }

            $locale = $this->localeFor($job->getOwnerId());
            $message = (new Message())
                ->setUserId($job->getOwnerId())
                ->setChat($chat)
                ->setTrackingId(time())
                ->setProviderIndex('DESKTOP')
                ->setUnixTimestamp(time())
                ->setDateTime(date('YmdHis'))
                ->setMessageType('API')
                ->setFile(0)
                ->setTopic('CHAT')
                ->setLanguage($locale)
                ->setText($this->buildText($job, $locale))
                ->setDirection('OUT')
                ->setStatus('complete');

            $this->em->persist($message);
            $chat->updateTimestamp();
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to post desktop job completion note', [
                'job_id' => $job->getId(),
                'chat_id' => $chatId,
                'exception' => $e,
            ]);
        }
    }

    private function localeFor(int $ownerId): string
    {
        $user = $this->userRepository->find($ownerId);

        return $user instanceof User ? $user->getLocale() : 'en';
    }

    private function buildText(DesktopJob $job, string $locale): string
    {
        $skill = trim((string) ($job->getInput()['skill'] ?? ''));
        if ('' === $skill) {
            $skill = $this->translator->trans('desktop.job.unnamed', [], 'desktop', $locale);
        }

        if (DesktopJob::STATUS_CANCELLED === $job->getStatus()) {
            return $this->translator->trans('desktop.job.cancelled', [
                '%skill%' => $skill,
            ], 'desktop', $locale);
        }

        if (DesktopJob::STATUS_SUCCEEDED !== $job->getStatus()) {
            return $this->translator->trans('desktop.job.failed', [
                '%skill%' => $skill,
                '%reason%' => $this->failureReason($job, $locale),
            ], 'desktop', $locale);
        }

        $result = $job->getResult() ?? [];
        $lines = [
            $this->translator->trans('desktop.job.finished', ['%skill%' => $skill], 'desktop', $locale),
        ];

        $summary = $this->deviceText($result['summary'] ?? null);
        if (null !== $summary) {
            $lines[] = $summary;
        }

        $fileIds = [];
        if (isset($result['fileIds']) && \is_array($result['fileIds'])) {
            foreach ($result['fileIds'] as $fileId) {
                if (is_numeric($fileId)) {
                    $fileIds[] = (int) $fileId;
                }
            }
        }
        if ([] !== $fileIds) {
            $lines[] = $this->translator->trans('desktop.job.files', [
                '%ids%' => implode(', ', array_map(static fn (int $id): string => '#'.$id, $fileIds)),
            ], 'desktop', $locale);
        }

        return implode("\n", $lines);
    }

    private function failureReason(DesktopJob $job, string $locale): string
    {
        $result = $job->getResult() ?? [];
        $message = $this->deviceText($result['message'] ?? null);
        if (null === $message) {
            $message = $this->deviceText($result['summary'] ?? null);
        }
        if (null !== $message) {
            return $message;
        }

        $code = $job->getErrorCode() ?? DesktopJobContract::ERROR_LOCAL_ERROR;
        $key = 'desktop.job.reason.'.$code;
        $translated = $this->translator->trans($key, [], 'desktop', $locale);
        if ($translated === $key) {
            return $this->translator->trans('desktop.job.reason.local_error', [], 'desktop', $locale);
        }

        return $translated;
    }

    private function deviceText(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $withoutControls = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $value) ?? $value;
        $cleaned = trim((string) preg_replace('/\s+/u', ' ', strip_tags($withoutControls)));
        if ('' === $cleaned) {
            return null;
        }
        if (mb_strlen($cleaned) <= self::DEVICE_TEXT_MAX) {
            return $cleaned;
        }

        return mb_substr($cleaned, 0, self::DEVICE_TEXT_MAX - 1).'…';
    }
}
