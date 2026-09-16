<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Feature;

use App\Service\Feature\FeatureFlagEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeatureFlagEnvTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function envVarNames(): iterable
    {
        yield 'plain group and setting' => ['IAM', 'SHARING_ENABLED', 'FEATURE_IAM_SHARING_ENABLED'];
        yield 'module gate' => ['MODULES', 'GATE_TIKA', 'FEATURE_MODULES_GATE_TIKA'];
        yield 'dotted setting is flattened' => ['TOOLS', 'POLICY.read', 'FEATURE_TOOLS_POLICY_READ'];
        yield 'lowercase input is upper-cased' => ['workflows', 'builder_enabled', 'FEATURE_WORKFLOWS_BUILDER_ENABLED'];
        yield 'dashes become underscores' => ['MODULES', 'GATE_my-module', 'FEATURE_MODULES_GATE_MY_MODULE'];
    }

    #[DataProvider('envVarNames')]
    public function testEnvVarNameIsDerivedFromTheBconfigKey(string $group, string $setting, string $expected): void
    {
        self::assertSame($expected, FeatureFlagEnv::envVarFor($group, $setting));
    }

    public function testUnsetVariableDoesNotPinTheFlag(): void
    {
        $env = new FeatureFlagEnv([]);

        self::assertNull($env->forced('IAM', 'SHARING_ENABLED'));
        self::assertFalse($env->isPinned('IAM', 'SHARING_ENABLED'));
    }

    public function testEmptyVariableDoesNotPinTheFlag(): void
    {
        $env = new FeatureFlagEnv(['FEATURE_IAM_SHARING_ENABLED' => '   ']);

        self::assertNull($env->forced('IAM', 'SHARING_ENABLED'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function booleanSpellings(): iterable
    {
        yield 'false' => ['false', false];
        yield 'FALSE' => ['FALSE', false];
        yield '0' => ['0', false];
        yield 'off' => ['off', false];
        yield 'no' => ['no', false];
        yield 'true' => ['true', true];
        yield '1' => ['1', true];
        yield 'on' => ['on', true];
        yield 'yes with whitespace' => [' yes ', true];
    }

    #[DataProvider('booleanSpellings')]
    public function testRecognisedBooleanSpellingsPinTheFlag(string $raw, bool $expected): void
    {
        $env = new FeatureFlagEnv(['FEATURE_WORKFLOWS_BUILDER_ENABLED' => $raw]);

        self::assertSame($expected, $env->forced('WORKFLOWS', 'BUILDER_ENABLED'));
        self::assertTrue($env->isPinned('WORKFLOWS', 'BUILDER_ENABLED'));
    }

    public function testUnrecognisedValueIsIgnoredInsteadOfGuessed(): void
    {
        $env = new FeatureFlagEnv(['FEATURE_WORKFLOWS_BUILDER_ENABLED' => 'maybe']);

        self::assertNull($env->forced('WORKFLOWS', 'BUILDER_ENABLED'));
        self::assertFalse($env->isPinned('WORKFLOWS', 'BUILDER_ENABLED'));
    }

    public function testOnlyTheMatchingVariableIsConsulted(): void
    {
        $env = new FeatureFlagEnv(['FEATURE_AGENTS_ENABLED' => 'false']);

        self::assertFalse($env->forced('AGENTS', 'ENABLED'));
        self::assertNull($env->forced('AGENTS', 'ROUTABLE_ENABLED'));
    }
}
