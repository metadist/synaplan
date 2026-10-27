<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\Entity\Message;

/**
 * A chat the facade already wrote to the usage log. Callers that also
 * record the turn keep one quota row and must not bill the same tokens again.
 */
final class FacadeMeteredChat
{
    /**
     * @param array<string, mixed> $metadata
     */
    public static function isMetered(array $metadata): bool
    {
        return true === ($metadata['facade_metered'] ?? false);
    }

    /**
     * Copy the facade's meter mark onto a rebuilt metadata array.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public static function stamp(array $metadata, array $response): array
    {
        if (true !== ($response['facade_metered'] ?? false)) {
            return $metadata;
        }

        $metadata['facade_metered'] = true;
        $metadata['facade_charged_cost'] = $response['facade_charged_cost'] ?? null;
        $metadata['facade_price_known'] = false !== ($response['facade_price_known'] ?? true);

        return $metadata;
    }

    /**
     * One message-allowance row. The provider, model and tokens come from the
     * turn; source, the two texts and any extra fields are the caller's.
     * When the facade already billed the call, the row keeps none of that.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function messages(array $metadata, string $source, string $input, string $answer, array $extra = []): array
    {
        $row = [
            'provider' => $metadata['provider'] ?? 'unknown',
            'model' => $metadata['model'] ?? 'unknown',
            'usage' => $metadata['usage'] ?? [],
            'model_id' => $metadata['model_id'] ?? null,
            'source' => $source,
            'input_text' => $input,
            'response_text' => $answer,
        ];
        if (self::isMetered($metadata)) {
            $row['facade_metered'] = true;
            $row['facade_charged_cost'] = $metadata['facade_charged_cost'] ?? null;
            if (array_key_exists('facade_price_known', $metadata)) {
                $row['facade_price_known'] = false !== $metadata['facade_price_known'];
            }
        }

        return self::logMetadata($extra + $row);
    }

    public static function logMetadata(array $metadata): array
    {
        if (!self::isMetered($metadata)) {
            return $metadata;
        }

        $metadata['usage'] = [];
        $metadata['model'] = '';
        $metadata['model_id'] = null;
        $metadata['provider'] = '';
        $metadata['input_text'] = '';
        $metadata['response_text'] = '';

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function chargedCost(array $metadata): ?string
    {
        $cost = $metadata['facade_charged_cost'] ?? null;

        return is_string($cost) && '' !== $cost ? $cost : null;
    }

    /**
     * The chat bubble shows what the provider charged. When the facade already
     * wrote that cost, the turn's own row is only the message allowance.
     * An unknown catalog price stays unknown: a real zero and "no price" are
     * different things on the bubble.
     *
     * @param array<string, mixed> $source
     */
    public static function attach(Message $message, ?RecordedUsage $recorded, array $source): void
    {
        $charged = self::chargedCost($source);
        if (null !== $charged) {
            $message->setMeta('ai_chat_cost', $charged);
            self::applyPriceKnown($message, $source);

            return;
        }

        $recorded?->attachChatCost($message);
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function applyPriceKnown(Message $message, array $source): void
    {
        if (false === ($source['facade_price_known'] ?? true)) {
            $message->setMeta('ai_chat_price_known', '0');
        }
    }
}
