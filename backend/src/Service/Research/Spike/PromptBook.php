<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

final class PromptBook
{
    public function __construct(
        private readonly string $directory = __DIR__.'/prompts',
    ) {
    }

    /**
     * @param array<string, string> $replacements
     */
    public function fill(string $name, array $replacements): string
    {
        $path = $this->directory.'/'.$name.'.txt';
        if (!is_file($path)) {
            throw new \RuntimeException('Missing spike prompt '.$name.'.');
        }
        $text = (string) file_get_contents($path);
        foreach ($replacements as $key => $value) {
            $text = str_replace('['.$key.']', $value, $text);
        }

        return $text;
    }
}
