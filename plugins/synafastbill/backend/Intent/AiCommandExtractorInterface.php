<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Intent;

/**
 * Optional AI-backed extraction seam.
 *
 * In Synaplan this is implemented by an adapter around `AiFacade` (using the
 * user's configured model). Offline tests and examples pass no extractor at
 * all, so the deterministic rule-based parser in {@see IntentParser} is used.
 *
 * Implementations MUST return null when they are not confident, so the parser
 * can fall back to rules rather than guessing a destructive command.
 */
interface AiCommandExtractorInterface
{
    public function extract(string $text): ?ParsedCommand;
}
