<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectItem;
use App\Entity\ProjectItemLog;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ProjectItemLogRepository;
use App\Repository\ProjectItemRepository;
use App\Service\Project\ProjectsConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The project board's items through the API (PJ30): each kind is created,
 * changed, finished and dropped by hand; every change is logged; an item is
 * only reachable inside its own project and only by those who may see it.
 */
final class ProjectItemControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testWithoutASignInNothingIsRead(): void
    {
        $this->client->request('GET', '/api/v1/projects/'.Uuid::v4()->toRfc4122().'/items');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->httpStatus());
    }

    public function testWithTheBoardOffEveryRouteAnswersNotConfigured(): void
    {
        $user = $this->signIn();
        $project = $this->project($user);
        static::getContainer()->set(ProjectsConfig::class, new ProjectsConfig(static::getContainer()->get(ConfigRepository::class), '1', '0'));

        $this->send('POST', $project, '', ['kind' => 'task', 'title' => 'Measure']);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
        self::assertSame('feature_not_configured', $this->json()['code'] ?? null);
    }

    public function testEachKindIsCreatedOpenAndShapedForItsKind(): void
    {
        $project = $this->project($this->signIn());

        $task = $this->create($project, ['kind' => 'task', 'title' => 'Measure the niche', 'dueAt' => '2026-12-02', 'contact' => 'Mr Vogt']);
        $date = $this->create($project, ['kind' => 'date', 'title' => 'Measuring', 'startAt' => '2026-11-30T10:00:00Z', 'rrule' => 'freq=weekly;byday=mo']);
        $decision = $this->create($project, ['kind' => 'decision', 'title' => 'Steinwerk worktop', 'detail' => 'Cheaper and in stock']);
        $cost = $this->create($project, ['kind' => 'cost', 'title' => 'Deposit', 'amount' => 8400, 'estimated' => false]);
        $note = $this->create($project, ['kind' => 'note', 'title' => 'The niche is 560 x 590 mm']);

        self::assertSame(['open', 'open', 'open', 'open', 'open'], array_column([$task, $date, $decision, $cost, $note], 'state'));
        self::assertSame('2026-12-02', $task['dueAt']);
        self::assertTrue($task['allDay']);
        self::assertNull($task['startAt']);
        self::assertSame('2026-11-30T10:00:00+00:00', $date['startAt']);
        self::assertSame('FREQ=WEEKLY;BYDAY=MO', $date['rrule']);
        self::assertSame('8400.00', $cost['amount']);
        self::assertSame('manual', $note['sourceKind']);
        self::assertSame('project', $note['visibility']);
    }

    public function testATimeWithoutZoneIsReadInTheProjectZoneAcrossDst(): void
    {
        $project = $this->project($this->signIn(), 'Europe/Berlin');

        $winter = $this->create($project, ['kind' => 'date', 'title' => 'Winter', 'startAt' => '2026-11-30T10:00']);
        $summer = $this->create($project, ['kind' => 'date', 'title' => 'Summer', 'startAt' => '2027-07-01T10:00']);

        self::assertSame('2026-11-30T09:00:00+00:00', $winter['startAt']);
        self::assertSame('2027-07-01T08:00:00+00:00', $summer['startAt']);
    }

    public function testAKindRefusesFieldsOfAnotherKindAndMissingRequiredOnes(): void
    {
        $project = $this->project($this->signIn());

        foreach ([
            ['kind' => 'note', 'title' => 'x', 'amount' => 3],
            ['kind' => 'date', 'title' => 'no start'],
            ['kind' => 'cost', 'title' => 'no amount'],
            ['kind' => 'task', 'title' => 'x', 'state' => 'done'],
            ['kind' => 'idea', 'title' => 'x'],
            ['kind' => 'date', 'title' => 'x', 'startAt' => '2026-11-30', 'allDay' => false],
            ['kind' => 'date', 'title' => 'x', 'startAt' => 'tomorrow'],
            ['kind' => 'date', 'title' => 'x', 'startAt' => '2026-11-30T10:00Z', 'rrule' => 'FREQ=HOURLY'],
            ['kind' => 'date', 'title' => 'x', 'startAt' => '2026-11-30T10:00Z', 'endAt' => '2026-11-30T09:00Z'],
            ['kind' => 'cost', 'title' => 'x', 'amount' => '-5'],
            ['kind' => 'task', 'title' => '   '],
        ] as $body) {
            $this->send('POST', $project, '', $body);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus(), json_encode($body, \JSON_THROW_ON_ERROR));
        }
        self::assertSame(0, $this->items()->countInProject((int) $project->getId()));
    }

    public function testChangeFinishDropAndRestoreEachWriteOneLogRow(): void
    {
        $user = $this->signIn();
        $project = $this->project($user);
        $task = $this->create($project, ['kind' => 'task', 'title' => 'Order tiles']);

        $this->send('PATCH', $project, '/'.$task['id'], ['title' => 'Order the wall tiles', 'waiting' => true]);
        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertNotNull($this->json()['item']['waitingSince']);
        $this->send('PATCH', $project, '/'.$task['id'], ['state' => 'done']);
        $this->send('PATCH', $project, '/'.$task['id'], ['state' => 'open']);
        $this->send('PATCH', $project, '/'.$task['id'], ['state' => 'dropped']);

        self::assertSame('dropped', $this->json()['item']['state']);
        $log = $this->logOf($task['id']);
        self::assertSame(['created', 'updated', 'done', 'restored', 'dropped'], array_map(static fn (ProjectItemLog $l): string => $l->getAction(), $log));
        self::assertNull($log[0]->getBefore());
        self::assertSame('Order tiles', $log[1]->getBefore()['title'] ?? null);
        self::assertSame((int) $user->getId(), $log[4]->getByUserId());
        self::assertNull($log[4]->getStage());
    }

    public function testAProposalIsAcceptedOrRejectedAndANoteIsNeverDone(): void
    {
        $user = $this->signIn();
        $project = $this->project($user);
        $accept = $this->stored($project, $user, ProjectItem::KIND_TASK, ProjectItem::STATE_PROPOSED);
        $reject = $this->stored($project, $user, ProjectItem::KIND_DATE, ProjectItem::STATE_PROPOSED);
        $note = $this->create($project, ['kind' => 'note', 'title' => 'Ben does not like green']);

        $this->send('PATCH', $project, '/'.$accept->getUuid(), ['state' => 'open']);
        $this->send('PATCH', $project, '/'.$reject->getUuid(), ['state' => 'dropped']);
        $this->send('PATCH', $project, '/'.$note['id'], ['state' => 'done']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus());

        $proposed = $this->stored($project, $user, ProjectItem::KIND_TASK, ProjectItem::STATE_PROPOSED);
        $this->send('PATCH', $project, '/'.$proposed->getUuid(), ['state' => 'done']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus());

        self::assertSame('accepted', $this->logOf($accept->getUuid())[0]->getAction());
        self::assertSame('rejected', $this->logOf($reject->getUuid())[0]->getAction());
    }

    public function testATaskUnderADateMovesWithIt(): void
    {
        $project = $this->project($this->signIn(), 'Europe/Berlin');
        $date = $this->create($project, ['kind' => 'date', 'title' => 'Measuring', 'startAt' => '2026-11-30T10:00']);
        $timed = $this->create($project, ['kind' => 'task', 'title' => 'Clear the wall', 'dueAt' => '2026-11-30T08:00', 'parentId' => $date['id']]);
        $allDay = $this->create($project, ['kind' => 'task', 'title' => 'Buy tape', 'dueAt' => '2026-11-29', 'parentId' => $date['id']]);
        $loose = $this->create($project, ['kind' => 'task', 'title' => 'Not under the date', 'dueAt' => '2026-11-29']);

        $this->send('PATCH', $project, '/'.$date['id'], ['startAt' => '2026-12-01T14:00']);

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame('2026-12-01T11:00:00+00:00', $this->fetch($project, $timed['id'])['dueAt']);
        self::assertSame('2026-11-30', $this->fetch($project, $allDay['id'])['dueAt']);
        self::assertSame('2026-11-29', $this->fetch($project, $loose['id'])['dueAt']);
        self::assertSame($date['id'], $this->fetch($project, $timed['id'])['parentId']);
        self::assertSame(['created', 'updated'], array_map(static fn (ProjectItemLog $l): string => $l->getAction(), $this->logOf($timed['id'])));
    }

    public function testAParentMustBeTheRightKindInTheSameProject(): void
    {
        $user = $this->signIn();
        $project = $this->project($user);
        $other = $this->project($user);
        $foreignDate = $this->create($other, ['kind' => 'date', 'title' => 'Elsewhere', 'startAt' => '2026-11-30T10:00Z']);
        $decision = $this->create($project, ['kind' => 'decision', 'title' => 'Worktop']);

        $this->send('POST', $project, '', ['kind' => 'task', 'title' => 'x', 'parentId' => $foreignDate['id']]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus());
        $this->send('POST', $project, '', ['kind' => 'task', 'title' => 'x', 'parentId' => $decision['id']]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus());

        $cost = $this->create($project, ['kind' => 'cost', 'title' => 'Worktop', 'amount' => '1200.5', 'parentId' => $decision['id']]);
        self::assertSame($decision['id'], $cost['parentId']);
        self::assertSame('1200.50', $cost['amount']);
    }

    public function testAnItemIsNotFoundThroughAnotherProjectOrByAnotherPerson(): void
    {
        $owner = $this->signIn();
        $project = $this->project($owner);
        $sibling = $this->project($owner);
        $task = $this->create($project, ['kind' => 'task', 'title' => 'Mine']);

        $this->send('GET', $sibling, '/'.$task['id']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
        $this->send('PATCH', $sibling, '/'.$task['id'], ['title' => 'Taken']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());

        $this->signIn();
        $this->send('GET', $project, '/'.$task['id']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
        $this->send('GET', $project, '');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
        self::assertSame('Mine', $this->items()->findVisible((int) $project->getId(), $task['id'], (int) $owner->getId())?->getTitle());
    }

    public function testAPrivateItemOfAnotherMemberIsInvisible(): void
    {
        $owner = $this->signIn();
        $project = $this->project($owner);
        $private = $this->stored($project, $this->user(), ProjectItem::KIND_TASK, ProjectItem::STATE_OPEN);
        $private->setVisibility(ProjectItem::VISIBILITY_PRIVATE);
        $this->em->flush();
        $shared = $this->create($project, ['kind' => 'task', 'title' => 'Shared']);

        $this->send('GET', $project, '');
        self::assertSame([$shared['id']], array_column($this->json()['items'], 'id'));
        $this->send('GET', $project, '/'.$private->getUuid());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
        $this->send('PATCH', $project, '/'.$private->getUuid(), ['title' => 'Seen']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
    }

    public function testListFiltersAndWaitingOnOthers(): void
    {
        $project = $this->project($this->signIn());
        $waiting = $this->create($project, ['kind' => 'task', 'title' => 'Waiting for the offer', 'waiting' => true]);
        $this->create($project, ['kind' => 'task', 'title' => 'Plain']);
        $dropped = $this->create($project, ['kind' => 'task', 'title' => 'Dropped', 'waiting' => true]);
        $this->send('PATCH', $project, '/'.$dropped['id'], ['state' => 'dropped']);
        $this->create($project, ['kind' => 'note', 'title' => 'A fact']);

        $this->send('GET', $project, '?waiting=1');
        self::assertSame([$waiting['id']], array_column($this->json()['items'], 'id'));
        $this->send('GET', $project, '?kind=task');
        self::assertCount(2, $this->json()['items']);
        $this->send('GET', $project, '?state=dropped');
        self::assertSame([$dropped['id']], array_column($this->json()['items'], 'id'));
        $this->send('GET', $project, '?kind=idea');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus());
    }

    public function testTheProjectHoldsAtMostMaxItems(): void
    {
        $project = $this->project($this->signIn());
        static::getContainer()->get(ConfigRepository::class)->setValue(0, ProjectsConfig::GROUP, ProjectsConfig::MAX_ITEMS, '2');

        $this->create($project, ['kind' => 'task', 'title' => 'One']);
        $this->create($project, ['kind' => 'task', 'title' => 'Two']);
        $this->send('POST', $project, '', ['kind' => 'task', 'title' => 'Three']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus());
        self::assertSame(2, $this->items()->countInProject((int) $project->getId()));
    }

    public function testSwitchingAllDayNeedsTheTimesAgain(): void
    {
        $project = $this->project($this->signIn());
        $task = $this->create($project, ['kind' => 'task', 'title' => 'Call', 'dueAt' => '2026-12-02']);

        $this->send('PATCH', $project, '/'.$task['id'], ['allDay' => false]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus());
        $this->send('PATCH', $project, '/'.$task['id'], ['allDay' => false, 'dueAt' => '2026-12-02T09:30Z']);
        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame('2026-12-02T09:30:00+00:00', $this->json()['item']['dueAt']);
    }

    public function testDeletingTheProjectRemovesItsItemsAndLog(): void
    {
        $project = $this->project($this->signIn());
        $task = $this->create($project, ['kind' => 'task', 'title' => 'Gone soon']);
        $projectId = (int) $project->getId();
        $itemId = (int) $this->items()->findOneBy(['uuid' => $task['id']])?->getId();
        self::assertCount(1, $this->logOf($task['id']));

        $this->client->request('DELETE', '/api/v1/projects/'.$project->getUuid());

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame(0, $this->items()->countInProject($projectId));
        self::assertSame([], static::getContainer()->get(ProjectItemLogRepository::class)->forItem($itemId));
    }

    public function testTheProjectTakesATimeZoneAndCurrency(): void
    {
        $project = $this->project($this->signIn());

        $this->client->request('PATCH', '/api/v1/projects/'.$project->getUuid(), server: ['CONTENT_TYPE' => 'application/json'], content: '{"timezone":"Europe/Vienna","currency":"CHF"}');
        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame('Europe/Vienna', $this->json()['project']['timezone']);
        self::assertSame('CHF', $this->json()['project']['currency']);

        foreach (['{"timezone":"Mars/Base"}', '{"currency":"euro"}'] as $body) {
            $this->client->request('PATCH', '/api/v1/projects/'.$project->getUuid(), server: ['CONTENT_TYPE' => 'application/json'], content: $body);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus());
        }
    }

    private function signIn(): User
    {
        $user = $this->user();
        $this->authenticateClient($this->client, $user);

        return $user;
    }

    private function user(): User
    {
        $user = (new User())
            ->setMail('project-items-'.uniqid().'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('project-items-'.uniqid())
            ->setUserLevel('PRO');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function project(User $owner, ?string $zone = 'UTC'): Project
    {
        $uuid = Uuid::v4()->toRfc4122();
        $project = new Project((int) $owner->getId(), 'Kitchen', $uuid, 'project:'.$uuid);
        $project->setTimezone($zone);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function stored(Project $project, User $author, string $kind, string $state): ProjectItem
    {
        $item = new ProjectItem(Uuid::v4()->toRfc4122(), (int) $project->getId(), $kind, $state, 'From the chat', ProjectItem::SOURCE_MESSAGE, (int) $author->getId());
        if (ProjectItem::KIND_DATE === $kind) {
            $item->setStartAt(new \DateTimeImmutable('2026-11-30 10:00', new \DateTimeZone('UTC')));
        }
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function create(Project $project, array $body): array
    {
        $this->send('POST', $project, '', $body);
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus(), (string) $this->client->getResponse()->getContent());

        return $this->json()['item'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(Project $project, string $itemId): array
    {
        $this->send('GET', $project, '/'.$itemId);

        return $this->json()['item'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, Project $project, string $rest, ?array $body = null): void
    {
        $this->client->request(
            $method,
            '/api/v1/projects/'.$project->getUuid().'/items'.$rest,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: null === $body ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return list<ProjectItemLog>
     */
    private function logOf(string $itemUuid): array
    {
        $item = $this->items()->findOneBy(['uuid' => $itemUuid]);

        return $item instanceof ProjectItem ? static::getContainer()->get(ProjectItemLogRepository::class)->forItem((int) $item->getId()) : [];
    }

    private function items(): ProjectItemRepository
    {
        return static::getContainer()->get(ProjectItemRepository::class);
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
