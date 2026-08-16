<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use PHPUnit\Framework\TestCase;

final class SavedTaskGraphValidatorTest extends TestCase
{
    private SavedTaskGraphValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SavedTaskGraphValidator();
    }

    public function testRejectsTriggerMismatch(): void
    {
        $errors = $this->validator->validate(
            [
                'version' => 1,
                'trigger' => ['id' => 't1', 'type' => 'schedule'],
                'nodes' => [['id' => 'n1', 'capability' => 'chat', 'depends_on' => []]],
            ],
            'manual',
            null,
        );

        $this->assertNotEmpty($errors);
    }

    public function testRejectsCycle(): void
    {
        $errors = $this->validator->validate(
            [
                'version' => 1,
                'trigger' => ['id' => 't1', 'type' => 'manual'],
                'nodes' => [
                    ['id' => 'n1', 'capability' => 'chat', 'depends_on' => ['n2']],
                    ['id' => 'n2', 'capability' => 'email_search', 'depends_on' => ['n1']],
                ],
            ],
            'manual',
            null,
        );

        $this->assertContains('steps contain a cycle', $errors);
    }

    public function testAcceptsFlagshipGraph(): void
    {
        $errors = $this->validator->validate(
            [
                'version' => 1,
                'trigger' => ['id' => 't1', 'type' => 'schedule'],
                'nodes' => [
                    ['id' => 'n1', 'capability' => 'email_search', 'depends_on' => []],
                    ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1']],
                    ['id' => 'n3', 'capability' => 'calendar_event', 'depends_on' => ['n2']],
                ],
            ],
            'schedule',
            ['kind' => 'weekly'],
        );

        $this->assertSame([], $errors);
    }
}
