<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\SignupCode;
use App\Entity\User;
use App\Repository\SignupCodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * In admission mode a new account is a paid plan or an invitation code.
 * Open mode ignores both and keeps today's free sign-up.
 *
 * @phpstan-type Admission array{plan: string|null, code: SignupCode|null}
 */
final readonly class SignupAdmission
{
    public const PLANS = ['PRO', 'TEAM', 'BUSINESS'];

    public function __construct(
        private RegistrationConfig $registration,
        private SignupCodeRepository $codes,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return Admission|null null while registration is open
     *
     * @throws SignupBlockedException
     */
    public function resolve(?string $plan, ?string $code): ?array
    {
        if (!$this->registration->isAdmissionMode()) {
            return null;
        }

        $plan = strtoupper(trim((string) $plan));
        $code = trim((string) $code);
        $hasPlan = '' !== $plan;
        $hasCode = '' !== $code;
        if ($hasPlan === $hasCode) {
            throw SignupBlockedException::admissionRequired();
        }
        if ($hasPlan) {
            if (!in_array($plan, self::PLANS, true)) {
                throw SignupBlockedException::planInvalid();
            }

            return ['plan' => $plan, 'code' => null];
        }

        $entity = $this->codes->findRedeemable($code);
        if (!$entity instanceof SignupCode) {
            throw SignupBlockedException::codeInvalid();
        }

        return ['plan' => null, 'code' => $entity];
    }

    /**
     * @param Admission $admission
     */
    public function apply(User $user, array $admission): void
    {
        if (null !== $admission['plan']) {
            $user->setAccountStatus(User::ACCOUNT_STATUS_PENDING_PAYMENT);
            $details = $user->getUserDetails();
            $details['pending_plan'] = $admission['plan'];
            $user->setUserDetails($details);
            $this->em->flush();

            return;
        }

        $code = $admission['code'];
        if ($code instanceof SignupCode) {
            $user->setUserLevel($code->getLevel());
        }
        $user->setAccountStatus(User::ACCOUNT_STATUS_ACTIVE);
    }

    /**
     * Spend the code now that the account has an id. A lost race removes the
     * account again so a code cannot open an unpaid active user.
     */
    public function commitCode(User $user, SignupCode $code): void
    {
        $use = $this->codes->redeem($code, (int) $user->getId());
        if (null === $use) {
            $this->em->remove($user);
            $this->em->flush();

            throw SignupBlockedException::codeInvalid();
        }

        $this->em->flush();
    }
}
