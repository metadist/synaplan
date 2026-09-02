<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\ToolCalling;

use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallingTranslator;
use App\AI\ToolCalling\ToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ToolCallingTranslatorTest extends TestCase
{
    private ToolCallingTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new ToolCallingTranslator(new ToolCallingCapability());
    }

    private function tool(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'handoff_mediamaker',
            description: 'Generate an image, video or audio clip.',
            parameters: [
                'type' => 'object',
                'properties' => ['media_type' => ['type' => 'string', 'enum' => ['image', 'video', 'audio']]],
                'required' => [],
            ],
        );
    }

    public function testOpenAiDialectWrapsEachToolInAFunctionEnvelope(): void
    {
        $params = $this->translator->translate('groq', 'openai/gpt-oss-120b', false, [$this->tool()]);

        self::assertSame([
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'handoff_mediamaker',
                    'description' => 'Generate an image, video or audio clip.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['media_type' => ['type' => 'string', 'enum' => ['image', 'video', 'audio']]],
                        'required' => [],
                    ],
                ],
            ]],
            'tool_choice' => 'auto',
        ], $params);
    }

    public function testAnthropicDialectUsesInputSchemaAndAnObjectToolChoice(): void
    {
        $params = $this->translator->translate('anthropic', 'claude-sonnet-5', false, [$this->tool()]);

        self::assertSame('handoff_mediamaker', $params['tools'][0]['name']);
        self::assertArrayHasKey('input_schema', $params['tools'][0]);
        self::assertArrayNotHasKey('parameters', $params['tools'][0]);
        self::assertSame(['type' => 'auto'], $params['tool_choice']);
    }

    /**
     * `auto`, never `required`: "called nothing" is the answer that means
     * "ordinary chat turn", so forcing a call would invert the default.
     */
    public function testToolChoiceIsNeverForced(): void
    {
        self::assertSame('auto', $this->translator->translate('groq', 'm', false, [$this->tool()])['tool_choice']);
        self::assertSame(['type' => 'auto'], $this->translator->translate('anthropic', 'm', false, [$this->tool()])['tool_choice']);
    }

    public function testUnsupportedProviderYieldsNoRequestParametersInsteadOfFailing(): void
    {
        self::assertSame([], $this->translator->translate('openai', 'gpt-5', false, [$this->tool()]));
        self::assertSame([], $this->translator->translate('triton', null, false, [$this->tool()]));
    }

    public function testNoToolsYieldsNoRequestParameters(): void
    {
        self::assertSame([], $this->translator->translate('groq', 'openai/gpt-oss-120b', false, []));
    }

    /**
     * Groq 400s on the combination; Anthropic expresses structured output AS a
     * forced tool call, so merging both would overwrite the schema's
     * `tools`/`tool_choice`. Either way the schema is the caller's output
     * contract and wins.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function conflictingProviderProvider(): iterable
    {
        yield 'groq rejects the combination outright' => ['groq', 'openai/gpt-oss-120b'];
        yield 'anthropic would have its forced schema tool overwritten' => ['anthropic', 'claude-sonnet-5'];
    }

    #[DataProvider('conflictingProviderProvider')]
    public function testToolsAreDroppedWhenTheSameRequestCarriesASchema(string $provider, string $model): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $translator = new ToolCallingTranslator(new ToolCallingCapability(), $logger);

        self::assertSame(
            [],
            $translator->translate($provider, $model, false, [$this->tool()], withStructuredOutput: true),
        );
        self::assertCount(1, $logger->warnings, 'dropping a tool declaration must not be silent');
    }

    /**
     * The guard must key off the schema actually being present, not off the
     * provider — a plain tool request on the same provider is the routing
     * path's normal case and stays untouched.
     */
    #[DataProvider('conflictingProviderProvider')]
    public function testToolsSurviveWhenNoSchemaTravelsWithThem(string $provider, string $model): void
    {
        $params = $this->translator->translate($provider, $model, false, [$this->tool()], withStructuredOutput: false);

        self::assertCount(1, $params['tools']);
        self::assertStringContainsString('handoff_mediamaker', (string) json_encode($params['tools']));
    }
}
