<?php

namespace App\Service;

use App\Entity\ApiKey;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Handles WordPress onboarding (legacy compatible wizard).
 */
class WordPressIntegrationService
{
    private const GROUP_KEY = 'WORDPRESS_WIZARD';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly PromptRepository $promptRepository,
        private readonly PromptService $promptService,
        private readonly FileStorageService $fileStorageService,
        private readonly FileProcessor $fileProcessor,
        private readonly VectorizationService $vectorizationService,
        private readonly WidgetService $widgetService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Step 1: Verify WP site & create user.
     *
     * @param array<string, mixed> $payload
     */
    public function step1VerifyAndCreateUser(array $payload): array
    {
        $verificationUrl = trim((string) ($payload['verification_url'] ?? ''));
        $verificationToken = trim((string) ($payload['verification_token'] ?? ''));
        $siteUrl = trim((string) ($payload['site_url'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $language = $payload['language'] ?? 'en';

        if ('' === $verificationUrl || '' === $verificationToken || '' === $siteUrl) {
            throw new \InvalidArgumentException('Missing verification parameters');
        }

        if ('' === $email || '' === $password) {
            throw new \InvalidArgumentException('Email and password are required');
        }

        $this->verifyWordPressSite($verificationUrl, $verificationToken);

        // Check if user already exists
        $existingUser = $this->userRepository->findByEmail($email);

        if ($existingUser) {
            // User already exists - update WordPress verification details and continue
            $this->logger->debug('WordPress wizard: Reusing existing user', [
                'user_id' => $existingUser->getId(),
            ]);

            $userDetails = $existingUser->getUserDetails();
            $userDetails['wordpress_verified'] = true;
            $userDetails['wordpress_site'] = $siteUrl;
            $existingUser->setUserDetails($userDetails);
            $this->em->flush();

            return [
                'success' => true,
                'data' => [
                    'user_id' => $existingUser->getId(),
                    'email' => $existingUser->getMail(),
                    'site_verified' => true,
                    'existing_user' => true,
                ],
            ];
        }

        // Create new user
        $user = new User();
        $user->setMail($email);
        $user->setPw(password_hash($password, PASSWORD_BCRYPT));
        $user->setCreated(date('YmdHis'));
        $user->setProviderId('local');
        $user->setType('MAIL');
        $user->setUserLevel('NEW');
        $user->setEmailVerified(true);
        $user->setUserDetails([
            'language' => $language,
            'wordpress_verified' => true,
            'wordpress_site' => $siteUrl,
        ]);
        $user->setPaymentDetails([]);

        $this->em->persist($user);
        $this->em->flush();

        return [
            'success' => true,
            'data' => [
                'user_id' => $user->getId(),
                'email' => $user->getMail(),
                'site_verified' => true,
            ],
        ];
    }

    /**
     * Step 2: Create API key.
     */
    public function step2CreateApiKey(int $userId): array
    {
        $user = $this->requireUser($userId);

        // Check if user already has a WordPress Plugin API key
        $existingApiKeys = $this->apiKeyRepository->findBy(['ownerId' => $userId, 'status' => 'active']);
        foreach ($existingApiKeys as $existingKey) {
            if ('WordPress Plugin' === $existingKey->getName()) {
                $this->logger->debug('WordPress wizard: Reusing existing API key', [
                    'user_id' => $userId,
                ]);

                return [
                    'success' => true,
                    'data' => [
                        'user_id' => $userId,
                        'api_key' => $existingKey->getKey(),
                        'existing_key' => true,
                    ],
                ];
            }
        }

        // Create new API key
        $apiKey = new ApiKey();
        $apiKey->setOwner($user);
        $apiKey->setOwnerId($userId);
        $apiKey->setName('WordPress Plugin');
        $apiKey->setKey($this->generateApiKey());
        $apiKey->setScopes(['widgets', 'api']);
        $apiKey->setStatus('active');

        $this->apiKeyRepository->save($apiKey);

        return [
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'api_key' => $apiKey->getKey(),
            ],
        ];
    }

    /**
     * Step 3: Upload a single RAG file.
     */
    public function step3UploadFile(int $userId, UploadedFile $file): array
    {
        $user = $this->requireUser($userId);
        $store = $this->fileStorageService->storeUploadedFile($file, $userId);
        if (!$store['success']) {
            throw new \RuntimeException($store['error'] ?? 'Failed to store file');
        }

        $relativePath = $store['path'];
        $extension = strtolower((string) $file->getClientOriginalExtension());

        [$extractedText] = $this->fileProcessor->extractText($relativePath, $extension, $userId);

        $fileEntity = (new File())
            ->setUserId($userId)
            ->setFilePath($relativePath)
            ->setFileType($extension ?: 'txt')
            ->setFileName($file->getClientOriginalName())
            ->setFileSize((int) $store['size'])
            ->setFileMime($store['mime'] ?? 'application/octet-stream')
            ->setStatus('processed')
            ->setFileText($extractedText ?? '');

        $this->em->persist($fileEntity);

        $message = $this->buildMessageForFile($userId, $relativePath, $extension, $file->getClientOriginalName(), $extractedText ?? '');
        $this->em->persist($message);
        $this->em->flush();

        if (!empty($extractedText)) {
            $this->vectorizationService->vectorizeAndStore(
                $extractedText,
                $user->getId(),
                (int) $message->getId(),
                self::GROUP_KEY,
                0,
            );
        }

        return [
            'success' => true,
            'data' => [
                'file_id' => $fileEntity->getId(),
                'filename' => $fileEntity->getFileName(),
                'processed' => !empty($extractedText),
            ],
        ];
    }

    /**
     * Step 4: Enable file search on "general" prompt.
     */
    public function step4EnableFileSearch(int $userId): array
    {
        $defaultPrompt = $this->promptRepository->findByTopic('general', 0);
        if (!$defaultPrompt instanceof Prompt) {
            throw new \RuntimeException('Default prompt "general" not found');
        }

        $prompt = $this->promptRepository->findByTopic('general', $userId);
        if (!$prompt) {
            $prompt = new Prompt();
            $prompt->setOwnerId($userId);
            $prompt->setLanguage($defaultPrompt->getLanguage());
            $prompt->setTopic($defaultPrompt->getTopic());
            $prompt->setShortDescription($defaultPrompt->getShortDescription());
            $prompt->setPrompt($defaultPrompt->getPrompt());
            $prompt->setSelectionRules($defaultPrompt->getSelectionRules());
            $this->em->persist($prompt);
            $this->em->flush();
        }

        $metadata = [
            'aiModel' => -1,
            'tool_internet' => false,
            'tool_files' => true,
            'tool_screenshot' => false,
            'tool_transfer' => false,
            'tool_files_keyword' => self::GROUP_KEY,
        ];

        $this->promptService->saveMetadataForPrompt($prompt, $metadata);

        return [
            'success' => true,
            'data' => [
                'prompt_id' => $prompt->getId(),
                'prompt_configured' => true,
            ],
        ];
    }

    /**
     * Step 5: Save widget configuration for the new user.
     *
     * @param array<string, mixed> $payload
     */
    public function step5SaveWidget(int $userId, array $payload): array
    {
        $user = $this->requireUser($userId);

        // Support both camelCase (legacy WordPress plugin) and snake_case (new API) parameters
        $name = trim((string) ($payload['widget_name'] ?? $payload['widgetName'] ?? 'WordPress Chat Widget'));
        $promptTopic = trim((string) (
            $payload['task_prompt_topic'] ??
            $payload['widgetPrompt'] ?? // WordPress plugin uses this
            $payload['prompt'] ?? // Alternative
            'general'
        ));

        // Get site_url from payload or fallback to user details
        $siteUrl = trim((string) ($payload['site_url'] ?? $payload['siteUrl'] ?? ''));
        if (empty($siteUrl)) {
            $userDetails = $user->getUserDetails();
            $siteUrl = $userDetails['wordpress_site'] ?? '';
        }
        $domain = $this->extractHostFromUrl($siteUrl);

        // Extract integration type (support both naming conventions)
        $integrationType = $payload['integration_type'] ?? $payload['integrationType'] ?? 'floating-button';

        $config = [
            'position' => $payload['position'] ?? $payload['widgetPosition'] ?? 'bottom-right',
            'primaryColor' => $payload['primary_color'] ?? $payload['widgetColor'] ?? '#007bff',
            'iconColor' => $payload['icon_color'] ?? $payload['widgetIconColor'] ?? '#ffffff',
            'defaultTheme' => $payload['default_theme'] ?? $payload['defaultTheme'] ?? 'light',
            'autoOpen' => (bool) ($payload['auto_open'] ?? $payload['autoOpen'] ?? false),
            'autoMessage' => $payload['auto_message'] ?? $payload['autoMessage'] ?? 'Hello! How can I help you today?',
            'allowFileUpload' => (bool) ($payload['allow_file_upload'] ?? $payload['allowFileUpload'] ?? false),
            'fileUploadLimit' => (int) ($payload['file_upload_limit'] ?? $payload['fileUploadLimit'] ?? 3),
            'messageLimit' => (int) ($payload['message_limit'] ?? $payload['messageLimit'] ?? 50),
            'maxFileSize' => (int) ($payload['max_file_size'] ?? $payload['maxFileSize'] ?? 10),
            'integrationType' => $integrationType,
            'inlinePlaceholder' => $payload['inline_placeholder'] ?? $payload['inlinePlaceholder'] ?? 'Ask me anything...',
            'inlineButtonText' => $payload['inline_button_text'] ?? $payload['inlineButtonText'] ?? 'Ask',
            'inlineFontSize' => (int) ($payload['inline_font_size'] ?? $payload['inlineFontSize'] ?? 16),
            'inlineTextColor' => $payload['inline_text_color'] ?? $payload['inlineTextColor'] ?? '#212529',
            'inlineBorderRadius' => (int) ($payload['inline_border_radius'] ?? $payload['inlineBorderRadius'] ?? 8),
            'allowedDomains' => $domain ? [$domain] : [],
        ];

        // Check if user already has a widget (for WordPress users, reuse existing)
        $existingWidgets = $this->widgetService->getWidgetsByUserId($userId);

        if (!empty($existingWidgets)) {
            // Update the first widget
            $widget = $existingWidgets[0];
            $this->widgetService->updateWidget($widget, $config);

            $this->logger->debug('WordPress wizard: Updated existing widget', [
                'user_id' => $userId,
                'widget_id' => $widget->getWidgetId(),
            ]);
        } else {
            // Create new widget with fixed widgetId "1" for WordPress compatibility
            try {
                $widget = $this->widgetService->createWidget($user, $name, $promptTopic, $config);

                // Override the auto-generated widgetId with "1" for WordPress plugin compatibility
                $widget->setWidgetId('1');
                $this->em->flush();

                $this->logger->info('WordPress wizard: Widget created', [
                    'user_id' => $userId,
                    'widget_id' => $widget->getWidgetId(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('WordPress wizard: Widget creation failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return [
            'success' => true,
            'data' => [
                'widget_id' => $widget->getWidgetId(),
                'widget_configured' => true,
            ],
        ];
    }

    /**
     * Legacy complete flow (single call).
     *
     * @param array<string, mixed> $payload
     * @param UploadedFile[]       $files
     */
    public function completeWizard(array $payload, array $files = []): array
    {
        $step1 = $this->step1VerifyAndCreateUser($payload);
        $userId = (int) $step1['data']['user_id'];

        $step2 = $this->step2CreateApiKey($userId);

        $processedFiles = [];
        foreach ($files as $file) {
            $processedFiles[] = $this->step3UploadFile($userId, $file);
        }

        if (!empty($processedFiles)) {
            $this->step4EnableFileSearch($userId);
        }

        // Ensure site_url is passed to step5 for domain whitelist
        $widgetPayload = $payload;
        if (empty($widgetPayload['site_url']) && !empty($payload['site_url'])) {
            $widgetPayload['site_url'] = $payload['site_url'];
        }

        $step5 = $this->step5SaveWidget($userId, $widgetPayload);

        return [
            'success' => true,
            'message' => 'WordPress wizard completed successfully',
            'data' => [
                'user_id' => $userId,
                'api_key' => $step2['data']['api_key'],
                'filesProcessed' => count($processedFiles),
                'widget_id' => $step5['data']['widget_id'],
            ],
        ];
    }

    private function verifyWordPressSite(string $verificationUrl, string $token): void
    {
        try {
            $response = $this->httpClient->request('POST', $verificationUrl, [
                'body' => ['token' => $token],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            if (200 !== $status || empty($data['verified'])) {
                throw new \RuntimeException('WordPress site could not be verified (invalid response)');
            }
        } catch (\Throwable $e) {
            $this->logger->error('WordPress site verification failed', [
                'error' => $e->getMessage(),
                'url' => $verificationUrl,
            ]);

            throw new \RuntimeException('WordPress site verification failed: '.$e->getMessage());
        }
    }

    private function requireUser(int $userId): User
    {
        $user = $this->userRepository->find($userId);
        if (!$user instanceof User) {
            throw new \RuntimeException('User not found');
        }

        return $user;
    }

    private function generateApiKey(): string
    {
        return 'sk_live_'.bin2hex(random_bytes(24));
    }

    private function buildMessageForFile(int $userId, string $path, string $extension, string $originalName, string $fileText): Message
    {
        $message = new Message();
        $message->setUserId($userId);
        $message->setTrackingId($this->generateTrackingId());
        $message->setProviderIndex('WORDPRESS');
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('RAG');
        $message->setFile(1);
        $message->setFilePath($path);
        $message->setFileType($extension ?: 'txt');
        $message->setTopic('RAG');
        $message->setLanguage('en');
        $message->setText('RAG file: '.$originalName);
        $message->setDirection('IN');
        $message->setStatus('processed');
        $message->setFileText($fileText);

        return $message;
    }

    private function generateTrackingId(): int
    {
        return (int) (microtime(true) * 1_000_000);
    }

    private function extractHostFromUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (isset($parts['port'])) {
            $host .= ':'.$parts['port'];
        }

        return $host;
    }

    /**
     * Get user ID by API key token.
     *
     * @param string $token The API key token
     *
     * @return int|null The user ID or null if not found
     */
    public function getUserByApiKey(string $token): ?int
    {
        $apiKey = $this->apiKeyRepository->findActiveByKey($token);

        if (!$apiKey) {
            return null;
        }

        return $apiKey->getOwnerId();
    }
}
