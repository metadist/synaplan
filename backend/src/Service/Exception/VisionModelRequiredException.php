<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown when the user attached images but no vision-capable chat model can be selected.
 */
final class VisionModelRequiredException extends \RuntimeException
{
    public const HINT_CODE = 'vision_model_required';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'Your message includes images, but no vision-capable chat model is available. Enable a chat model with vision support (or a Pic→Text default) in AI configuration, then try again.',
            422,
            $previous
        );
    }
}
