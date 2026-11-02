<?php

declare(strict_types=1);

namespace App\Tests\Support\AgentTemplate;

use App\AI\Provider\TestProvider;
use App\Service\AgentTemplate\AgentTemplate;

/**
 * Runs one acceptance file: every try-it sentence is sent to TestProvider
 * and to the prompt-faithful stand-in. Failures are readable strings.
 */
final class AgentTemplateAcceptanceRunner
{
    private const ACCEPTANCE_KEYS = ['id', 'version', 'mustMention', 'cases', 'toolsOffered', 'toolsNotOffered'];

    private const CASE_KEYS = ['utterance', 'knowledge', 'expects'];

    private const EXPECT_KEYS = ['answerContains', 'answerOmits', 'toolCalled', 'approvalRequested'];

    public function __construct(
        private readonly AgentTemplateAcceptanceProvider $provider,
        private readonly TestProvider $models,
    ) {
    }

    /**
     * @return list<string>
     */
    public function run(AgentTemplate $template, mixed $acceptance): array
    {
        if (!is_array($acceptance)) {
            return [$template->id.': acceptance file is not a map'];
        }

        $shape = $this->shape($template, $acceptance);
        if ([] !== $shape) {
            return $shape;
        }

        /** @var list<array<string, mixed>> $cases */
        $cases = $acceptance['cases'];
        $failures = array_merge(
            $this->tools($template, $acceptance),
            $this->coverage($template, $cases),
        );
        foreach ($cases as $case) {
            $failures = array_merge($failures, $this->case($template, $case));
        }

        return $failures;
    }

    /**
     * @param array<mixed> $acceptance
     *
     * @return list<string>
     */
    private function shape(AgentTemplate $template, array $acceptance): array
    {
        $id = $template->id;
        $unknown = array_diff(array_keys($acceptance), self::ACCEPTANCE_KEYS);
        if ([] !== $unknown) {
            return [$id.': unknown acceptance key '.implode(', ', $unknown)];
        }
        if (($acceptance['id'] ?? null) !== $id || ($acceptance['version'] ?? null) !== $template->version) {
            return [$id.': id or version does not match the template'];
        }
        if (!is_array($acceptance['cases'] ?? null) || [] === $acceptance['cases']) {
            return [$id.': needs at least one case'];
        }

        $failures = [];
        foreach ($acceptance['cases'] as $index => $case) {
            if (!is_array($case)) {
                $failures[] = $id.': case '.$index.' is not a map';
                continue;
            }
            $failures = array_merge($failures, $this->caseShape($id, $index, $case));
        }

        return $failures;
    }

    /**
     * @param array<mixed> $case
     *
     * @return list<string>
     */
    private function caseShape(string $id, int|string $index, array $case): array
    {
        $where = $id.': case '.$index;
        $unknown = array_diff(array_keys($case), self::CASE_KEYS);
        if ([] !== $unknown) {
            return [$where.' has unknown key '.implode(', ', $unknown)];
        }
        if (!is_string($case['utterance'] ?? null) || '' === trim($case['utterance'])) {
            return [$where.' needs an utterance'];
        }
        if (isset($case['knowledge']) && !is_string($case['knowledge'])) {
            return [$where.' knowledge must be text'];
        }
        $expects = $case['expects'] ?? null;
        if (!is_array($expects)) {
            return [$where.' needs expects'];
        }
        $extra = array_diff(array_keys($expects), self::EXPECT_KEYS);
        if ([] !== $extra) {
            return [$where.' has unknown expects key '.implode(', ', $extra)];
        }
        $contains = $expects['answerContains'] ?? null;
        if (!$this->stringList($contains) || [] === $contains) {
            return [$where.' needs answerContains'];
        }
        if (isset($expects['answerOmits']) && !$this->stringList($expects['answerOmits'])) {
            return [$where.' answerOmits must be a list of sentences'];
        }
        if (array_key_exists('toolCalled', $expects) && null !== $expects['toolCalled'] && !is_string($expects['toolCalled'])) {
            return [$where.' toolCalled must be a name or empty'];
        }
        if (isset($expects['approvalRequested']) && !is_bool($expects['approvalRequested'])) {
            return [$where.' approvalRequested must be true or false'];
        }

        return [];
    }

    /**
     * @param array<mixed> $acceptance
     *
     * @return list<string>
     */
    private function tools(AgentTemplate $template, array $acceptance): array
    {
        $offered = $acceptance['toolsOffered'] ?? null;
        $withheld = $acceptance['toolsNotOffered'] ?? null;
        if (!$this->stringList($offered) || !$this->stringList($withheld)) {
            return [$template->id.': toolsOffered and toolsNotOffered must list files and internet'];
        }
        $union = array_merge($offered, $withheld);
        sort($union);
        $actual = $this->offered($template);
        $declared = $offered;
        sort($declared);
        if (['files', 'internet'] !== $union || $declared !== $actual || [] !== array_intersect($offered, $withheld)) {
            return [$template->id.': tool flags do not match the template'];
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $cases
     *
     * @return list<string>
     */
    private function coverage(AgentTemplate $template, array $cases): array
    {
        $starters = $this->starters($template);
        if ([] === $starters) {
            return [$template->id.': template has no try-it sentence'];
        }
        $utterances = [];
        $hasBare = false;
        foreach ($cases as $case) {
            $utterances[] = $case['utterance'];
            $knowledge = $case['knowledge'] ?? null;
            $hasBare = $hasBare || !is_string($knowledge) || '' === trim($knowledge);
        }
        $failures = [];
        foreach ($starters as $starter) {
            if (!in_array($starter, $utterances, true)) {
                $failures[] = $template->id.': no case for "'.$starter.'"';
            }
        }
        foreach ($utterances as $utterance) {
            if (!in_array($utterance, $starters, true)) {
                $failures[] = $template->id.': "'.$utterance.'" is not a try-it sentence';
            }
        }
        if ($this->hasMissingRule($template->body->instruction) && !$hasBare) {
            $failures[] = $template->id.': needs a case where the source is missing';
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return list<string>
     */
    private function case(AgentTemplate $template, array $case): array
    {
        /** @var array<string, mixed> $expects */
        $expects = $case['expects'];
        $utterance = (string) $case['utterance'];
        $knowledge = isset($case['knowledge']) && is_string($case['knowledge']) ? $case['knowledge'] : null;
        $messages = [
            ['role' => 'system', 'content' => $template->body->instruction],
            ['role' => 'user', 'content' => $this->provider->attach($utterance, $knowledge)],
        ];
        $where = $template->id.': "'.$utterance.'"';
        $failures = $this->demo($where, $messages, $utterance, $expects, $this->offered($template));
        $answer = $this->provider->chat($messages)['content'];
        $contains = $expects['answerContains'] ?? null;
        if (!is_array($contains)) {
            return array_merge($failures, [$where.' needs answerContains']);
        }
        foreach ($contains as $needle) {
            if (!is_string($needle) || !str_contains($answer, $needle)) {
                $failures[] = $where.' answer is missing "'.$needle.'"';
            }
        }
        foreach ($expects['answerOmits'] ?? [] as $needle) {
            if (is_string($needle) && str_contains($answer, $needle)) {
                $failures[] = $where.' answer must not contain "'.$needle.'"';
            }
        }
        $approval = true === ($expects['approvalRequested'] ?? false);
        $asked = str_contains($answer, 'Approval requested.');
        if ($approval !== $asked) {
            $failures[] = $where.' approval requested is '.($asked ? 'true' : 'false');
        }

        return $failures;
    }

    /**
     * Sends the same turn to TestProvider with the template's tool flags.
     * A normal try-it sentence must come back as text, not a tool call.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>                       $expects
     * @param list<string>                               $offered
     *
     * @return list<string>
     */
    private function demo(string $where, array $messages, string $utterance, array $expects, array $offered): array
    {
        $tools = [];
        foreach ($offered as $name) {
            $tools[] = ['type' => 'function', 'function' => ['name' => $name]];
        }
        $reply = $this->models->chat($messages, ['tools' => $tools]);
        $failures = [];
        if (!str_contains(strtolower($reply['content']), strtolower($utterance))) {
            $failures[] = $where.' was not echoed by the demo provider';
        }
        $called = $this->calledTool($reply);
        $expected = $expects['toolCalled'] ?? null;
        if ($called !== $expected) {
            $failures[] = $where.' tool called is '.var_export($called, true);
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $reply
     */
    private function calledTool(array $reply): ?string
    {
        $calls = $reply['tool_calls'] ?? null;
        if (!is_array($calls) || [] === $calls) {
            return null;
        }
        $first = $calls[0];
        if (!is_array($first)) {
            return null;
        }
        $name = $first['function']['name'] ?? $first['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * @return list<string>
     */
    private function starters(AgentTemplate $template): array
    {
        $behaviour = $template->body->definition['behaviour'] ?? null;
        $prompts = is_array($behaviour) ? ($behaviour['starterPrompts'] ?? null) : null;
        if (!is_array($prompts)) {
            return [];
        }
        $starters = [];
        foreach ($prompts as $prompt) {
            if (is_string($prompt) && '' !== trim($prompt)) {
                $starters[] = $prompt;
            }
        }

        return $starters;
    }

    /**
     * @return list<string>
     */
    private function offered(AgentTemplate $template): array
    {
        $flags = $template->body->definition['tools'] ?? null;
        if (!is_array($flags)) {
            return [];
        }
        $names = [];
        foreach (['files', 'internet'] as $name) {
            if (true === ($flags[$name] ?? null)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function hasMissingRule(string $instruction): bool
    {
        return 'I do not have the source.' !== $this->provider->whenSourceIsMissing($instruction);
    }

    private function stringList(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || '' === trim($item)) {
                return false;
            }
        }

        return true;
    }
}
