<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Model;/**
 * The canned replies a slash command gets instead of a model answer:
 * a usage hint for a bare command that needs an argument, and the
 * not-found reply when /docs matches nothing.
 */
final readonly class SlashCommandReplies
{
    public function __construct(
        private ?SlashCommandCopy $slashCommandCopy = null,
    ) {
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function hint(array $classification): ?string
    {
        if (empty($classification['slash_hint'])) {
            return null;
        }

        $command = is_string($classification['slash_command'] ?? null)
            ? (string) $classification['slash_command']
            : 'pic';
        $locale = is_string($classification['language'] ?? null) ? (string) $classification['language'] : 'en';

        if (null === $this->slashCommandCopy) {
            return sprintf(
                'Write what to create after the command, for example: /%s a dog on the beach',
                $command,
            );
        }

        return $this->slashCommandCopy->needsArgument($command, $locale);
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function docsNotFound(array $classification): string
    {
        $locale = is_string($classification['language'] ?? null) ? (string) $classification['language'] : 'en';

        if (null === $this->slashCommandCopy) {
            return 'No matching file was found in your knowledge base.';
        }

        return $this->slashCommandCopy->docsNotFound($locale);
    }
}
