<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Service\Agent\AgentPinResolver;
use App\Service\Document\DocumentToolsConfig;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessageSorter;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use App\Service\Message\Routing\EmbeddingRouterMatch;
use App\Service\Message\Routing\EmbeddingRouterService;
use App\Service\Message\Routing\NativeToolRoutingConfig;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Runtime\RuntimeProfile;
use App\Service\SelfAware\SelfAwareConfig;
use App\Tests\Characterization\Support\RoutingSnapshot;
use App\Tests\Support\MessageClassifierFactory;
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
 *
 * @phpstan-type RoutingCase array{
 *     id: string,
 *     text: string,
 *     language?: string,
 *     topic?: string,
 *     fastPath?: bool,
 *     files?: list<array{type?: string, name?: string, mime?: string, text?: string}>,
 *     textSource?: string,
 *     meta?: array<string, string>,
 *     modelTag?: string,
 *     sorter?: array<string, mixed>,
 *     config?: array<string, array<string, string>>,
 *     chatProvider?: string,
 *     pin?: array{internet: bool},
 *     embedding?: array{topic: string, score: float},
 *     selfAware?: bool,
 *     documentTools?: bool,
 *     history?: list<array{direction: string, text?: string, filePath?: string}>,
 *     legacyFile?: array{type: string, path: string}
 * }
 */
final class RoutingCharacterizationTest extends TestCase
{
    private const SNAPSHOT_FILE = __DIR__.'/__snapshots__/routing_classification.json';

    /** Model tags a user can pick for Again; `rerank` stands for a tag no rule names. */
    private const OVERRIDE_TAGS = ['text2pic', 'text2vid', 'text2sound', 'pic2text', 'analyze', 'vision', 'document', 'officemaker', 'text2doc', 'chat', 'vectorize', 'rerank'];

    /** The topic already on the message when the override arrives. */
    private const OVERRIDE_TOPICS = ['no_topic' => '', 'custom_topic' => 'legal-review', 'mediamaker' => 'mediamaker'];

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
     * @return list<RoutingCase>
     */
    private function corpus(): array
    {
        return [...self::fixedCorpus(), ...self::modelOverrideCorpus()];
    }

    /**
     * A model override per tag, on a message without a topic, with a custom
     * prompt topic and with `mediamaker`, plus the topics a new message may
     * carry instead of none.
     *
     * @return list<array{id: string, text: string, language: string, topic: string, fastPath: bool, meta: array<string, string>, modelTag: string}>
     */
    private static function modelOverrideCorpus(): array
    {
        $cases = [];
        foreach (self::OVERRIDE_TAGS as $tag) {
            foreach (self::OVERRIDE_TOPICS as $topicId => $topic) {
                $cases[] = ['id' => "override_{$tag}_{$topicId}", 'text' => 'redo that', 'language' => 'en', 'topic' => $topic, 'fastPath' => false, 'meta' => ['MODEL_ID' => '42'], 'modelTag' => $tag];
            }
        }
        foreach (['unknown', 'UNKNOWN', 'CHAT', 'CONTINUE'] as $topic) {
            $cases[] = ['id' => "override_rerank_{$topic}_topic", 'text' => 'redo that', 'language' => 'en', 'topic' => $topic, 'fastPath' => false, 'meta' => ['MODEL_ID' => '42'], 'modelTag' => 'rerank'];
        }

        return $cases;
    }

    /**
     * @return list<RoutingCase>
     */
    private static function fixedCorpus(): array
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
            // /search strips the command and continues through normal classification
            // with force_web_search (issue #2280).
            ['id' => 'cmd_search', 'text' => '/search latest php release', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en']],
            ['id' => 'cmd_lang', 'text' => '/lang de', 'language' => 'en'],
            ['id' => 'cmd_web', 'text' => '/web example.com', 'language' => 'en'],
            ['id' => 'cmd_list', 'text' => '/list', 'language' => 'en'],
            // /docs strips and routes to rag_query (no tools:filesort).
            ['id' => 'cmd_docs', 'text' => '/docs sort my files', 'language' => 'en'],
            ['id' => 'cmd_help', 'text' => '/help', 'language' => 'en'],
            // Bare arg-required commands → usage hint, no model (#2280).
            ['id' => 'cmd_pic_bare', 'text' => '/pic', 'language' => 'en'],
            ['id' => 'cmd_pic_bare_ws', 'text' => '/pic   ', 'language' => 'en'],
            ['id' => 'cmd_pic_bare_at', 'text' => '/pic@TestBot', 'language' => 'en'],
            ['id' => 'cmd_vid_bare', 'text' => '/vid', 'language' => 'en'],
            ['id' => 'cmd_tts_bare', 'text' => '/tts', 'language' => 'en'],
            ['id' => 'cmd_search_bare', 'text' => '/search', 'language' => 'en'],
            ['id' => 'cmd_docs_bare', 'text' => '/docs', 'language' => 'en'],

            // ---- Again overrides (fast-path off, like the existing override tests) ----
            ['id' => 'again_prompt_override', 'text' => 'redo that', 'language' => 'en', 'fastPath' => false, 'meta' => ['PROMPTID' => 'tools:pic']],
            ['id' => 'again_model_override_chat', 'text' => 'redo that', 'language' => 'en', 'fastPath' => false, 'meta' => ['MODEL_ID' => '42'], 'modelTag' => 'chat'],
            ['id' => 'again_model_override_text2pic', 'text' => 'redo that', 'language' => 'en', 'fastPath' => false, 'meta' => ['MODEL_ID' => '77'], 'modelTag' => 'text2pic'],

            // ---- Attachment force-routes (analyzefile) — migration-risk #2 ----
            ['id' => 'attach_pdf', 'text' => 'Summarize this', 'language' => 'en', 'files' => [['type' => 'pdf', 'name' => 'report.pdf']]],
            ['id' => 'attach_docx', 'text' => 'What is in here?', 'language' => 'en', 'files' => [['type' => 'docx', 'name' => 'contract.docx']]],
            ['id' => 'attach_audio_mp3', 'text' => 'Transcribe', 'language' => 'de', 'files' => [['type' => 'mp3', 'name' => 'voice.mp3']]],
            // A caption-less voice note is the user's words (#2287). Same route
            // as the typed question: web search, not file analysis.
            ['id' => 'voice_note_weather', 'text' => 'Wie wird das Wetter heute in Münster?', 'language' => 'de', 'fastPath' => false, 'textSource' => 'transcript', 'files' => [['type' => 'ogg', 'name' => 'voice.ogg', 'text' => 'Wie wird das Wetter heute in Münster?']], 'sorter' => ['topic' => 'general', 'language' => 'de', 'web_search' => true]],
            ['id' => 'attach_merge_pdf', 'text' => 'führe beide dateien in eine pdf zusammen', 'language' => 'de', 'files' => [
                ['type' => 'xlsx', 'name' => 'Finanzmodell.xlsx'],
                ['type' => 'pdf', 'name' => 'Finanzmodell.pdf'],
            ]],
            ['id' => 'attach_export_pdf', 'text' => 'hieraus eine pdf', 'language' => 'de', 'files' => [
                ['type' => 'xlsx', 'name' => 'Finanzmodell.xlsx'],
            ]],
            // Neither of these is a merge: the first is a save_to_folder
            // request, the second plausibly wants one PDF per file. Both stay
            // on analyzefile so the planner can decide.
            ['id' => 'attach_save_pdfs_to_folder', 'text' => 'Speichere die PDFs im Ordner Projekte', 'language' => 'de', 'files' => [
                ['type' => 'pdf', 'name' => 'a.pdf'],
                ['type' => 'pdf', 'name' => 'b.pdf'],
            ]],
            ['id' => 'attach_convert_both_to_pdf', 'text' => 'convert both files to PDF', 'language' => 'en', 'files' => [
                ['type' => 'xlsx', 'name' => 'sheet.xlsx'],
                ['type' => 'docx', 'name' => 'text.docx'],
            ]],

            // ---- Produce-a-file on attachments skips analyzefile (#2047, #2049) ----
            // A chart from an attached table or a stamp on an attached image
            // must reach the sorter even when it votes single-step: the
            // multi_step force sends the turn to the planner, whose rule 3a
            // arbitrates code_run vs file_analysis/image_generation.
            ['id' => 'produce_chart_from_csv', 'text' => 'Mach daraus ein Balkendiagramm nach Region.', 'language' => 'de', 'fastPath' => false, 'files' => [['type' => 'csv', 'name' => 'sales.csv']], 'sorter' => ['topic' => 'general', 'language' => 'de', 'multi_step' => false]],
            ['id' => 'produce_chart_from_csv_en', 'text' => 'Make a bar chart from this table, grouped by region.', 'language' => 'en', 'fastPath' => false, 'files' => [['type' => 'csv', 'name' => 'sales.csv']], 'sorter' => ['topic' => 'general', 'language' => 'en', 'multi_step' => false]],
            ['id' => 'produce_stamp_on_png', 'text' => 'Stempel unten rechts INTERNAL drauf.', 'language' => 'de', 'fastPath' => false, 'files' => [['type' => 'png', 'name' => 'logo.png']], 'sorter' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'image', 'multi_step' => false]],

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

            // ---- Sorter-driven: BMULTI vote (planner skip) ----
            // The vote rides along with the classification and decides whether
            // TaskPlanExecutor pays for a planner round-trip. `false` (one step)
            // must reach the executor untouched; `true` must too.
            ['id' => 'sort_single_step_vote', 'text' => 'write me a short poem about rain', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en', 'multi_step' => false]],
            ['id' => 'sort_multi_step_vote', 'text' => 'write me a poem about rain and read it to me as MP3', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en', 'multi_step' => true]],
            // A single deliverable the chat model cannot produce on its own
            // (calendar invite) also votes true — those capabilities only exist
            // in the DAG, so skipping the planner would silently degrade them
            // into a chat answer that merely talks about the appointment.
            ['id' => 'sort_calendar_needs_planning', 'text' => 'set up a meeting tomorrow at 15:00 with Tom', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en', 'multi_step' => true]],
            // A sorter that never voted (older seeded prompt) stays null so the
            // planner keeps deciding — this is the safe fallback.
            ['id' => 'sort_no_step_vote', 'text' => 'tell me about the weather on mars', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en']],

            // ---- Phase M acceptance utterances (plan 10 §1) — VERBATIM ----
            // These four sentences are the product acceptance bar for the
            // chat-actions phase. They are quoted word for word so any change
            // to how the classifier treats them is an explicit, reviewed diff.
            // All four are multi-deliverable requests: the sorter votes
            // multi_step=true and the planner builds the DAG.
            ['id' => 'u1_outlook_calendar_write', 'text' => "Create a meeting reminder for tomorrow at 10am for 'Marketing Strategy' and put it into my Outlook", 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en', 'multi_step' => true]],
            ['id' => 'u2_mail_me_the_invite', 'text' => "Create a meeting reminder for tomorrow at 10am for 'Marketing Strategy' and mail the calendar entry to me", 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en', 'multi_step' => true]],
            ['id' => 'u3_mail_search_summarize', 'text' => 'What is the latest mail of Oliver Braun regarding FPSenergy, summarize that for me', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'en', 'multi_step' => true]],
            ['id' => 'u4_document_to_target', 'text' => 'Create a marketing plan document with a solid TOC for my company and put it into: Nextcloud', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'officemaker', 'language' => 'en', 'multi_step' => true]],

            // ---- Sorter-driven: CUSTOM user topic — migration-risk #1 ----
            // A user-authored BPROMPTS topic (BOWNERID>0) is NOT in the canonical
            // map, so today it falls back to the chat intent. This baseline proves
            // the planner adapter must carry the topic/prompt id to preserve it.
            // ---- Every seam of the cascade, and where two steps compete (V4 S6) ----
            // The agent pin: after the slash commands, before everything else.
            ['id' => 'pin_chat', 'text' => 'Where is my order?', 'language' => 'en', 'pin' => ['internet' => false]],
            ['id' => 'pin_asks_the_sorter_only_for_the_web', 'text' => 'What does the new tariff cost?', 'language' => 'en', 'pin' => ['internet' => true], 'sorter' => ['topic' => 'general', 'language' => 'en', 'web_search' => true, 'read_pages' => 2]],
            ['id' => 'pin_bare_pic_gets_the_usage_hint', 'text' => '/pic', 'language' => 'en', 'pin' => ['internet' => true]],
            ['id' => 'pin_search_keeps_the_pin_and_forces_search', 'text' => '/search weather in Bonn', 'language' => 'en', 'pin' => ['internet' => true]],
            ['id' => 'pin_docs_keeps_the_pin_and_queries_the_knowledge', 'text' => '/docs vacation policy', 'language' => 'en', 'pin' => ['internet' => false]],
            // The fast path, switched on.
            ['id' => 'fastpath_on_plain_chat', 'text' => 'Hello, how are you today?', 'language' => 'en', 'config' => ['CLASSIFIER' => ['FAST_PATH_ENABLED' => '1']]],
            ['id' => 'fastpath_on_search_command', 'text' => '/search php', 'language' => 'en', 'config' => ['CLASSIFIER' => ['FAST_PATH_ENABLED' => '1']]],
            ['id' => 'fastpath_on_uses_the_message_language', 'text' => 'ok', 'language' => 'de', 'config' => ['CLASSIFIER' => ['FAST_PATH_ENABLED' => '1']]],
            ['id' => 'fastpath_on_declines_an_unknown_language', 'text' => 'ok', 'language' => 'NN', 'config' => ['CLASSIFIER' => ['FAST_PATH_ENABLED' => '1']], 'sorter' => ['topic' => 'general', 'language' => 'de']],
            ['id' => 'fastpath_on_declines_media_words', 'text' => 'zeichne eine katze', 'language' => 'de', 'config' => ['CLASSIFIER' => ['FAST_PATH_ENABLED' => '1']], 'sorter' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'image']],
            ['id' => 'fastpath_on_declines_after_a_generated_file', 'text' => 'mach den Titel fett', 'language' => 'de', 'config' => ['CLASSIFIER' => ['FAST_PATH_ENABLED' => '1']], 'history' => [['direction' => 'IN', 'text' => 'schreib einen Bericht als docx'], ['direction' => 'OUT', 'text' => '__FILE_GENERATED__:bericht.docx']], 'sorter' => ['topic' => 'officemaker', 'language' => 'de']],
            ['id' => 'fastpath_on_declines_after_generated_media', 'text' => 'mach es blau', 'language' => 'de', 'config' => ['CLASSIFIER' => ['FAST_PATH_ENABLED' => '1']], 'history' => [['direction' => 'OUT', 'text' => '__IMAGE_GENERATED__', 'filePath' => '7/2026/10/1001_openai.png']], 'sorter' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'image', 'input_mode' => 'reference_images']],
            ['id' => 'fastpath_on_declines_a_later_document_edit', 'text' => 'ändere den Titel in der Datei', 'language' => 'de', 'config' => ['CLASSIFIER' => ['FAST_PATH_ENABLED' => '1']], 'history' => [['direction' => 'OUT', 'text' => '__FILE_GENERATED__:bericht.docx'], ['direction' => 'IN', 'text' => 'danke'], ['direction' => 'OUT', 'text' => 'Gern geschehen!']], 'sorter' => ['topic' => 'officemaker', 'language' => 'de']],
            ['id' => 'fastpath_on_wins_over_an_again_override', 'text' => 'Hello, how are you today?', 'language' => 'en', 'config' => ['CLASSIFIER' => ['FAST_PATH_ENABLED' => '1']], 'meta' => ['MODEL_ID' => '77'], 'modelTag' => 'text2pic'],
            // Again: a prompt with a model, and the sorter's own prompt id.
            ['id' => 'again_prompt_and_model_override', 'text' => 'redo that', 'language' => 'en', 'fastPath' => false, 'meta' => ['PROMPTID' => 'legal-review', 'MODEL_ID' => '42'], 'modelTag' => 'chat'],
            ['id' => 'again_sort_prompt_is_no_override', 'text' => 'redo that', 'language' => 'en', 'fastPath' => false, 'meta' => ['PROMPTID' => 'tools:sort'], 'sorter' => ['topic' => 'general', 'language' => 'en']],
            // Attachments: each kind, and a slash command on an attachment.
            ['id' => 'attach_video', 'text' => 'What happens here?', 'language' => 'en', 'files' => [['type' => 'mp4', 'name' => 'clip.mp4']]],
            ['id' => 'attach_image_goes_to_the_sorter', 'text' => 'What is on this photo?', 'language' => 'en', 'fastPath' => false, 'files' => [['type' => 'jpg', 'name' => 'photo.jpg']], 'sorter' => ['topic' => 'general', 'language' => 'en']],
            ['id' => 'attach_legacy_pdf', 'text' => 'Summarize this', 'language' => 'en', 'legacyFile' => ['type' => 'pdf', 'path' => '7/report.pdf']],
            ['id' => 'attach_office_edit', 'text' => 'change the title to Q3 report', 'language' => 'en', 'documentTools' => true, 'config' => ['DOCUMENT_TOOLS' => ['ENABLED' => '1', 'ALLOW_UPLOAD_EDIT' => '1']], 'files' => [['type' => 'docx', 'name' => 'report.docx']]],
            ['id' => 'attach_image_code_execution_forces_the_planner', 'text' => 'run python to count the red pixels', 'language' => 'en', 'fastPath' => false, 'files' => [['type' => 'png', 'name' => 'chart.png']], 'sorter' => ['topic' => 'general', 'language' => 'en', 'multi_step' => false]],
            ['id' => 'attach_with_a_slash_command', 'text' => '/pic a cat like this', 'language' => 'en', 'files' => [['type' => 'pdf', 'name' => 'report.pdf']]],
            // A spoken answer needs the planner whatever the sorter votes.
            ['id' => 'spoken_output_forces_the_planner', 'text' => 'Mach mir eine Audiodatei, in der du die Relativitätstheorie erklärst', 'language' => 'de', 'fastPath' => false, 'sorter' => ['topic' => 'general', 'language' => 'de', 'multi_step' => false]],
            // The embedding router (Phase 8).
            ['id' => 'embedding_match', 'text' => 'zeichne mir eine katze', 'language' => 'de', 'fastPath' => false, 'config' => ['EMBEDDING_ROUTER' => ['ENABLED' => '1']], 'embedding' => ['topic' => 'mediamaker', 'score' => 0.93]],
            ['id' => 'embedding_match_declined_for_an_unknown_language', 'text' => 'katze bild', 'language' => 'NN', 'fastPath' => false, 'config' => ['EMBEDDING_ROUTER' => ['ENABLED' => '1'], 'NATIVE_TOOL_ROUTING' => ['ENABLED' => '1']], 'chatProvider' => 'anthropic', 'embedding' => ['topic' => 'mediamaker', 'score' => 0.93], 'sorter' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'image']],
            ['id' => 'embedding_below_the_threshold', 'text' => 'tell me about cats', 'language' => 'en', 'fastPath' => false, 'config' => ['EMBEDDING_ROUTER' => ['ENABLED' => '1']], 'embedding' => ['topic' => 'mediamaker', 'score' => 0.5], 'sorter' => ['topic' => 'general', 'language' => 'en']],
            ['id' => 'self_aware_question_skips_the_embedding_router', 'text' => 'what can you do for me?', 'language' => 'en', 'fastPath' => false, 'selfAware' => true, 'config' => ['EMBEDDING_ROUTER' => ['ENABLED' => '1']], 'embedding' => ['topic' => 'general', 'score' => 0.95], 'sorter' => ['topic' => 'synaplan', 'language' => 'en']],
            // The deferral to the answering call (Phase 9).
            ['id' => 'deferral_to_the_answering_call', 'text' => 'tell me about the history of Rome', 'language' => 'en', 'fastPath' => false, 'config' => ['NATIVE_TOOL_ROUTING' => ['ENABLED' => '1']], 'chatProvider' => 'anthropic'],
            ['id' => 'deferral_needs_a_tool_calling_provider', 'text' => 'tell me about the history of Rome', 'language' => 'en', 'fastPath' => false, 'config' => ['NATIVE_TOOL_ROUTING' => ['ENABLED' => '1']], 'chatProvider' => 'not-a-tool-caller', 'sorter' => ['topic' => 'general', 'language' => 'en']],
            ['id' => 'produce_a_file_skips_the_embedding_router_and_the_deferral', 'text' => 'Make a bar chart from this table, grouped by region.', 'language' => 'en', 'fastPath' => false, 'config' => ['EMBEDDING_ROUTER' => ['ENABLED' => '1'], 'NATIVE_TOOL_ROUTING' => ['ENABLED' => '1']], 'chatProvider' => 'anthropic', 'embedding' => ['topic' => 'general', 'score' => 0.95], 'files' => [['type' => 'csv', 'name' => 'sales.csv']], 'sorter' => ['topic' => 'general', 'language' => 'en', 'multi_step' => false]],

            ['id' => 'sort_custom_topic', 'text' => 'review this contract clause', 'language' => 'en', 'fastPath' => false, 'sorter' => ['topic' => 'legal-review', 'language' => 'en']],
        ];
    }

    /**
     * @param RoutingCase $case
     *
     * @return array<string, mixed>
     */
    private function classifyCase(array $case): array
    {
        $fastPath = $case['fastPath'] ?? true;
        $config = $case['config'] ?? [];

        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use ($fastPath, $config): ?string {
                if (isset($config[$group][$setting])) {
                    return $config[$group][$setting];
                }
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

        $modelConfig = $this->createMock(ModelConfigService::class);
        if (isset($case['chatProvider'])) {
            $modelConfig->method('getDefaultProvider')->willReturn($case['chatProvider']);
        }
        $em = $this->createMock(EntityManagerInterface::class);
        if (isset($case['modelTag'])) {
            $modelConfig->method('getModelTag')->willReturn($case['modelTag']);
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

        // The attachment merge/export layer only routes when the multi-task
        // engine can execute it and the office engine can convert (#1694), so
        // both are on here — otherwise those corpus cases would lock the
        // fallback instead of the contract.
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);

        $agentPin = $this->createMock(AgentPinResolver::class);
        if (isset($case['pin'])) {
            $agentPin->method('resolve')->willReturn(new RuntimeProfile(7, 'support-desk', null, ['chat' => 11], [], ['tool_internet' => $case['pin']['internet']], null, null, [], 3, 4));
        }

        $embeddingRouter = $this->createMock(EmbeddingRouterService::class);
        if (isset($case['embedding'])) {
            $embeddingRouter->method('findClosestAnchor')->willReturn(new EmbeddingRouterMatch($case['embedding']['topic'], $case['embedding']['score'], [['topic' => 'general', 'score' => 0.41]]));
        }

        $classifier = MessageClassifierFactory::build([
            'messageSorter' => $sorter,
            'messageMetaRepository' => $metaRepo,
            'modelConfigService' => $modelConfig,
            'configRepository' => $configRepo,
            'em' => $em,
            'logger' => $this->createMock(LoggerInterface::class),
            'topicIntents' => new TopicIntentRule(new SystemCapabilityRegistry()),
            'embeddingRouter' => $embeddingRouter,
            'embeddingRouterConfig' => new EmbeddingRouterConfig($configRepo),
            'nativeToolRoutingConfig' => new NativeToolRoutingConfig($configRepo),
            'toolCallingCapability' => new ToolCallingCapability(),
            'agentPin' => $agentPin,
            'selfAwareConfig' => isset($case['selfAware']) ? new SelfAwareConfig($configRepo) : null,
            'officeConverter' => $converter,
            'documentToolsConfig' => isset($case['documentTools']) ? new DocumentToolsConfig($configRepo) : null,
            'multitaskConfig' => new MultitaskRoutingConfig($configRepo),
        ]);

        $history = array_map(function (array $turn): Message {
            $earlier = $this->createMock(Message::class);
            $earlier->method('getDirection')->willReturn($turn['direction']);
            $earlier->method('getText')->willReturn($turn['text'] ?? '');
            $earlier->method('getFilePath')->willReturn($turn['filePath'] ?? '');

            return $earlier;
        }, $case['history'] ?? []);

        // A pinned turn carries its RuntimeProfile object; the snapshot compares
        // plain arrays, so the classification is handed over as JSON data.
        return json_decode(json_encode($classifier->classify($this->buildMessage($case), $history), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param RoutingCase $case
     */
    private function buildMessage(array $case): Message
    {
        $text = $case['text'];
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(1);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturnCallback(static function () use (&$text): string {
            return $text;
        });
        $message->method('setText')->willReturnCallback(static function (string $value) use (&$text, $message): Message {
            $text = $value;

            return $message;
        });
        $message->method('getLanguage')->willReturn($case['language'] ?? 'en');
        $message->method('getDateTime')->willReturn('20260607120000');
        $message->method('getFilePath')->willReturn($case['legacyFile']['path'] ?? '');
        $message->method('getFileType')->willReturn($case['legacyFile']['type'] ?? '');
        $message->method('getTopic')->willReturn($case['topic'] ?? '');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(isset($case['legacyFile']) ? 5 : 0);
        $textSource = $case['textSource'] ?? null;
        $message->method('getMeta')->willReturnCallback(
            static function (string $key, ?string $default = null) use ($textSource): ?string {
                if ('text_source' === $key && is_string($textSource)) {
                    return $textSource;
                }

                return $default;
            }
        );

        $files = [];
        foreach ($case['files'] ?? [] as $f) {
            $file = $this->createMock(File::class);
            $file->method('getFileType')->willReturn($f['type'] ?? '');
            $file->method('getFileName')->willReturn($f['name'] ?? '');
            $file->method('getFileMime')->willReturn($f['mime'] ?? '');
            $file->method('getFileText')->willReturn($f['text'] ?? '');
            $files[] = $file;
        }
        $message->method('getFiles')->willReturn(new ArrayCollection($files));

        return $message;
    }
}
