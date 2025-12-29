<?php

namespace App\Service;

use App\Service\Email\SmartEmailHelper;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Inbound Email Service.
 *
 * Handles fetching emails from Mailhog (development) or Gmail IMAP (production)
 * and forwarding them to the email webhook for smart@synaplan.net processing.
 */
class InboundEmailService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $mailhogApiUrl = 'http://mailhog:8025/api/v2',
        private ?string $gmailUsername = null,
        private ?string $gmailPassword = null,
    ) {
    }

    /**
     * Fetch emails from Mailhog API.
     */
    public function fetchMailhogEmails(int $limit = 50): array
    {
        try {
            $response = $this->httpClient->request('GET', "{$this->mailhogApiUrl}/messages", [
                'query' => ['limit' => $limit],
            ]);

            $data = $response->toArray();

            return $data['items'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch emails from Mailhog', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse Mailhog message format.
     */
    public function parseMailhogMessage(array $message): array
    {
        $headers = $message['Content']['Headers'] ?? [];
        $body = $message['Content']['Body'] ?? '';

        // Extract 'To' address
        $toAddresses = $headers['To'] ?? [];
        $toEmail = '';
        if (!empty($toAddresses) && is_array($toAddresses[0])) {
            $toEmail = $toAddresses[0];
        } elseif (!empty($toAddresses)) {
            // Parse "Name <email@domain.com>" format
            if (preg_match('/<([^>]+)>/', $toAddresses[0], $matches)) {
                $toEmail = $matches[1];
            } else {
                $toEmail = $toAddresses[0];
            }
        }

        // Extract 'From' address
        $fromAddresses = $headers['From'] ?? [];
        $fromEmail = '';
        if (!empty($fromAddresses) && is_array($fromAddresses[0])) {
            $fromEmail = $fromAddresses[0];
        } elseif (!empty($fromAddresses)) {
            if (preg_match('/<([^>]+)>/', $fromAddresses[0], $matches)) {
                $fromEmail = $matches[1];
            } else {
                $fromEmail = $fromAddresses[0];
            }
        }

        // Subject
        $subject = $headers['Subject'][0] ?? '(no subject)';

        // Message ID
        $messageId = $headers['Message-ID'][0] ?? null;

        // In-Reply-To (for threading)
        $inReplyTo = $headers['In-Reply-To'][0] ?? null;

        return [
            'id' => $message['ID'] ?? null,
            'from' => $fromEmail,
            'to' => $toEmail,
            'subject' => $subject,
            'body' => $body,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'raw' => $message,
        ];
    }

    /**
     * Send email to webhook for processing.
     */
    public function forwardToWebhook(array $emailData, string $webhookUrl): array
    {
        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => [
                    'from' => $emailData['from'],
                    'to' => $emailData['to'],
                    'subject' => $emailData['subject'],
                    'body' => $emailData['body'],
                    'message_id' => $emailData['message_id'],
                    'in_reply_to' => $emailData['in_reply_to'],
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
                'response' => $response->toArray(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to forward email to webhook', [
                'email_id' => $emailData['id'] ?? 'unknown',
                'from' => $emailData['from'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete email from Mailhog.
     */
    public function deleteMailhogMessage(string $messageId): bool
    {
        try {
            $response = $this->httpClient->request('DELETE', "{$this->mailhogApiUrl}/messages/{$messageId}");

            return 200 === $response->getStatusCode();
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete Mailhog message', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Process all pending emails from Mailhog (development) or Gmail IMAP (production).
     */
    public function processMailhogEmails(string $webhookUrl, bool $deleteAfterProcessing = true): array
    {
        // Try Gmail IMAP first if credentials are available
        if (!empty($this->gmailUsername) && !empty($this->gmailPassword)) {
            $this->logger->info('Using Gmail IMAP for email processing', [
                'username' => $this->gmailUsername,
            ]);

            return $this->processGmailImapEmails($webhookUrl, $deleteAfterProcessing);
        }

        $this->logger->debug('Gmail credentials not available, using Mailhog', [
            'has_username' => !empty($this->gmailUsername),
            'has_password' => !empty($this->gmailPassword),
        ]);

        // Fallback to Mailhog for development
        $messages = $this->fetchMailhogEmails();
        $results = [
            'total' => count($messages),
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($messages as $message) {
            $emailData = $this->parseMailhogMessage($message);

            // Only process emails to smart@synaplan.net or smart+*@synaplan.net
            // (accept legacy synaplan.com too)
            if (!$this->isValidDestination($emailData['to'])) {
                $this->logger->debug('Skipping email to invalid destination', [
                    'to' => $emailData['to'],
                ]);
                continue;
            }

            $this->logger->info('Processing email from Mailhog', [
                'id' => $emailData['id'],
                'from' => $emailData['from'],
                'to' => $emailData['to'],
                'subject' => $emailData['subject'],
            ]);

            $result = $this->forwardToWebhook($emailData, $webhookUrl);

            if ($result['success']) {
                ++$results['processed'];

                // Delete message from Mailhog if requested
                if ($deleteAfterProcessing && !empty($emailData['id'])) {
                    $this->deleteMailhogMessage($emailData['id']);
                }
            } else {
                ++$results['failed'];
                $results['errors'][] = [
                    'email' => $emailData['from'],
                    'error' => $result['error'] ?? 'Unknown error',
                ];
            }
        }

        return $results;
    }

    /**
     * Fetch and process emails from Gmail IMAP.
     */
    private function processGmailImapEmails(string $webhookUrl, bool $deleteAfterProcessing = true): array
    {
        if (!function_exists('imap_open')) {
            $this->logger->error('IMAP extension not available');

            return [
                'total' => 0,
                'processed' => 0,
                'failed' => 0,
                'errors' => ['IMAP extension not available'],
            ];
        }

        $results = [
            'total' => 0,
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        try {
            $server = '{imap.gmail.com:993/imap/ssl}INBOX';
            $connection = @imap_open($server, $this->gmailUsername, $this->gmailPassword, 0);

            if (!$connection) {
                $errors = imap_errors();
                throw new \Exception('IMAP connection failed: '.implode(', ', $errors ?: ['Unknown error']));
            }

            // Search for unread emails to smart@synaplan.net or smart+*@synaplan.net
            $searchCriteria = 'UNSEEN TO "smart@synaplan.net"';
            $messages = imap_search($connection, $searchCriteria);

            if (!$messages) {
                imap_close($connection);

                return $results;
            }

            $results['total'] = count($messages);

            foreach ($messages as $msgNumber) {
                try {
                    $header = imap_headerinfo($connection, $msgNumber);
                    $body = $this->extractEmailBody($connection, $msgNumber);

                    $fromEmail = $header->from[0]->mailbox.'@'.$header->from[0]->host;
                    $toAddresses = [];
                    if (isset($header->to)) {
                        foreach ($header->to as $to) {
                            $toAddresses[] = $to->mailbox.'@'.$to->host;
                        }
                    }

                    // Find the smart@synaplan.net address in TO field
                    $toEmail = null;
                    foreach ($toAddresses as $toAddr) {
                        if (SmartEmailHelper::isValidSmartAddress($toAddr)) {
                            $toEmail = $toAddr;
                            break;
                        }
                    }

                    if (!$toEmail) {
                        $this->logger->debug('Skipping email - not to smart address', [
                            'to' => $toAddresses,
                        ]);
                        continue;
                    }

                    $subject = $header->subject ?? '(no subject)';
                    $messageId = $header->message_id ?? null;
                    $inReplyTo = $header->in_reply_to ?? null;

                    $emailData = [
                        'id' => (string) $msgNumber,
                        'from' => $fromEmail,
                        'to' => $toEmail,
                        'subject' => $subject,
                        'body' => $body,
                        'message_id' => $messageId,
                        'in_reply_to' => $inReplyTo,
                    ];

                    $this->logger->info('Processing email from Gmail IMAP', [
                        'from' => $fromEmail,
                        'to' => $toEmail,
                        'subject' => $subject,
                    ]);

                    $result = $this->forwardToWebhook($emailData, $webhookUrl);

                    if ($result['success']) {
                        ++$results['processed'];

                        // Mark as read (or delete if configured)
                        if ($deleteAfterProcessing) {
                            imap_delete($connection, $msgNumber);
                        } else {
                            imap_setflag_full($connection, $msgNumber, '\\Seen');
                        }
                    } else {
                        ++$results['failed'];
                        $results['errors'][] = [
                            'email' => $fromEmail,
                            'error' => $result['error'] ?? 'Unknown error',
                        ];
                    }
                } catch (\Exception $e) {
                    ++$results['failed'];
                    $results['errors'][] = [
                        'email' => 'unknown',
                        'error' => $e->getMessage(),
                    ];
                    $this->logger->error('Failed to process email from IMAP', [
                        'message_number' => $msgNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($deleteAfterProcessing) {
                imap_expunge($connection);
            }

            imap_close($connection);
        } catch (\Exception $e) {
            $this->logger->error('Failed to process Gmail IMAP emails', [
                'error' => $e->getMessage(),
            ]);
            $results['errors'][] = [
                'email' => 'system',
                'error' => $e->getMessage(),
            ];
        }

        return $results;
    }

    /**
     * Extract email body from IMAP message.
     */
    private function extractEmailBody($connection, int $msgNumber): string
    {
        $structure = imap_fetchstructure($connection, $msgNumber);

        // Single part message
        if (!isset($structure->parts)) {
            $body = imap_body($connection, $msgNumber);
            $decoded = $this->decodeEmailBody($body, $structure->encoding ?? 0);

            // Ensure UTF-8 encoding
            if (!mb_check_encoding($decoded, 'UTF-8')) {
                $decoded = mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
            }

            return $decoded;
        }

        // Multipart message - extract text/plain or text/html
        $textPlain = '';
        $textHtml = '';

        foreach ($structure->parts as $partNumber => $part) {
            $partBody = imap_fetchbody($connection, $msgNumber, (string) ($partNumber + 1));
            $mimeType = $this->getMimeType($part);

            if ('text/plain' === $mimeType) {
                $decoded = $this->decodeEmailBody($partBody, $part->encoding ?? 0);
                // Ensure UTF-8 encoding
                if (!mb_check_encoding($decoded, 'UTF-8')) {
                    $decoded = mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
                }
                $textPlain = $decoded;
            } elseif ('text/html' === $mimeType) {
                $decoded = $this->decodeEmailBody($partBody, $part->encoding ?? 0);
                // Ensure UTF-8 encoding
                if (!mb_check_encoding($decoded, 'UTF-8')) {
                    $decoded = mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
                }
                $textHtml = $decoded;
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
        $body = imap_body($connection, $msgNumber);
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }

        return $body;
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
     * Check if email destination is a valid smart address.
     */
    private function isValidDestination(string $email): bool
    {
        return SmartEmailHelper::isValidSmartAddress($email);
    }
}
