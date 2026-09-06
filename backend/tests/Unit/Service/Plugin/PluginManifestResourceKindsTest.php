<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Plugin;

use App\Service\Iam\Permission;
use App\Service\Plugin\InvalidPluginManifestException;
use App\Service\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginManifestResourceKindsTest extends TestCase
{
    public function testValidDeclarationIsKept(): void
    {
        $manifest = PluginManifest::fromArray([
            'name' => 'synaform',
            'provides' => [
                'resourceKinds' => [
                    [
                        'key' => 'synaform:form',
                        'dataType' => 'form',
                        'labelKey' => 'synaform.kind.form',
                        'permissions' => ['read', 'use', 'edit'],
                    ],
                ],
            ],
        ]);

        self::assertCount(1, $manifest->resourceKinds);
        self::assertSame('synaform:form', $manifest->resourceKinds[0]['key']);
        self::assertSame('form', $manifest->resourceKinds[0]['dataType']);
        self::assertSame(
            [Permission::Read, Permission::Use, Permission::Edit],
            $manifest->resourceKinds[0]['permissions'],
        );
    }

    public function testInvalidKeyNamesTheField(): void
    {
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('provides.resourceKinds[0].key');

        PluginManifest::fromArray([
            'name' => 'synaform',
            'provides' => [
                'resourceKinds' => [
                    [
                        'key' => 'other:form',
                        'dataType' => 'form',
                        'permissions' => ['read'],
                    ],
                ],
            ],
        ]);
    }

    public function testInvalidPermissionNamesTheField(): void
    {
        $this->expectException(InvalidPluginManifestException::class);
        $this->expectExceptionMessage('provides.resourceKinds[0].permissions');

        PluginManifest::fromArray([
            'name' => 'synaform',
            'provides' => [
                'resourceKinds' => [
                    [
                        'key' => 'synaform:form',
                        'dataType' => 'form',
                        'permissions' => ['read', 'publish'],
                    ],
                ],
            ],
        ]);
    }
}
