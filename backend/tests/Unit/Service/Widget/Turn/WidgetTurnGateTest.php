<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget\Turn;

use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\Widget\Turn\WidgetTurn;
use App\Service\Widget\Turn\WidgetTurnGate;
use App\Service\Widget\WidgetDomainGuard;
use App\Service\Widget\WidgetRequestRefused;
use App\Service\Widget\WidgetService;
use App\Service\Widget\WidgetSessionResolver;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

final class WidgetTurnGateTest extends TestCase
{
    private WidgetSession $session;
    private ?User $owner;
    /** @var list<int> the message limits the session was checked against */
    private array $limitsChecked = [];
    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->session = (new WidgetSession())->setWidgetId('wdg_1')->setSessionId('sess');
        $this->owner = $this->createStub(User::class);
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        self::assertSame([400, ['error' => 'Invalid JSON in request body']], $this->refusal($this->gate(), 'wdg_1', 'not json'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function incompleteBodies(): iterable
    {
        yield 'no text' => ['{"sessionId": "sess"}'];
        yield 'no session' => ['{"text": "Hi"}'];
        yield 'empty text' => ['{"sessionId": "sess", "text": ""}'];
        yield 'text as a list' => ['{"sessionId": "sess", "text": ["Hi"]}'];
        yield 'session as a list' => ['{"sessionId": ["sess"], "text": "Hi"}'];
        yield 'not an object' => ['"Hi"'];
    }

    #[DataProvider('incompleteBodies')]
    public function testAMessageWithoutTextOrSessionIsRefused(string $body): void
    {
        self::assertSame([400, ['error' => 'Missing required fields: sessionId, text']], $this->refusal($this->gate(), 'wdg_1', $body));
    }

    public function testAnUnknownWidgetIsRefused(): void
    {
        self::assertSame([404, ['error' => 'Widget not found']], $this->refusal($this->gate(), 'wdg_other', $this->body()));
    }

    public function testAnInactiveWidgetIsRefused(): void
    {
        self::assertSame(
            [503, ['error' => 'Widget is not active', 'reason' => 'owner_limits_exceeded']],
            $this->refusal($this->gate(['active' => false]), 'wdg_1', $this->body()),
        );
    }

    public function testASessionOverItsLimitIsRefusedWithTheRetryHint(): void
    {
        $gate = $this->gate(['limit' => ['allowed' => false, 'reason' => 'rate_limit_exceeded', 'remaining' => 0, 'retry_after' => 42]]);

        self::assertSame(
            [429, ['error' => 'Rate limit exceeded', 'reason' => 'rate_limit_exceeded', 'remaining' => 0, 'retryAfter' => 42]],
            $this->refusal($gate, 'wdg_1', $this->body()),
        );
        self::assertSame([3], $this->limitsChecked);
    }

    public function testWithoutAConfiguredLimitTheDefaultApplies(): void
    {
        $this->gate(['config' => []])->admit('wdg_1', $this->request($this->body()));

        self::assertSame([WidgetSessionService::DEFAULT_MAX_MESSAGES], $this->limitsChecked);
    }

    public function testTheOwnersInternalSessionSkipsTheLimit(): void
    {
        $this->session->setMode(WidgetSession::MODE_INTERNAL);

        $this->gate(['limit' => ['allowed' => false]])->admit('wdg_1', $this->request($this->body()));

        self::assertSame([], $this->limitsChecked);
    }

    public function testAWidgetWithoutItsOwnerIsRefused(): void
    {
        $this->owner = null;

        self::assertSame([500, ['error' => 'Widget owner not found']], $this->refusal($this->gate(), 'wdg_1', $this->body()));
    }

    public function testAnAdmittedTurnCarriesWhatTheVisitorSent(): void
    {
        $turn = $this->gate()->admit('wdg_1', $this->request(json_encode([
            'sessionId' => 'sess',
            'text' => 'Hi',
            'files' => [3, 'x'],
            'chatId' => '12',
            'externalUserId' => '  user-1 ',
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(
            ['Hi', [3, 'x'], 12, 'user-1', false, ['messageLimit' => 3, 'allowedDomains' => []]],
            [$turn->text, $turn->files, $turn->chatId, $turn->externalUserId, $turn->heldForOperator, $turn->config],
        );
        self::assertSame([$this->session, $this->owner], [$turn->session, $turn->owner]);
    }

    public function testASessionAPersonRunsIsHeldForTheOperator(): void
    {
        $held = [];
        foreach ([WidgetSession::MODE_HUMAN, WidgetSession::MODE_WAITING, WidgetSession::MODE_AI] as $mode) {
            $this->session->setMode($mode);
            $held[$mode] = $this->admit()->heldForOperator;
        }

        self::assertSame([WidgetSession::MODE_HUMAN => true, WidgetSession::MODE_WAITING => true, WidgetSession::MODE_AI => false], $held);
    }

    public function testTheFirstMessageRecordsTheVisitorsCountry(): void
    {
        $this->admit(['HTTP_CF_IPCOUNTRY' => 'DE']);
        $this->admit(['HTTP_CF_IPCOUNTRY' => 'FR']);

        self::assertSame(['DE', 1], [$this->session->getCountry(), $this->flushes]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function admit(array $headers = []): WidgetTurn
    {
        return $this->gate()->admit('wdg_1', $this->request($this->body(), $headers));
    }

    /**
     * @param array{active?: bool, limit?: array<string, mixed>, config?: array<string, mixed>} $setup
     */
    private function gate(array $setup = []): WidgetTurnGate
    {
        $widget = (new Widget())->setWidgetId('wdg_1')->setOwnerId(7)->setConfig($setup['config'] ?? ['messageLimit' => 3]);
        $widgets = $this->createStub(WidgetService::class);
        $widgets->method('getWidgetById')->willReturnCallback(static fn (string $id): ?Widget => 'wdg_1' === $id ? $widget : null);
        $widgets->method('isWidgetActive')->willReturn($setup['active'] ?? true);

        $resolver = $this->createStub(WidgetSessionResolver::class);
        $resolver->method('resolve')->willReturn($this->session);

        $sessions = $this->createStub(WidgetSessionService::class);
        $sessions->method('checkSessionLimit')->willReturnCallback(function (WidgetSession $session, ?int $maxMessages) use ($setup): array {
            $this->limitsChecked[] = (int) $maxMessages;

            return $setup['limit'] ?? ['allowed' => true];
        });

        $users = $this->createStub(EntityRepository::class);
        $users->method('find')->willReturn($this->owner);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($users);
        $em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return new WidgetTurnGate($widgets, new WidgetDomainGuard($this->createStub(Security::class), new NullLogger()), $resolver, $sessions, $em);
    }

    private function body(): string
    {
        return '{"sessionId": "sess", "text": "Hi"}';
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $body, array $headers = []): Request
    {
        return Request::create('/api/v1/widget/wdg_1/message', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'] + $headers, $body);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function refusal(WidgetTurnGate $gate, string $widgetId, string $body): array
    {
        try {
            $gate->admit($widgetId, $this->request($body));
        } catch (WidgetRequestRefused $refused) {
            return [$refused->httpStatus, $refused->payload];
        }

        self::fail('The message was admitted.');
    }
}
