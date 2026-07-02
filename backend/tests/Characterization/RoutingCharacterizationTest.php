<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessageSorter;
use App\Service\ModelConfigService;
use App\Tests\Characterization\Support\RoutingSnapshot;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden-corpus characterization of the CURRENT routing contract (Sprint 0).
 *
 * This locks down exactly what `MessageClassifier` returns for a representative
 * corpus so later multi-task-routing sprints can prove "no regression": the new
 * planner/executor must keep producing the same routing decision for these
 * inputs (the migration principle — decide tasks, not models).
 *
 * Two layers, both deterministic and DB-free (matching the repo convention that
 * unit tests run without a seeded DB):
 *   - DETERMINISTIC inputs (fast-path, slash commands, Again overrides, doc/audio
 *     attachments) run through the REAL classifier end-to-end — these are the
 *     routes we explicitly keep pre-planner, so they must never drift.
 *   - SORTER-DRIVEN inputs inject the sorter's raw output and snapshot how the
 *     classifier transforms it (intent mapping, media/duration/resolution
 *     passthrough, custom-topic → chat fallback). This is the exact transform
 *     the planner adapter must reproduce.
 *
 * The snapshot also intentionally documents the 4 migration-risk areas from the
 * plan: custom user topic, analyzefile, mediamaker params, override paths.
 *
 * Record/refresh the baseline with: UPDATE_ROUTING_SNAPSHOTS=1
 */
final class RoutingCharacterizationTest extends TestCase
{
    private const SNAPSHOT_FILE = __DIR__.'/__snapshots__/routing_classification.json';

    public function testRoutingContractMatchesGoldenCorpus(): void
    {
        $actual = [];
        foreach ($this->corpus() as $case) {
            $actual[$case['id']] = $this->classifyCase($case);
        }

        $snapshot = new RoutingSnapshot(self::SNAPSHOT_FILE);

        if (RoutingSnapshot::updateMode()) {
            $snapshot->write($actual);
            self::assertNotEmpty($actual, 'Recorded routing baseline.');

            return;
        }

        self::assertTrue(
            $snapshot->exists(),
            'Missing routing baseline. Generate it once with UPDATE_ROUTING_SNAPSHOTS=1 and commit '.self::SNAPSHOT_FILE,
        );

        $expected = $snapshot->load();

        // Per-case comparison gives a readable diff that pinpoints the regressed
        // input instead of dumping the whole corpus.
        foreach ($actual as $id => $result) {
            self::assertArrayHasKey($id, $expected, "New corpus case '{$id}' has no baseline; re-record with UPDATE_ROUTING_SNAPSHOTS=1.");
            self::assertSame(
                RoutingSnapshot::encodeCase($expected[$id]),
                RoutingSnapshot::encodeCase($result),
                "Routing regression for corpus case '{$id}'.",
            );
        }

        foreach ($expected as $id => $_) {
            self::assertArrayHasKey($id, $actual, "Baseline case '{$id}' is no longer produced by the corpus.");
        }
    }

    /**
     * The golden corpus. Each case is one inbound message scenario.
     *
     * @return list<array{
     *     id: string,
     *     text: string,
     *     language?: string,
     *     topic?: string,
     *     fastPath?: bool,
     *     files?: list<array{type?: string, name?: string, mime?: string}>,
     *     meta?: array<string, string>,
     *     modelTag?: string,
     *     sorter?: array<string, mixed>
     * }>
     */
    private function corpus(): array
    {
        return [
            // ---- Plain chat (fast-path heuristic, language detection) ----
            ['id' => 'chat_plain_en', 'text' => 'Hello, how are you today?', 'language' => 'en'],
            ['id' => 'chat_plain_de', 'text' => 'Hallo, wie geht es dir und der Familie?', 'language' => 'de'],
            ['id' => 'chat_plain_fr', 'text' => 'Bonjour, pouvez vous le faire pour moi?', 'language' => 'fr'],
            ['id' => 'chat_plain_es', 'text' => 'Hola, por favor escribir el texto para los amigos', 'language' => 'es'],
            ['id' => 'chat_thanks', 'text' => 'Thanks, that helps a lot!', 'language' => 'en'],

            // ---- Slash / tool commands (deterministic, stay pre-planner) ----
            ['id' => 'cmd_pic', 'text' => '/pic a watercolor cat', 'language' => 'en'],
            ['id' => 'cmd_vid', 'text' => '/vid a drone shot of the alps', 'language' => 'en'],
            ['id' => 'cmd_tts', 'text' => '/tts read this aloud', 'language' => 'en'],
            ['id' => 'cmd_search', 'text' => '/search latest php release', 'language' => 'en'],
            ['id' => 'cmd_lang', 'text' => '/lang de', 'language' => 'en'],
            ['id' => 'cmd_web', 'text' => '/web example.com', 'language' => 'en'],
            ['id' => 'cmd_list', 'text' => '/list', 'language' => 'en'],
            ['id' => 'cmd_docs', 'text' => '/docs sort my files', 'language' => 'en'],

            // ---- Again overrides (fast-path off, like the existing override tests) ----
            ['id' => 'again_prompt_override', 'text' => 'redo that', 'language' => 'en', 'fastPath' => false, 'meta' => ['PROMPTID' => 'tools:pic']],
            ['id' => 'again_model_override_chat', 'text' => 'redo that', 'language' => 'en', 'fastPath' => false, 'meta' => ['MODEL_ID' => '42'], 'modelTag' => 'chat'],
            ['id' => 'again_model_override_text2pic', 'text' => 'redo that', 'language' => 'en', 'fastPath' => false, 'meta' => ['MODEL_ID' => '77'], 'modelTag' => 'text2pic'],

            // ---- Attachment force-routes (analyzefile) — migration-risk #2 ----
            ['id' => 'attach_pdf', 'text' => 'Summarize this', 'language' => 'en', 'files' => [['type' => 'pdf', 'name' => 'report.pdf']]],
            ['id' => 'attach_docx', 'text' => 'What is in here?', 'language' => 'en', 'files' => [['type' => 'docx', 'name' => 'contract.docx']]],
            ['id' => 'attach_audio_mp3', 'text' => 'Transcribe', 'language' => 'de', 'files' => [['type' => 'mp3', 'name' => 'voice.mp3']]],

            // ---- Sorter-driven: media generation params — migration-risk #3 ----
            ['id' => 'sort_image', 'text' => 'make an image of a cat', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image']],
            ['id' => 'sort_video_params', 'text' => 'make a 8s 720p clip', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'video', 'duration' => 8, 'resolution' => '720p']],
            ['id' => 'sort_audio', 'text' => 'make a song', 'language' => 'de', 'fastPath' => false, 'sorter' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio']],

            // ---- #1237 guard: mediamaker/audio + image attachment → describe path ----
            // The sorter mis-votes mediamaker/audio because the user names an audio
            // OUTPUT ("beschreibe in einem audio …"); with an attached image the
            // classifier must deterministically reroute to `general` (vision
            // describe; the planner chains file_analysis → text2sound) instead of
            // letting MediaGenerationHandler run a doomed pic2pic image job.
            ['id' => 'sort_audio_describe_attached_image', 'text' => 'beschreibe in einem audio was hier zu sehen ist', 'language' => 'de', 'fastPath' => false, 'files' => [['type' => 'png', 'name' => 'photo.png']], 'sorter' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio']],
            // Generated-file re-attachment stores the generic kind `image` (#1236) —
            // the guard must accept it like a concrete extension.
            ['id' => 'sort_audio_describe_attached_generic_image', 'text' => 'beschreibe in einem audio was hier zu sehen ist', 'language' => 'de', 'fastPath' => false, 'files' => [['type' => 'image', 'name' => 'media_1_google.png']], 'sorter' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio']],
            // Control: mediamaker/audio WITHOUT an image attachment stays mediamaker
            // (a genuine "read this text aloud" TTS request must not be rerouted).
            ['id' => 'sort_audio_no_attachment_stays_mediamaker', 'text' => 'lies mir diesen text vor: hallo welt', 'language' => 'de', 'fastPath' => false, 'sorter' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'audio']],

            // ---- Sorter-driven: other canonical topics ----
            ['id' => 'sort_officemaker', 'text' => 'mach eine excel tabelle', 'language' => 'de', 'fastPath' => false, 'sorter' => ['topic' => 'officemaker', 'language' => 'de']],
            ['id' => 'sort_general', 'text' => 'tell me a joke', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en', 'sorting_model_id' => 5, 'sorting_provider' => 'ollama', 'sorting_model_name' => 'llama3']],
            ['id' => 'sort_analyze', 'text' => 'what is on this picture', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'analyze', 'language' => 'en']],
            ['id' => 'sort_websearch', 'text' => 'what is the weather tomorrow', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en', 'web_search' => true]],

            // ---- Sorter-driven: CUSTOM user topic — migration-risk #1 ----
            // A user-authored BPROMPTS topic (BOWNERID>0) is NOT in the canonical
            // map, so today it falls back to the chat intent. This baseline proves
            // the planner adapter must carry the topic/prompt id to preserve it.
            ['id' => 'sort_custom_topic', 'text' => 'review this contract clause', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'legal-review', 'language' => 'en']],
        ];
    }

    /**
     * @param array{
     *     id: string, text: string, language?: string, topic?: string,
     *     fastPath?: bool, files?: list<array{type?: string, name?: string, mime?: string}>,
     *     meta?: array<string, string>, modelTag?: string, sorter?: array<string, mixed>
     * } $case
     *
     * @return array<string, mixed>
     */
    private function classifyCase(array $case): array
    {
        $fastPath = $case['fastPath'] ?? true;

        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use ($fastPath): ?string {
                if ('QDRANT_SEARCH' === $group) {
                    return '0';
                }
                if ('CLASSIFIER' === $group && 'FAST_PATH_ENABLED' === $setting) {
                    return $fastPath ? null : '0'; // null => default-on
                }

                return null;
            }
        );

        $sorter = $this->createMock(MessageSorter::class);
        if (isset($case['sorter'])) {
            $sorter->method('classify')->willReturn($case['sorter']);
        }

        $em = $this->createMock(EntityManagerInterface::class);
        if (isset($case['modelTag'])) {
            $model = $this->createMock(Model::class);
            $model->method('getTag')->willReturn($case['modelTag']);
            $repo = $this->createMock(EntityRepository::class);
            $repo->method('find')->willReturn($model);
            $em->method('getRepository')->willReturn($repo);
        }

        $metaRepo = $this->createMock(MessageMetaRepository::class);
        $meta = $case['meta'] ?? [];
        $metaRepo->method('findOneBy')->willReturnCallback(
            function (array $criteria) use ($meta): ?MessageMeta {
                $key = $criteria['metaKey'] ?? null;
                if (is_string($key) && isset($meta[$key])) {
                    $m = $this->createMock(MessageMeta::class);
                    $m->method('getMetaValue')->willReturn($meta[$key]);

                    return $m;
                }

                return null;
            }
        );

        $classifier = new MessageClassifier(
            $sorter,
            $metaRepo,
            $this->createMock(ModelConfigService::class),
            $configRepo,
            $em,
            $this->createMock(LoggerInterface::class),
        );

        $message = $this->buildMessage($case);

        return $classifier->classify($message);
    }

    /**
     * @param array{
     *     id: string, text: string, language?: string, topic?: string,
     *     fastPath?: bool, files?: list<array{type?: string, name?: string, mime?: string}>,
     *     meta?: array<string, string>, modelTag?: string, sorter?: array<string, mixed>
     * } $case
     */
    private function buildMessage(array $case): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(1);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn($case['text']);
        $message->method('getLanguage')->willReturn($case['language'] ?? 'en');
        $message->method('getDateTime')->willReturn('20260607120000');
        $message->method('getFilePath')->willReturn('');
        $message->method('getTopic')->willReturn($case['topic'] ?? '');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);

        $files = [];
        foreach ($case['files'] ?? [] as $f) {
            $file = $this->createMock(File::class);
            $file->method('getFileType')->willReturn($f['type'] ?? '');
            $file->method('getFileName')->willReturn($f['name'] ?? '');
            $file->method('getFileMime')->willReturn($f['mime'] ?? '');
            $files[] = $file;
        }
        $message->method('getFiles')->willReturn(new ArrayCollection($files));

        return $message;
    }
}
