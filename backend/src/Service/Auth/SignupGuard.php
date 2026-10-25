<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Rate limits and the pause switch for account creation.
 *
 * Two sliding windows (config/packages/rate_limiter.yaml):
 *  - `signup_ip`     — new accounts per client IP (`SIGNUP_IP_LIMIT` per hour),
 *  - `signup_global` — new accounts installation-wide (`SIGNUP_GLOBAL_LIMIT`
 *    per 10 minutes). Tripping it raises {@see SignupAnomalyAlert}.
 *
 * Both count attempts, not successes, so a script that keeps failing is
 * throttled too. The client IP is the one Symfony resolves behind the
 * configured trusted proxies; without them every request looks like the
 * load balancer and the per-IP window degrades into a second global one.
 */
final readonly class SignupGuard
{
    public function __construct(
        private RateLimiterFactoryInterface $signupIpLimiter,
        private RateLimiterFactoryInterface $signupGlobalLimiter,
        private SignupPauseSwitch $pauseSwitch,
        private SignupAnomalyAlert $anomalyAlert,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * `$invited`: the person brings a valid invitation code an administrator
     * issued. The pause stops strangers, not them; the limits still count.
     *
     * @throws SignupBlockedException
     */
    public function assertAllowed(?string $clientIp, bool $invited = false): void
    {
        if ($this->pauseSwitch->isPaused()) {
            if (!$invited) {
                $this->logger->notice('Signup refused: paused by operator', ['ip' => $clientIp]);

                throw SignupBlockedException::paused();
            }
            $this->logger->notice('Signup admitted while paused: invitation code', ['ip' => $clientIp]);
        }

        $ip = null !== $clientIp && '' !== $clientIp ? $clientIp : 'unknown';
        $perIp = $this->signupIpLimiter->create($ip)->consume();
        if (!$perIp->isAccepted()) {
            $this->logger->warning('Signup refused: per-IP limit reached', ['ip' => $clientIp]);

            throw SignupBlockedException::rateLimited($this->secondsUntil($perIp->getRetryAfter()));
        }

        $global = $this->signupGlobalLimiter->create('all')->consume();
        if (!$global->isAccepted()) {
            $this->logger->warning('Signup refused: installation-wide limit reached', ['ip' => $clientIp]);
            $this->anomalyAlert->signupBurst(['last_ip' => $clientIp]);

            throw SignupBlockedException::rateLimited($this->secondsUntil($global->getRetryAfter()));
        }
    }

    private function secondsUntil(\DateTimeImmutable $retryAfter): int
    {
        return max(1, $retryAfter->getTimestamp() - time());
    }
}
