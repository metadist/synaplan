<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Service\File\OgImageService;
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
        private OgImageService $ogImageService,
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
        $imageUrl = $this->getOgImageUrl($chat);
        $canonicalUrl = $this->synaplanUrl.'/shared/'.$lang.'/'.$token;

        // For crawlers, serve HTML with meta tags
        // For regular users, also serve HTML but it will load the SPA
        return $this->renderSharedPage(
            $title,
            $description,
            $imageUrl, // May be null if no valid OG image exists
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
     * Get OG image URL for a chat.
     *
     * Uses the pre-generated OG image stored with the chat when sharing was enabled.
     * Only returns the URL if the image file actually exists.
     *
     * @param \App\Entity\Chat $chat The chat to get OG image for
     *
     * @return string|null Full URL to OG image, or null if no valid image exists
     */
    private function getOgImageUrl($chat): ?string
    {
        $ogImagePath = $chat->getOgImagePath();

        // Only return URL if we have a stored path AND the file exists
        if ($ogImagePath && $this->ogImageService->verifyFileExists($ogImagePath)) {
            return $this->ogImageService->getOgImageUrl($ogImagePath, $this->synaplanUrl);
        }

        // No valid OG image - return null to skip og:image tag entirely
        // This is better than returning a broken URL
        return null;
    }

    /**
     * Render the shared chat page with Open Graph meta tags.
     */
    private function renderSharedPage(
        string $title,
        string $description,
        ?string $imageUrl,
        string $canonicalUrl,
        string $lang,
        string $token,
        bool $isCrawler,
    ): Response {
        // Try to load the built index.html from production build
        $indexPath = '/var/www/frontend/index.html';
        $html = '';

        if (file_exists($indexPath)) {
            // Production: Use built index.html with correct asset paths
            $html = file_get_contents($indexPath);

            // Replace the title
            $html = preg_replace(
                '/<title>.*?<\/title>/i',
                '<title>'.$this->escape($title).'</title>',
                $html
            );

            // Inject Open Graph and additional meta tags into <head>
            $metaTags = $this->generateMetaTags($title, $description, $imageUrl, $canonicalUrl, $lang, $token);
            $html = str_replace('</head>', $metaTags.'</head>', $html);
        } else {
            // Development fallback: Build HTML manually with dev paths
            // This allows the controller to work in development without built assets
            $html = $this->buildHtmlManually($title, $description, $imageUrl, $canonicalUrl, $lang, $token);
        }

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
     * Generate Open Graph and additional meta tags for injection.
     */
    private function generateMetaTags(
        string $title,
        string $description,
        ?string $imageUrl,
        string $canonicalUrl,
        string $lang,
        string $token,
    ): string {
        $metaTags = <<<HTML

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

        // Only add og:image if we have a valid, verified image URL
        if ($imageUrl) {
            $metaTags .= <<<HTML

    <!-- Image Preview -->
    <meta property="og:image" content="{$this->escape($imageUrl)}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
HTML;
        }

        $metaTags .= <<<HTML

    <!-- Twitter Card -->
    <meta name="twitter:card" content="{$this->escape($imageUrl ? 'summary_large_image' : 'summary')}">
    <meta name="twitter:url" content="{$this->escape($canonicalUrl)}">
    <meta name="twitter:title" content="{$this->escape($title)}">
    <meta name="twitter:description" content="{$this->escape($description)}">
HTML;

        // Only add twitter:image if we have a valid image
        if ($imageUrl) {
            $metaTags .= <<<HTML

    <meta name="twitter:image" content="{$this->escape($imageUrl)}">
HTML;
        }

        $metaTags .= <<<HTML

    <!-- hreflang for SEO -->
    <link rel="alternate" hreflang="en" href="{$this->escape($this->synaplanUrl)}/shared/en/{$this->escape($token)}">
    <link rel="alternate" hreflang="de" href="{$this->escape($this->synaplanUrl)}/shared/de/{$this->escape($token)}">
    <link rel="alternate" hreflang="x-default" href="{$this->escape($this->synaplanUrl)}/shared/en/{$this->escape($token)}">

HTML;

        return $metaTags;
    }

    /**
     * Build HTML manually (for development when built index.html doesn't exist).
     */
    private function buildHtmlManually(
        string $title,
        string $description,
        ?string $imageUrl,
        string $canonicalUrl,
        string $lang,
        string $token,
    ): string {
        $metaTags = $this->generateMetaTags($title, $description, $imageUrl, $canonicalUrl, $lang, $token);

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$this->escape($title)}</title>
{$metaTags}
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/single_bird.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#0003c7">
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
    }

    /**
     * Render 404 page.
     */
    private function render404(): Response
    {
        $title = 'Chat Not Found | Synaplan AI';
        $indexPath = '/var/www/frontend/index.html';
        $html = '';

        if (file_exists($indexPath)) {
            // Production: Use built index.html
            $html = file_get_contents($indexPath);

            // Replace the title
            $html = preg_replace(
                '/<title>.*?<\/title>/i',
                '<title>'.$this->escape($title).'</title>',
                $html
            );

            // Add noindex meta tag
            $html = str_replace(
                '</head>',
                '    <meta name="robots" content="noindex">'."\n".'</head>',
                $html
            );
        } else {
            // Development fallback: Build HTML manually
            $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Not Found | Synaplan AI</title>
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/svg+xml" href="/single_bird.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#0003c7">
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
        }

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
