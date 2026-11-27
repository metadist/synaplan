<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Project\Item;

use App\Entity\ProjectItem;
use App\Service\Project\Item\ProjectItemInput;
use App\Service\Project\Item\RecurrenceRule;
use App\Service\Project\ProjectException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectItemInputTest extends TestCase
{
    private ProjectItemInput $input;
    private \DateTimeZone $berlin;

    protected function setUp(): void
    {
        $this->input = new ProjectItemInput();
        $this->berlin = new \DateTimeZone('Europe/Berlin');
    }

    public function testATaskDueDayIsAnAllDayTimeAtMidnightUtc(): void
    {
        $fields = $this->input->read(['title' => 'Call', 'dueAt' => '2026-03-29'], ProjectItem::KIND_TASK, $this->berlin, true);

        self::assertTrue($fields['allDay']);
        self::assertInstanceOf(\DateTimeImmutable::class, $fields['endAt']);
        self::assertSame('2026-03-29T00:00:00+00:00', $fields['endAt']->format(\DateTimeInterface::ATOM));
        self::assertArrayNotHasKey('dueAt', $fields);
    }

    public function testATimeWithAnOffsetKeepsItsOffset(): void
    {
        $fields = $this->input->read(['title' => 'x', 'startAt' => '2026-11-30T10:00:00+05:30'], ProjectItem::KIND_DATE, $this->berlin, true);

        self::assertInstanceOf(\DateTimeImmutable::class, $fields['startAt']);
        self::assertSame('2026-11-30T04:30:00+00:00', $fields['startAt']->format(\DateTimeInterface::ATOM));
        self::assertFalse($fields['allDay']);
    }

    public function testControlCharactersAreRemovedAndADetailKeepsLineBreaks(): void
    {
        $fields = $this->input->read(['title' => "Order\u{0007} tiles\n", 'detail' => "Line one\nLine\u{0000} two"], ProjectItem::KIND_TASK, $this->berlin, true);

        self::assertSame('Order  tiles', $fields['title']);
        self::assertSame("Line one\nLine two", $fields['detail']);
    }

    public function testAmountsAreNormalizedWithoutFloatDrift(): void
    {
        $read = fn (mixed $amount): mixed => $this->input->read(['title' => 'x', 'amount' => $amount], ProjectItem::KIND_COST, $this->berlin, true)['amount'];

        self::assertSame('8400.00', $read(8400));
        self::assertSame('0.10', $read(0.1));
        self::assertSame('9999999999.99', $read('9999999999.99'));
        self::assertSame('12.50', $read(' 012.5 '));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('refused')]
    public function testRefusedInput(string $kind, array $body): void
    {
        $this->expectException(ProjectException::class);
        $this->expectExceptionCode(ProjectException::INVALID);

        $this->input->read(['title' => 'x', ...$body], $kind, $this->berlin, false);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function refused(): iterable
    {
        yield 'a day that does not exist' => [ProjectItem::KIND_DATE, ['startAt' => '2026-02-31']];
        yield 'a time that does not exist' => [ProjectItem::KIND_DATE, ['startAt' => '2026-11-30T25:00']];
        yield 'relative words' => [ProjectItem::KIND_DATE, ['startAt' => 'next monday']];
        yield 'a relative suffix' => [ProjectItem::KIND_DATE, ['startAt' => '2026-11-30T10:00 +1 day']];
        yield 'far future' => [ProjectItem::KIND_DATE, ['startAt' => '2201-01-01']];
        yield 'mixed day and time' => [ProjectItem::KIND_DATE, ['startAt' => '2026-11-30', 'endAt' => '2026-11-30T12:00']];
        yield 'a time on an all-day item' => [ProjectItem::KIND_TASK, ['dueAt' => '2026-11-30T12:00', 'allDay' => true]];
        yield 'three decimals' => [ProjectItem::KIND_COST, ['amount' => '1.005']];
        yield 'a float with three decimals' => [ProjectItem::KIND_COST, ['amount' => 1.005]];
        yield 'amount null' => [ProjectItem::KIND_COST, ['amount' => null]];
        yield 'a title too long' => [ProjectItem::KIND_NOTE, ['title' => str_repeat('a', 201)]];
        yield 'a parent that is no id' => [ProjectItem::KIND_TASK, ['parentId' => '1 OR 1=1']];
        yield 'a negative sort' => [ProjectItem::KIND_TASK, ['sort' => -1]];
        yield 'waiting as text' => [ProjectItem::KIND_TASK, ['waiting' => 'yes']];
        yield 'proposed set by hand' => [ProjectItem::KIND_TASK, ['state' => 'proposed']];
        yield 'a note done' => [ProjectItem::KIND_NOTE, ['state' => 'done']];
        yield 'a decision with a time' => [ProjectItem::KIND_DECISION, ['startAt' => '2026-11-30']];
    }

    #[DataProvider('rules')]
    public function testRecurrenceRules(string $rule, ?string $normalized): void
    {
        if (null === $normalized) {
            $this->expectException(ProjectException::class);
        }

        self::assertSame($normalized, RecurrenceRule::normalize($rule));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function rules(): iterable
    {
        yield 'weekly on Monday' => ['freq=weekly;byday=mo', 'FREQ=WEEKLY;BYDAY=MO'];
        yield 'with prefix' => ['RRULE:FREQ=MONTHLY;BYDAY=1MO;COUNT=6', 'FREQ=MONTHLY;BYDAY=1MO;COUNT=6'];
        yield 'until a day' => ['FREQ=DAILY;INTERVAL=2;UNTIL=20261231', 'FREQ=DAILY;INTERVAL=2;UNTIL=20261231'];
        yield 'no frequency' => ['BYDAY=MO', null];
        yield 'hourly' => ['FREQ=HOURLY', null];
        yield 'count and until' => ['FREQ=DAILY;COUNT=3;UNTIL=20261231', null];
        yield 'unknown part' => ['FREQ=DAILY;BYSETPOS=1', null];
        yield 'repeated day' => ['FREQ=WEEKLY;BYDAY=MO,MO', null];
        yield 'interval zero' => ['FREQ=DAILY;INTERVAL=0', null];
        yield 'injection' => ["FREQ=DAILY\r\nATTENDEE:x", null];
    }
}
