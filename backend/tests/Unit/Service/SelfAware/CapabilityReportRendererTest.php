<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware;

use App\Service\SelfAware\CapabilityFact;
use App\Service\SelfAware\CapabilityReport;
use App\Service\SelfAware\CapabilityReportRenderer;
use App\Service\SelfAware\CapabilityState;
use PHPUnit\Framework\TestCase;

final class CapabilityReportRendererTest extends TestCase
{
    public function testRenderIsDeterministicAndWithinBudget(): void
    {
        $report = $this->fullReport(isAdmin: false, billingEnabled: false);
        $renderer = new CapabilityReportRenderer();

        $first = $renderer->render($report);
        $second = $renderer->render($report);

        $this->assertSame($first, $second);
        $this->assertLessThanOrEqual(CapabilityReportRenderer::MAX_CHARS, strlen($first));
        $this->assertStringContainsString('AVAILABLE NOW:', $first);
        $this->assertStringContainsString('NEEDS SETUP:', $first);
        $this->assertStringContainsString('NOT AVAILABLE:', $first);
        $this->assertStringContainsString('ask your administrator', $first);
        $this->assertStringNotContainsString('pricing page', $first);
        $this->assertDoesNotMatchRegularExpression('/\d\s*[€$£]|[€$£]\s*\d/', $first);
    }

    public function testAdminSeesHintsAndBillingAddsPricingRule(): void
    {
        $renderer = new CapabilityReportRenderer();
        $admin = $renderer->render($this->fullReport(isAdmin: true, billingEnabled: true));

        $this->assertStringContainsString('office engine (OFFICE_CONVERT_URL)', $admin);
        $this->assertStringNotContainsString('ask your administrator', $admin);
        $this->assertStringContainsString('For plans and pricing, link the pricing page.', $admin);
    }

    public function testOversizedReportTruncatesAtTheBudget(): void
    {
        $facts = [];
        for ($i = 0; $i < 60; ++$i) {
            $facts[] = new CapabilityFact('fact-'.$i, 'Capability number '.$i, CapabilityState::Available, 'with a fairly long detail string', null, null, null);
        }
        $report = new CapabilityReport($facts, '4.9.0', false, false);

        $rendered = (new CapabilityReportRenderer())->render($report);

        $this->assertLessThanOrEqual(CapabilityReportRenderer::MAX_CHARS, strlen($rendered));
        $this->assertStringEndsWith('…', $rendered);
        $this->assertStringContainsString('AVAILABLE NOW:', $rendered);
    }

    public function testTruncationNeverSplitsMultibyteCharacters(): void
    {
        $renderer = new CapabilityReportRenderer();

        // Vary the ASCII padding so the byte-budget boundary lands on every
        // alignment inside the multibyte run: a byte-based cut would split a
        // 2-byte character on half of these paddings, mb_strcut on none.
        for ($pad = 0; $pad < 4; ++$pad) {
            $facts = [
                new CapabilityFact('mbu', 'Fact '.str_repeat('x', $pad).' '.str_repeat('é', 1500), CapabilityState::Available, '', null, null, null),
            ];
            $rendered = $renderer->render(new CapabilityReport($facts, '4.9.0', false, false));

            $this->assertLessThanOrEqual(CapabilityReportRenderer::MAX_CHARS, strlen($rendered), "pad $pad");
            $this->assertStringEndsWith('…', $rendered, "pad $pad");
            $this->assertTrue(mb_check_encoding($rendered, 'UTF-8'), "pad $pad splits a character");
        }
    }

    private function fullReport(bool $isAdmin, bool $billingEnabled): CapabilityReport
    {
        $facts = [
            new CapabilityFact('chat', 'Chat', CapabilityState::Available, 'workspace model', null, null, null),
            new CapabilityFact('document_generation', 'Documents', CapabilityState::Available, 'DOCX, XLSX, PPTX, CSV', null, null, null),
            new CapabilityFact('pdf_export', 'PDF export', CapabilityState::NeedsSetup, 'office engine not configured', 'DOCX, XLSX, PPTX, CSV', 'office engine (OFFICE_CONVERT_URL)', 'using-synaplan'),
            new CapabilityFact('music_generation', 'Composing or producing music', CapabilityState::Absent, 'No music or song model exists', 'original lyrics in that style', null, null),
        ];

        return new CapabilityReport($facts, '4.2.1', $billingEnabled, $isAdmin);
    }
}
