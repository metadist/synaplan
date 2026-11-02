<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AgentTemplate;

use App\Bundle\BundleEnvelopeValidator;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\AgentTemplate\AgentTemplateCatalog;
use App\Service\AgentTemplate\AgentTemplateCreates;
use PHPUnit\Framework\TestCase;

final class AgentTemplateCreatesTest extends TestCase
{
    public function testCountsAssistantsAndInstructions(): void
    {
        $creates = AgentTemplateCreates::fromSections([
            ['kind' => 'prompts', 'version' => 1, 'items' => [['key' => 'a'], ['key' => 'b']]],
            ['kind' => 'agents', 'version' => 1, 'items' => [['key' => 'offer-writer']]],
        ]);

        self::assertSame(1, $creates->assistants);
        self::assertSame(2, $creates->prompts);
        self::assertSame(0, $creates->tasks);
    }

    public function testASwitchedOffScheduleIsNotATask(): void
    {
        $creates = AgentTemplateCreates::fromSections([
            ['kind' => 'agents', 'version' => 1, 'items' => [[
                'definition' => ['triggers' => ['schedules' => [
                    ['id' => 'morning', 'enabled' => false],
                    ['id' => 'later', 'enabled' => true],
                ]]],
            ]]],
        ]);

        self::assertSame(1, $creates->tasks);
    }

    public function testBuiltInOfferWriterCreatesOneAssistantAndNoExtraInstruction(): void
    {
        $backend = dirname(__DIR__, 4);
        $catalog = new AgentTemplateCatalog(
            $backend.'/templates/agents',
            new BundleEnvelopeValidator(),
            new AgentDefinitionValidator(),
        );

        $offer = null;
        $briefing = null;
        foreach ($catalog->all() as $template) {
            self::assertGreaterThan(0, $template->creates->assistants, $template->id);
            if ('offer-writer' === $template->id) {
                $offer = $template;
            }
            if ('daily-briefing' === $template->id) {
                $briefing = $template;
            }
        }

        if (null === $offer) {
            self::fail('offer-writer is missing from the built-in templates');
        }
        self::assertSame(1, $offer->creates->assistants);
        self::assertSame(0, $offer->creates->prompts);
        self::assertSame(0, $offer->creates->tasks);
        if (null === $briefing) {
            self::fail('daily-briefing is missing from the built-in templates');
        }
        self::assertSame(1, $briefing->creates->tasks);
        self::assertTrue($briefing->body->definition['triggers']['schedules'][0]['enabled']);
    }
}
