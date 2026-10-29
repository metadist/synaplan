<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\GatewayAutoResearch;
use App\AI\Messages\GatewayEffortHeader;
use App\AI\Messages\GatewayResearchReply;
use App\Entity\User;
use App\Service\Message\Effort\AutoResearchGate;
use App\Service\Message\Effort\AutoResearchText;
use App\Service\Message\Effort\Effort;
use App\Service\Message\Effort\GateDecision;
use App\Service\Message\Effort\GateTurn;
use App\Service\Message\MessageSorter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GatewayAutoResearchTest extends TestCase
{
    public function testWithoutTheHeaderNothingIsRated(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->never())->method('classify');
        $gate = $this->createMock(AutoResearchGate::class);
        $gate->expects($this->never())->method('decideFor');

        $service = new GatewayAutoResearch($sorter, $gate, $this->text(), new NullLogger());

        self::assertNull($service->rate(new Request(), $this->prepared(), $this->user()));
        self::assertNull($service->rate($this->request('answer'), $this->prepared(), $this->user()));
    }

    public function testAResearchRatingStartsARunAndAnswersWithTheNotice(): void
    {
        $sorter = $this->createMock(MessageSorter::class);
        $sorter->expects($this->once())->method('classify')->with(
            $this->callback(static fn (array $row): bool => 'Compare the visa rules of A, B and C' === $row['BTEXT']),
            $this->callback(static fn (array $history): bool => 1 === \count($history) && 'Hello there' === $history[0]->getText()),
            7,
        )->willReturn(['topic' => 'general', 'language' => 'de', 'raw_response' => '{"BTOPIC":"general","BLANG":"de","BEFFORT":"research","BEFFORT_WHY":"comparison"}']);

        $gate = $this->createMock(AutoResearchGate::class);
        $gate->expects($this->once())->method('decideFor')->with(
            $this->anything(),
            $this->callback(static function (GateTurn $turn): bool {
                return Effort::Research === $turn->verdict->effort
                    && 'DESKTOP:personal' === $turn->folder
                    && 'de' === $turn->language
                    && null === $turn->chatId
                    && 3 === $turn->assistantId
                    && str_starts_with($turn->idempotencyKey, 'gw:7:')
                    && [['role' => 'user', 'text' => 'Hello there']] === $turn->turns;
            }),
        )->willReturn(GateDecision::started('run-1', 6, 'comparison'));

        $service = new GatewayAutoResearch($sorter, $gate, $this->text(), new NullLogger());
        $request = $this->request('auto');
        $request->headers->set('x-synaplan-rag-group-key', 'DESKTOP:personal');
        $request->headers->set('x-synaplan-agent-id', '3');

        $rating = $service->rate($request, $this->prepared(), $this->user());

        self::assertNotNull($rating);
        self::assertTrue($rating->startedRun());
        self::assertSame('notice:6 comparison:de', $rating->notice);
        self::assertSame(['x-synaplan-effort' => 'research', 'x-synaplan-research-run' => 'run-1'], $rating->headers());

        $response = GatewayResearchReply::respond($this->prepared(), $rating);
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('msg_research_run-1', $body['id']);
        self::assertSame('notice:6 comparison:de', $body['content'][0]['text']);
        self::assertSame('end_turn', $body['stop_reason']);
        self::assertSame('run-1', $response->headers->get('x-synaplan-research-run'));
    }

    public function testAHeldBackResearchNamesTheLimitAndLetsTheTurnRun(): void
    {
        $sorter = $this->createStub(MessageSorter::class);
        $sorter->method('classify')->willReturn(['language' => 'en', 'raw_response' => '{"BEFFORT":"research","BEFFORT_WHY":"procedure"}']);
        $gate = $this->createStub(AutoResearchGate::class);
        $gate->method('decideFor')->willReturn(GateDecision::heldBack(GateDecision::NO_SOURCES));

        $rating = (new GatewayAutoResearch($sorter, $gate, $this->text(), new NullLogger()))->rate($this->request('auto'), $this->prepared(), $this->user());

        self::assertNotNull($rating);
        self::assertFalse($rating->startedRun());
        self::assertSame(['x-synaplan-effort' => 'research', 'x-synaplan-research-limit' => 'no_sources'], $rating->headers());
    }

    public function testASorterThatFailsLeavesTheTurnAlone(): void
    {
        $sorter = $this->createStub(MessageSorter::class);
        $sorter->method('classify')->willThrowException(new \RuntimeException('model down'));
        $gate = $this->createMock(AutoResearchGate::class);
        $gate->expects($this->never())->method('decideFor');

        $rating = (new GatewayAutoResearch($sorter, $gate, $this->text(), new NullLogger()))->rate($this->request('auto'), $this->prepared(), $this->user());

        self::assertNotNull($rating);
        self::assertFalse($rating->startedRun());
        self::assertSame(['x-synaplan-effort' => 'answer'], $rating->headers());
    }

    public function testTheStreamedReplyIsTheAnthropicEventSequence(): void
    {
        $events = GatewayResearchReply::events([
            'id' => 'msg_research_run-1', 'type' => 'message', 'role' => 'assistant', 'model' => 'm',
            'content' => [['type' => 'text', 'text' => 'Looking.']], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ]);

        $names = array_map(static fn (string $event): string => explode("\n", $event)[0], $events);
        self::assertSame(
            ['event: message_start', 'event: content_block_start', 'event: content_block_delta', 'event: content_block_stop', 'event: message_delta', 'event: message_stop'],
            $names,
        );
        self::assertStringContainsString('"text":"Looking."', $events[2]);
    }

    private function request(string $effort): Request
    {
        $request = new Request();
        $request->headers->set(GatewayEffortHeader::HEADER, $effort);

        return $request;
    }

    /** @return array<string, mixed> */
    private function prepared(): array
    {
        return [
            'stream' => false,
            'headers' => [],
            'resolved' => ['displayModel' => 'claude-sonnet-4-6'],
            'request_body' => ['messages' => [
                ['role' => 'user', 'content' => 'Hello there'],
                ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'x', 'input' => []]]],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Compare the visa rules of A, B and C']]],
            ]],
        ];
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }

    private function text(): AutoResearchText
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params, ?string $domain, ?string $locale): string => 'research.auto.notice' === $id
                ? 'notice:'.$params['%minutes%']
                : (str_starts_with($id, 'research.auto.why.') ? substr($id, \strlen('research.auto.why.')).':'.$locale : $id),
        );

        return new AutoResearchText($translator);
    }
}
