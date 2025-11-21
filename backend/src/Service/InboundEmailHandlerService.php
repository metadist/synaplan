<?php

namespace App\Service;

use App\Entity\InboundEmailHandler;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\PromptRepository;
use App\AI\Service\AiFacade;
use App\Service\EncryptionService;
use Psr\Log\LoggerInterface;

/**
 * Inbound Email Handler Service
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
        private EncryptionService $encryptionService,
        private LoggerInterface $logger
    ) {}

    /**
     * Test IMAP/POP3 connection
     */
    public function testConnection(InboundEmailHandler $handler): array
    {
        // Check if IMAP extension is available
        if (!function_exists('imap_open')) {
            $this->logger->error('IMAP extension not available');
            return [
                'success' => false,
                'message' => 'IMAP extension is not installed. Please install php-imap extension.'
            ];
        }

        try {
            $connection = $this->connectImap($handler);
            
            if ($connection) {
                imap_close($connection);
                return [
                    'success' => true,
                    'message' => 'Connection successful'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to connect'
            ];
        } catch (\Exception $e) {
            $this->logger->error('IMAP connection test failed', [
                'handler_id' => $handler->getId(),
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Connect to IMAP/POP3 server
     */
    private function connectImap(InboundEmailHandler $handler): ?\IMAP\Connection
    {
        $server = $this->buildServerString($handler);
        $password = $handler->getDecryptedPassword($this->encryptionService);
        
        $connection = @imap_open(
            $server,
            $handler->getUsername(),
            $password,
            OP_HALFOPEN
        );

        if (!$connection) {
            $errors = imap_errors();
            throw new \Exception('IMAP connection failed: ' . implode(', ', $errors ?: ['Unknown error']));
        }

        return $connection;
    }

    /**
     * Build IMAP server connection string
     */
    private function buildServerString(InboundEmailHandler $handler): string
    {
        $server = $handler->getMailServer();
        $port = $handler->getPort();
        $protocol = strtolower($handler->getProtocol());
        $security = $handler->getSecurity();

        // Build connection string: {server:port/protocol/security}
        $securityFlag = match($security) {
            'SSL/TLS' => 'ssl',
            'STARTTLS' => 'tls',
            default => 'notls'
        };

        return sprintf('{%s:%d/%s/%s}INBOX', $server, $port, $protocol, $securityFlag);
    }

    /**
     * Route email to department using AI
     */
    public function routeEmailToDepartment(InboundEmailHandler $handler, string $subject, string $body): ?string
    {
        $departments = $handler->getDepartments();
        
        if (empty($departments)) {
            $this->logger->warning('No departments configured for handler', [
                'handler_id' => $handler->getId()
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
        $fullPrompt = $promptText . "\n\nSubject: " . $subject . "\n\nBody:\n" . $body;

        try {
            // Call AI to route email
            $response = $this->aiFacade->chat(
                messages: [
                    ['role' => 'user', 'content' => $fullPrompt]
                ],
                modelId: null, // Use default model
                userId: $handler->getUserId()
            );

            $routedEmail = trim($response['content'] ?? '');
            
            // Validate that routed email is in departments list
            if ($this->isValidDepartmentEmail($routedEmail, $departments)) {
                $this->logger->info('Email routed to department', [
                    'handler_id' => $handler->getId(),
                    'routed_email' => $routedEmail,
                    'subject' => substr($subject, 0, 50)
                ]);
                return $routedEmail;
            }

            // Fallback to default
            $this->logger->warning('AI routed to invalid email, using default', [
                'handler_id' => $handler->getId(),
                'routed_email' => $routedEmail
            ]);
            return $this->getDefaultDepartment($departments);

        } catch (\Exception $e) {
            $this->logger->error('AI routing failed', [
                'handler_id' => $handler->getId(),
                'error' => $e->getMessage()
            ]);
            return $this->getDefaultDepartment($departments);
        }
    }

    /**
     * Build target list string for AI prompt
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
     * Get default department email
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
     * Validate that email is in departments list
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
     * Fetch and process emails for a handler
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
                    'errors' => ['Failed to connect to mail server']
                ];
            }

            // Get unread messages
            $messages = imap_search($connection, 'UNSEEN');
            
            if (!$messages) {
                imap_close($connection);
                $handler->setLastChecked(date('YmdHis'));
                $handler->setStatus('active');
                return [
                    'success' => true,
                    'processed' => 0,
                    'errors' => []
                ];
            }

            foreach ($messages as $msgNumber) {
                try {
                    $header = imap_headerinfo($connection, $msgNumber);
                    $body = imap_body($connection, $msgNumber);
                    
                    $subject = $header->subject ?? '(no subject)';
                    $from = $header->from[0]->mailbox . '@' . $header->from[0]->host;
                    
                    // Route email to department
                    $routedEmail = $this->routeEmailToDepartment($handler, $subject, $body);
                    
                    if ($routedEmail) {
                        // TODO: Forward email to routed department or process via webhook
                        $this->logger->info('Email processed', [
                            'handler_id' => $handler->getId(),
                            'from' => $from,
                            'subject' => $subject,
                            'routed_to' => $routedEmail
                        ]);
                    }
                    
                    // Mark as read (or delete if configured)
                    if ($handler->isDeleteAfter()) {
                        imap_delete($connection, $msgNumber);
                    } else {
                        imap_setflag_full($connection, $msgNumber, '\\Seen');
                    }
                    
                    $processed++;
                    
                } catch (\Exception $e) {
                    $errors[] = "Message {$msgNumber}: " . $e->getMessage();
                    $this->logger->error('Failed to process email', [
                        'handler_id' => $handler->getId(),
                        'message_number' => $msgNumber,
                        'error' => $e->getMessage()
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
                'errors' => $errors
            ];

        } catch (\Exception $e) {
            $handler->setStatus('error');
            $this->logger->error('Handler processing failed', [
                'handler_id' => $handler->getId(),
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'processed' => $processed,
                'errors' => [$e->getMessage()]
            ];
        }
    }

    /**
     * Process all active handlers
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

