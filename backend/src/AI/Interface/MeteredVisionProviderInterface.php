<?php

declare(strict_types=1);

namespace App\AI\Interface;

use App\AI\Vision\VisionReply;

/**
 * A vision provider that also reports the tokens of the call, so
 * {@see \App\AI\Service\AiVisionExecutor} can bill it. A provider without it
 * is still billed, on the byte estimate of prompt and answer.
 */
interface MeteredVisionProviderInterface extends VisionProviderInterface
{
    /**
     * The same call as {@see VisionProviderInterface::explainImage()}.
     *
     * @param array<string, mixed> $options
     */
    public function explainImageMetered(string $imageUrl, string $prompt = '', array $options = []): VisionReply;
}
