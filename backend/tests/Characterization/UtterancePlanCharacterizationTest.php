<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Service\Multitask\Plan\TaskPlanValidator;
use App\Tests\Characterization\Support\RoutingSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Golden snapshot of the CANONICAL task plans for the Phase M acceptance
 * utterances (plan 10 §1, step M1).
 *
 * The planner is an AI model, so its live output cannot be snapshot-tested.
 * What IS deterministic — and what this test locks — is the *contract* those
 * plans rely on: every canonical plan must stay valid against the real
 * `TaskPlanValidator` (capability vocabulary, reference grammar, reply-node
 * rules), and its shape is recorded as a baseline so any change to the
 * expected DAG for these four sentences is an explicit, reviewed diff.
 *
 * The plans below describe TODAY's honest behavior. Phase M steps change them
 * on purpose (M6 added the Outlook delivery to U1 via `params.channel`, M3
 * widened U3's mail sources); when that happens this snapshot MUST drift and
 * the diff MUST be reviewed line by line — that is the point of the lock, not
 * an annoyance.
 *
 * Record/refresh the baseline with: UPDATE_ROUTING_SNAPSHOTS=1
 */
final class UtterancePlanCharacterizationTest extends TestCase
{
    private const SNAPSHOT_FILE = __DIR__.'/__snapshots__/utterance_plans.json';

    public function testCanonicalUtterancePlansValidateAndMatchBaseline(): void
    {
        $validator = new TaskPlanValidator();

        $actual = [];
        foreach ($this->cases() as $id => $case) {
            self::assertSame(
                [],
                $validator->validate($case['plan']),
                "Canonical plan for utterance '{$id}' no longer passes TaskPlanValidator — "
                .'a capability was renamed/removed or the plan grammar changed.',
            );
            $actual[$id] = $case;
        }

        $snapshot = new RoutingSnapshot(self::SNAPSHOT_FILE);

        if (RoutingSnapshot::updateMode()) {
            $snapshot->write($actual);
            self::assertNotEmpty($actual, 'Recorded utterance-plan baseline.');

            return;
        }

        self::assertTrue(
            $snapshot->exists(),
            'Missing utterance-plan baseline. Generate it once with UPDATE_ROUTING_SNAPSHOTS=1 and commit '.self::SNAPSHOT_FILE,
        );

        $expected = $snapshot->load();

        foreach ($actual as $id => $case) {
            self::assertArrayHasKey($id, $expected, "New utterance '{$id}' has no baseline; re-record with UPDATE_ROUTING_SNAPSHOTS=1.");
            self::assertSame(
                RoutingSnapshot::encodeCase((array) $expected[$id]),
                RoutingSnapshot::encodeCase($case),
                "Canonical plan drift for utterance '{$id}'. If intentional (a Phase M step), review the diff and re-record.",
            );
        }

        foreach ($expected as $id => $_) {
            self::assertArrayHasKey($id, $actual, "Baseline utterance '{$id}' was removed from the corpus.");
        }
    }

    /**
     * The four Phase M acceptance utterances, verbatim, each with the canonical
     * plan the planner is expected to emit for it (per the `tools:plan`
     * examples in PromptCatalog). Dates are fixed fixtures — the planner
     * resolves "tomorrow" against its injected time context, which is not
     * under test here.
     *
     * @return array<string, array{utterance: string, plan: array<string, mixed>}>
     */
    private function cases(): array
    {
        return [
            // U1 — Step M6 shipped: with a connected Outlook calendar (the
            // premise of "put it into my Outlook"), the planner names the
            // calendar channel and the runner creates the event via Graph.
            // The .ics stays attached as the download fallback; without a
            // connected calendar channel `params.channel` is simply omitted.
            'u1_outlook_calendar_write' => [
                'utterance' => "Create a meeting reminder for tomorrow at 10am for 'Marketing Strategy' and put it into my Outlook",
                'plan' => [
                    'version' => 1,
                    'language' => 'en',
                    'tasks' => [
                        [
                            'id' => 'n1',
                            'capability' => 'calendar_event',
                            'depends_on' => [],
                            'params' => [
                                'title' => 'Marketing Strategy',
                                'start' => '2026-08-19T10:00:00',
                                'timezone' => 'Europe/Berlin',
                                'duration_minutes' => 60,
                                'channel' => 'outlook',
                            ],
                        ],
                        [
                            'id' => 'n2',
                            'capability' => 'compose_reply',
                            'depends_on' => ['n1'],
                            'inputs' => ['attachments' => ['$n1.file']],
                        ],
                    ],
                    'reply_node' => 'n2',
                ],
            ],

            // U2 — shipped and regression-locked: the .ics is mailed to the
            // account owner. CalendarEmailChainTest proves the execution path;
            // this case locks the plan shape.
            'u2_mail_me_the_invite' => [
                'utterance' => "Create a meeting reminder for tomorrow at 10am for 'Marketing Strategy' and mail the calendar entry to me",
                'plan' => [
                    'version' => 1,
                    'language' => 'en',
                    'tasks' => [
                        [
                            'id' => 'n1',
                            'capability' => 'calendar_event',
                            'depends_on' => [],
                            'params' => [
                                'title' => 'Marketing Strategy',
                                'start' => '2026-08-19T10:00:00',
                                'timezone' => 'Europe/Berlin',
                                'duration_minutes' => 60,
                            ],
                        ],
                        [
                            'id' => 'n2',
                            'capability' => 'email_me',
                            'depends_on' => ['n1'],
                            'inputs' => ['attachments' => ['$n1.file']],
                        ],
                        [
                            'id' => 'n3',
                            'capability' => 'compose_reply',
                            'depends_on' => ['n1', 'n2'],
                            'inputs' => ['attachments' => ['$n1.file'], 'text' => '$n2.text'],
                        ],
                    ],
                    'reply_node' => 'n3',
                ],
            ],

            // U3 — mail search feeding a summarizing chat node. Today the
            // search source is IMAP only; step M3 adds M365 as a second
            // backend behind the SAME capability, so this plan shape must
            // survive M3 unchanged.
            'u3_mail_search_summarize' => [
                'utterance' => 'What is the latest mail of Oliver Braun regarding FPSenergy, summarize that for me',
                'plan' => [
                    'version' => 1,
                    'language' => 'en',
                    'tasks' => [
                        [
                            'id' => 'n1',
                            'capability' => 'email_search',
                            'depends_on' => [],
                            'params' => ['query' => 'FPSenergy', 'from' => 'Oliver Braun'],
                        ],
                        [
                            'id' => 'n2',
                            'capability' => 'chat',
                            'depends_on' => ['n1'],
                            'inputs' => ['text' => '$n1.text'],
                            'params' => ['instruction' => 'Summarize the newest matching email.'],
                        ],
                        [
                            'id' => 'n3',
                            'capability' => 'compose_reply',
                            'depends_on' => ['n2'],
                            'inputs' => ['text' => '$n2.text'],
                        ],
                    ],
                    'reply_node' => 'n3',
                ],
            ],

            // U4 — document generation with a folder delivery as the extra
            // side-channel sink (compose_reply still carries the file, per the
            // tools:plan example). The `nextcloud` channel is the shipped
            // target; steps M7/M8 add the Outlook-inbox and openCloud targets.
            'u4_document_to_target' => [
                'utterance' => 'Create a marketing plan document with a solid TOC for my company and put it into: Nextcloud',
                'plan' => [
                    'version' => 1,
                    'language' => 'en',
                    'tasks' => [
                        [
                            'id' => 'n1',
                            'capability' => 'document_generation',
                            'depends_on' => [],
                            'params' => ['format' => 'docx', 'topic' => 'Marketing plan with table of contents'],
                        ],
                        [
                            'id' => 'n2',
                            'capability' => 'save_to_folder',
                            'depends_on' => ['n1'],
                            'inputs' => ['attachments' => ['$n1.file']],
                            'params' => ['channel' => 'nextcloud'],
                        ],
                        [
                            'id' => 'n3',
                            'capability' => 'compose_reply',
                            'depends_on' => ['n1', 'n2'],
                            'inputs' => ['attachments' => ['$n1.file']],
                        ],
                    ],
                    'reply_node' => 'n3',
                ],
            ],
        ];
    }
}
