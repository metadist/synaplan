<?php

namespace App\Service;

use App\AI\Service\AiFacade;
use App\Entity\InboundEmailHandler;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\PromptRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Inbound Email Handler Service.
 *
 * Handles IMAP/POP3 email fetching and AI-based routing to departments.
 * This is a TOOL that allows users to automatically sort incoming emails.
 */
class InboundEmailHandlerService
{
    public function __construct(
        private InboundEmailHandlerRepository $handlerRepository,
        private PromptRepository $promptRepository,
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private EncryptionService $encryptionService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Test IMAP/POP3 connection.
     */
    public function testConnection(InboundEmailHandler $handler): array
    {
        // Check if IMAP extension is available
        if (!function_exists('imap_open')) {
            $this->logger->error('IMAP extension not available');

            return [
                'success' => false,
                'message' => 'IMAP extension is not installed. Please install php-imap extension.',
            ];
        }

        try {
            $connection = $this->connectImap($handler);

            if ($connection) {
                imap_close($connection);

                return [
                    'success' => true,
                    'message' => 'Connection successful',
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to connect',
            ];
        } catch (\Exception $e) {
            $this->logger->error('IMAP connection test failed', [
                'handler_id' => $handler->getId(),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
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
     * Handles multipart MIME messages and extracts text/plain or text/html.
     */
    private function extractEmailBody($connection, int $msgNumber): string
    {
        $structure = imap_fetchstructure($connection, $msgNumber);

        // Single part message (not multipart)
        if (!isset($structure->parts)) {
            $body = imap_body($connection, $msgNumber);

            // Decode based on encoding
            return $this->decodeEmailBody($body, $structure->encoding ?? 0);
        }

        // Multipart message - extract text/plain or text/html
        $textPlain = '';
        $textHtml = '';

        foreach ($structure->parts as $partNumber => $part) {
            $partBody = imap_fetchbody($connection, $msgNumber, (string) ($partNumber + 1));

            // Check MIME type
            $mimeType = $this->getMimeType($part);

            if ('text/plain' === $mimeType) {
                $textPlain = $this->decodeEmailBody($partBody, $part->encoding ?? 0);
            } elseif ('text/html' === $mimeType) {
                $textHtml = $this->decodeEmailBody($partBody, $part->encoding ?? 0);
            }
        }

        // Prefer plain text, fallback to HTML (stripped)
        if (!empty($textPlain)) {
            return $textPlain;
        }

        if (!empty($textHtml)) {
            return strip_tags($textHtml);
        }

        // Fallback: return raw body
        return imap_body($connection, $msgNumber);
    }

    /**
     * Get MIME type from IMAP body part.
     */
    private function getMimeType(object $part): string
    {
        $primaryType = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
        $type = $primaryType[$part->type] ?? 'text';
        $subtype = strtolower($part->subtype ?? 'plain');

        return $type.'/'.$subtype;
    }

    /**
     * Decode email body based on encoding.
     */
    private function decodeEmailBody(string $body, int $encoding): string
    {
        return match ($encoding) {
            1 => imap_8bit($body),           // 8BIT
            2 => imap_binary($body),         // BINARY
            3 => base64_decode($body),       // BASE64
            4 => quoted_printable_decode($body), // QUOTED-PRINTABLE
            default => $body,                // 7BIT or OTHER
        };
    }

    /**
     * Connect to IMAP/POP3 server.
     */
    private function connectImap(InboundEmailHandler $handler): ?\IMAP\Connection
    {
        $server = $this->buildServerString($handler);
        $password = $handler->getDecryptedPassword($this->encryptionService);

        $connection = @imap_open(
            $server,
            $handler->getUsername(),
            $password,
            0
        );

        if (!$connection) {
            $errors = imap_errors();
            throw new \Exception('IMAP connection failed: '.implode(', ', $errors ?: ['Unknown error']));
        }

        return $connection;
    }

    /**
     * Build IMAP server connection string.
     */
    private function buildServerString(InboundEmailHandler $handler): string
    {
        $server = $handler->getMailServer();
        $port = $handler->getPort();
        $protocol = strtolower($handler->getProtocol());
        $security = $handler->getSecurity();

        // Build connection string: {server:port/protocol/security}
        $securityFlag = match ($security) {
            'SSL/TLS' => 'ssl',
            'STARTTLS' => 'tls',
            default => 'notls',
        };

        return sprintf('{%s:%d/%s/%s}INBOX', $server, $port, $protocol, $securityFlag);
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

        try {
            $connection = $this->connectImap($handler);

            if (!$connection) {
                $handler->setStatus('error');
                $handler->touch();

                // Note: Need EntityManager to flush - will be handled by caller
                return [
                    'success' => false,
                    'processed' => 0,
                    'errors' => ['Failed to connect to mail server'],
                ];
            }

            // Build search criteria based on email filter
            $searchCriteria = $this->buildSearchCriteria($handler);
            $messages = imap_search($connection, $searchCriteria);

            if (!$messages) {
                imap_close($connection);
                $handler->setLastChecked(date('YmdHis'));
                $handler->setStatus('active');

                return [
                    'success' => true,
                    'processed' => 0,
                    'errors' => [],
                ];
            }

            foreach ($messages as $msgNumber) {
                try {
                    $header = imap_headerinfo($connection, $msgNumber);
                    $body = $this->extractEmailBody($connection, $msgNumber);

                    $subject = $header->subject ?? '(no subject)';
                    $from = $header->from[0]->mailbox.'@'.$header->from[0]->host;

                    // Route email to department
                    $routedEmail = $this->routeEmailToDepartment($handler, $subject, $body);

                    if ($routedEmail) {
                        // Forward email to routed department using SMTP
                        $this->forwardEmail($handler, $from, $routedEmail, $subject, $body);

                        $this->logger->info('Email processed and forwarded', [
                            'handler_id' => $handler->getId(),
                            'from' => $from,
                            'subject' => $subject,
                            'routed_to' => $routedEmail,
                        ]);
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
                        'handler_id' => $handler->getId(),
                        'message_number' => $msgNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($handler->isDeleteAfter()) {
                imap_expunge($connection);
            }

            imap_close($connection);

            $handler->setLastChecked(date('YmdHis'));
            $handler->setStatus('active');

            return [
                'success' => true,
                'processed' => $processed,
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            $handler->setStatus('error');
            $this->logger->error('Handler processing failed', [
                'handler_id' => $handler->getId(),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'processed' => $processed,
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Forward email to department using handler's SMTP credentials.
     */
    private function forwardEmail(
        InboundEmailHandler $handler,
        string $fromEmail,
        string $toEmail,
        string $subject,
        string $body,
    ): void {
        // Get SMTP credentials (decrypted)
        $smtpConfig = $handler->getSmtpCredentials($this->encryptionService);

        if (!$smtpConfig) {
            $this->logger->warning('No SMTP credentials configured for forwarding', [
                'handler_id' => $handler->getId(),
            ]);

            return;
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
        } catch (\Exception $e) {
            $this->logger->error('Failed to forward email', [
                'handler_id' => $handler->getId(),
                'error' => $e->getMessage(),
                'to' => $toEmail,
            ]);
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
