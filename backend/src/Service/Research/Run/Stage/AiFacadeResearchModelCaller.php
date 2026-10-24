<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Service\ModelConfigService;

/** Asks the model selected for a research role. A transport failure is a failed reply, not a stack trace. */
final readonly class AiFacadeResearchModelCaller implements ResearchModelCaller
{
    public function __construct(
        private AiFacade $ai,
        private ModelConfigService $models,
    ) {
    }

    public function answer(
        int $userId,
        int $modelId,
        string $system,
        string $user,
        ?StructuredOutputSchema $schema,
    ): ResearchModelReply {
        $provider = $this->models->getProviderForModel($modelId);
        $name = $this->models->getModelName($modelId);
        if (null === $provider || '' === $provider || null === $name || '' === $name) {
            return ResearchModelReply::failed();
        }

        $options = [
            'provider' => $provider,
            'model' => $name,
            'temperature' => 0.1,
        ];
        if ($schema instanceof StructuredOutputSchema) {
            $options['structured_output'] = $schema;
        }

        try {
            $response = $this->ai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], $userId, $options);
        } catch (\Throwable) {
            return ResearchModelReply::failed(true);
        }

        $text = is_string($response['content'] ?? null) ? $response['content'] : '';
        $data = self::objectFrom($text);
        $cost = isset($response['cost']) && is_numeric($response['cost']) ? (float) $response['cost'] : null;
        $ok = null === $schema ? '' !== trim($text) : is_array($data);

        return new ResearchModelReply($ok, true, $data, $text, $provider.':'.$name, $cost);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function objectFrom(string $text): ?array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
