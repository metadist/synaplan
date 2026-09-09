<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Plan;

use App\Service\Multitask\Plan\TaskPlanValidator;
use PHPUnit\Framework\TestCase;

final class TaskPlanValidatorAllowListTest extends TestCase
{
    public function testNullAllowListIsUnrestricted(): void
    {
        $errors = (new TaskPlanValidator())->validate($this->plan('email_me'));

        self::assertSame([], $errors);
    }

    public function testDeniedCapabilityIsRejected(): void
    {
        $errors = (new TaskPlanValidator())->validate($this->plan('email_me'), ['chat', 'rag_query']);

        self::assertContains('capability_not_allowed:email_me', $errors);
    }

    public function testAllowedCapabilityPasses(): void
    {
        $errors = (new TaskPlanValidator())->validate($this->plan('chat'), ['chat', 'rag_query']);

        self::assertSame([], $errors);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $capability): array
    {
        return [
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n1',
            'tasks' => [[
                'id' => 'n1',
                'capability' => $capability,
            ]],
        ];
    }
}
