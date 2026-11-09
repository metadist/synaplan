<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

use App\Entity\Agent;
use App\Entity\Approval;
use App\Entity\GroupMember;
use App\Entity\Share;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\ApprovalRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Service\AgentTemplate\Exception\AgentPublishReviewException;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ShareService;
use App\Service\Tool\ApprovalRealtimeNotifier;

/**
 * Holds "share with everyone" until another admin approves it.
 *
 * The group policy AGENTS.PUBLISH_REVIEW is off until a group turns it on.
 * The person who asked cannot approve their own request. A system admin
 * or a manager of that group can. The share is written for the owner,
 * because only an administrator may share with everyone.
 */
final readonly class AgentPublishReview
{
    public const TOOL = 'agent.publish';
    public const OUTCOME_SENT_BACK = 'sent_back';
    public const OUTCOME_WITHDRAWN = 'withdrawn';

    private const KEEPS_YEARS = 10;

    public function __construct(
        private LayeredConfigResolver $config,
        private ApprovalRepository $approvals,
        private GroupMemberRepository $members,
        private GroupConfigRepository $groupConfig,
        private ShareRepository $shares,
        private ShareService $shareService,
        private IamConfig $iam,
        private AgentRepository $agents,
        private UserRepository $users,
        private ?ApprovalRealtimeNotifier $realtime = null,
    ) {
    }

    public function required(User $user): bool
    {
        return $this->config->resolveBool((int) $user->getId(), 'AGENTS', 'PUBLISH_REVIEW', false);
    }

    public function request(User $author, Agent $agent): void
    {
        $id = $agent->getId();
        if (null === $id) {
            return;
        }
        $existing = $this->approvals->findLatestForRequestedBy($this->reference($id));
        if ($existing instanceof Approval && $existing->isPending()) {
            return;
        }

        $approval = new Approval(
            (int) $author->getId(),
            $this->reference($id),
            self::TOOL,
            'write',
            time() + (self::KEEPS_YEARS * 365 * 24 * 3600),
        );
        $approval->setArgs([
            'agentId' => $id,
            'name' => $agent->getName(),
        ]);
        $approval->setPreview($agent->getName());
        $this->approvals->save($approval);
        $this->realtime?->pending($approval);
    }

    public function sharedWithEveryone(Agent $agent): bool
    {
        $id = $agent->getId();
        if (null === $id) {
            return false;
        }

        return $this->shares->findOneForSubject(AgentKind::KEY, (string) $id, Share::SUBJECT_EVERYONE, 0) instanceof Share;
    }

    /**
     * @return array{status: string, note: string|null}|null
     */
    public function stateFor(User $owner, ?Agent $agent): ?array
    {
        if (!$agent instanceof Agent || null === $agent->getId() || (int) $owner->getId() !== $agent->getOwnerId()) {
            return null;
        }
        $id = $agent->getId();
        $latest = $this->approvals->findLatestForRequestedBy($this->reference($id));
        if (!$latest instanceof Approval || $latest->getOwnerId() !== (int) $owner->getId()) {
            return null;
        }
        if ($latest->isPending()) {
            return ['status' => 'waiting', 'note' => null];
        }
        $args = $latest->getArgs() ?? [];
        if (Approval::STATUS_REJECTED === $latest->getStatus() && self::OUTCOME_SENT_BACK === ($args['outcome'] ?? null)) {
            $note = trim((string) ($args['note'] ?? ''));

            return ['status' => 'sent_back', 'note' => '' === $note ? null : $note];
        }

        return null;
    }

    /**
     * @return list<Approval>
     */
    public function forReviewer(User $viewer, string $statusGroup): array
    {
        $statuses = 'pending' === $statusGroup
            ? [Approval::STATUS_PENDING]
            : [Approval::STATUS_APPROVED, Approval::STATUS_REJECTED, Approval::STATUS_EXPIRED, Approval::STATUS_EXECUTED, Approval::STATUS_FAILED];
        $rows = [];
        foreach ($this->approvals->findByToolAndStatuses(self::TOOL, $statuses) as $approval) {
            if ($approval->getOwnerId() === (int) $viewer->getId()) {
                continue;
            }
            if ('pending' === $statusGroup && !$this->canReview($viewer, $approval)) {
                continue;
            }
            if ('pending' !== $statusGroup && !$this->couldReview($viewer, $approval)) {
                continue;
            }
            $rows[] = $approval;
        }

        return $rows;
    }

    public function countForReviewer(User $viewer): int
    {
        return count($this->forReviewer($viewer, 'pending'));
    }

    public function approve(int $id, User $actor): Approval
    {
        $approval = $this->requirePending($id);
        if (!$this->canReview($actor, $approval)) {
            throw AgentPublishReviewException::forbidden();
        }
        $owner = $this->users->find($approval->getOwnerId());
        $agentId = $this->agentId($approval);
        if (!$owner instanceof User || null === $agentId || !$this->agents->find($agentId) instanceof Agent) {
            throw AgentPublishReviewException::notFound();
        }
        if (!$this->iam->isSharingEnabled((int) $owner->getId())) {
            throw AgentPublishReviewException::sharingOff();
        }
        try {
            $this->shareService->grant($owner, AgentKind::KEY, (string) $agentId, Share::SUBJECT_EVERYONE, 0, Permission::Use->value);
        } catch (ShareNotAllowedException|\InvalidArgumentException) {
            throw AgentPublishReviewException::sharingOff();
        }
        $approval->markApproved((int) $actor->getId());
        $this->approvals->save($approval);
        $this->realtime?->decided($approval);

        return $approval;
    }

    public function sendBack(int $id, User $actor, ?string $note): Approval
    {
        $approval = $this->requirePending($id);
        if (!$this->canReview($actor, $approval)) {
            throw AgentPublishReviewException::forbidden();
        }
        $this->close($approval, $actor, self::OUTCOME_SENT_BACK, $note);
        $this->realtime?->decided($approval);

        return $approval;
    }

    public function withdraw(User $author, Agent $agent): void
    {
        $id = $agent->getId();
        $latest = null !== $id ? $this->approvals->findLatestForRequestedBy($this->reference($id)) : null;
        if (!$latest instanceof Approval || !$latest->isPending() || $latest->getOwnerId() !== (int) $author->getId()) {
            throw AgentPublishReviewException::notFound();
        }
        $this->close($latest, $author, self::OUTCOME_WITHDRAWN, null);
        $this->realtime?->decided($latest);
    }

    public function isPublishReview(int $id): bool
    {
        $approval = $this->approvals->find($id);

        return $approval instanceof Approval && self::TOOL === $approval->getTool();
    }

    private function close(Approval $approval, User $actor, string $outcome, ?string $note): void
    {
        $args = $approval->getArgs() ?? [];
        $args['outcome'] = $outcome;
        $args['note'] = null === $note ? '' : trim($note);
        $approval->setArgs($args);
        $approval->markRejected((int) $actor->getId());
        $this->approvals->save($approval);
    }

    private function requirePending(int $id): Approval
    {
        $approval = $this->approvals->find($id);
        if (!$approval instanceof Approval || self::TOOL !== $approval->getTool() || !$approval->isPending()) {
            throw AgentPublishReviewException::notFound();
        }

        return $approval;
    }

    private function canReview(User $actor, Approval $approval): bool
    {
        if ($approval->getOwnerId() === (int) $actor->getId()) {
            return false;
        }

        return $this->couldReview($actor, $approval);
    }

    private function couldReview(User $actor, Approval $approval): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }
        $groups = $this->reviewGroupIds($approval->getOwnerId());
        if ([] === $groups) {
            return false;
        }
        foreach ($this->members->findByUserId((int) $actor->getId()) as $membership) {
            if (GroupMember::ROLE_MANAGER === $membership->getRole() && in_array($membership->getGroupId(), $groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function reviewGroupIds(int $ownerId): array
    {
        $groupIds = array_map(
            static fn (GroupMember $membership): int => $membership->getGroupId(),
            $this->members->findByUserId($ownerId),
        );
        $ids = [];
        foreach ($this->groupConfig->getForGroups($groupIds, 'AGENTS', 'PUBLISH_REVIEW') as $row) {
            if (true === filter_var($row->getValue(), \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE)) {
                $ids[] = $row->getGroupId();
            }
        }

        return $ids;
    }

    private function agentId(Approval $approval): ?int
    {
        $args = $approval->getArgs() ?? [];
        $id = $args['agentId'] ?? null;

        return is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : null);
    }

    private function reference(int $agentId): string
    {
        return 'agent_publish:'.$agentId;
    }
}
