<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Entity\DesktopJob;

/**
 * The frozen Synaplan Desktop job / check-in contract (Sprint A3, DS18).
 *
 * `protocol: 1`. Both the check-in request and response carry this version; a
 * device that sends an unknown protocol is answered with an empty job list and
 * a far next_call_at (never a guess). Every enum here is closed and fixture-
 * frozen (`_devextras/testing/desktop/fixtures/`); changing it is a
 * `protocol: 2` decision with a migration, not a client-convenience edit (C9).
 *
 * The single most important rule this class encodes: a job's device-facing
 * input is ONLY `{skill, prompt, fileIds}`. Any other key (`command`,
 * `script`, `argv`, …) is dropped by {@see buildDevicePayload()} and MUST be
 * ignored by the device. That is why a future server bug cannot turn into
 * remote code execution — there is no field through which a shell string could
 * ever reach the laptop.
 */
final class DesktopJobContract
{
    public const PROTOCOL_VERSION = 1;

    /** The only job type in v1. No `shell.exec`, ever. */
    public const TYPE_SKILL_RUN = DesktopJob::TYPE_SKILL_RUN;

    /** @var list<string> */
    public const JOB_TYPES = [self::TYPE_SKILL_RUN];

    /** @var list<string> */
    public const STATUSES = [
        DesktopJob::STATUS_QUEUED,
        DesktopJob::STATUS_LEASED,
        DesktopJob::STATUS_SUCCEEDED,
        DesktopJob::STATUS_FAILED,
        DesktopJob::STATUS_CANCELLED,
    ];

    public const ERROR_UNKNOWN_SKILL = 'unknown_skill';
    public const ERROR_UNKNOWN_TYPE = 'unknown_type';
    public const ERROR_SKILL_DISABLED = 'skill_disabled';
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_LOCAL_ERROR = 'local_error';

    /** @var list<string> */
    public const ERROR_CODES = [
        self::ERROR_UNKNOWN_SKILL,
        self::ERROR_UNKNOWN_TYPE,
        self::ERROR_SKILL_DISABLED,
        self::ERROR_TIMEOUT,
        self::ERROR_LOCAL_ERROR,
    ];

    /**
     * The ONLY keys a device ever receives in a job's input. Everything else is
     * dropped server-side and must be ignored client-side.
     *
     * @var list<string>
     */
    public const ALLOWED_INPUT_KEYS = ['skill', 'prompt', 'fileIds'];

    /** Provenance stamped on results re-entering the account (RAG / chat). */
    public const RESULT_SOURCE = 'desktop_skill';

    /** Idle poll cadence (seconds) suggested to a device with no work. */
    public const NEXT_CALL_IDLE_SECONDS = 180;

    /** Poll cadence (seconds) suggested when work was handed out. */
    public const NEXT_CALL_ACTIVE_SECONDS = 30;

    /** How far ahead to defer a device speaking an unknown protocol. */
    public const NEXT_CALL_UNKNOWN_PROTOCOL_SECONDS = 3600;

    private function __construct()
    {
    }

    public static function isValidType(string $type): bool
    {
        return \in_array($type, self::JOB_TYPES, true);
    }

    public static function isValidErrorCode(string $code): bool
    {
        return \in_array($code, self::ERROR_CODES, true);
    }

    /**
     * Build the device-facing payload for a job, keeping ONLY the allowed input
     * keys. A `command`/`script`/`argv` smuggled into BINPUT never appears here.
     *
     * @return array{jobId: int, type: string, input: array{skill: string, prompt: string, fileIds: list<int>}, leaseToken: string, leaseExpires: int, attempt: int}
     */
    public static function buildDevicePayload(DesktopJob $job): array
    {
        $input = $job->getInput();

        $fileIds = [];
        if (isset($input['fileIds']) && \is_array($input['fileIds'])) {
            foreach ($input['fileIds'] as $fileId) {
                if (is_numeric($fileId)) {
                    $fileIds[] = (int) $fileId;
                }
            }
        }

        return [
            'jobId' => (int) $job->getId(),
            'type' => $job->getType(),
            'input' => [
                'skill' => \is_string($input['skill'] ?? null) ? $input['skill'] : '',
                'prompt' => \is_string($input['prompt'] ?? null) ? $input['prompt'] : '',
                'fileIds' => $fileIds,
            ],
            'leaseToken' => (string) $job->getLeaseToken(),
            'leaseExpires' => $job->getLeaseExpires(),
            'attempt' => $job->getAttempt(),
        ];
    }
}
