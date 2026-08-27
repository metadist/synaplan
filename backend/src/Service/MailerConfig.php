<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Whether this installation can actually send mail.
 *
 * An unset DSN or Symfony's null transport means nothing is ever delivered —
 * forgot-password and sign-up confirmation then fail silently, so the UI has
 * to offer the CLI reset instead of pretending an inbox will light up.
 */
final readonly class MailerConfig
{
    public function isConfigured(): bool
    {
        $dsn = trim((string) ($_ENV['MAILER_DSN'] ?? ''));

        return '' !== $dsn && 'null://null' !== $dsn;
    }
}
