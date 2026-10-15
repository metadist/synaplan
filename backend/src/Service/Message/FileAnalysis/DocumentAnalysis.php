<?php

declare(strict_types=1);

namespace App\Service\Message\FileAnalysis;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Exception\ChatFailureReason;
use App\AI\Service\AiFacade;
use App\Service\Context\ContextCondenser;
use App\Service\Context\ModelContextWindow;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Answers about one or more documents with extracted text in a single chat
 * call: every document's text goes into the system prompt, so the model can
 * reason across all uploaded files, not only the first (#978).
 */
final readonly class DocumentAnalysis
{
    /** Historical flat completion budget; still the floor for models without catalog metadata. */
    private const MIN_ANSWER_OUTPUT_TOKENS = 4000;

    /** Upper bound for the requested completion budget (reasoning tokens count against it). */
    private const MAX_ANSWER_OUTPUT_TOKENS = 16000;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
        private ChatFailureClassifier $failureClassifier = new ChatFailureClassifier(),
        private ?ContextCondenser $contextCondenser = null,
        private ?ModelContextWindow $modelContextWindow = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $documents
     *
     * @return array<string, mixed>
     */
    public function analyze(AnalysisContext $context, array $documents): array
    {
        $context->notify('generating', count($documents) > 1 ? 'Analyzing document contents...' : 'Analyzing document content...');

        $model = AnalysisModel::choose($this->modelConfigService, $context, 'ANALYZE', 'CHAT');
        $this->logger->info('FileAnalysisHandler: Using model for document analysis', $model->logContext($context) + [
            'document_count' => count($documents),
        ]);

        $userId = $context->message->getUserId();
        $outputTokens = $this->answerOutputTokens($model->id);
        $finalPrompt = $this->userPrompt($context->message->getText(), $documents);
        $fitted = $this->fitDocumentsToModel($documents, $finalPrompt, $model->id, $userId, $outputTokens, $context);

        try {
            $response = $this->chatWithOverflowRetry($context, $fitted, $finalPrompt, $model->options($outputTokens));
        } catch (\Exception $e) {
            $this->logger->error('FileAnalysisHandler: Chat analysis failed', [
                'files' => AnalysisFileResolver::describe($documents),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $context->notify('complete', 'Analysis complete.');

        return $context->reply->fromModel(
            $response,
            $model->metadata($response['provider'] ?? null, $response['model'] ?? null, $documents, 'chat_with_extracted_text'),
        );
    }

    /**
     * Completion budget for the answer. Reasoning models spend hidden thinking
     * tokens against `max_tokens`; the old flat 4 000 left a few hundred tokens
     * for the visible reply on big spreadsheets.
     */
    private function answerOutputTokens(?int $modelId): int
    {
        if (null === $this->modelContextWindow) {
            return self::MIN_ANSWER_OUTPUT_TOKENS;
        }

        return $this->modelContextWindow->answerOutputTokens($modelId, self::MIN_ANSWER_OUTPUT_TOKENS, self::MAX_ANSWER_OUTPUT_TOKENS);
    }

    /**
     * Fit every document's text into the answering model's window. The budget
     * comes from the model's catalog context window; documents share it in
     * proportion to their size. Oversized texts go through the question-aware
     * condenser (stacked map-reduce), so the model sees a focused view instead
     * of a hard cut.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return list<array<string, mixed>> documents with `text` fitted and `fit_strategy` set
     */
    private function fitDocumentsToModel(array $documents, string $question, ?int $modelId, int $userId, int $outputTokens, AnalysisContext $context): array
    {
        if (null === $this->contextCondenser || null === $this->modelContextWindow) {
            return $documents;
        }

        $allText = implode("\n", array_map(static fn (array $doc): string => (string) ($doc['text'] ?? ''), $documents));
        $totalChars = mb_strlen($allText);
        if (0 === $totalChars) {
            return $documents;
        }

        $totalBudget = $this->modelContextWindow->attachmentCharBudget($modelId, $allText, $userId, $outputTokens);
        if ($totalChars <= $totalBudget) {
            return $documents;
        }

        $context->notify('generating', count($documents) > 1
            ? 'Documents are large — condensing them for the model...'
            : 'Document is large — condensing it for the model...');

        $fitted = [];
        foreach ($documents as $doc) {
            $text = (string) ($doc['text'] ?? '');
            $docBudget = max(1000, (int) floor($totalBudget * (mb_strlen($text) / $totalChars)));

            $result = $this->contextCondenser->fit($text, $question, $docBudget, $userId, static function (array $progress) use ($context, $doc): void {
                $context->notify('generating', sprintf(
                    'Condensing %s — part %d of %d%s...',
                    (string) ($doc['name'] ?? 'document'),
                    $progress['chunk'],
                    $progress['chunks'],
                    $progress['level'] > 1 ? sprintf(' (round %d)', $progress['level']) : '',
                ));
            });

            $note = $result->provenanceNote();
            $doc['text'] = null !== $note ? $note."\n\n".$result->text : $result->text;
            $doc['fit_strategy'] = $result->strategy;
            $fitted[] = $doc;

            $this->logger->info('FileAnalysisHandler: fitted document to model window', $result->toLogContext() + [
                'file' => $doc['name'] ?? '',
                'model_id' => $modelId,
            ]);
        }

        return $fitted;
    }

    /**
     * One analysis call, retried ONCE with a halved attachment budget when the
     * provider rejects the request for size (context_length_exceeded / 413) —
     * the safety net for token estimates that were still too generous.
     *
     * @param list<array<string, mixed>> $documents
     * @param array<string, mixed>       $aiOptions
     *
     * @return array<string, mixed>
     */
    private function chatWithOverflowRetry(AnalysisContext $context, array $documents, string $finalPrompt, array $aiOptions): array
    {
        $languageDirective = $context->languageDirective();
        $userId = $context->message->getUserId();
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($documents).$languageDirective],
            ['role' => 'user', 'content' => $finalPrompt],
        ];

        try {
            return $context->reply->chat($this->aiFacade, $messages, $userId, $aiOptions);
        } catch (\Throwable $e) {
            $reason = $this->failureClassifier->classify($e);
            if (null === $this->contextCondenser || !in_array($reason, [ChatFailureReason::ContextLengthExceeded, ChatFailureReason::RequestTooLarge], true)) {
                throw $e;
            }

            $this->logger->warning('FileAnalysisHandler: provider rejected request size, retrying with halved attachment budget', [
                'reason' => $reason->value,
                'error' => $e->getMessage(),
            ]);

            $retryDocs = [];
            foreach ($documents as $doc) {
                $text = (string) ($doc['text'] ?? '');
                $halved = $this->contextCondenser->trimmed($text, max(1000, (int) floor(mb_strlen($text) / 2)));
                $doc['text'] = $halved->text;
                $doc['fit_strategy'] = 'retry_'.$halved->strategy;
                $retryDocs[] = $doc;
            }

            $messages[0]['content'] = $this->systemPrompt($retryDocs).$languageDirective;

            return $context->reply->chat($this->aiFacade, $messages, $userId, $aiOptions);
        }
    }

    /**
     * The extracted text of every document. A single upload keeps the original
     * layout so the prompt is unchanged for the common case.
     *
     * @param list<array<string, mixed>> $documents
     */
    private function systemPrompt(array $documents): string
    {
        if (1 === count($documents)) {
            $doc = $documents[0];
            $prompt = "You are analyzing a document. The user has uploaded a file and wants to know about its contents.\n\n";
            $prompt .= "=== FILE INFORMATION ===\n";
            $prompt .= "Filename: {$doc['name']}\n";
            $prompt .= "Type: {$doc['type']}\n\n";
            $prompt .= "=== EXTRACTED CONTENT ===\n";
            $prompt .= $doc['text']."\n";
            $prompt .= "=== END OF CONTENT ===\n\n";
            $prompt .= 'Answer the user\'s question about this document. If they ask what\'s in the file, summarize the key points. Spreadsheet extracts are sheet-by-sheet Markdown tables with A1 coordinates — cite cells as SheetName!B12 when that helps.';

            return $prompt;
        }

        $count = count($documents);
        $prompt = "You are analyzing {$count} documents. The user has uploaded multiple files and wants to know about their contents. Treat the documents as a related set — cross-reference them when the user's question spans more than one file, and clearly attribute any quotes or facts to the originating filename.\n\n";

        foreach ($documents as $index => $doc) {
            $position = $index + 1;
            $prompt .= "=== FILE {$position} OF {$count} INFORMATION ===\n";
            $prompt .= "Filename: {$doc['name']}\n";
            $prompt .= "Type: {$doc['type']}\n\n";
            $prompt .= "=== FILE {$position} EXTRACTED CONTENT ===\n";
            $prompt .= $doc['text']."\n";
            $prompt .= "=== END OF FILE {$position} CONTENT ===\n\n";
        }

        $prompt .= 'Answer the user\'s question using information from any of the files above. When summarizing, list the key points per file so the user can tell which document each statement came from.';

        return $prompt;
    }

    /**
     * A sensible question when the user typed nothing beside the uploads.
     *
     * @param list<array<string, mixed>> $documents
     */
    private function userPrompt(string $userPrompt, array $documents): string
    {
        $userPrompt = trim($userPrompt);
        if ('' !== $userPrompt) {
            return $userPrompt;
        }

        return count($documents) > 1
            ? 'What is in these documents? Please summarize the content of each file.'
            : 'What is in this document? Please summarize the content.';
    }
}
