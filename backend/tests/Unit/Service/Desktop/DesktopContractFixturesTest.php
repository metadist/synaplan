<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Entity\DesktopJob;
use App\Mcp\McpServerFactory;
use App\Service\Desktop\DesktopJobContract;
use App\Service\Desktop\DesktopJobStore;
use PHPUnit\Framework\TestCase;

/**
 * DS18 — the committed contract fixtures under
 * `_devextras/testing/desktop/fixtures/` are the frozen `protocol: 1` wire
 * shapes the future desktop client vendors. This test asserts every fixture
 * against the LIVE server contract ({@see DesktopJobContract}, the entity
 * enums, and the MCP tool schemas), so a later server change that breaks the
 * frozen contract fails the gate here rather than silently breaking a shipped
 * client (invariant C9).
 *
 * If this test fails, the fix is almost never "edit the fixture": either the
 * server drifted from `protocol: 1` (revert it) or the change is deliberate and
 * needs a `protocol: 2` migration.
 */
final class DesktopContractFixturesTest extends TestCase
{
    /**
     * The published, client-vendored copy (Phase B, DC3). It is the canonical
     * home per the plan, but it lives at the repo root, which the dev backend
     * container does not mount — so the test READS the in-backend mirror below
     * and, whenever the canonical copy IS on disk (CI, host), asserts the two
     * are byte-identical. Drift between them is therefore impossible.
     */
    private const CANONICAL_DIR = '/_devextras/testing/desktop/fixtures';

    /** The mirror the container + CI both mount (`backend/` is always present). */
    private const MIRROR_DIR = '/Fixtures/Desktop';

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $mirror = \dirname(__DIR__, 3).self::MIRROR_DIR.'/'.$name;
        self::assertFileExists($mirror, "Missing frozen fixture mirror: {$name}");

        $raw = (string) file_get_contents($mirror);

        // C9 drift guard: the client vendors the canonical copy, so the mirror
        // the server test validates MUST be byte-identical to it.
        $canonical = \dirname(__DIR__, 5).self::CANONICAL_DIR.'/'.$name;
        if (is_file($canonical)) {
            self::assertSame(
                file_get_contents($canonical),
                $raw,
                "Fixture drift: backend/tests/Fixtures/Desktop/{$name} differs from the canonical _devextras copy the client vendors."
            );
        }

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, "Fixture {$name} is not valid JSON");

        return $decoded;
    }

    public function testCheckinRequestMatchesLiveSchema(): void
    {
        $req = $this->fixture('checkin_request.json');
        $schema = McpServerFactory::agentCheckinSchema();

        self::assertSame(DesktopJobContract::PROTOCOL_VERSION, $req['protocol']);
        self::assertSame('synaplan-desktop', $req['agentKind']);

        // capabilities must be job types the server knows.
        foreach ($req['capabilities'] as $capability) {
            self::assertContains($capability, DesktopJobContract::JOB_TYPES);
        }

        // Every fixture key exists in the frozen tool schema, which forbids extras.
        self::assertFalse($schema['additionalProperties'] ?? true);
        foreach (array_keys($req) as $key) {
            self::assertArrayHasKey($key, $schema['properties'], "checkin_request has unknown key '{$key}'");
        }
    }

    public function testCheckinResponseMatchesLiveContract(): void
    {
        $res = $this->fixture('checkin_response.json');

        self::assertSame(DesktopJobContract::PROTOCOL_VERSION, $res['protocol']);
        self::assertIsArray($res['jobs']);
        self::assertIsInt($res['next_call_at']);

        foreach ($res['jobs'] as $job) {
            $this->assertJobMatchesDevicePayloadShape($job);
        }
    }

    public function testStandaloneJobFixtureMatchesDevicePayload(): void
    {
        $this->assertJobMatchesDevicePayloadShape($this->fixture('job_skill_run.json'));
    }

    /**
     * A job fixture must have EXACTLY the keys the live builder emits, and its
     * input must be exactly the allowed keys — never a `command`/`script`/`argv`.
     *
     * @param array<string, mixed> $job
     */
    private function assertJobMatchesDevicePayloadShape(array $job): void
    {
        $reference = DesktopJobContract::buildDevicePayload(
            (new DesktopJob())
                ->setOwnerId(1)
                ->setType(DesktopJob::TYPE_SKILL_RUN)
                ->setLeaseToken('lt_x')
                ->setInput(['skill' => 'x', 'prompt' => 'y', 'fileIds' => []])
        );

        self::assertSame(array_keys($reference), array_keys($job), 'job payload keys drifted from buildDevicePayload()');
        self::assertSame(DesktopJobContract::ALLOWED_INPUT_KEYS, array_keys($job['input']));
        self::assertContains($job['type'], DesktopJobContract::JOB_TYPES);
        self::assertArrayNotHasKey('command', $job['input']);
    }

    public function testSuccessReportMatchesLiveSchema(): void
    {
        $report = $this->fixture('report_success.json');
        $schema = McpServerFactory::agentReportResultSchema();

        self::assertSame(DesktopJob::STATUS_SUCCEEDED, $report['status']);
        self::assertContains($report['status'], [DesktopJob::STATUS_SUCCEEDED, DesktopJob::STATUS_FAILED]);

        $this->assertReportKeysAndSize($report, $schema);
    }

    public function testUnknownSkillReportMatchesLiveSchema(): void
    {
        $report = $this->fixture('report_unknown_skill.json');
        $schema = McpServerFactory::agentReportResultSchema();

        self::assertSame(DesktopJob::STATUS_FAILED, $report['status']);
        self::assertSame(DesktopJobContract::ERROR_UNKNOWN_SKILL, $report['errorCode']);
        self::assertTrue(DesktopJobContract::isValidErrorCode($report['errorCode']));

        $this->assertReportKeysAndSize($report, $schema);
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $schema
     */
    private function assertReportKeysAndSize(array $report, array $schema): void
    {
        self::assertFalse($schema['additionalProperties'] ?? true);
        foreach (array_keys($report) as $key) {
            self::assertArrayHasKey($key, $schema['properties'], "report has unknown key '{$key}'");
        }

        if (isset($report['result'])) {
            $encoded = (string) json_encode($report['result']);
            self::assertLessThanOrEqual(DesktopJobStore::RESULT_MAX_BYTES, \strlen($encoded));
        }
    }

    public function testEnqueueRequestMatchesLiveValidation(): void
    {
        $req = $this->fixture('enqueue_request.json');

        // Mirrors DesktopController::enqueueJob validation, which is the live
        // source of truth for the POST /jobs contract.
        self::assertTrue(DesktopJobContract::isValidType($req['type']));
        self::assertSame(1, preg_match('/^[a-z0-9-]{1,64}$/', $req['input']['skill']));

        $allowedTopKeys = ['deviceId', 'type', 'input', 'chatId', 'messageId', 'idempotencyKey'];
        foreach (array_keys($req) as $key) {
            self::assertContains($key, $allowedTopKeys, "enqueue_request has unsupported key '{$key}'");
        }

        // input carries only the frozen keys (skill required).
        foreach (array_keys($req['input']) as $key) {
            self::assertContains($key, DesktopJobContract::ALLOWED_INPUT_KEYS, "enqueue input has non-contract key '{$key}'");
        }
    }

    public function testEveryFixtureThatCarriesProtocolPinsToOne(): void
    {
        foreach (['checkin_request.json', 'checkin_response.json'] as $name) {
            $data = $this->fixture($name);
            self::assertSame(1, $data['protocol'], "{$name} must pin protocol: 1");
        }
    }
}
