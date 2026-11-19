<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Calendar;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Repository\PromptRepository;
use App\Service\Calendar\CalendarEntryExtractor;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * The model proposes entries, the read text decides which are kept: an entry
 * is returned only when the text names its start time.
 */
final class CalendarEntryExtractorTest extends TestCase
{
    /** An invented booking, in the shape a picture's text extraction returns. */
    private const BOOKING = "Hinreise • Freitag 12 März, 2027\n06:45 1h 35min 08:20\nHamburg Flughafen (HAM) Wien (VIE)\n"
        ."Hinreise • Samstag 13 März, 2027\n19:30 3h 45min 22:15\nWien (VIE) Lissabon (LIS)\n"
        ."Hinreise • Mittwoch 17 März, 2027\n14:00 3h 0min 17:00\nLissabon (LIS) Hamburg Flughafen (HAM)\nBuchungsnummer 4471-205-918";

    /** @var array{messages: list<array{role: string, content: string}>, options: array<string, mixed>}|null */
    private ?array $call = null;

    public function testTheThreeFlightsOfABookingBecomeThreeEntries(): void
    {
        $extraction = $this->extractor(json_encode(['entries' => [
            ['title' => 'Flug Hamburg (HAM) → Wien (VIE)', 'start' => '2027-03-12T06:45:00', 'end' => '2027-03-12T08:20:00', 'location' => 'Hamburg Flughafen (HAM)', 'description' => 'Buchungsnummer 4471-205-918'],
            ['title' => 'Flug Wien (VIE) → Lissabon (LIS)', 'start' => '2027-03-13T19:30:00', 'end' => '2027-03-13T22:15:00', 'location' => 'Wien (VIE)', 'description' => null],
            ['title' => 'Flug Lissabon (LIS) → Hamburg (HAM)', 'start' => '2027-03-17T14:00:00', 'end' => '2027-03-17T17:00:00', 'location' => null, 'description' => null],
        ]], \JSON_UNESCAPED_UNICODE))->extract('Trage bitte die 3 Flüge in mein Outlook Calender', self::BOOKING, $this->berlin(), 7);

        self::assertCount(3, $extraction->entries);
        self::assertSame(0, $extraction->rejected);
        self::assertSame('Flug Hamburg (HAM) → Wien (VIE)', $extraction->entries[0]->title);
        self::assertSame('2027-03-12T06:45:00', $extraction->entries[0]->start);
        self::assertSame('2027-03-12T08:20:00', $extraction->entries[0]->end);
        self::assertSame('Hamburg Flughafen (HAM)', $extraction->entries[0]->location);
        self::assertSame('Buchungsnummer 4471-205-918', $extraction->entries[0]->description);
        self::assertSame('2027-03-17T14:00:00', $extraction->entries[2]->start);
        self::assertNull($extraction->entries[2]->location);
    }

    /**
     * The promise the calendar step makes for a typed time holds for a read
     * one: no hour is invented. 09:00 is nowhere on the booking.
     */
    public function testAnEntryWhoseTimeTheTextDoesNotNameIsRefused(): void
    {
        $extraction = $this->extractor('{"entries":['
            .'{"title":"Flug nach Wien","start":"2027-03-12T06:45:00","end":null,"location":null,"description":null},'
            .'{"title":"Check-in","start":"2027-03-12T09:00:00","end":null,"location":null,"description":null}]}')
            ->extract('Trage die Flüge ein', self::BOOKING, $this->berlin(), 7);

        self::assertCount(1, $extraction->entries);
        self::assertSame('Flug nach Wien', $extraction->entries[0]->title);
        self::assertSame(1, $extraction->rejected);
    }

    /** The booking says 19:30. That is not 09:30. */
    public function testAnEndTheTextDoesNotNameIsDroppedButTheEntryStays(): void
    {
        $extraction = $this->extractor('{"entries":[{"title":"Flug nach Wien","start":"2027-03-12T06:45:00","end":"2027-03-12T09:30:00","location":null,"description":null}]}')
            ->extract('Trage die Flüge ein', self::BOOKING, $this->berlin(), 7);

        self::assertCount(1, $extraction->entries);
        self::assertNull($extraction->entries[0]->end);
    }

    /**
     * The time is the one on the page. An offset or a "Z" the model adds is
     * cut off; the calendar step places the wall clock in the person's zone.
     */
    public function testAnOffsetTheModelWroteIsCutOff(): void
    {
        $extraction = $this->extractor('{"entries":[{"title":"Flug","start":"2027-03-12T06:45:00Z","end":"2027-03-12 08:20","location":null,"description":null}]}')
            ->extract('Trage die Flüge ein', self::BOOKING, $this->berlin(), 7);

        self::assertSame('2027-03-12T06:45:00', $extraction->entries[0]->start);
        self::assertSame('2027-03-12T08:20:00', $extraction->entries[0]->end);
    }

    public function testRowsWithoutAUsableStartAndRepeatsAreLeftOut(): void
    {
        $extraction = $this->extractor('{"entries":['
            .'{"title":"Ohne Zeit","start":"2027-03-12"},'
            .'{"title":"Kein Datum","start":"06:45"},'
            .'{"title":"Unmöglich","start":"2027-02-31T06:45:00"},'
            .'"not an object",'
            .'{"title":"Flug","start":"2027-03-12T06:45:00"},'
            .'{"title":"flug","start":"2027-03-12T06:45:00"}]}')
            ->extract('Trage die Flüge ein', self::BOOKING, $this->berlin(), 7);

        self::assertCount(1, $extraction->entries);
        self::assertSame('Flug', $extraction->entries[0]->title);
        self::assertSame(0, $extraction->rejected, 'only an entry with a time the text does not name counts as refused');
    }

    public function testABareListAndFencedJsonAreRead(): void
    {
        $extraction = $this->extractor("```json\n[{\"title\":\"Flug\",\"start\":\"2027-03-12T06:45:00\"}]\n```")
            ->extract('Trage die Flüge ein', self::BOOKING, $this->berlin(), 7);

        self::assertCount(1, $extraction->entries);
    }

    public function testNothingToEnterIsAnEmptyList(): void
    {
        $extraction = $this->extractor('{"entries":[]}')->extract('Trag das ein', 'Ein Foto einer Katze.', $this->berlin(), 7);

        self::assertSame([], $extraction->entries);
    }

    public function testEmptyReadTextAsksNoModel(): void
    {
        $facade = $this->createMock(AiFacade::class);
        $facade->expects(self::never())->method('chat');

        $extraction = $this->extractorWith($facade)->extract('Trag das ein', "  \n", $this->berlin(), 7);

        self::assertSame([], $extraction->entries);
    }

    public function testAReplyThatIsNotJsonIsAFailureNotAnEmptyResult(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->extractor('I could not find anything, sorry.')->extract('Trag das ein', self::BOOKING, $this->berlin(), 7);
    }

    /**
     * The request, the person's "now" and the read text travel as data; the
     * call runs on the text analytics model, without sampling, with the
     * schema, and carries the caller's usage attribution to the facade.
     */
    public function testTheCallCarriesTheRequestTheClockAndTheReadText(): void
    {
        $this->extractor('{"entries":[]}')->extract(
            'Trage bitte die 3 Flüge in mein Outlook Calender',
            self::BOOKING,
            $this->berlin(),
            7,
            ['usage_source' => 'MULTITASK', 'usage_chat_id' => 42],
        );

        self::assertNotNull($this->call);
        self::assertSame('system', $this->call['messages'][0]['role']);
        self::assertStringContainsString('Never invent an hour', $this->call['messages'][0]['content']);

        $payload = json_decode($this->call['messages'][1]['content'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Trage bitte die 3 Flüge in mein Outlook Calender', $payload['request']);
        self::assertSame('Monday, 2027-03-01 09:15 (Europe/Berlin)', $payload['now']);
        self::assertSame(self::BOOKING, $payload['read']);

        $options = $this->call['options'];
        self::assertSame('testprovider', $options['provider']);
        self::assertSame('analytics-model', $options['model']);
        self::assertSame(0, $options['temperature']);
        self::assertSame('MULTITASK', $options['usage_source']);
        self::assertSame(42, $options['usage_chat_id']);
        self::assertArrayNotHasKey('caller_meters', $options, 'the facade meters this call');
        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output']);
        self::assertSame('calendar_entries', $options['structured_output']->name);
    }

    public function testTheUsageOfTheCallIsReturnedInStepResultKeys(): void
    {
        $extraction = $this->extractor('{"entries":[]}')->extract('Trag das ein', self::BOOKING, $this->berlin(), 7);

        self::assertSame('testprovider', $extraction->usage['provider']);
        self::assertSame('analytics-model-2026', $extraction->usage['model']);
        self::assertSame(31, $extraction->usage['model_id']);
        self::assertSame(['prompt_tokens' => 400, 'completion_tokens' => 90, 'total_tokens' => 490], $extraction->usage['usage']);
        self::assertTrue($extraction->usage['facade_metered']);
        self::assertSame('0.001200', $extraction->usage['facade_charged_cost']);
    }

    public function testWithoutAModelItFailsBeforeAnyCall(): void
    {
        $facade = $this->createMock(AiFacade::class);
        $facade->expects(self::never())->method('chat');
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getSummaryModelConfig')->willReturn(['model' => null, 'provider' => null, 'model_id' => null]);

        $this->expectException(\RuntimeException::class);

        $this->extractorWith($facade, $models)->extract('Trag das ein', self::BOOKING, $this->berlin(), 7);
    }

    private function extractor(string $reply): CalendarEntryExtractor
    {
        $facade = $this->createStub(AiFacade::class);
        $facade->method('chat')->willReturnCallback(function (array $messages, ?int $userId, array $options) use ($reply): array {
            $this->call = ['messages' => $messages, 'options' => $options];

            return [
                'content' => $reply,
                'provider' => 'testprovider',
                'model' => 'analytics-model-2026',
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 90, 'total_tokens' => 490],
                'facade_metered' => true,
                'facade_charged_cost' => '0.001200',
                'facade_price_known' => true,
            ];
        });

        return $this->extractorWith($facade);
    }

    private function extractorWith(AiFacade $facade, ?ModelConfigService $models = null): CalendarEntryExtractor
    {
        if (null === $models) {
            $models = $this->createStub(ModelConfigService::class);
            $models->method('getSummaryModelConfig')->willReturn(['model' => 'analytics-model', 'provider' => 'testprovider', 'model_id' => 31]);
        }
        // No stored row: the instructions come from the built-in catalog.
        $prompts = $this->createStub(PromptRepository::class);
        $prompts->method('findByTopic')->willReturn(null);
        $structured = $this->createStub(StructuredOutputConfig::class);
        $structured->method('isEnabled')->willReturn(true);

        return new CalendarEntryExtractor(
            $facade,
            $prompts,
            $models,
            $structured,
            new NullLogger(),
            new MockClock(new \DateTimeImmutable('2027-03-01 08:15:00', new \DateTimeZone('UTC'))),
        );
    }

    private function berlin(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Berlin');
    }
}
