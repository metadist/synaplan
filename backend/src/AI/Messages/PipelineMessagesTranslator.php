<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallContext;
use App\AI\Pipeline\AiCallOptions;
use App\AI\Pipeline\AiCallPayload;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\AiCallSource;
use App\AI\Pipeline\AiCapability;
use App\AI\Pipeline\AiUsage;

/**
 * Wraps a {@see MessagesTranslatorInterface} so every upstream call (single
 * turn or each round inside {@see Tools\GatewayToolLoop})
 * runs as an {@see AiCall} through {@see AiCallPipeline} (`03_contracts.md`
 * §1.6, S1 AU12).
 */
final readonly class PipelineMessagesTranslator implements MessagesTranslatorInterface
{
    /**
     * @param array{provider: string, providerModelId: string, model_id: int} $resolved
     */
    public function __construct(
        private MessagesTranslatorInterface $inner,
        private AiCallPipeline $pipeline,
        private int $userId,
        private array $resolved,
        private string $keySource,
        private string $turnId,
    ) {
    }

    public function supports(string $providerName): bool
    {
        return $this->inner->supports($providerName);
    }

    public function complete(array $requestBody, array $context): array
    {
        $call = $this->buildCall(AiCapability::Passthrough, $requestBody, stream: false, responseText: null);

        /* @var array{status: int, headers: array<string, list<string>>, body: array<string, mixed>|string|null, usage: MessagesUsage} */
        return $this->pipeline->run(
            $call,
            fn (AiCall $c): array => $this->inner->complete($c->payload->passthroughBody, $context),
            static function (mixed $res): ?AiUsage {
                if (!\is_array($res) || !($res['usage'] ?? null) instanceof MessagesUsage) {
                    return null;
                }

                return AiUsage::fromTokenArray($res['usage']->toRateLimitUsage());
            },
        )->value;
    }

    public function stream(array $requestBody, array $context, callable $emit): MessagesUsage
    {
        $call = $this->buildCall(AiCapability::ChatStream, $requestBody, stream: true, responseText: null);

        // Both paths out of MessagesGateway::executeStream() arrive here: the
        // direct stream, and every round of the tool loop. Releasing once
        // around executeStream() misses the round after a TOOLS usage write.
        /* @var MessagesUsage */
        return $this->pipeline->stream(
            $call,
            function (AiCall $c, callable $onChunk) use ($context): MessagesUsage {
                $this->pipeline->releaseBeforeProvider('messages stream');

                return $this->inner->stream($c->payload->passthroughBody, $context, $onChunk);
            },
            $this->pipeline->releaseOnFirstChunk($emit, 'messages stream'),
            static fn (mixed $usage): ?AiUsage => $usage instanceof MessagesUsage ? AiUsage::fromTokenArray($usage->toRateLimitUsage()) : null,
        )->value;
    }

    /**
     * @param array<string, mixed> $requestBody
     */
    private function buildCall(AiCapability $capability, array $requestBody, bool $stream, ?string $responseText): AiCall
    {
        $messages = \is_array($requestBody['messages'] ?? null) ? $requestBody['messages'] : [];

        return new AiCall(
            capability: $capability,
            provider: $this->resolved['provider'],
            model: $this->resolved['providerModelId'],
            context: new AiCallContext(
                userId: $this->userId,
                source: AiCallSource::MessagesApi,
                turnId: $this->turnId,
            ),
            payload: AiCallPayload::passthrough($requestBody),
            options: AiCallOptions::fromArray([
                'model_id' => $this->resolved['model_id'],
                'skip_circuit_breaker' => true,
                'skip_model_health' => true,
                '_messages_gateway' => [
                    'key_source' => $this->keySource,
                    'input_text' => AnthropicContentText::lastHumanRequest($messages),
                    'response_text' => $responseText,
                ],
            ], stream: $stream, callerMeters: true),
        );
    }
}
