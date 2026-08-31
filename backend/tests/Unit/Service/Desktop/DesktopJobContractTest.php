<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Entity\DesktopJob;
use App\Service\Desktop\DesktopJobContract;
use PHPUnit\Framework\TestCase;

final class DesktopJobContractTest extends TestCase
{
    public function testProtocolVersionIsOne(): void
    {
        self::assertSame(1, DesktopJobContract::PROTOCOL_VERSION);
    }

    public function testOnlySkillRunTypeIsValid(): void
    {
        self::assertTrue(DesktopJobContract::isValidType('skill.run'));
        self::assertFalse(DesktopJobContract::isValidType('shell.exec'));
        self::assertFalse(DesktopJobContract::isValidType(''));
    }

    public function testErrorCodeVocabularyIsClosed(): void
    {
        self::assertTrue(DesktopJobContract::isValidErrorCode('unknown_skill'));
        self::assertTrue(DesktopJobContract::isValidErrorCode('timeout'));
        self::assertFalse(DesktopJobContract::isValidErrorCode('rm_rf'));
        self::assertFalse(DesktopJobContract::isValidErrorCode(''));
    }

    /**
     * The security-critical invariant: whatever else ends up in BINPUT, a device
     * only ever receives {skill, prompt, fileIds}. A smuggled `command` key must
     * never reach the payload — that is what makes RCE structurally impossible.
     */
    public function testBuildDevicePayloadDropsEverythingButAllowedInputKeys(): void
    {
        $job = (new DesktopJob())
            ->setOwnerId(1)
            ->setDeviceId(7)
            ->setType(DesktopJob::TYPE_SKILL_RUN)
            ->setLeaseToken('lt_abc')
            ->setLeaseExpires(1_800_000_000)
            ->setAttempt(2)
            ->setInput([
                'skill' => 'pptx',
                'prompt' => 'Make slides',
                'fileIds' => [10, 11],
                'command' => 'rm -rf /',
                'script' => 'evil.sh',
                'argv' => ['--yes'],
            ]);

        $payload = DesktopJobContract::buildDevicePayload($job);

        self::assertSame(['skill', 'prompt', 'fileIds'], array_keys($payload['input']));
        self::assertSame('pptx', $payload['input']['skill']);
        self::assertSame('Make slides', $payload['input']['prompt']);
        self::assertSame([10, 11], $payload['input']['fileIds']);
        self::assertArrayNotHasKey('command', $payload['input']);
        self::assertArrayNotHasKey('script', $payload['input']);
        self::assertArrayNotHasKey('argv', $payload['input']);
        self::assertSame('skill.run', $payload['type']);
        self::assertSame('lt_abc', $payload['leaseToken']);
        self::assertSame(1_800_000_000, $payload['leaseExpires']);
        self::assertSame(2, $payload['attempt']);
    }

    public function testBuildDevicePayloadCoercesMissingOrOddInput(): void
    {
        $job = (new DesktopJob())
            ->setOwnerId(1)
            ->setInput([
                'skill' => 'notes',
                // no prompt
                'fileIds' => [3, 'x', '4', null],
            ]);

        $payload = DesktopJobContract::buildDevicePayload($job);

        self::assertSame('notes', $payload['input']['skill']);
        self::assertSame('', $payload['input']['prompt']);
        // Only numeric ids survive, cast to int.
        self::assertSame([3, 4], $payload['input']['fileIds']);
    }

    public function testBuildDevicePayloadHandlesEmptyInput(): void
    {
        $job = (new DesktopJob())->setOwnerId(1)->setInput(null);

        $payload = DesktopJobContract::buildDevicePayload($job);

        self::assertSame('', $payload['input']['skill']);
        self::assertSame('', $payload['input']['prompt']);
        self::assertSame([], $payload['input']['fileIds']);
    }
}
