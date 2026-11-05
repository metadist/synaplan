<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Keep creative image follow-ups on `image_generation` (PIC2PIC).
 *
 * Rule 3a sends pixel-exact stamps to `code_run`. The planner also treats
 * "text in the corner" on a generated picture as a stamp, so a request like
 * "make the sitting bird fly and write SYNAPLAN top right" runs a Python
 * overlay, finds no attached file this turn, and fails. Scene changes stay
 * image generation; only an unchanged-picture stamp stays file work.
 */
final class ImageFollowUpPlan
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function adjustPlan(array $payload, string $messageText): array
    {
        $tasks = $payload['tasks'] ?? null;
        if (!is_array($tasks) || !self::isCreativeImageFollowUp($messageText) || !self::hasCodeRun($tasks)) {
            return $payload;
        }

        foreach ($tasks as $i => $task) {
            if (!is_array($task) || ($task['capability'] ?? '') !== 'code_run') {
                continue;
            }
            $tasks[$i] = self::asImageGeneration($task, $messageText);
        }
        $payload['tasks'] = $tasks;

        return $payload;
    }

    public static function isCreativeImageFollowUp(string $messageText): bool
    {
        $text = trim($messageText);
        if ('' === $text) {
            return false;
        }
        if (self::isExplicitScript($text) || self::isChartFromTable($text) || self::isPixelExactStamp($text)) {
            return false;
        }

        return 1 === preg_match(
            '/(?:'
            .'lass es|genau so aber|keep it|make it'
            .'|fliegend|hintergrund|verschwommen|blass'
            .'|restyle|recolor|background|blur|pose'
            .'|mach den|auch flieg|both birds'
            .'|oben rechts|top right|in die ecke|in the corner'
            .'|großbuchstaben|capital buchstaben'
            .')/iu',
            $text,
        );
    }

    /**
     * @param list<mixed> $tasks
     */
    private static function hasCodeRun(array $tasks): bool
    {
        foreach ($tasks as $task) {
            if (is_array($task) && ($task['capability'] ?? '') === 'code_run') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $task
     *
     * @return array<string, mixed>
     */
    private static function asImageGeneration(array $task, string $messageText): array
    {
        $inputs = is_array($task['inputs'] ?? null) ? $task['inputs'] : [];
        unset($inputs['script'], $inputs['attachments']);
        if (!is_string($inputs['prompt'] ?? null) || '' === trim($inputs['prompt'])) {
            $inputs['prompt'] = $messageText;
        }

        $params = is_array($task['params'] ?? null) ? $task['params'] : [];
        unset($params['script'], $params['image'], $params['inputFileNames'], $params['inputFileIds']);
        $params['input_mode'] = 'reference_images';

        $task['capability'] = 'image_generation';
        $task['inputs'] = $inputs;
        $task['params'] = $params;

        return $task;
    }

    private static function isExplicitScript(string $text): bool
    {
        return 1 === preg_match(
            '/\b(python|node\.?js|javascript|script|programm|code)\b/i',
            $text,
        ) && 1 === preg_match(
            '/\b(run|execute|apply|führe|ausführen|ausfuehren)\b/iu',
            $text,
        );
    }

    private static function isChartFromTable(string $text): bool
    {
        return 1 === preg_match(
            '/\b(balken|torten|säulen|linien)?diagramm\b|\b(bar|pie|line)\s+chart\b|\bmach\s+daraus\b/iu',
            $text,
        );
    }

    private static function isPixelExactStamp(string $text): bool
    {
        if (1 !== preg_match(
            '/\b(stempel\w*|wasserzeichen|watermark|stamp|crop|resize|rotate|zuschneiden|skalier\w*)\b/iu',
            $text,
        )) {
            return false;
        }

        return 1 === preg_match(
            '/keins neu malen|don\'t (?:re)?draw|do not (?:re)?draw|pixel-exact|vorhandene[sn]? bild/iu',
            $text,
        ) || 1 !== preg_match(
            '/(?:fliegend|hintergrund|verschwommen|restyle|recolor|background|blur|lass es|genau so aber)/iu',
            $text,
        );
    }
}
