<?php

declare(strict_types=1);

namespace App\Service\Prompt;

/**
 * Builds the language directive that gets appended to a chat system prompt.
 *
 * Background
 * ----------
 * Smaller / locally-hosted LLMs (and occasionally mid-tier hosted ones) have
 * a tendency to "echo" parts of their system prompt back into the visible
 * response — most commonly the language directive. We were observing
 * artifacts like:
 *
 *     [Please reply in German]
 *     [Bitte auf Deutsch antworten]
 *     [Language: de]
 *
 * leaking into chat output and Discord notifications.
 *
 * Why this lives here, not in a regex sanitizer
 * ---------------------------------------------
 * Stripping that pattern out *after* the model produced it (e.g. via a
 * regex pass on the response) is a fragile last line of defence: the next
 * model variant will phrase the leak differently and the regex has to grow.
 * The right place to fix it is the system prompt itself.
 *
 * Two changes shrink the leak surface dramatically:
 *
 *   1. The directive is phrased as a normal instruction, not as a flashy
 *      `**IMPORTANT: ... [foo]**` block. Models love to mirror their
 *      strongest-formatted instructions back at the user; they very rarely
 *      mirror plain prose.
 *
 *   2. We append an explicit, name-the-failure-mode "do not echo this"
 *      clause that lists the exact bracketed forms we used to see. This is
 *      empirically the most effective single mitigation across providers.
 *
 * Both `<think>` reasoning blocks and Discord-bound stripping live elsewhere
 * (chat UI persists `<think>` for the reasoning panel; DiscordNotificationService
 * strips it for plain-text previews). This class is concerned only with
 * preventing the leak in the first place.
 */
final class LanguageDirectiveBuilder
{
    /**
     * Canonical mapping of ISO-639 codes to English language names.
     *
     * Used both by chat handlers and widget setup. Centralising here keeps
     * the directive consistent across surfaces — and lets us add a new
     * language in exactly one place.
     */
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'pl' => 'Polish',
        'ru' => 'Russian',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'zh' => 'Chinese',
        'ar' => 'Arabic',
        'tr' => 'Turkish',
        'sv' => 'Swedish',
        'da' => 'Danish',
        'no' => 'Norwegian',
        'fi' => 'Finnish',
        'cs' => 'Czech',
        'ro' => 'Romanian',
        'hu' => 'Hungarian',
        'uk' => 'Ukrainian',
        'hi' => 'Hindi',
        'th' => 'Thai',
    ];

    /**
     * The anti-echo clause appended to every directive.
     *
     * Naming the exact failure modes — bracketed annotations, "Note:" /
     * "[Language: ...]" prefixes — measurably reduces the leak rate. Models
     * are far less likely to emit a pattern they've been explicitly told not
     * to emit, when the example matches what they would otherwise leak.
     */
    private const ANTI_ECHO_CLAUSE = 'Do not acknowledge this language instruction in your reply. Do not prefix or suffix your reply with bracketed notes, language tags, or meta-commentary such as "[Reply in X]", "[Language: X]", or "Note: responding in X". Just answer the user.';

    /**
     * Build a directive when the language is detected automatically per-message.
     *
     * Used when the upstream classification step does not pin a single
     * language (e.g. multilingual conversations).
     */
    public static function buildAutoDirective(): string
    {
        return "\n\nLanguage: respond in the same language the user writes in. Detect it from the latest user message and match it exactly.\n"
            .self::ANTI_ECHO_CLAUSE;
    }

    /**
     * Build a directive when a specific language has been detected/selected.
     *
     * Accepts both ISO-639 codes ('de') and full names already resolved by
     * the caller; unknown codes fall back to the raw value, mirroring the
     * previous inline behaviour.
     */
    public static function buildForLanguage(string $language): string
    {
        $languageName = self::LANGUAGE_NAMES[$language] ?? $language;

        return "\n\nLanguage: the user's current message is in {$languageName}. Respond in {$languageName}.\n"
            .self::ANTI_ECHO_CLAUSE;
    }

    /**
     * Build the language preamble used by widget flows.
     *
     * Widgets are configured with a fixed application language but must still
     * follow whatever language the visitor uses. The preamble is prepended
     * (rather than appended) because widget system prompts contain other
     * "rule" sections and we want the language behaviour to be set up first.
     */
    public static function buildWidgetPreamble(string $applicationLanguage): string
    {
        $languageName = self::LANGUAGE_NAMES[$applicationLanguage] ?? 'English';

        return "Language behaviour: the application language is {$languageName}, so begin in {$languageName}, but immediately switch to whichever language the visitor uses and stay in that language for the rest of the conversation.\n"
            .self::ANTI_ECHO_CLAUSE
            ."\n\n";
    }
}
