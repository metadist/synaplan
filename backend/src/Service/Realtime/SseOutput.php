<?php

declare(strict_types=1);

namespace App\Service\Realtime;

/**
 * Prepares the PHP output layer before the first Server-Sent Event.
 *
 * Production drops every buffer the framework opened so each frame reaches
 * the client immediately. Tests set the controller's capture flag and keep
 * their own buffer, which {@see \App\Controller\StreamController::sendSSE()}
 * writes into instead of flushing.
 */
final class SseOutput
{
    public static function begin(bool $capture): void
    {
        if ($capture) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_implicit_flush(true);
    }
}
