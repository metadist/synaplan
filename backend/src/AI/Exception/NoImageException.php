<?php

declare(strict_types=1);

namespace App\AI\Exception;

/**
 * The image model completed the request and answered in text, with no image.
 *
 * Gemini does this with a text part and finishReason STOP; the OpenAI
 * Responses API does it with an assistant message and no image_generation_call.
 * The provider is up. A handful of prompts the model chose to answer in words
 * must not open the circuit or count against model health.
 *
 * {@see ProviderException::noImage()} is the factory. Callers still catch
 * {@see ProviderException}; the subclass is how the circuit breaker and the
 * health classifier tell this outcome apart from an outage.
 */
final class NoImageException extends ProviderException
{
}
