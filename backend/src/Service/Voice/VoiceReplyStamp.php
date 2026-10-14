<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\Message;
use App\Entity\Model;
use App\Service\Usage\RecordedUsage;

/**
 * Copies one spoken answer's models, topic and token cost onto the chat
 * message the person actually sees. The live model is always named. When
 * that model asked Synaplan, the Synaplan model and the sorter are named too.
 */
final readonly class VoiceReplyStamp
{
    /**
     * @param array<string, mixed> $result process() payload
     *
     * @return array<string, mixed>
     */
    public function fromProcess(array $result, string $chargedCost, bool $priceKnown): array
    {
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $classification = is_array($result['classification'] ?? null) ? $result['classification'] : [];
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $prompt = $this->tokens($usage, 'prompt_tokens', 'input_tokens');
        $completion = $this->tokens($usage, 'completion_tokens', 'output_tokens');
        $sortingProvider = $this->text($classification['sorting_provider'] ?? '');
        $sortingModel = $this->text($classification['sorting_model_name'] ?? '');

        $pending = [
            'topic' => $this->text($classification['topic'] ?? ''),
            'language' => $this->text($classification['language'] ?? ''),
            'chat' => [
                'provider' => $this->text($metadata['provider'] ?? ''),
                'model' => $this->text($metadata['model'] ?? ''),
                'model_id' => is_numeric($metadata['model_id'] ?? null) ? (int) $metadata['model_id'] : null,
            ],
            'usage' => [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => $prompt + $completion,
            ],
            'cost' => $chargedCost,
            'price_known' => $priceKnown,
        ];

        if ('' !== $sortingProvider || '' !== $sortingModel) {
            $pending['sorting'] = [
                'provider' => $sortingProvider,
                'model' => $sortingModel,
                'model_id' => is_numeric($classification['sorting_model_id'] ?? null) ? (int) $classification['sorting_model_id'] : null,
            ];
        }

        $sortingUsage = $classification['sorting_usage'] ?? null;
        if (is_array($sortingUsage)) {
            $pending['usage_extra'] = [
                RecordedUsage::fromSortingUsage($sortingUsage, $sortingProvider, $sortingModel),
            ];
        }

        return $pending;
    }

    /**
     * @param array<string, mixed> $pending
     */
    public function apply(Message $message, array $pending): void
    {
        $topic = $this->text($pending['topic'] ?? '');
        if ('' !== $topic) {
            $message->setTopic($topic);
        }
        $language = $this->text($pending['language'] ?? '');
        if (2 === strlen($language)) {
            $message->setLanguage($language);
        }

        $chat = is_array($pending['chat'] ?? null) ? $pending['chat'] : [];
        if ('' !== $this->text($chat['provider'] ?? '') || '' !== $this->text($chat['model'] ?? '')) {
            $message->setMeta('ai_chat_provider', $this->text($chat['provider'] ?? ''));
            $message->setMeta('ai_chat_model', $this->text($chat['model'] ?? ''));
            if (is_numeric($chat['model_id'] ?? null)) {
                $message->setMeta('ai_chat_model_id', (string) (int) $chat['model_id']);
            }
        }

        $usage = is_array($pending['usage'] ?? null) ? $pending['usage'] : [];
        if ([] !== $usage) {
            $message->setMeta('ai_chat_usage', json_encode([
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ], \JSON_THROW_ON_ERROR));
        }
        $cost = $this->text($pending['cost'] ?? '');
        if ('' !== $cost) {
            $message->setMeta('ai_chat_cost', $cost);
        }
        if (false === ($pending['price_known'] ?? true)) {
            $message->setMeta('ai_chat_price_known', '0');
        }

        $sorting = is_array($pending['sorting'] ?? null) ? $pending['sorting'] : [];
        if ('' !== $this->text($sorting['provider'] ?? '') || '' !== $this->text($sorting['model'] ?? '')) {
            $message->setMeta('ai_sorting_provider', $this->text($sorting['provider'] ?? ''));
            $message->setMeta('ai_sorting_model', $this->text($sorting['model'] ?? ''));
            if (is_numeric($sorting['model_id'] ?? null)) {
                $message->setMeta('ai_sorting_model_id', (string) (int) $sorting['model_id']);
            }
        }

        $extra = $pending['usage_extra'] ?? null;
        if (is_array($extra) && [] !== $extra) {
            $message->setMeta('ai_usage_extra', json_encode(array_values($extra), \JSON_THROW_ON_ERROR));
        }
        $message->setMeta('voice_reply_applied', '1');
    }

    public function applyVoiceModel(Message $message, Model $model): void
    {
        $message->setMeta('voice_provider', $model->getService());
        $message->setMeta('voice_model', $model->getProviderId());
        $message->setMeta('voice_model_name', $model->getName());
        $id = $model->getId();
        if (null !== $id) {
            $message->setMeta('voice_model_id', (string) $id);
        }
    }

    /**
     * Add this slice of the live model's usage. A later tick extends the same
     * row instead of adding a second one.
     *
     * @param array<string, mixed> $entry
     */
    public function mergeLiveUsage(Message $message, array $entry): void
    {
        $decoded = json_decode((string) $message->getMeta('ai_usage_extra'), true);
        $rows = is_array($decoded) ? array_values($decoded) : [];
        $merged = false;
        foreach ($rows as $index => $row) {
            if (!is_array($row) || 'LIVE' !== ($row['kind'] ?? '')) {
                continue;
            }
            $rows[$index] = [
                'promptTokens' => (int) ($row['promptTokens'] ?? 0) + (int) ($entry['promptTokens'] ?? 0),
                'completionTokens' => (int) ($row['completionTokens'] ?? 0) + (int) ($entry['completionTokens'] ?? 0),
                'totalTokens' => (int) ($row['totalTokens'] ?? 0) + (int) ($entry['totalTokens'] ?? 0),
                'cost' => number_format((float) ($row['cost'] ?? 0) + (float) ($entry['cost'] ?? 0), 6, '.', ''),
                'modelKey' => $this->text($entry['modelKey'] ?? $row['modelKey'] ?? 'unknown'),
                'kind' => 'LIVE',
                'priceKnown' => false !== ($row['priceKnown'] ?? true) && false !== ($entry['priceKnown'] ?? true),
            ];
            $merged = true;
            break;
        }
        if (!$merged) {
            $rows[] = $entry;
        }
        $message->setMeta('ai_usage_extra', json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function tokens(array $usage, string $primary, string $fallback): int
    {
        $value = $usage[$primary] ?? $usage[$fallback] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
