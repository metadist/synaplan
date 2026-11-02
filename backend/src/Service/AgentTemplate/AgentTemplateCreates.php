<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

/**
 * How many assistants and instructions a template bundle will create.
 */
final readonly class AgentTemplateCreates
{
    public function __construct(
        public int $assistants,
        public int $prompts,
        public int $tasks = 0,
    ) {
    }

    /**
     * @param list<array{kind: string, version: int, items: list<array<string, mixed>>}> $sections
     */
    public static function fromSections(array $sections): self
    {
        $assistants = 0;
        $prompts = 0;
        $tasks = 0;
        foreach ($sections as $section) {
            $count = count($section['items']);
            if ('agents' === $section['kind']) {
                $assistants += $count;
                foreach ($section['items'] as $item) {
                    $tasks += self::enabledSchedules($item);
                }
            }
            if ('prompts' === $section['kind']) {
                $prompts += $count;
            }
        }

        return new self($assistants, $prompts, $tasks);
    }

    /**
     * A schedule that is switched on becomes a task when the assistant is installed.
     * One that the template leaves off does not.
     *
     * @param array<string, mixed> $item
     */
    private static function enabledSchedules(array $item): int
    {
        $definition = is_array($item['definition'] ?? null) ? $item['definition'] : [];
        $triggers = is_array($definition['triggers'] ?? null) ? $definition['triggers'] : [];
        $schedules = is_array($triggers['schedules'] ?? null) ? $triggers['schedules'] : [];
        $count = 0;
        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            if (array_key_exists('enabled', $schedule) && false === $schedule['enabled']) {
                continue;
            }
            ++$count;
        }

        return $count;
    }
}
