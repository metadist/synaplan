<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\Entity\User;
use App\Service\Chat\Stream\StreamAgentPin;
use App\Service\Chat\Stream\StreamCaller;
use App\Service\Chat\Stream\StreamQuote;
use App\Service\Chat\Stream\StreamSavedContext;
use App\Service\Chat\Stream\StreamTurnOptions;
use App\Service\Chat\Stream\StreamTurnRequest;
use App\Service\Chat\Stream\StreamUserInput;

/**
 * The scalar description of one web-chat turn.
 *
 * The prompt is here for the 120 seconds until the pump claims the job.
 * A provider key is not: {@see StreamJobGuard} runs before the job is stored.
 */
final class ChatTurnJob
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(StreamTurnRequest $turn, string $runId): array
    {
        $quote = $turn->input->quote;

        return [
            'channel' => 'chat_stream',
            'runId' => $runId,
            'userId' => (int) ($turn->caller->user->getId() ?? 0),
            'uiLanguage' => $turn->caller->uiLanguage,
            'clientCountry' => $turn->caller->clientCountry,
            'chatId' => $turn->chatId,
            'trackId' => (string) $turn->trackId,
            'message' => $turn->input->message,
            'fileIds' => $turn->input->fileIds,
            'continueMessageId' => $turn->input->continueMessageId,
            'quote' => null === $quote ? null : ['text' => $quote->text, 'messageId' => $quote->messageId],
            'options' => [
                'reasoning' => $turn->options->reasoning,
                'reasoningEffort' => $turn->options->reasoningEffort,
                'webSearch' => $turn->options->webSearch,
                'voiceReply' => $turn->options->voiceReply,
                'disableMemories' => $turn->options->savedContext->disableMemories,
                'disableKnowledge' => $turn->options->savedContext->disableKnowledge,
                'modelId' => $turn->options->modelId,
                'isAgain' => $turn->options->isAgain,
                'fixedTaskPromptTopic' => $turn->options->fixedTaskPromptTopic,
                'ragGroupKey' => $turn->options->ragGroupKey,
                'agentId' => $turn->options->agent?->agentId,
                'agentDraft' => true === $turn->options->agent?->draft,
                'agentVersionId' => $turn->options->agent?->versionId,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function restore(array $payload, User $user): ?StreamTurnRequest
    {
        $runId = $payload['runId'] ?? null;
        $message = $payload['message'] ?? null;
        if (!is_string($runId) || '' === $runId || !is_string($message)) {
            return null;
        }
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $quote = self::quote($payload['quote'] ?? null);
        $agent = self::agent($options);

        return new StreamTurnRequest(
            caller: new StreamCaller(
                user: $user,
                uiLanguage: is_string($payload['uiLanguage'] ?? null) ? $payload['uiLanguage'] : 'en',
                clientCountry: is_string($payload['clientCountry'] ?? null) ? $payload['clientCountry'] : null,
            ),
            chatId: is_string($payload['chatId'] ?? null) ? $payload['chatId'] : (is_int($payload['chatId'] ?? null) ? (string) $payload['chatId'] : null),
            trackId: is_string($payload['trackId'] ?? null) || is_int($payload['trackId'] ?? null) ? (string) $payload['trackId'] : '0',
            input: new StreamUserInput(
                message: $message,
                fileIds: self::ints($payload['fileIds'] ?? []),
                continueMessageId: is_string($payload['continueMessageId'] ?? null) ? $payload['continueMessageId'] : null,
                quote: $quote,
            ),
            options: new StreamTurnOptions(
                reasoning: true === ($options['reasoning'] ?? false),
                reasoningEffort: is_string($options['reasoningEffort'] ?? null) ? $options['reasoningEffort'] : '',
                webSearch: true === ($options['webSearch'] ?? false),
                voiceReply: true === ($options['voiceReply'] ?? false),
                savedContext: new StreamSavedContext(
                    true === ($options['disableMemories'] ?? false),
                    true === ($options['disableKnowledge'] ?? false),
                ),
                modelId: is_string($options['modelId'] ?? null) ? $options['modelId'] : (is_int($options['modelId'] ?? null) ? (string) $options['modelId'] : null),
                isAgain: true === ($options['isAgain'] ?? false),
                fixedTaskPromptTopic: is_string($options['fixedTaskPromptTopic'] ?? null) ? $options['fixedTaskPromptTopic'] : null,
                ragGroupKey: is_string($options['ragGroupKey'] ?? null) ? $options['ragGroupKey'] : null,
                agent: $agent,
            ),
            assignedRunId: $runId,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function agent(array $options): ?StreamAgentPin
    {
        $id = $options['agentId'] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        $version = $options['agentVersionId'] ?? null;

        return new StreamAgentPin(
            (int) $id,
            true === ($options['agentDraft'] ?? false),
            is_int($version) || (is_string($version) && ctype_digit($version)) ? (int) $version : null,
        );
    }

    private static function quote(mixed $raw): ?StreamQuote
    {
        if (!is_array($raw) || !is_string($raw['text'] ?? null) || '' === $raw['text']) {
            return null;
        }
        $messageId = $raw['messageId'] ?? null;

        return new StreamQuote(
            $raw['text'],
            is_int($messageId) || (is_string($messageId) && ctype_digit($messageId)) ? (int) $messageId : null,
        );
    }

    /**
     * @return list<int>
     */
    private static function ints(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
