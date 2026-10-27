<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Entity\NotionActivity;
use App\Service\Mcp\McpClientException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Copies one Synaplan file onto a Notion page: Notion hands out a short-lived
 * upload link, Synaplan posts the bytes there, then the page gets a file
 * block that points at the upload.
 */
final readonly class NotionFileUpload
{
    /** Notion's single-part upload limit. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const UPLOAD_TIMEOUT_SECONDS = 60;

    public function __construct(
        private NotionGateway $gateway,
        private HttpClientInterface $httpClient,
        private NotionActivityLog $activity,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{page_url: string, page_title: string, file_name: string}
     *
     * @throws NotionActionException
     */
    public function attachToPage(int $userId, string $page, string $absolutePath, string $name): array
    {
        $server = $this->gateway->writable($userId);
        if (null === $server) {
            throw new NotionActionException('Connect your own Notion first. Nothing was added to Notion.');
        }
        $pageId = NotionGateway::pageId($page);
        if (null === $pageId) {
            throw new NotionActionException('That is not a Notion page link. Nothing was added to Notion.');
        }
        $title = $this->pageTitle($server, $pageId);
        $markdown = $this->upload($server, $absolutePath, $name);

        try {
            $this->gateway->call($server, NotionPageWrite::UPDATE_TOOL, [
                'page_id' => $pageId,
                'command' => 'insert_content',
                'content' => $markdown,
            ]);
        } catch (McpClientException $e) {
            $this->logger->warning('NotionFileUpload: placing the file on the page failed', ['error' => $e->getMessage()]);
            throw new NotionActionException(sprintf('Notion took the file but could not add it to "%s". The page was not changed.', $title));
        }

        $url = NotionGateway::pageUrl($pageId);
        $this->activity->record($userId, NotionActivity::ACTION_FILE, $pageId, $title, $url);

        return ['page_url' => $url, 'page_title' => $title, 'file_name' => $name];
    }

    /**
     * Uploads the bytes and returns the Notion markdown that shows the file.
     *
     * @throws NotionActionException
     */
    public function upload(McpServerConfig $server, string $absolutePath, string $name): string
    {
        $size = is_file($absolutePath) ? filesize($absolutePath) : false;
        if (false === $size) {
            throw new NotionActionException(sprintf('The file "%s" is no longer stored here. Nothing was added to Notion.', $name));
        }
        if ($size > self::MAX_BYTES) {
            throw new NotionActionException(sprintf('"%s" is larger than the 20 MB Notion accepts from Synaplan. Nothing was added to Notion.', $name));
        }

        try {
            $slot = $this->gateway->call($server, 'create-file-upload', ['filename' => $name]);
        } catch (McpClientException $e) {
            $this->logger->warning('NotionFileUpload: no upload link', ['error' => $e->getMessage()]);
            throw new NotionActionException('Notion did not accept a new file right now. Nothing was added to Notion.');
        }

        $uploadUrl = is_string($slot['upload_url'] ?? null) ? $slot['upload_url'] : '';
        $host = strtolower((string) parse_url($uploadUrl, PHP_URL_HOST));
        if (!str_starts_with($uploadUrl, 'https://') || !NotionGateway::isNotionHost($host)) {
            throw new NotionActionException('Notion answered with an upload link Synaplan does not trust. Nothing was added to Notion.');
        }

        $answer = $this->post($uploadUrl, $slot, $absolutePath, $name);
        $fileId = is_string($slot['file_upload_id'] ?? null) ? $slot['file_upload_id'] : (is_string($answer['id'] ?? null) ? $answer['id'] : '');
        foreach (['suggested_markdown', 'markdown_source'] as $key) {
            if (is_string($answer[$key] ?? null) && str_contains($answer[$key], '<')) {
                return $answer[$key];
            }
        }
        if (is_string($answer['markdown_source'] ?? null) && '' !== $answer['markdown_source']) {
            return sprintf('<file source="%s"></file>', htmlspecialchars($answer['markdown_source'], ENT_QUOTES));
        }
        if ('' === $fileId) {
            throw new NotionActionException('Notion did not confirm the upload. Nothing was added to Notion.');
        }

        return sprintf('<file source="file-upload://%s"></file>', htmlspecialchars($fileId, ENT_QUOTES));
    }

    /**
     * @param array<string, mixed> $slot
     *
     * @return array<string, mixed>
     */
    private function post(string $uploadUrl, array $slot, string $absolutePath, string $name): array
    {
        $field = is_string($slot['upload_form_field'] ?? null) && '' !== $slot['upload_form_field'] ? $slot['upload_form_field'] : 'file';
        $form = new FormDataPart([$field => DataPart::fromPath($absolutePath, $name)]);
        $headers = $form->getPreparedHeaders()->toArray();
        foreach ((array) ($slot['upload_headers'] ?? []) as $header => $value) {
            if (is_string($header) && is_string($value) && 'content-type' !== strtolower($header)) {
                $headers[] = $header.': '.$value;
            }
        }

        try {
            $response = $this->httpClient->request('POST', $uploadUrl, [
                'headers' => $headers,
                'body' => $form->bodyToIterable(),
                'timeout' => self::UPLOAD_TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->warning('NotionFileUpload: upload request failed', ['error' => $e->getMessage()]);
            throw new NotionActionException('The file could not be sent to Notion. Nothing was added to Notion.');
        }
        if ($status >= 400) {
            $this->logger->warning('NotionFileUpload: upload rejected', ['status' => $status, 'body' => mb_substr($body, 0, 300)]);
            throw new NotionActionException('Notion rejected the file. Nothing was added to Notion.');
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @throws NotionActionException
     */
    private function pageTitle(McpServerConfig $server, string $pageId): string
    {
        try {
            $page = $this->gateway->call($server, 'fetch', ['id' => $pageId]);
        } catch (McpClientException $e) {
            $this->logger->info('NotionFileUpload: page could not be opened', ['error' => $e->getMessage()]);
            throw new NotionActionException('Synaplan cannot open that Notion page. Check the link and that your Notion connection can see it. Nothing was added to Notion.');
        }
        $title = is_string($page['title'] ?? null) ? trim($page['title']) : '';

        return '' !== $title ? $title : 'Untitled';
    }
}
