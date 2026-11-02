<?php

declare(strict_types=1);

namespace App\Tests\Support\AgentTemplate;

/**
 * Deterministic stand-in for the chat provider in template acceptance.
 *
 * It answers from the system prompt and the attached file text only.
 * A citation, a disclaimer, or a refusal appears when the instruction
 * asks for it. The file block uses the same label as ChatMessageAssembler.
 */
final class AgentTemplateAcceptanceProvider
{
    private const FILE_MARK = "User provided 1 file(s):\n\n";

    /**
     * @param list<array{role?: string, content?: string}> $messages
     *
     * @return array{content: string}
     */
    public function chat(array $messages): array
    {
        $system = $this->system($messages);
        $user = $this->user($messages);
        $knowledge = $this->knowledge($user);
        $body = null === $knowledge
            ? [$this->missingSentence($system)]
            : $this->fromKnowledge($system, $knowledge);

        return ['content' => implode("\n", array_merge($body, $this->obligations($system)))];
    }

    public function whenSourceIsMissing(string $instruction): string
    {
        return $this->missingSentence($instruction);
    }

    public function attach(string $utterance, ?string $knowledge): string
    {
        if (null === $knowledge || '' === trim($knowledge)) {
            return $utterance;
        }

        return $utterance."\n\n\n---\n\n\n".self::FILE_MARK.$knowledge."\n\n";
    }

    /**
     * @param list<array{role?: string, content?: string}> $messages
     */
    private function system(array $messages): string
    {
        foreach ($messages as $message) {
            if ('system' === ($message['role'] ?? '')) {
                return (string) ($message['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param list<array{role?: string, content?: string}> $messages
     */
    private function user(array $messages): string
    {
        $user = '';
        foreach ($messages as $message) {
            if ('user' === ($message['role'] ?? '')) {
                $user = (string) ($message['content'] ?? '');
            }
        }

        return $user;
    }

    private function knowledge(string $user): ?string
    {
        $pos = strpos($user, self::FILE_MARK);
        if (false === $pos) {
            return null;
        }

        return trim(substr($user, $pos + strlen(self::FILE_MARK)));
    }

    /**
     * @return list<string>
     */
    private function fromKnowledge(string $system, string $knowledge): array
    {
        $lines = array_values(array_filter(array_map(
            trim(...),
            preg_split('/\R/', $knowledge) ?: [],
        ), static fn (string $line): bool => '' !== $line));
        $file = array_shift($lines) ?? 'source';
        if ($this->pageUnchanged($system, $lines)) {
            $body = ['Nothing material changed.'];
            if ($this->wantsCitation($system)) {
                $body[] = 'Source: '.$file;
            }

            return $body;
        }
        if (str_contains(strtolower($system), 'skip newsletters')) {
            $lines = array_values(array_filter(
                $lines,
                static fn (string $line): bool => !str_contains(strtolower($line), 'newsletter'),
            ));
        }

        $body = $this->wantsThreePosts($system) && [] !== $lines
            ? $this->threePosts(implode(' ', $lines))
            : $lines;
        if ($this->wantsCitation($system)) {
            $body[] = 'Source: '.$file;
        }

        return $body;
    }

    /**
     * @return list<string>
     */
    private function threePosts(string $text): array
    {
        return ['1. '.$text, '2. '.$text, '3. '.$text];
    }

    /**
     * @param list<string> $lines
     */
    private function pageUnchanged(string $system, array $lines): bool
    {
        if (!str_contains(strtolower($system), 'nothing material changed')) {
            return false;
        }
        foreach ($lines as $line) {
            if ('unchanged' === strtolower($line)) {
                return true;
            }
        }

        return false;
    }

    private function wantsCitation(string $system): bool
    {
        return 1 === preg_match('/cite the (file|passage|article|page)/i', $system);
    }

    private function wantsThreePosts(string $system): bool
    {
        $lower = strtolower($system);

        return str_contains($lower, 'three') && str_contains($lower, 'posts');
    }

    private function missingSentence(string $system): string
    {
        $sentences = preg_split('/(?<=\.)\s+/', trim($system)) ?: [];
        foreach ($sentences as $sentence) {
            if (str_starts_with($sentence, 'If no ')) {
                return $sentence;
            }
        }
        foreach ($sentences as $sentence) {
            if (str_contains($sentence, 'is missing')) {
                return $sentence;
            }
        }

        return 'I do not have the source.';
    }

    /**
     * @return list<string>
     */
    private function obligations(string $system): array
    {
        $lower = strtolower($system);
        $lines = [];
        if (str_contains($lower, 'not legal advice')) {
            $lines[] = 'This is not legal advice.';
        }
        if (str_contains($lower, 'do not evaluate candidates')) {
            $lines[] = 'This does not evaluate a candidate.';
        }
        if (str_contains($lower, 'do not claim a ticket')) {
            $lines[] = 'No ticket was opened.';
        }
        if (str_contains($lower, 'not a decision of the office')) {
            $lines[] = 'This is not a decision of the office.';
        }
        if (str_contains($lower, 'do not send a request')) {
            $lines[] = 'Nothing was sent.';
        }
        if (1 === preg_match('/request approval|ask for approval/i', $system)) {
            $lines[] = 'Approval requested.';
        }

        return $lines;
    }
}
