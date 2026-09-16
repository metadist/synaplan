<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Service\Compute\ComputeRequestBuilder;
use PHPUnit\Framework\TestCase;

final class ComputeRequestBuilderTest extends TestCase
{
    public function testUserWorkspaceFixtureShape(): void
    {
        $fixture = $this->decode('run_request_user_workspace.json');
        $built = (new ComputeRequestBuilder())->userWorkspaceRun(
            (string) $fixture['owner'],
            (string) $fixture['workspace']['id'],
            (string) $fixture['image'],
            $fixture['entry'],
            $fixture['files'],
            $fixture['limits'],
            $fixture['egress'],
        );

        self::assertSame($fixture, $built->toArray());
    }

    public function testEgressFixtureShape(): void
    {
        $fixture = $this->decode('run_request_egress.json');
        $built = (new ComputeRequestBuilder())->ephemeralRun(
            (string) $fixture['owner'],
            (string) $fixture['image'],
            $fixture['entry'],
            $fixture['files'],
            $fixture['limits'],
            $fixture['egress'],
        );

        self::assertSame($fixture, $built->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $name): array
    {
        $raw = file_get_contents(dirname(__DIR__, 3).'/Fixtures/compute-contract/'.$name);
        self::assertNotFalse($raw);
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
