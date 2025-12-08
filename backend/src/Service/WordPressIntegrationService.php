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

        if ($this->userRepository->findByEmail($email)) {
            throw new \RuntimeException('An account with this email already exists');
        }

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

        $name = trim((string) ($payload['widget_name'] ?? 'WordPress Chat Widget'));
        $promptTopic = trim((string) ($payload['task_prompt_topic'] ?? 'general'));
        $siteUrl = trim((string) ($payload['site_url'] ?? ''));
        $domain = $this->extractHostFromUrl($siteUrl);

        $config = [
            'position' => $payload['position'] ?? 'bottom-right',
            'primaryColor' => $payload['primary_color'] ?? '#007bff',
            'iconColor' => $payload['icon_color'] ?? '#ffffff',
            'defaultTheme' => $payload['default_theme'] ?? 'light',
            'autoOpen' => (bool) ($payload['auto_open'] ?? false),
            'autoMessage' => $payload['auto_message'] ?? 'Hello! How can I help you today?',
            'allowFileUpload' => (bool) ($payload['allow_file_upload'] ?? false),
            'fileUploadLimit' => (int) ($payload['file_upload_limit'] ?? 3),
            'messageLimit' => (int) ($payload['message_limit'] ?? 50),
            'maxFileSize' => (int) ($payload['max_file_size'] ?? 10),
            'integrationType' => $payload['integration_type'] ?? 'floating-button',
            'inlinePlaceholder' => $payload['inline_placeholder'] ?? 'Ask me anything...',
            'inlineButtonText' => $payload['inline_button_text'] ?? 'Ask',
            'allowedDomains' => $domain ? [$domain] : [],
        ];

        $widget = $this->widgetService->createWidget($user, $name, $promptTopic, $config);

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
            if (!$file instanceof UploadedFile) {
                continue;
            }
            $processedFiles[] = $this->step3UploadFile($userId, $file);
        }

        if (!empty($processedFiles)) {
            $this->step4EnableFileSearch($userId);
        }

        $step5 = $this->step5SaveWidget($userId, $payload);

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
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('WordPress verification failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('WordPress site verification failed: '.$e->getMessage());
        }

        if (200 !== $status || empty($data['verified'])) {
            throw new \RuntimeException('WordPress site could not be verified');
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
