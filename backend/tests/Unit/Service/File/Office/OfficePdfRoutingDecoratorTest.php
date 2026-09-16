<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Office;

use App\Service\File\Office\OfficeConverterClient;
use App\Service\File\Office\OfficePdfRoutingDecorator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class OfficePdfRoutingDecoratorTest extends TestCase
{
    public function testDisabledLeavesTopicsAndPromptUnchanged(): void
    {
        $decorator = new OfficePdfRoutingDecorator($this->converter(''));
        $topics = [
            ['topic' => 'officemaker', 'description' => 'Not for any other format.'],
        ];
        $prompt = 'Real PDFs are NOT supported — say so in a single `chat` node.';

        self::assertFalse($decorator->isEnabled());
        self::assertSame($topics, $decorator->decorateTopics($topics));
        self::assertSame($prompt, $decorator->decoratePrompt($prompt));
    }

    public function testExplicitDisabledUrlLeavesTopicsAndPromptUnchanged(): void
    {
        $decorator = new OfficePdfRoutingDecorator($this->converter('disabled'));
        $topics = [
            ['topic' => 'officemaker', 'description' => 'Not for any other format.'],
        ];
        $prompt = 'Real PDFs are NOT supported — say so in a single `chat` node.';

        self::assertFalse($decorator->isEnabled());
        self::assertSame($topics, $decorator->decorateTopics($topics));
        self::assertSame($prompt, $decorator->decoratePrompt($prompt));
    }

    public function testEnabledRewritesOfficemakerDescriptionAndPlannerRules(): void
    {
        $decorator = new OfficePdfRoutingDecorator($this->converter('http://collabora:9980'));
        $topics = [
            ['topic' => 'general', 'description' => 'catch-all'],
            ['topic' => 'officemaker', 'description' => 'Not for any other format.'],
        ];

        $decorated = $decorator->decorateTopics($topics);
        self::assertTrue($decorator->isEnabled());
        self::assertSame('catch-all', $decorated[0]['description']);
        self::assertSame(OfficePdfRoutingDecorator::officeMakerDescription(), $decorated[1]['description']);
        self::assertStringContainsString('Erstelle mir ein PDF', $decorated[1]['description']);

        $prompt = <<<'PROMPT'
4. NEVER invent file paths. The ONLY way a file reaches the user is as the `file` output
   of a generator node (text2sound, image_generation, video_generation,
   document_generation, calendar_event), surfaced through `compose_reply`.
5. If the user asks for output the capability list cannot produce (e.g. a real
   PDF, a phone call), use a single `chat` node and tell them plainly what is
   not possible. Do NOT pretend.
5. Office document (XLSX, DOCX, PPTX, CSV) → `document_generation` (NOT
   chat). Real PDFs are NOT supported — say so in a single `chat` node.
PROMPT;

        $out = $decorator->decoratePrompt($prompt);
        self::assertStringNotContainsString('Real PDFs are NOT supported', $out);
        self::assertStringContainsString('Creating a real PDF is supported', $out);
        // #1691: an existing file becomes a PDF through document_export, never by re-authoring it.
        self::assertStringContainsString('single `document_export` node — NOT extract_text + document_generation', $out);
        self::assertStringContainsString('document_generation, document_export, document_combine, calendar_event), surfaced through `compose_reply`.', $out);
        self::assertStringContainsString('single `document_combine` node', $out);
        self::assertStringContainsString('Office document (XLSX, DOCX, PPTX, CSV, PDF)', $out);
        self::assertStringNotContainsString('e.g. a real', $out);
        self::assertStringContainsString('OFFICE_PDF_ROUTING', $out);
        self::assertStringContainsString('set BTOPIC to "officemaker"', $out);

        $again = $decorator->decoratePrompt($out);
        self::assertSame(1, substr_count($again, 'OFFICE_PDF_ROUTING'));
    }

    private function converter(string $url): OfficeConverterClient
    {
        return new OfficeConverterClient(new MockHttpClient(), new NullLogger(), $url, 60000);
    }
}
