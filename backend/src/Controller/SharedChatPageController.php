<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Service\File\DataUrlFixer;
use App\Service\File\ThumbnailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves shared chat pages with proper Open Graph meta tags for social media.
 *
 * Social media crawlers don't execute JavaScript, so we need to serve
 * pre-rendered HTML with meta tags for proper link previews.
 */
class SharedChatPageController extends AbstractController
{
    // Common crawler user agents
    private const CRAWLER_PATTERNS = [
        'facebookexternalhit',
        'Facebot',
        'Twitterbot',
        'LinkedInBot',
        'WhatsApp',
        'Slackbot',
        'TelegramBot',
        'Discordbot',
        'Pinterest',
        'Googlebot',
        'bingbot',
        'Applebot',
    ];

    public function __construct(
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private DataUrlFixer $dataUrlFixer,
        private ThumbnailService $thumbnailService,
        private string $synaplanUrl,
    ) {
    }

    /**
     * Serve shared chat page with Open Graph meta tags.
     *
     * For crawlers: returns HTML with meta tags
     * For users: redirects to SPA or serves index.html
     */
    #[Route('/shared/{token}', name: 'shared_chat_page', methods: ['GET'], priority: 10)]
    #[Route('/shared/{lang}/{token}', name: 'shared_chat_page_lang', methods: ['GET'], priority: 10, requirements: ['lang' => '[a-z]{2}'])]
    public function servePage(Request $request, string $token, string $lang = 'en'): Response
    {
        // Check if this is a crawler
        $userAgent = $request->headers->get('User-Agent', '');
        $isCrawler = $this->isCrawler($userAgent);

        // Load chat data
        $chat = $this->chatRepository->findPublicByShareToken($token);

        if (!$chat) {
            // Return 404 HTML page
            return $this->render404();
        }

        // Get messages
        $messages = $this->messageRepository->findBy(
            ['chatId' => $chat->getId()],
            ['unixTimestamp' => 'ASC']
        );

        // Extract metadata for Open Graph
        $title = $this->generateTitle($chat, $messages);
        $description = $this->generateDescription($messages);
        $imageUrl = $this->findFirstMediaUrl($messages);
        $canonicalUrl = $this->synaplanUrl.'/shared/'.$lang.'/'.$token;

        // For crawlers, serve HTML with meta tags
        // For regular users, also serve HTML but it will load the SPA
        return $this->renderSharedPage(
            $title,
            $description,
            $imageUrl,
            $canonicalUrl,
            $lang,
            $token,
            $isCrawler
        );
    }

    /**
     * Check if the user agent is a known crawler.
     */
    private function isCrawler(string $userAgent): bool
    {
        foreach (self::CRAWLER_PATTERNS as $pattern) {
            if (false !== stripos($userAgent, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a title from the chat content.
     */
    private function generateTitle($chat, array $messages): string
    {
        // First, try to use chat title if it's meaningful
        $chatTitle = $chat->getTitle();
        if ($chatTitle && 'New Chat' !== $chatTitle && 'New chat' !== $chatTitle) {
            // Clean slash commands from chat title
            $cleanTitle = $this->cleanSlashCommands($chatTitle);
            if (!empty(trim($cleanTitle))) {
                return trim($cleanTitle).' | Synaplan AI';
            }
        }

        // Try to use first meaningful AI response as title
        foreach ($messages as $message) {
            if ('OUT' === $message->getDirection()) {
                $text = strip_tags($message->getText());

                // Skip media generation responses - extract the actual content
                if (preg_match('/^Generated (?:image|video|audio):\s*(.+)$/i', $text, $matches)) {
                    $text = $matches[1];
                }

                // Remove slash commands from the beginning
                $text = $this->cleanSlashCommands($text);

                // Get first sentence or first 60 chars
                $firstSentence = preg_split('/[.!?]/', $text, 2)[0] ?? $text;
                if (strlen($firstSentence) > 60) {
                    $firstSentence = substr($firstSentence, 0, 57).'...';
                }
                if (!empty(trim($firstSentence))) {
                    return trim($firstSentence).' | Synaplan AI';
                }
            }
        }

        return 'Shared Chat | Synaplan AI';
    }

    /**
     * Remove slash commands from text.
     */
    private function cleanSlashCommands(string $text): string
    {
        return preg_replace('/^\/(?:pic|vid|audio|tts|image|video)\s*/i', '', $text);
    }

    /**
     * Generate description from the conversation.
     */
    private function generateDescription(array $messages): string
    {
        // Use first user message as context
        foreach ($messages as $message) {
            if ('IN' === $message->getDirection()) {
                $text = strip_tags($message->getText());

                // Remove slash commands from description
                $text = $this->cleanSlashCommands($text);

                if (strlen($text) > 160) {
                    return substr($text, 0, 157).'...';
                }

                if (!empty(trim($text))) {
                    return trim($text);
                }
            }
        }

        return 'An AI conversation shared via Synaplan - Your intelligent knowledge assistant.';
    }

    /**
     * Find first image or video thumbnail URL for og:image.
     */
    private function findFirstMediaUrl(array $messages): string
    {
        foreach ($messages as $message) {
            $filePath = $message->getFilePath();
            if (!$filePath) {
                continue;
            }

            // Fix data URL to file if needed
            if (str_starts_with($filePath, 'data:')) {
                $filePath = $this->dataUrlFixer->ensureFileOnDisk($message);
            }

            if (!$filePath) {
                continue;
            }

            // Extract relative path from API URL format
            // filePath is like "/api/v1/files/uploads/02/000/00002/2026/01/3092_google_xxx.mp4"
            $relativePath = $filePath;
            if (str_starts_with($filePath, '/api/v1/files/uploads/')) {
                $relativePath = substr($filePath, strlen('/api/v1/files/uploads/'));
            }

            // Check if it's an image or video
            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            $isVideo = in_array($extension, ['mp4', 'webm']);

            if ($isImage) {
                // For images, return full URL
                return $this->synaplanUrl.$filePath;
            }

            if ($isVideo) {
                // For videos, use thumbnail if available
                $thumbnailPath = $this->thumbnailService->getThumbnailIfExists($relativePath);
                if ($thumbnailPath) {
                    // Return thumbnail URL
                    return $this->synaplanUrl.'/api/v1/files/uploads/'.$thumbnailPath;
                }

                // Fallback: generate thumbnail on-the-fly for social media
                $thumbnailPath = $this->thumbnailService->generateThumbnail($relativePath);
                if ($thumbnailPath) {
                    return $this->synaplanUrl.'/api/v1/files/uploads/'.$thumbnailPath;
                }

                // Last resort: return video URL (some platforms support video previews)
                return $this->synaplanUrl.$filePath;
            }
        }

        // Fallback to default Synaplan image
        return $this->synaplanUrl.'/apple-touch-icon.png';
    }

    /**
     * Render the shared chat page with Open Graph meta tags.
     */
    private function renderSharedPage(
        string $title,
        string $description,
        string $imageUrl,
        string $canonicalUrl,
        string $lang,
        string $token,
        bool $isCrawler,
    ): Response {
        // Determine if image is a video
        $isVideo = str_ends_with($imageUrl, '.mp4') || str_ends_with($imageUrl, '.webm');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$this->escape($title)}</title>

    <!-- Basic Meta Tags -->
    <meta name="description" content="{$this->escape($description)}">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{$this->escape($canonicalUrl)}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="{$this->escape($canonicalUrl)}">
    <meta property="og:title" content="{$this->escape($title)}">
    <meta property="og:description" content="{$this->escape($description)}">
    <meta property="og:site_name" content="Synaplan AI">
    <meta property="og:locale" content="{$this->escape($lang)}">
HTML;

        if ($isVideo) {
            $html .= <<<HTML

    <!-- Video Preview -->
    <meta property="og:video" content="{$this->escape($imageUrl)}">
    <meta property="og:video:type" content="video/mp4">
    <meta property="og:image" content="{$this->escape($this->synaplanUrl)}/apple-touch-icon.png">
HTML;
        } else {
            $html .= <<<HTML

    <!-- Image Preview -->
    <meta property="og:image" content="{$this->escape($imageUrl)}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
HTML;
        }

        $html .= <<<HTML

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="{$this->escape($canonicalUrl)}">
    <meta name="twitter:title" content="{$this->escape($title)}">
    <meta name="twitter:description" content="{$this->escape($description)}">
    <meta name="twitter:image" content="{$this->escape($isVideo ? $this->synaplanUrl.'/apple-touch-icon.png' : $imageUrl)}">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/single_bird.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#0003c7">

    <!-- hreflang for SEO -->
    <link rel="alternate" hreflang="en" href="{$this->escape($this->synaplanUrl)}/shared/en/{$this->escape($token)}">
    <link rel="alternate" hreflang="de" href="{$this->escape($this->synaplanUrl)}/shared/de/{$this->escape($token)}">
    <link rel="alternate" hreflang="x-default" href="{$this->escape($this->synaplanUrl)}/shared/en/{$this->escape($token)}">
</head>
<body>
    <div id="app"></div>
    <script type="module" src="/src/main.ts"></script>
    <noscript>
        <h1>{$this->escape($title)}</h1>
        <p>{$this->escape($description)}</p>
        <p>Please enable JavaScript to view this shared conversation.</p>
    </noscript>
</body>
</html>
HTML;

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        // Allow caching for crawlers
        if ($isCrawler) {
            $response->setPublic();
            $response->setMaxAge(3600); // 1 hour
        }

        return $response;
    }

    /**
     * Render 404 page.
     */
    private function render404(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Not Found | Synaplan AI</title>
    <meta name="robots" content="noindex">
</head>
<body>
    <div id="app"></div>
    <script type="module" src="/src/main.ts"></script>
    <noscript>
        <h1>Chat Not Found</h1>
        <p>This shared chat does not exist or is no longer available.</p>
    </noscript>
</body>
</html>
HTML;

        return new Response($html, Response::HTTP_NOT_FOUND, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Escape HTML special characters.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
