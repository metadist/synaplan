<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Repository\UserRepository;
use App\Service\InternalEmailService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `email_me` runner — mails the assembled task results to the ACCOUNT OWNER as
 * one multi-MIME email (text + attachments produced by upstream nodes). No
 * model call; pure side-channel delivery via {@see InternalEmailService}.
 *
 * Inputs (resolved like {@see ComposeReplyRunner}):
 *   - `text`        : string (typically "$nX.text")
 *   - `attachments` : list of upstream file descriptors (["$nX.file", ...])
 *
 * Guards: the owner must exist, have a real (non-placeholder) address, and be
 * email-verified — otherwise the node fails with a clear reason while the rest
 * of the plan (including the chat reply) is unaffected, because `compose_reply`
 * never depends on this node.
 */
final readonly class EmailMeRunner implements TaskRunner
{
    /** Channel placeholder addresses (anonymous_*@…, whatsapp_*@…) are never mailable. */
    private const PLACEHOLDER_DOMAIN = '@synaplan.local';

    public function __construct(
        private InternalEmailService $emailService,
        private UserRepository $userRepository,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::EmailMe];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(Capability::EmailMe, 'Email the results to the account owner as one multi-part mail (text + attachments from other nodes). ONLY when the user explicitly asks to be mailed/emailed the result ("mail it to me", "send it to my email"). Inputs: text, attachments. Never the reply node.'),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $userId = $context->userId ?? (int) $context->message->getUserId();
        $user = $this->userRepository->find($userId);
        if (null === $user) {
            return NodeResult::failed('account owner not found');
        }

        $address = trim($user->getMail());
        if ('' === $address || str_ends_with(strtolower($address), self::PLACEHOLDER_DOMAIN)) {
            return NodeResult::failed('no email address on this account — sign in with a registered account to use "mail it to me"');
        }
        if (!$user->isEmailVerified()) {
            return NodeResult::failed('the account email address is not verified yet');
        }

        $inputs = $context->resolveInputs($node);

        $text = $inputs['text'] ?? '';
        if (is_array($text)) {
            $text = implode("\n\n", array_filter($text, 'is_string'));
        }
        $text = is_string($text) ? $text : '';

        $attachments = $this->resolveAttachments($inputs['attachments'] ?? []);

        if ('' === trim($text) && [] === $attachments) {
            return NodeResult::failed('nothing to send: the previous steps produced no content');
        }

        $locale = $this->locale($context);
        $subject = $this->translator->trans('email.task_result.subject', [], 'emails', $locale);

        try {
            $this->emailService->sendTaskResultEmail($address, $subject, $text, $attachments);
        } catch (\Throwable $e) {
            $this->logger->warning('EmailMeRunner: delivery failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed('email delivery failed: '.$e->getMessage());
        }

        $confirmation = $this->translator->trans(
            'email.task_result.sent_confirmation',
            ['%email%' => $this->maskAddress($address)],
            'emails',
            $locale
        );

        // Surface the confirmation on the task card (and via $nX.text).
        $context->streamChunk($confirmation);

        return NodeResult::ok($confirmation, [], [
            'email_sent_to' => $this->maskAddress($address),
            'attachment_count' => count($attachments),
        ]);
    }

    /**
     * Resolve upstream file descriptors to absolute paths inside the uploads
     * dir (mirrors WebhookController::resolveUploadAbsolutePath): prefer the
     * descriptor's `local_path` (relative to var/uploads), fall back to
     * stripping the static-serve URL prefix from `path`. Unresolvable or
     * out-of-tree entries are dropped with a warning — one missing file must
     * not sink the whole mail.
     *
     * @return list<array{path: string, type: string|null}>
     */
    private function resolveAttachments(mixed $value): array
    {
        $out = [];
        foreach ($this->flatten($value) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $absolute = $this->resolveAbsolutePath($candidate);
            if (null !== $absolute) {
                $out[] = [
                    'path' => $absolute,
                    'type' => is_string($candidate['type'] ?? null) ? $candidate['type'] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private function resolveAbsolutePath(array $descriptor): ?string
    {
        $relative = is_string($descriptor['local_path'] ?? null) ? $descriptor['local_path'] : null;

        if (null === $relative || '' === trim($relative)) {
            $servePrefix = '/api/v1/files/uploads/';
            $url = is_string($descriptor['path'] ?? null) ? $descriptor['path'] : null;
            if (null !== $url && str_starts_with($url, $servePrefix)) {
                $relative = substr($url, strlen($servePrefix));
            }
        }

        if (null === $relative || '' === trim($relative)) {
            return null;
        }

        $baseDir = realpath(rtrim($this->uploadDir, '/')) ?: rtrim($this->uploadDir, '/');
        $resolved = realpath($baseDir.'/'.ltrim($relative, '/'));

        $isWithinBaseDir = false !== $resolved
            && (str_starts_with($resolved, $baseDir.DIRECTORY_SEPARATOR) || $resolved === $baseDir);

        if (false === $resolved || !$isWithinBaseDir || !is_file($resolved)) {
            $this->logger->warning('EmailMeRunner: attachment not found or outside uploads dir', [
                'descriptor_path' => $descriptor['path'] ?? null,
                'descriptor_local_path' => $descriptor['local_path'] ?? null,
            ]);

            return null;
        }

        return $resolved;
    }

    /**
     * Flatten one level of nested arrays so both `["$n3.file"]` (each resolving
     * to a descriptor) and a pre-resolved list of descriptors work — same
     * contract as {@see ComposeReplyRunner::flatten()}.
     *
     * @return list<mixed>
     */
    private function flatten(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item) && !isset($item['path'])) {
                foreach ($item as $sub) {
                    $out[] = $sub;
                }
            } else {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function locale(NodeContext $context): string
    {
        $language = $context->classification['language'] ?? null;
        if (is_string($language) && 2 === strlen($language) && 'NN' !== strtoupper($language)) {
            return strtolower($language);
        }

        $messageLanguage = $context->message->getLanguage();

        return ($messageLanguage && 'NN' !== strtoupper($messageLanguage)) ? strtolower($messageLanguage) : 'en';
    }

    /** "alice@example.com" → "a***@example.com" — never echo the full address into chat. */
    private function maskAddress(string $address): string
    {
        $at = strrpos($address, '@');
        if (false === $at || 0 === $at) {
            return '***';
        }

        return substr($address, 0, 1).'***'.substr($address, $at);
    }
}
