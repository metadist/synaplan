<?php

namespace App\Service;

use App\AI\Service\AiFacade;
use App\Entity\InboundEmailHandler;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Inbound Email Handler Service.
 *
 * Handles IMAP/POP3 email fetching and AI-based routing to departments.
 * This is a TOOL that allows users to automatically sort incoming emails.
 */
final readonly class InboundEmailHandlerService
{
    /** Masked password placeholder from API (same as frontend). */
    private const MASKED_PASSWORD_PLACEHOLDER = '••••••••';

    public function __construct(
        private InboundEmailHandlerRepository $handlerRepository,
        private PromptRepository $promptRepository,
        private UserRepository $userRepository,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private EncryptionService $encryptionService,
        private MailHandlerLogService $activityLog,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Test IMAP/POP3 connection.
     */
    public function testConnection(InboundEmailHandler $handler): array
    {
        return $this->runMailboxConnectionTest(
            $handler->getMailServer(),
            $handler->getPort(),
            $handler->getProtocol(),
            $handler->getSecurity(),
            $handler->getUsername(),
            $handler->getDecryptedPassword($this->encryptionService),
            ['handler_id' => $handler->getId()]
        );
    }

    /**
     * Test mailbox login using explicit credentials (no persisted handler required).
     */
    public function testMailboxCredentials(
        string $mailServer,
        int $port,
        string $protocol,
        string $security,
        string $username,
        string $plainPassword,
    ): array {
        return $this->runMailboxConnectionTest(
            $mailServer,
            $port,
            $protocol,
            $security,
            $username,
            $plainPassword,
            []
        );
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function runMailboxConnectionTest(
        string $mailServer,
        int $port,
        string $protocol,
        string $security,
        string $username,
        string $plainPassword,
        array $logContext,
    ): array {
        if (!function_exists('imap_open')) {
            $this->logger->error('IMAP extension not available');

            return [
                'success' => false,
                'message' => 'IMAP extension is not installed. Please install php-imap extension.',
            ];
        }

        try {
            $connection = $this->connectMailbox(
                $mailServer,
                $port,
                $protocol,
                $security,
                $username,
                $plainPassword
            );

            imap_close($connection);

            return [
                'success' => true,
                'message' => 'Connection successful',
            ];
        } catch (\Exception $e) {
            $this->logger->error('IMAP connection test failed', array_merge($logContext, [
                'error' => $e->getMessage(),
            ]));

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public static function isMaskedPasswordPlaceholder(string $password): bool
    {
        return '' === $password || self::MASKED_PASSWORD_PLACEHOLDER === $password;
    }

    /**
     * Build IMAP search criteria based on handler's email filter config.
     * Uses combination of UNSEEN flag + timestamp for robust duplicate prevention.
     */
    private function buildSearchCriteria(InboundEmailHandler $handler): string
    {
        $emailFilter = $handler->getEmailFilter();
        $mode = $emailFilter['mode'] ?? 'new';

        // Mode: new - Only fetch unseen emails since last successful check
        if ('new' === $mode) {
            // Use last successful check timestamp to avoid re-processing
            $lastChecked = $handler->getLastChecked();
            if ($lastChecked) {
                $sinceDate = \DateTime::createFromFormat('YmdHis', $lastChecked);
                if ($sinceDate) {
                    // UNSEEN + SINCE last check = robust duplicate prevention
                    return 'UNSEEN SINCE "'.date('d M Y', $sinceDate->getTimestamp()).'"';
                }
            }

            return 'UNSEEN';
        }

        // Mode: historical - Fetch emails from a specific date onwards
        // IMPORTANT: Always use UNSEEN to prevent re-processing forwarded emails
        if ('historical' === $mode) {
            $fromDate = $emailFilter['from_date'] ?? null;

            // For historical mode: Use either fromDate or lastChecked (whichever is later)
            // This ensures we don't re-process emails from previous runs
            $searchDate = null;

            if ($fromDate) {
                $searchDate = strtotime($fromDate);
            }

            $lastChecked = $handler->getLastChecked();
            if ($lastChecked) {
                $lastCheckedDate = \DateTime::createFromFormat('YmdHis', $lastChecked);
                if ($lastCheckedDate) {
                    $lastCheckedTimestamp = $lastCheckedDate->getTimestamp();
                    // Use the LATER date to avoid re-processing
                    $searchDate = $searchDate ? max($searchDate, $lastCheckedTimestamp) : $lastCheckedTimestamp;
                }
            }

            if ($searchDate) {
                return 'UNSEEN SINCE "'.date('d M Y', $searchDate).'"';
            }

            // If no date specified, fetch only unseen
            return 'UNSEEN';
        }

        // Fallback
        return 'UNSEEN';
    }

    /**
     * Extract clean email body from IMAP message.
     * Recursively walks multipart MIME tree to extract the first text/plain
     * (or text/html as fallback) body, ignoring attachments.
     */
    private function extractEmailBody(\IMAP\Connection $connection, int $msgNumber): string
    {
        $structure = imap_fetchstructure($connection, $msgNumber);

        if (!is_object($structure)) {
            // imap_fetchstructure() returns false on protocol-level errors.
            // Returning '' here would silently feed the AI router an empty
            // body — fall back to the raw imap_body() (same "last resort"
            // shape the deep-multipart path uses) and log the failure so
            // the activity log can surface it.
            $this->logger->warning('imap_fetchstructure failed; falling back to raw imap_body', [
                'message_number' => $msgNumber,
                'imap_errors' => imap_errors() ?: [],
            ]);

            return imap_body($connection, $msgNumber);
        }

        // Single-part message (not multipart): the whole body is the part.
        if (empty($structure->parts)) {
            $body = imap_body($connection, $msgNumber);

            return $this->decodeEmailBody(
                $body,
                $structure->encoding ?? 0,
                $this->getCharset($structure)
            );
        }

        $fetchBody = static fn (string $section): string => imap_fetchbody($connection, $msgNumber, $section);
        $textParts = $this->collectTextParts($structure->parts, '', $fetchBody);

        if ('' !== $textParts['plain']) {
            return $textParts['plain'];
        }

        if ('' !== $textParts['html']) {
            return trim(strip_tags($textParts['html']));
        }

        // Last resort: return the raw body so a downstream LLM at least
        // has something to look at, even if it's MIME boundaries.
        return imap_body($connection, $msgNumber);
    }

    /**
     * Recursively walk an IMAP body-structure tree and collect the first
     * text/plain and text/html body parts, ignoring explicit attachments.
     *
     * Section numbers follow IMAP's dotted addressing (RFC 3501 §6.4.5):
     * top-level parts are "1", "2", ...; nested parts are "1.1", "1.2", ...
     *
     * The walker takes a `$fetchBody` closure rather than calling
     * `imap_fetchbody()` directly so it can be unit-tested against
     * synthetic body-structure trees without a real IMAP connection.
     *
     * @param array<int, object>       $parts
     * @param callable(string): string $fetchBody receives a dotted section
     *                                            number and returns the
     *                                            (still-encoded) body for
     *                                            that section
     *
     * @return array{plain: string, html: string}
     */
    private function collectTextParts(
        array $parts,
        string $sectionPrefix,
        callable $fetchBody,
    ): array {
        $textPlain = '';
        $textHtml = '';

        foreach ($parts as $partNumber => $part) {
            $section = '' === $sectionPrefix
                ? (string) ($partNumber + 1)
                : $sectionPrefix.'.'.($partNumber + 1);

            if ($this->isAttachment($part)) {
                continue;
            }

            $mimeType = $this->getMimeType($part);
            $isMultipart = !empty($part->parts);

            if ('' === $textPlain && 'text/plain' === $mimeType && !$isMultipart) {
                $textPlain = $this->decodeEmailBody(
                    $fetchBody($section),
                    $part->encoding ?? 0,
                    $this->getCharset($part)
                );
            } elseif ('' === $textHtml && 'text/html' === $mimeType && !$isMultipart) {
                $textHtml = $this->decodeEmailBody(
                    $fetchBody($section),
                    $part->encoding ?? 0,
                    $this->getCharset($part)
                );
            } elseif ($isMultipart) {
                $nested = $this->collectTextParts($part->parts, $section, $fetchBody);
                if ('' === $textPlain) {
                    $textPlain = $nested['plain'];
                }
                if ('' === $textHtml) {
                    $textHtml = $nested['html'];
                }
            }

            if ('' !== $textPlain && '' !== $textHtml) {
                break;
            }
        }

        return ['plain' => $textPlain, 'html' => $textHtml];
    }

    /**
     * Get MIME type from IMAP body part (e.g. "text/plain", "multipart/alternative").
     */
    private function getMimeType(object $part): string
    {
        $primaryType = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $type = $primaryType[$part->type ?? 0] ?? 'text';
        $subtype = strtolower($part->subtype ?? 'plain');

        return $type.'/'.$subtype;
    }

    /**
     * Detect whether an IMAP body part is an explicit attachment so we
     * don't accidentally treat an attached .txt or .html file as the body.
     */
    private function isAttachment(object $part): bool
    {
        if (empty($part->ifdisposition)) {
            return false;
        }

        return 'attachment' === strtolower($part->disposition ?? '');
    }

    /**
     * Read the charset parameter from a part's Content-Type, if any.
     */
    private function getCharset(object $part): ?string
    {
        if (empty($part->ifparameters) || empty($part->parameters)) {
            return null;
        }

        foreach ($part->parameters as $param) {
            $attr = isset($param->attribute) ? strtolower($param->attribute) : '';
            if ('charset' === $attr) {
                $value = $param->value ?? null;

                return ('' === $value || null === $value) ? null : (string) $value;
            }
        }

        return null;
    }

    /**
     * Decode an email body part according to its Content-Transfer-Encoding
     * and convert to UTF-8 if a non-UTF-8 charset was declared.
     *
     * NOTE: Encodings 7BIT (0), 8BIT (1), BINARY (2) and "OTHER" all carry the
     * payload as-is — the imap_8bit() / imap_binary() helpers *encode* (not
     * decode) and would corrupt the body, so they are deliberately not used.
     */
    private function decodeEmailBody(string $body, int $encoding, ?string $charset = null): string
    {
        $decoded = match ($encoding) {
            3 => base64_decode($body, false),
            4 => quoted_printable_decode($body),
            default => $body,
        };

        if ('' === $decoded) {
            return $decoded;
        }

        if (null !== $charset) {
            $normalized = strtoupper($charset);
            if ('UTF-8' !== $normalized && 'US-ASCII' !== $normalized && '' !== $normalized) {
                $converted = @mb_convert_encoding($decoded, 'UTF-8', $normalized);
                if (is_string($converted)) {
                    $decoded = $converted;
                }
            }
        }

        return $decoded;
    }

    /**
     * Decode RFC 2047 encoded-word headers (e.g. =?UTF-8?B?...?=) to a plain UTF-8 string.
     */
    private function decodeMimeHeader(string $header): string
    {
        if ('' === $header) {
            return $header;
        }

        $elements = imap_mime_header_decode($header);
        if (false === $elements || [] === $elements) {
            return $header;
        }

        $decoded = '';
        foreach ($elements as $element) {
            $charset = isset($element->charset) ? strtolower((string) $element->charset) : 'default';
            $text = (string) ($element->text ?? '');

            if ('default' === $charset || '' === $charset || 'utf-8' === $charset || 'us-ascii' === $charset) {
                $decoded .= $text;
                continue;
            }

            $converted = @mb_convert_encoding($text, 'UTF-8', strtoupper($charset));
            $decoded .= is_string($converted) ? $converted : $text;
        }

        return $decoded;
    }

    /**
     * Connect to IMAP/POP3 server.
     */
    private function connectImap(InboundEmailHandler $handler): \IMAP\Connection
    {
        return $this->connectMailbox(
            $handler->getMailServer(),
            $handler->getPort(),
            $handler->getProtocol(),
            $handler->getSecurity(),
            $handler->getUsername(),
            $handler->getDecryptedPassword($this->encryptionService)
        );
    }

    private function connectMailbox(
        string $mailServer,
        int $port,
        string $protocol,
        string $security,
        string $username,
        string $plainPassword,
    ): \IMAP\Connection {
        $server = $this->buildMailboxServerString($mailServer, $port, $protocol, $security);

        $connection = @imap_open(
            $server,
            $username,
            $plainPassword,
            0
        );

        if (!$connection) {
            $errors = imap_errors();
            $alerts = imap_alerts();
            $errorMessage = 'IMAP connection failed: '.implode(', ', $errors ?: ['Unknown error']);
            if ($alerts) {
                $errorMessage .= ' | Alerts: '.implode(', ', $alerts);
            }
            throw new \Exception($errorMessage);
        }

        return $connection;
    }

    private function buildMailboxServerString(
        string $mailServer,
        int $port,
        string $protocol,
        string $security,
    ): string {
        $protocolKey = strtolower($protocol);

        $securityFlag = match ($security) {
            'SSL/TLS' => 'ssl',
            'STARTTLS' => 'tls',
            default => 'notls',
        };

        return sprintf('{%s:%d/%s/%s}INBOX', $mailServer, $port, $protocolKey, $securityFlag);
    }

    /**
     * Route email to department using AI.
     */
    public function routeEmailToDepartment(InboundEmailHandler $handler, string $subject, string $body): ?string
    {
        $departments = $handler->getDepartments();

        if (empty($departments)) {
            $this->logger->warning('No departments configured for handler', [
                'handler_id' => $handler->getId(),
            ]);

            return $this->getDefaultDepartment($departments);
        }

        // Get mail handler prompt
        $prompt = $this->promptRepository->findByTopic('tools:mailhandler', 0, 'en');

        if (!$prompt) {
            $this->logger->error('Mail handler prompt not found');

            return $this->getDefaultDepartment($departments);
        }

        // Build target list for prompt
        $targetList = $this->buildTargetList($departments);

        // Replace [TARGETLIST] in prompt
        $promptText = str_replace('[TARGETLIST]', $targetList, $prompt->getPrompt());

        // Add email content
        $fullPrompt = $promptText."\n\nSubject: ".$subject."\n\nBody:\n".$body;

        // Get user's default chat model
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $handler->getUserId());
        $provider = null;
        $modelName = null;

        if ($modelId) {
            $provider = $this->modelConfigService->getProviderForModel($modelId);
            $modelName = $this->modelConfigService->getModelName($modelId);
        }

        if (!$provider || !$modelName) {
            $this->logger->error('No chat model configured for mail handler', [
                'handler_id' => $handler->getId(),
                'user_id' => $handler->getUserId(),
            ]);

            return $this->getDefaultDepartment($departments);
        }

        try {
            // Call AI to route email
            $response = $this->aiFacade->chat(
                messages: [
                    ['role' => 'user', 'content' => $fullPrompt],
                ],
                userId: $handler->getUserId(),
                options: [
                    'provider' => $provider,
                    'model' => $modelName,
                ]
            );

            $routedEmail = trim($response['content'] ?? '');

            $handlerUser = $this->userRepository->find($handler->getUserId());
            if ($handlerUser) {
                $this->rateLimitService->recordUsage($handlerUser, 'EMAIL_ROUTING', [
                    'provider' => $response['provider'] ?? $provider,
                    'model' => $response['model'] ?? $modelName,
                    'model_id' => $modelId,
                    'usage' => $response['usage'] ?? [],
                    'response_text' => $routedEmail,
                    'input_text' => $fullPrompt,
                ]);
            }

            // Check if AI decided to discard the email
            if ('DISCARD' === strtoupper($routedEmail)) {
                $this->logger->info('Email discarded as irrelevant by AI', [
                    'handler_id' => $handler->getId(),
                    'subject' => substr($subject, 0, 50),
                ]);

                return null; // Do not forward
            }

            // Validate that routed email is in departments list
            if ($this->isValidDepartmentEmail($routedEmail, $departments)) {
                $this->logger->info('Email routed to department', [
                    'handler_id' => $handler->getId(),
                    'routed_email' => $routedEmail,
                    'subject' => substr($subject, 0, 50),
                ]);

                return $routedEmail;
            }

            // Fallback to default
            $this->logger->warning('AI routed to invalid email, using default', [
                'handler_id' => $handler->getId(),
                'routed_email' => $routedEmail,
            ]);

            return $this->getDefaultDepartment($departments);
        } catch (\Exception $e) {
            $this->logger->error('AI routing failed', [
                'handler_id' => $handler->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->getDefaultDepartment($departments);
        }
    }

    /**
     * Build target list string for AI prompt.
     */
    private function buildTargetList(array $departments): string
    {
        $list = [];
        foreach ($departments as $dept) {
            $default = isset($dept['isDefault']) && $dept['isDefault'] ? 'Default: yes' : 'Default: no';
            $list[] = sprintf(
                "- Email: %s\n  Description: %s\n  %s",
                $dept['email'] ?? '',
                $dept['rules'] ?? 'No description',
                $default
            );
        }

        return implode("\n\n", $list);
    }

    /**
     * Get default department email.
     */
    private function getDefaultDepartment(array $departments): ?string
    {
        foreach ($departments as $dept) {
            if (isset($dept['isDefault']) && $dept['isDefault']) {
                return $dept['email'] ?? null;
            }
        }

        // If no default, return first department
        if (!empty($departments)) {
            return $departments[0]['email'] ?? null;
        }

        return null;
    }

    /**
     * Validate that email is in departments list.
     */
    private function isValidDepartmentEmail(string $email, array $departments): bool
    {
        foreach ($departments as $dept) {
            if (isset($dept['email']) && strtolower(trim($dept['email'])) === strtolower(trim($email))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch and process emails for a handler.
     */
    public function processHandler(InboundEmailHandler $handler): array
    {
        $processed = 0;
        $errors = [];
        $connection = null;
        $userId = $handler->getUserId();
        $handlerId = (int) ($handler->getId() ?? 0);

        try {
            $connection = $this->connectImap($handler);

            // Build search criteria based on email filter
            $searchCriteria = $this->buildSearchCriteria($handler);
            $messages = imap_search($connection, $searchCriteria);

            if (!$messages) {
                $handler->setLastChecked(date('YmdHis'));
                $handler->setStatus('active');
                $this->activityLog->log(
                    $userId,
                    $handlerId,
                    MailHandlerLogService::EVENT_CHECK,
                    MailHandlerLogService::STATUS_SUCCESS,
                    null,
                    ['matched' => 0, 'criteria' => $searchCriteria],
                );
                $this->activityLog->prune($userId, $handlerId);

                return [
                    'success' => true,
                    'processed' => 0,
                    'errors' => [],
                ];
            }

            $matched = count($messages);
            $this->activityLog->log(
                $userId,
                $handlerId,
                MailHandlerLogService::EVENT_CHECK,
                MailHandlerLogService::STATUS_SUCCESS,
                null,
                ['matched' => $matched, 'criteria' => $searchCriteria],
            );

            foreach ($messages as $msgNumber) {
                try {
                    $header = imap_headerinfo($connection, $msgNumber);
                    $body = $this->extractEmailBody($connection, $msgNumber);

                    $rawSubject = (string) ($header->subject ?? '');
                    $subject = '' === $rawSubject ? '(no subject)' : $this->decodeMimeHeader($rawSubject);
                    $from = $header->from[0]->mailbox.'@'.$header->from[0]->host;

                    // Route email to department
                    $routedEmail = $this->routeEmailToDepartment($handler, $subject, $body);

                    if (null === $routedEmail) {
                        $this->logger->info('Email not forwarded (discarded as irrelevant)', [
                            'handler_id' => $handlerId,
                            'from' => $from,
                            'subject' => $subject,
                        ]);
                        $this->activityLog->log(
                            $userId,
                            $handlerId,
                            MailHandlerLogService::EVENT_DISCARDED,
                            MailHandlerLogService::STATUS_SUCCESS,
                            null,
                            ['from' => $from, 'subject' => $subject],
                        );
                    } else {
                        $forwardResult = $this->forwardEmail($handler, $from, $routedEmail, $subject, $body);

                        if ('forwarded' === $forwardResult) {
                            $this->logger->info('Email processed and forwarded', [
                                'handler_id' => $handlerId,
                                'from' => $from,
                                'subject' => $subject,
                                'routed_to' => $routedEmail,
                            ]);
                            $this->activityLog->log(
                                $userId,
                                $handlerId,
                                MailHandlerLogService::EVENT_FORWARDED,
                                MailHandlerLogService::STATUS_SUCCESS,
                                null,
                                ['from' => $from, 'subject' => $subject, 'routed_to' => $routedEmail],
                            );
                        } else {
                            $this->activityLog->log(
                                $userId,
                                $handlerId,
                                MailHandlerLogService::EVENT_NO_SMTP,
                                MailHandlerLogService::STATUS_ERROR,
                                'SMTP credentials are not configured for this handler — the routing decision was made but the email could not be forwarded.',
                                ['from' => $from, 'subject' => $subject, 'routed_to' => $routedEmail],
                            );
                        }
                    }

                    // Mark as read (or delete if configured)
                    if ($handler->isDeleteAfter()) {
                        imap_delete($connection, $msgNumber);
                    } else {
                        imap_setflag_full($connection, $msgNumber, '\\Seen');
                    }

                    ++$processed;
                } catch (\Exception $e) {
                    $errors[] = "Message {$msgNumber}: ".$e->getMessage();
                    $this->logger->error('Failed to process email', [
                        'handler_id' => $handlerId,
                        'message_number' => $msgNumber,
                        'error' => $e->getMessage(),
                    ]);
                    $this->activityLog->log(
                        $userId,
                        $handlerId,
                        MailHandlerLogService::EVENT_PROCESS_ERROR,
                        MailHandlerLogService::STATUS_ERROR,
                        $e->getMessage(),
                        ['message_number' => $msgNumber],
                    );
                }
            }

            if ($handler->isDeleteAfter()) {
                imap_expunge($connection);
            }

            $handler->setLastChecked(date('YmdHis'));
            $handler->setStatus('active');

            $this->activityLog->prune($userId, $handlerId);

            return [
                'success' => true,
                'processed' => $processed,
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            $handler->setStatus('error');
            $this->logger->error('Handler processing failed', [
                'handler_id' => $handlerId,
                'error' => $e->getMessage(),
            ]);
            if ($handlerId > 0) {
                $this->activityLog->log(
                    $userId,
                    $handlerId,
                    MailHandlerLogService::EVENT_CONNECT_FAILED,
                    MailHandlerLogService::STATUS_ERROR,
                    $e->getMessage(),
                );
                $this->activityLog->prune($userId, $handlerId);
            }

            return [
                'success' => false,
                'processed' => $processed,
                'errors' => [$e->getMessage()],
            ];
        } finally {
            if (null !== $connection) {
                @imap_close($connection);
            }
        }
    }

    /**
     * Forward email to department using handler's SMTP credentials.
     *
     * Returns:
     *   - "forwarded" when the SMTP send completed.
     *   - "no_smtp"   when no SMTP credentials are configured (caller decides
     *                 whether to mark the message seen anyway — keeping the
     *                 historical "best-effort" behaviour).
     *
     * Throws on any other SMTP failure so the caller can log
     * `forward_failed` and avoid marking the source message seen.
     */
    private function forwardEmail(
        InboundEmailHandler $handler,
        string $fromEmail,
        string $toEmail,
        string $subject,
        string $body,
    ): string {
        // Get SMTP credentials (decrypted)
        $smtpConfig = $handler->getSmtpCredentials($this->encryptionService);

        if (!$smtpConfig) {
            $this->logger->warning('No SMTP credentials configured for forwarding', [
                'handler_id' => $handler->getId(),
            ]);

            return 'no_smtp';
        }

        try {
            // Build DSN for Symfony Mailer Transport
            $dsn = $this->buildSmtpDsn($smtpConfig);

            // Create transport from DSN
            $transport = Transport::fromDsn($dsn);
            $mailer = new \Symfony\Component\Mailer\Mailer($transport);

            // Create email
            $email = (new Email())
                ->from($smtpConfig['username'])
                ->to($toEmail)
                ->replyTo($fromEmail)
                ->subject('Fwd: '.$subject)
                ->text($body);

            // Send email
            $mailer->send($email);

            $this->logger->info('Email forwarded successfully', [
                'handler_id' => $handler->getId(),
                'from' => $fromEmail,
                'to' => $toEmail,
                'subject' => $subject,
            ]);

            return 'forwarded';
        } catch (\Exception $e) {
            $this->logger->error('Failed to forward email', [
                'handler_id' => $handler->getId(),
                'error' => $e->getMessage(),
                'to' => $toEmail,
            ]);
            $handlerId = (int) ($handler->getId() ?? 0);
            if ($handlerId > 0) {
                $this->activityLog->log(
                    $handler->getUserId(),
                    $handlerId,
                    MailHandlerLogService::EVENT_FORWARD_FAILED,
                    MailHandlerLogService::STATUS_ERROR,
                    $e->getMessage(),
                    ['from' => $fromEmail, 'subject' => $subject, 'routed_to' => $toEmail],
                );
            }
            throw $e;
        }
    }

    /**
     * Build SMTP DSN from config.
     */
    private function buildSmtpDsn(array $smtpConfig): string
    {
        $scheme = match ($smtpConfig['security']) {
            'SSL/TLS' => 'smtps',
            'STARTTLS' => 'smtp',
            default => 'smtp',
        };

        // Trim username to avoid encoding issues with trailing spaces
        $username = trim($smtpConfig['username']);

        return sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            urlencode($username),
            urlencode($smtpConfig['password']),
            $smtpConfig['server'],
            $smtpConfig['port']
        );
    }

    /**
     * Process all active handlers.
     */
    public function processAllHandlers(): array
    {
        $handlers = $this->handlerRepository->findHandlersToCheck();
        $results = [];

        foreach ($handlers as $handler) {
            $results[$handler->getId()] = $this->processHandler($handler);
        }

        return $results;
    }
}
