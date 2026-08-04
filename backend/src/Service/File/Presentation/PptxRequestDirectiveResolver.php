<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

/**
 * Applies explicit theme and transition names from the user's request when the
 * model forgets the PPTX directive. The request is authoritative: a named
 * option overrides a conflicting model choice, while unspecified options keep
 * the model's directive unchanged.
 */
final class PptxRequestDirectiveResolver
{
    private function __construct()
    {
    }

    public static function apply(string $content, string $request): string
    {
        $theme = self::requestedTheme($request);
        $transition = self::requestedTransition($request);

        if (null === $theme && null === $transition) {
            return $content;
        }

        $settings = self::existingSettings($content);
        if (null !== $theme) {
            $settings['theme'] = $theme->value;
        }
        if (null !== $transition) {
            $settings['transition'] = $transition->value;
        }

        $parts = [];
        foreach ($settings as $key => $value) {
            $parts[] = $key.'='.$value;
        }
        $directive = '{{PPTX:'.implode(', ', $parts).'}}';

        if (1 === preg_match(SlideMarkdownParser::DIRECTIVE_PATTERN, $content)) {
            return preg_replace(SlideMarkdownParser::DIRECTIVE_PATTERN, $directive, $content, 1) ?? $content;
        }

        return $directive."\n".$content;
    }

    private static function requestedTheme(string $request): ?PptxTheme
    {
        foreach (PptxTheme::cases() as $theme) {
            if (self::mentionsName($request, $theme->value)) {
                return $theme;
            }
        }

        return null;
    }

    private static function requestedTransition(string $request): ?SlideTransitionKind
    {
        foreach (SlideTransitionKind::cases() as $transition) {
            if (self::mentionsName($request, $transition->value)) {
                return $transition;
            }
        }

        return null;
    }

    private static function mentionsName(string $request, string $name): bool
    {
        return 1 === preg_match(
            '/(?<![\p{L}\p{N}_])'.preg_quote($name, '/').'(?![\p{L}\p{N}_])/iu',
            $request,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function existingSettings(string $content): array
    {
        if (1 !== preg_match(SlideMarkdownParser::DIRECTIVE_PATTERN, $content, $matches)) {
            return [];
        }

        $settings = [];
        foreach (preg_split('/[,;]/', $matches[1]) ?: [] as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = strtolower(trim($key));
            $value = trim($value);
            if ('' !== $key && '' !== $value) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }
}
