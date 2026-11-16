<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * The scripted chats next to the corpus.
 *
 * A `conversation` is one long chat that can run against today's stack. A
 * `tool_stub` describes a tool that misbehaves on purpose; it needs the loop
 * of PJ5 to plant the stub, so today it is data that is only validated.
 */
final class ScriptedChats
{
    public const GROUP = 'long_chat';
    public const DIR = __DIR__.'/scripted';

    /**
     * The messages of a conversation in order, then its probes.
     *
     * @return list<CorpusTurn>
     */
    public static function conversation(string $path): array
    {
        $script = self::decode($path);
        $length = $script['length'] ?? null;
        $filler = $script['filler'] ?? null;
        if ('conversation' !== ($script['kind'] ?? null) || !\is_int($length) || $length < 1 || !\is_array($filler) || [] === $filler) {
            throw new \RuntimeException($path.' is not a conversation with a length and filler messages.');
        }
        $filler = array_values($filler);
        $fixed = \is_array($script['messages'] ?? null) ? $script['messages'] : [];

        $turns = [];
        for ($position = 1; $position <= $length; ++$position) {
            $text = \is_array($fixed[(string) $position] ?? null) ? ($fixed[(string) $position]['text'] ?? null) : $filler[($position - 1) % \count($filler)];
            if (!\is_string($text) || '' === trim($text)) {
                throw new \RuntimeException(sprintf('%s: message %d has no text.', $path, $position));
            }
            $turns[] = new CorpusTurn(sprintf('message-%03d', $position), self::GROUP, 'en', $text, false, [], []);
        }
        foreach (\is_array($script['probes'] ?? null) ? $script['probes'] : [] as $probe) {
            if (!\is_array($probe) || !\is_string($probe['id'] ?? null) || !\is_string($probe['text'] ?? null)) {
                throw new \RuntimeException($path.': a probe needs an id and a text.');
            }
            $expect = [];
            foreach (\is_array($probe['expect'] ?? null) ? $probe['expect'] : [] as $assertion) {
                $needles = array_values(array_filter(\is_array($assertion) ? $assertion : [], static fn (mixed $needle): bool => \is_string($needle) && '' !== trim($needle)));
                // An assertion without a needle would count as met.
                if ([] !== $needles) {
                    $expect[] = $needles;
                }
            }
            $turns[] = new CorpusTurn($probe['id'], self::GROUP, 'en', $probe['text'], false, [], $expect);
        }

        return $turns;
    }

    /**
     * @return array{id: string, tool: string, behaviour: string, text: string, wrapup: string, nudges: int}
     */
    public static function toolStub(string $path): array
    {
        $script = self::decode($path);
        $stub = \is_array($script['stub'] ?? null) ? $script['stub'] : [];
        $turn = \is_array($script['turn'] ?? null) ? $script['turn'] : [];
        $expect = \is_array($script['expect'] ?? null) ? $script['expect'] : [];
        $shape = [
            'id' => $script['id'] ?? null,
            'tool' => $stub['tool'] ?? null,
            'behaviour' => $stub['behaviour'] ?? null,
            'text' => $turn['text'] ?? null,
            'wrapup' => $expect['wrapup'] ?? null,
        ];
        foreach ($shape as $field => $value) {
            if (!\is_string($value) || '' === trim($value)) {
                throw new \RuntimeException($path.': the tool stub has no "'.$field.'".');
            }
        }
        if ('tool_stub' !== ($script['kind'] ?? null) || !\is_int($expect['nudges'] ?? null)) {
            throw new \RuntimeException($path.' is not a tool stub with an expected number of nudges.');
        }

        return [...$shape, 'nudges' => $expect['nudges']];
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $path): array
    {
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!\is_array($decoded)) {
            throw new \RuntimeException('The script '.$path.' does not exist or is not valid JSON.');
        }

        return $decoded;
    }
}
