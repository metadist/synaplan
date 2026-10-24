<?php

declare(strict_types=1);

namespace App\Service\Prompt;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Service\Connection\PlannerChannel;
use App\Service\Connection\PlannerChannelCatalog;
use App\Service\File\ConnectedFolderContentGuard;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\DocumentImageCatalog;
use App\Service\Plugin\PluginContextProviderInterface;
use App\Service\SelfAware\Docs\PlatformDocsRetriever;
use App\Service\SelfAware\SelfAwareConfig;
use App\Service\SelfAware\SelfAwarePromptDecorator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The parts of the chat system prompt that read state: the date and
 * time for the person, the images a document may embed, the files the
 * conversation holds, plugin context, and the self-aware decoration
 * with the platform documentation it retrieved.
 */
final readonly class ChatSystemPromptBuilder
{
    /**
     * @param iterable<PluginContextProviderInterface> $pluginContextProviders
     */
    public function __construct(
        private TimeContextBuilder $timeContextBuilder,
        private DocumentImageCatalog $documentImageCatalog,
        private ConversationFileCatalog $conversationFileCatalog,
        private ConnectedFolderContentGuard $connectedFolderContentGuard,
        private PlannerChannelCatalog $plannerChannelCatalog,
        private LoggerInterface $logger,
        #[AutowireIterator('app.plugin.context_provider')] private iterable $pluginContextProviders = [],
        #[Autowire(lazy: true)] private ?SelfAwarePromptDecorator $selfAwarePromptDecorator = null,
        #[Autowire(lazy: true)] private ?PlatformDocsRetriever $platformDocsRetriever = null,
    ) {
    }

    /**
     * Build the current date/time block for the chat system prompt.
     *
     * Delegates the formatting + timezone resolution to {@see TimeContextBuilder}
     * and only resolves the two inputs from request state here: the user's
     * stored IANA timezone (profile) and the approximate Cloudflare country
     * (already forwarded as $options['client_country'] for buildLocationContext).
     * Both are optional — guests/widget visitors simply fall back to the
     * server timezone, clearly labelled.
     *
     * @param array<string, mixed> $options
     */
    public function timeContext(?User $user, array $options): string
    {
        $details = $user?->getUserDetails() ?? [];
        $userTimezone = $details['timezone'] ?? null;
        $country = $options['client_country'] ?? null;

        return $this->timeContextBuilder->build(
            is_string($userTimezone) ? $userTimezone : null,
            is_string($country) ? $country : null,
        );
    }

    /**
     * List the images this document turn may embed, so the model references an
     * existing picture instead of guessing a marker (#1382). Only the
     * officemaker topic writes `{{IMAGE:...}}` markers, so every other topic
     * keeps its prompt shape unchanged.
     *
     * @param array<int, array{role: string, content: string}|Message> $thread
     * @param array<string, mixed>                                     $options
     */
    public function documentImageContext(Message $message, array $thread, string $topic, array $options): string
    {
        if ('officemaker' !== $topic) {
            return '';
        }

        $extraPaths = [];
        foreach ($options['document_images'] ?? [] as $path) {
            if (is_string($path) && '' !== trim($path)) {
                $extraPaths[] = $path;
            }
        }

        // When the turn is about an uploaded document, the pictures generated
        // earlier in the thread are out of scope: offering them here is how a
        // "clean copy of my .docx" came back as a document about the WW1
        // trench image (#1689). This turn's own images — attached now or
        // produced by an upstream node — stay available.
        $documentInFocus = $this->documentInFocus($message, $thread);
        $images = $this->documentImageCatalog->build($message, null === $documentInFocus ? $thread : [], $extraPaths);
        $this->logger->info('ChatHandler: Document image catalog built', [
            'available_images' => count($images),
            'upstream_images' => count($extraPaths),
            'document_in_focus' => $documentInFocus?->reference,
        ]);

        return $this->documentImageCatalog->renderPromptBlock($images);
    }

    /**
     * Replace or strip `[PLATFORM_CAPABILITIES]` / `[PLATFORM_DOCS]` after the
     * topic prompt is loaded and before RAG/memories are appended.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{prompt: string, docs: list<array{slug: string, title: string, url: string}>}
     */
    public function decorateSelfAware(
        string $systemPrompt,
        string $topic,
        Message $message,
        array $classification,
        array $options,
        ?callable $progressCallback,
        ?int $modelId = null,
    ): array {
        $docsList = [];
        if (null === $this->selfAwarePromptDecorator) {
            return ['prompt' => $systemPrompt, 'docs' => $docsList];
        }

        $isWidget = SelfAwarePromptDecorator::isWidgetConversation($classification, $options);
        $docsHits = null;
        if (SelfAwareConfig::ROUTABLE_TOPIC === $topic && null !== $this->platformDocsRetriever && !$isWidget) {
            $query = trim($message->getText());
            if ('' === $query || 1 === preg_match('/^\/help\b/i', $query)) {
                $query = 'What can you do here?';
            }
            try {
                $docsHits = $this->platformDocsRetriever->retrieve($query, $message->getUserId());
                $docsList = $docsHits->toClientList();
            } catch (\Throwable $e) {
                $this->logger->warning('ChatHandler: Platform docs retrieval failed, continuing without', [
                    'error' => $e->getMessage(),
                ]);
                $docsHits = null;
                $docsList = [];
            }
            if ([] !== $docsList && null !== $progressCallback) {
                $progressCallback([
                    'status' => 'docs_loaded',
                    'message' => 'Documentation loaded',
                    'metadata' => [
                        'docs' => $docsList,
                        'count' => count($docsList),
                    ],
                    'timestamp' => time(),
                ]);
            }
        }

        return [
            'prompt' => $this->selfAwarePromptDecorator->apply(
                $systemPrompt,
                $topic,
                $message->getUserId(),
                $isWidget,
                $docsHits,
                $modelId,
            ),
            'docs' => $docsList,
        ];
    }

    /**
     * Collect and append context from all registered plugin context providers.
     *
     * Total plugin context is capped at 8000 chars to prevent prompt bloat.
     */
    public function appendPluginContext(string $systemPrompt, Message $message, array $classification, array $options): string
    {
        $maxTotalLength = 8000;
        $pluginContext = '';

        foreach ($this->pluginContextProviders as $provider) {
            try {
                if ($provider->supports($message->getUserId(), $classification, $options)) {
                    $ctx = $provider->getContext(
                        $message->getUserId(),
                        $message->getText(),
                        $classification,
                        $options
                    );

                    if (!empty($ctx)) {
                        $pluginContext .= $ctx;

                        if (strlen($pluginContext) >= $maxTotalLength) {
                            $pluginContext = substr($pluginContext, 0, $maxTotalLength)."\n[... plugin context truncated]\n";
                            $this->logger->warning('ChatHandler: Plugin context truncated', [
                                'max_length' => $maxTotalLength,
                            ]);
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ChatHandler: Plugin context provider failed', [
                    'provider' => $provider::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($pluginContext)) {
            $systemPrompt .= $pluginContext;
            $this->logger->info('ChatHandler: Plugin context appended to system prompt', [
                'plugin_context_length' => strlen($pluginContext),
            ]);
        }

        return $systemPrompt;
    }

    /**
     * Side list of every file this conversation still has, plus extracted
     * text for documents the history window already dropped.
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     */
    public function conversationFileContext(Message $message, array $thread): string
    {
        $catalog = $this->conversationFileCatalog->build($message, $thread);
        if ([] === $catalog) {
            return '';
        }

        $present = [];
        foreach ([$message, ...$thread] as $entry) {
            if (!$entry instanceof Message) {
                continue;
            }
            foreach ($entry->getFiles() as $file) {
                $id = $file->getId();
                if (null !== $id && '' !== trim($file->getFileText())) {
                    $present[$id] = true;
                }
            }
        }

        $block = $this->conversationFileCatalog->renderPromptContext($catalog, $present);
        if ('' !== $block) {
            $this->logger->info('ChatHandler: Conversation file inventory appended to system prompt', [
                'message_id' => $message->getId(),
                'file_count' => count($catalog),
            ]);
        }

        return $block;
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     */
    public function connectedFolderReadHonesty(Message $message, array $thread): string
    {
        $userId = (int) $message->getUserId();
        if ($userId <= 0) {
            return '';
        }

        return $this->connectedFolderContentGuard->systemInstructionForChatHandler(
            (string) $message->getText(),
            $this->plannerChannelCatalog->ofKind($userId, PlannerChannel::KIND_FOLDER),
            $message,
            $thread,
        );
    }

    /**
     * The uploaded document this turn is about, if it displaces the images the
     * assistant generated earlier ({@see ConversationFileCatalog::documentInFocus()}).
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     */
    private function documentInFocus(Message $currentMessage, array $thread): ?ConversationFile
    {
        return $this->conversationFileCatalog->documentInFocus(
            $this->conversationFileCatalog->build($currentMessage, $thread),
        );
    }
}
