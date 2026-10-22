<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Entity\User;
use App\Service\Context\ContextCondenser;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Research\UrlContentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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

    public const PAGE_LIMIT_MESSAGE = 'page limit for this turn';

    private const ATTR_USED = 'messages_gateway.web_fetch_used';

    public function __construct(
        private UrlContentService $pages,
        private ContextCondenser $condenser,
        private MessagesGatewayConfig $config,
        private RequestStack $requests,
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
     * result the model can recover from in the same turn.
     *
     * @param array<string, mixed> $input
     *
     * @return array{text: string, isError: bool, url: string}
     */
    public function execute(array $input, User $user): array
    {
        $url = \is_string($input['url'] ?? null) ? trim($input['url']) : '';
        if ('' === $url || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
            return $this->error($url, 'web_fetch requires an absolute http or https URL.');
        }

        $max = $this->config->webFetchMaxPerTurn((int) $user->getId());
        if (!$this->claim($max)) {
            return $this->error($url, self::PAGE_LIMIT_MESSAGE);
        }

        try {
            $page = $this->pages->fetchForReading($url);
        } catch (\Throwable $e) {
            $this->logger->warning('WebFetchTool: read failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return $this->error($url, 'The page could not be read: '.$e->getMessage());
        }

        if (!$page->success) {
            $reason = \is_string($page->error) && '' !== $page->error
                ? $page->error
                : 'The page could not be read.';

            return $this->error($url, $reason);
        }

        $text = $this->fit($page->extractedText, $url, (int) $user->getId());
        $title = '' !== $page->title ? $page->title : $page->finalHostname();
        $shown = $page->finalUrl ?? $url;

        return [
            'text' => "Title: {$title}\nURL: {$shown}\n\n{$text}",
            'isError' => false,
            'url' => $shown,
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
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return mb_substr($text, 0, self::RESULT_CHAR_BUDGET);
        }
    }

    /**
     * One HTTP request is one message. The count lives on that request so a
     * worker does not keep it for the next caller.
     */
    private function claim(int $max): bool
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return $max > 0;
        }

        $used = (int) $request->attributes->get(self::ATTR_USED, 0);
        if ($used >= $max) {
            return false;
        }

        $request->attributes->set(self::ATTR_USED, $used + 1);

        return true;
    }

    /**
     * @return array{text: string, isError: true, url: string}
     */
    private function error(string $url, string $message): array
    {
        return [
            'text' => $message,
            'isError' => true,
            'url' => $url,
        ];
    }
}
