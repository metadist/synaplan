<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Mcp\McpClientException;
use App\Service\Mcp\Notion\NotionActionException;
use App\Service\Mcp\Notion\NotionFileUpload;
use App\Service\Mcp\Notion\NotionGateway;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\StepApprovalGate;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\Tool\Source\SkillToolSource;
use Psr\Log\LoggerInterface;

/**
 * `save_to_notion` runner — puts a step's text and files on a new Notion page
 * under the page the person picked. No model call.
 *
 * Inputs: `text` (`$nX.text`) and `attachments` (`$nX.file`). Params: `page`
 * (Notion link or id of the parent page), optional `title`.
 */
final readonly class SaveToNotionRunner implements TaskRunner
{
    private const MAX_CONTENT_CHARS = 100000;

    public function __construct(
        private NotionGateway $gateway,
        private NotionFileUpload $upload,
        private UploadedNodeFiles $files,
        private LoggerInterface $logger,
        private ?StepApprovalGate $approvalGate = null,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::SaveToNotion];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::SaveToNotion,
                'Put the result on a new Notion page under a page the user names by link. ONLY when the user explicitly asks to save or deliver the result to Notion and gives the Notion page link. Inputs: text ($nX.text) and attachments ($nX.file). params.page is that Notion link, params.title an optional page title. Never the reply node.',
                dynamicNote: fn (?int $userId, array $context): ?string => $this->renderAvailabilityNote($userId),
                enabledFlag: MultitaskRoutingConfig::KEY_MCP_ACTION_ENABLED,
                enabledDefault: false,
                requiresDynamicNote: true,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $userId = (int) ($context->userId ?? $context->message->getUserId());
        $page = is_string($node->params['page'] ?? null) ? trim($node->params['page']) : '';
        $parentId = NotionGateway::pageId($page);
        if (null === $parentId) {
            return NodeResult::failed('no Notion page link is set for this step — nothing was saved to Notion');
        }
        $server = $this->gateway->writable($userId);
        if (null === $server) {
            return NodeResult::failed('Notion is not connected — connect it under Apps → Notion. Nothing was saved to Notion');
        }
        if (!$server->allowsWrite()) {
            return NodeResult::failed('Notion is read-only — turn on "The AI may change Notion" under Apps → Notion. Nothing was saved to Notion');
        }

        $inputs = $context->resolveInputs($node);
        $text = is_string($inputs['text'] ?? null) ? trim($inputs['text']) : '';
        $attachments = $inputs['attachments'] ?? [];
        if ('' === $text && [] === $attachments) {
            [$text, $attachments] = $this->fromDependencies($node, $context);
        }
        $files = $this->files->resolve($attachments, $userId);
        if ('' === $text && [] === $files) {
            return NodeResult::failed('nothing to save: the previous steps produced no text and no file');
        }

        $gated = $this->approvalGate?->consult($context, $node, SkillToolSource::nameFor(Capability::SaveToNotion), [
            'page' => $page,
            'file_count' => count($files),
        ]);
        if (null !== $gated) {
            return $gated;
        }

        $blocks = [];
        foreach ($files as $file) {
            try {
                $blocks[] = $this->upload->upload($server, $file['path'], $file['name']);
            } catch (NotionActionException $e) {
                return NodeResult::failed($e->getMessage());
            }
        }

        $title = $this->title($node);
        $content = mb_substr($text, 0, self::MAX_CONTENT_CHARS);
        if ([] !== $blocks) {
            $content = trim($content."\n\n".implode("\n", $blocks));
        }

        try {
            $answer = $this->gateway->call($server, 'create-pages', [
                'parent' => ['page_id' => $parentId],
                'pages' => [['properties' => ['title' => $title], 'content' => $content]],
            ]);
        } catch (McpClientException $e) {
            $this->logger->warning('SaveToNotionRunner: page could not be created', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return NodeResult::failed('Notion did not create the page: '.mb_substr($e->getMessage(), 0, 200).'. Nothing was saved to Notion');
        }

        $url = $this->createdUrl($answer) ?? NotionGateway::pageUrl($parentId);
        $message = [] === $files
            ? sprintf('Saved the result to the new Notion page "%s" (%s).', $title, $url)
            : sprintf('Saved the result and %d file(s) to the new Notion page "%s" (%s).', count($files), $title, $url);
        $context->streamChunk($message);

        return NodeResult::ok($message, [], ['notion_url' => $url, 'files_saved' => count($files)]);
    }

    /**
     * A step added in the Steps editor maps no inputs: it saves what the
     * steps it depends on produced.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function fromDependencies(TaskNode $node, NodeContext $context): array
    {
        $texts = [];
        $files = [];
        foreach ($node->dependsOn as $dependencyId) {
            $result = $context->getResult($dependencyId);
            if (null === $result) {
                continue;
            }
            if (is_string($result->text) && '' !== trim($result->text)) {
                $texts[] = trim($result->text);
            }
            foreach ($result->files as $file) {
                $files[] = $file;
            }
        }

        return [implode("\n\n", $texts), $files];
    }

    private function title(TaskNode $node): string
    {
        $title = is_string($node->params['title'] ?? null) ? trim($node->params['title']) : '';

        return '' !== $title ? mb_substr($title, 0, 200) : 'Synaplan · '.date('Y-m-d H:i');
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function createdUrl(array $answer): ?string
    {
        $pages = is_array($answer['pages'] ?? null) ? $answer['pages'] : [];
        $first = is_array($pages[0] ?? null) ? $pages[0] : [];
        if (is_string($first['url'] ?? null) && str_starts_with($first['url'], 'https://')) {
            return $first['url'];
        }
        $id = is_string($first['id'] ?? null) ? NotionGateway::pageId($first['id']) : null;
        if (null !== $id) {
            return NotionGateway::pageUrl($id);
        }
        $text = is_string($answer['text'] ?? null) ? $answer['text'] : '';

        return 1 === preg_match('/https:\/\/[a-z0-9.-]*notion\.(?:so|com|site)\/[^\s"\'<>)]+/i', $text, $match) ? $match[0] : null;
    }

    /**
     * SkillCatalogFactory builds runners without constructors in DB-free
     * tests, so an injected dependency must degrade to "no note".
     */
    private function renderAvailabilityNote(?int $userId): ?string
    {
        if (null === $userId || $userId <= 0) {
            return null;
        }
        try {
            if (true !== $this->gateway->writable($userId)?->allowsWrite()) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return '  Notion is connected. Use save_to_notion only with a Notion page link the user gave.';
    }
}
