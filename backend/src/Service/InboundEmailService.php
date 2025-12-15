<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Inbound Email Service.
 *
 * Handles fetching emails from Mailhog (or IMAP) and forwarding them to the email webhook.
 */
class InboundEmailService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $mailhogApiUrl = 'http://mailhog:8025/api/v2',
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
     * Process all pending emails from Mailhog.
     */
    public function processMailhogEmails(string $webhookUrl, bool $deleteAfterProcessing = true): array
    {
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
     * Check if email destination is valid (smart@synaplan.net or smart+keyword@synaplan.net).
     * Accept legacy synaplan.com too.
     */
    private function isValidDestination(string $email): bool
    {
        $email = strtolower(trim($email));

        return 1 === preg_match('/^smart(\+[a-z0-9\-_]+)?@synaplan\.(?:net|com)$/i', $email);
    }
}
