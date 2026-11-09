<?php

declare(strict_types=1);

namespace App\AI\Provider\Concerns;

use App\AI\Pipeline\AiCall;
use App\AI\Stream\OpenAiChatCompletionsDecoder;
use App\AI\Stream\StreamDecoderInterface;

/**
 * The request and decoder a pump will use. The in-request loop still builds
 * the same array through buildChatRequest().
 */
trait OpenAiCompatibleStreamRequest
{
    abstract private function buildChatRequest(array $messages, array $options, string $model, bool $stream): array;

    abstract private function requireModel(array $options): string;

    public function buildStreamRequest(AiCall $call): array
    {
        $options = $call->options->providerOptions;
        if (is_string($call->model) && '' !== $call->model) {
            $options['model'] = $call->model;
        }

        return $this->buildChatRequest($call->payload->messages, $options, $this->requireModel($options), true);
    }

    public function streamDecoder(AiCall $call): StreamDecoderInterface
    {
        unset($call);

        return new OpenAiChatCompletionsDecoder();
    }
}
