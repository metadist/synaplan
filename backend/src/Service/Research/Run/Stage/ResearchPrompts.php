<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Prompt\PromptCatalog;

/** Fills one tools:research_* prompt from the catalog. */
final class ResearchPrompts
{
    /**
     * @param array<string, string> $values
     */
    public static function fill(string $topic, array $values): string
    {
        $template = null;
        foreach (PromptCatalog::all() as $entry) {
            if ($topic === $entry['topic']) {
                $template = $entry['prompt'];
                break;
            }
        }
        if (null === $template) {
            throw new \InvalidArgumentException('The research prompt is not in the catalog.');
        }

        $replace = [];
        foreach ($values as $key => $value) {
            $replace['['.$key.']'] = $value;
        }

        return strtr($template, $replace);
    }

    /** Text from a page or a model cannot close the block it is listed in. */
    public static function data(string $text): string
    {
        return str_replace(['<', '>'], ['‹', '›'], $text);
    }
}
