<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Entity\User;
use App\Service\Context\ContextCondenser;
use App\Service\File\FileHelper;
use App\Service\Research\UrlContentResult;
use App\Service\Research\UrlContentService;
use Psr\Log\LoggerInterface;

/**
 * Synaplan reads one web page for a Messages-gateway model that cannot use
 * Anthropic's `web_fetch_*` server tool.
 *
 * The name matches the server tool, so a client that asked for `web_fetch`
 * still sees a page. Addresses {@see UrlContentService} refuses (private
 * networks, link-local, metadata hosts) come back as an error tool result
 * and are never requested twice.
 */
final readonly class WebFetchTool
{
    public const NAME = 'web_fetch';

    /**
     * Same ceiling {@see GatewayToolLoop} applies when it clamps a tool
     * result. Longer pages are condensed to this size first, so the clamp
     * does not cut a page in the middle of a sentence.
     */
    public const RESULT_CHAR_BUDGET = 12000;

    public const PAGE_LIMIT_MESSAGE = 'Page limit for this message reached. Answer from the pages you already read.';

    public function __construct(
        private UrlContentService $pages,
        private ContextCondenser $condenser,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Read one public web page and return its text. Use it when a search result is not enough and the answer depends on what that page says. Cite the URL you read.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'Absolute http or https URL of the page to read.',
                    ],
                ],
                'required' => ['url'],
            ],
        ];
    }

    /**
     * Never throws. A blocked, failed or over-budget read is an error tool
     * result the model can recover from in the same turn. `url` is redacted
     * (no query string) because it ends up in logs and the usage ledger.
     *
     * @param array<string, mixed> $input
     *
     * @return array{text: string, isError: bool, url: string}
     */
    public function execute(array $input, User $user, WebFetchBudget $budget): array
    {
        $url = \is_string($input['url'] ?? null) ? trim($input['url']) : '';
        if ('' === $url || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
            return $this->error($url, 'web_fetch requires an absolute http or https URL.');
        }

        if (!$budget->claim()) {
            return $this->error($url, self::PAGE_LIMIT_MESSAGE);
        }

        try {
            $page = $this->pages->fetchForReading($url);
        } catch (\Throwable $e) {
            $this->logger->warning('WebFetchTool: read failed', [
                'url' => FileHelper::redactUrlForLogging($url),
                'error' => $e::class,
            ]);

            return $this->error($url, 'The page could not be read.');
        }

        if (!$page->success) {
            return $this->error($url, $this->failureText($page));
        }

        $text = $this->fit($page->extractedText, $url, (int) $user->getId());
        $title = '' !== $page->title ? $page->title : $page->finalHostname();
        $shown = $page->finalUrl ?? $url;

        return [
            'text' => "Title: {$title}\nURL: {$shown}\n\n{$text}",
            'isError' => false,
            'url' => FileHelper::redactUrlForLogging($shown),
        ];
    }

    private function fit(string $text, string $url, int $userId): string
    {
        if (mb_strlen($text) <= self::RESULT_CHAR_BUDGET) {
            return $text;
        }

        try {
            return $this->condenser->fit(
                $text,
                $url,
                self::RESULT_CHAR_BUDGET,
                $userId,
                preferFastModel: true,
            )->text;
        } catch (\Throwable $e) {
            $this->logger->warning('WebFetchTool: page could not be condensed', [
                'url' => FileHelper::redactUrlForLogging($url),
                'error' => $e->getMessage(),
            ]);

            return mb_substr($text, 0, self::RESULT_CHAR_BUDGET);
        }
    }

    /**
     * A fixed sentence per failure kind. Transport errors can carry resolver
     * and connection details that must not reach the model.
     */
    private function failureText(UrlContentResult $page): string
    {
        return match ($page->blockedReason) {
            'blocked_address' => 'This address is on a private or internal network, so Synaplan does not open it.',
            'login_wall' => 'The page needs a login, so its content could not be read.',
            'bot_blocked' => 'The site refused automated access, so its content could not be read.',
            'unsupported_content' => 'The page is not HTML or text, so it could not be read.',
            'no_text' => 'The page has no readable text. It may only render in a browser.',
            'redirect_loop', 'bad_redirect' => 'The page redirects in a loop or to nowhere, so it could not be read.',
            default => preg_match('/^HTTP \d{3}$/', (string) $page->error)
                ? sprintf('The site answered %s, so the page could not be read.', $page->error)
                : 'The page could not be read.',
        };
    }

    /**
     * @return array{text: string, isError: true, url: string}
     */
    private function error(string $url, string $message): array
    {
        return [
            'text' => $message,
            'isError' => true,
            'url' => '' === $url ? '' : FileHelper::redactUrlForLogging($url),
        ];
    }
}
