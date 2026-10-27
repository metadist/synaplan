<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Service\Mcp\McpArgumentSchema;
use PHPUnit\Framework\TestCase;

final class McpArgumentSchemaTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $searchSchema = [
        'properties' => [
            'query' => ['type' => 'string'],
            'query_type' => ['type' => 'string', 'enum' => ['internal', 'user']],
            'filters' => [
                'type' => 'object',
                'properties' => [
                    'created_date_range' => ['type' => 'object'],
                    'created_by_user_ids' => ['type' => 'array'],
                ],
            ],
        ],
        'required' => ['query'],
    ];

    public function testDropsEmptyOptionalsAndKeepsTheRequiredQuery(): void
    {
        $cleaned = McpArgumentSchema::clean([
            'query' => '',
            'query_type' => '',
            'filters' => [],
        ], $this->searchSchema);

        self::assertSame(['query' => ''], $cleaned);
    }

    public function testDropsAnOptionalValueOutsideItsEnum(): void
    {
        $cleaned = McpArgumentSchema::clean([
            'query' => 'Bild',
            'query_type' => 'nope',
        ], $this->searchSchema);

        self::assertSame(['query' => 'Bild'], $cleaned);
        self::assertSame(
            ['query' => 'Bild', 'query_type' => 'user'],
            McpArgumentSchema::clean(['query' => 'Bild', 'query_type' => 'user'], $this->searchSchema),
        );
    }

    public function testKeepsARequiredValueOutsideItsEnum(): void
    {
        $schema = $this->searchSchema;
        $schema['required'] = ['query', 'query_type'];

        $cleaned = McpArgumentSchema::clean(['query' => 'Bild', 'query_type' => ''], $schema);

        self::assertSame(['query' => 'Bild', 'query_type' => ''], $cleaned);
    }

    public function testEncodesAnObjectPropertyAsAnObject(): void
    {
        $schema = $this->searchSchema;
        $schema['required'] = ['query', 'filters'];

        $cleaned = McpArgumentSchema::clean([
            'query' => 'Bild',
            'filters' => ['created_date_range' => [], 'created_by_user_ids' => ['me']],
        ], $schema);

        self::assertSame(
            '{"query":"Bild","filters":{"created_date_range":{},"created_by_user_ids":["me"]}}',
            json_encode($cleaned),
        );
    }

    public function testLeavesArgumentsAloneWhenTheSchemaListsNoProperties(): void
    {
        self::assertSame(
            ['query' => '', 'filters' => []],
            McpArgumentSchema::clean(['query' => '', 'filters' => []], []),
        );
    }
}
