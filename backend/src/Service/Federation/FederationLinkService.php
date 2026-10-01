<?php

declare(strict_types=1);

namespace App\Service\Federation;

use App\Entity\FederationPartner;
use App\Repository\FederationPartnerRepository;
use App\Service\Iam\AuditLogWriter;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Open this server to partners, invite one, and accept, pause or disconnect.
 *
 * Sharing knowledge and assistants is a later milestone. A connection grants nothing.
 */
final class FederationLinkService
{
    private const INVITE_TTL = 604800;
    private const MAX_PENDING_INVITES = 20;
    private const SKEW_SECONDS = 300;
    private const NAME_MAX = 80;

    public function __construct(
        private FederationIdentityStore $identities,
        private FederationPartnerRepository $partners,
        private FederationPeerClient $peers,
        private FederationSigner $signer,
        private FederationUrlGuard $urls,
        private CacheItemPoolInterface $cache,
        private AuditLogWriter $audit,
        private LoggerInterface $logger,
        private string $appUrl,
        private string $frontendUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function membership(): array
    {
        $identity = $this->identities->get();

        return [
            'reachable' => $this->urls->isReachableAppUrl($this->appUrl),
            'opened' => $identity instanceof FederationIdentity && $identity->opened,
            'domain' => $this->domain(),
            'name' => $identity instanceof FederationIdentity ? $identity->name : '',
            'fingerprint' => $identity instanceof FederationIdentity ? $this->signer->fingerprint($identity->publicKey) : '',
            'sodium' => FederationSigner::available(),
            'pageUrl' => rtrim($this->frontendUrl, '/').'/partners/join',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function open(int $actorId, string $name): array
    {
        $this->assertReachable();
        $name = $this->cleanName($name);
        $current = $this->identities->get();
        if ($current instanceof FederationIdentity) {
            $this->identities->save($current->withOpened(true, $name));
        } else {
            $keys = $this->signer->generate();
            $this->identities->save(new FederationIdentity($keys['publicKey'], $keys['secretKey'], $name, true));
        }
        $this->audit->record($actorId, 'federation.open', 'federation', $this->domain());

        return $this->membership();
    }

    /**
     * @return array<string, mixed>
     */
    public function close(int $actorId): array
    {
        $identity = $this->requireOpened();
        foreach ($this->partners->listLive() as $partner) {
            $this->endPartner($partner, $actorId, true);
        }
        $this->identities->save($identity->withOpened(false));
        $this->audit->record($actorId, 'federation.close', 'federation', $this->domain());

        return $this->membership() + [
            'message' => 'Closed. New connections stop. Existing partners were disconnected.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createInvite(int $actorId): array
    {
        $this->requireOpened();
        if ($this->partners->countInvited() >= self::MAX_PENDING_INVITES) {
            throw new FederationException('invite_limit', 'You already have 20 unused invites. Use one, or wait until one expires.');
        }
        $token = bin2hex(random_bytes(16));
        $partner = new FederationPartner();
        $partner->setStatus(FederationPartner::STATUS_INVITED);
        $partner->setTokenHash(hash('sha256', $token));
        $partner->setExpiresAt(time() + self::INVITE_TTL);
        $partner->setAcceptedBy($actorId);
        $this->partners->save($partner);
        $this->audit->record($actorId, 'federation.invite', 'federation_partner', (string) $partner->getId());

        return [
            'pasteUrl' => $this->apiBase().'/invites/'.$token,
            'expiresAt' => $partner->getExpiresAt(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function previewInvite(string $token): array
    {
        $partner = $this->usableInvite($token);
        $identity = $this->requireOpened();

        return [
            'name' => $identity->name,
            'domain' => $this->domain(),
            'expiresAt' => $partner->getExpiresAt(),
            'pasteUrl' => $this->apiBase().'/invites/'.$token,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPartners(): array
    {
        $rows = [];
        foreach ($this->partners->listCurrent() as $partner) {
            $rows[] = $this->partnerRow($partner);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function acceptInvite(int $actorId, string $inviteUrl): array
    {
        $identity = $this->requireOpened();
        $parsed = FederationInviteLink::parse($inviteUrl);
        $this->urls->assertOutbound($parsed['origin'].'/api/v1/federation/invites/'.$parsed['token']);
        $preview = $this->peers->invite($parsed['origin'], $parsed['token']);
        $wellKnown = $this->peers->wellKnown($parsed['origin']);
        $this->assertWellKnown($wellKnown, $parsed['origin']);
        $theirDomain = $this->stringField($wellKnown, 'domain');
        if ($theirDomain === $this->domain()) {
            throw new FederationException('self', 'That invite was created on this server.');
        }
        if ($this->partners->findLiveByDomain($theirDomain) instanceof FederationPartner) {
            throw new FederationException('already_partner', 'You are already connected with this server.');
        }
        $body = $this->signer->signBody([
            'protocol' => 0,
            'action' => 'accept',
            'from' => $this->domain(),
            'name' => $identity->name,
            'key' => $identity->publicKey,
            'api' => $this->apiBase(),
            'invite' => $parsed['token'],
            'nonce' => bin2hex(random_bytes(16)),
            'issuedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $identity->secretKey);
        $this->peers->connect($this->stringField($wellKnown, 'api'), $body);

        $partner = new FederationPartner();
        $partner->setStatus(FederationPartner::STATUS_ACTIVE);
        $partner->setPeerDomain($theirDomain);
        $partner->setPeerApi($this->stringField($wellKnown, 'api'));
        $partner->setPeerKey($this->stringField($wellKnown, 'key'));
        $partner->setPeerName($this->displayName($wellKnown, $theirDomain));
        $partner->setAcceptedBy($actorId);
        $this->partners->save($partner);
        $this->audit->record($actorId, 'federation.connect', 'federation_partner', (string) $partner->getId(), [
            'domain' => $theirDomain,
        ]);

        return [
            'partner' => $this->partnerRow($partner),
            'peerConfirmed' => true,
            'message' => 'Connected. Nothing is shared yet, on either side.',
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function handleConnect(array $body): array
    {
        $this->requireOpened();
        $this->assertFresh($body);
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new FederationException('bad_request', 'The request is missing an action.');
        }
        if ('accept' === $action) {
            return $this->inboundAccept($body);
        }

        $from = $this->stringField($body, 'from');
        $partner = $this->partners->findLiveByDomain($from);
        $key = $partner?->getPeerKey();
        if (!$partner instanceof FederationPartner || !is_string($key) || '' === $key) {
            throw new FederationException('not_partner', 'There is no connection with this server.', 404);
        }
        $this->signer->verifyBody($body, $key);
        $this->rememberNonce($body);

        return match ($action) {
            'pause' => $this->inboundPause($partner),
            'resume' => $this->inboundResume($partner),
            'end' => $this->inboundEnd($partner),
            default => throw new FederationException('bad_request', 'That action is not supported.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function pause(int $actorId, int $id): array
    {
        $partner = $this->requireLive($id);
        $pausedBy = $partner->getPausedBy();
        $partner->setPausedBy(FederationPartner::PAUSE_REMOTE === $pausedBy || FederationPartner::PAUSE_BOTH === $pausedBy
            ? FederationPartner::PAUSE_BOTH
            : FederationPartner::PAUSE_LOCAL);
        $partner->setStatus(FederationPartner::STATUS_PAUSED);
        $this->partners->save($partner);
        $confirmed = $this->notify($partner, 'pause');
        $this->audit->record($actorId, 'federation.pause', 'federation_partner', (string) $partner->getId());

        return $this->actionResult($partner, $confirmed, 'Paused. They cannot use this connection until you resume.', 'Paused here. The other server has not confirmed yet.');
    }

    /**
     * @return array<string, mixed>
     */
    public function resume(int $actorId, int $id): array
    {
        $partner = $this->requireLive($id);
        $pausedBy = $partner->getPausedBy();
        if (FederationPartner::PAUSE_REMOTE === $pausedBy) {
            throw new FederationException('not_paused_by_us', 'They paused this connection. You can disconnect, or wait for them to resume.');
        }
        if (FederationPartner::PAUSE_BOTH === $pausedBy) {
            $partner->setPausedBy(FederationPartner::PAUSE_REMOTE);
            $partner->setStatus(FederationPartner::STATUS_PAUSED);
        } else {
            $partner->setPausedBy(null);
            $partner->setStatus(FederationPartner::STATUS_ACTIVE);
        }
        $this->partners->save($partner);
        $confirmed = $this->notify($partner, 'resume');
        $this->audit->record($actorId, 'federation.resume', 'federation_partner', (string) $partner->getId());

        return $this->actionResult($partner, $confirmed, 'Resumed.', 'Resumed here. The other server has not confirmed yet.');
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(int $actorId, int $id): array
    {
        $partner = $this->findOwned($id);
        if (FederationPartner::STATUS_INVITED === $partner->getStatus()) {
            $partner->setStatus(FederationPartner::STATUS_ENDED);
            $partner->setTokenHash(null);
            $this->partners->save($partner);
            $this->audit->record($actorId, 'federation.disconnect', 'federation_partner', (string) $partner->getId());

            return [
                'partner' => $this->partnerRow($partner),
                'peerConfirmed' => true,
                'message' => 'Invite removed. The link no longer works.',
            ];
        }
        $confirmed = $this->endPartner($partner, $actorId, true);

        return $this->actionResult(
            $partner,
            $confirmed,
            'Disconnected. This connection stops now.',
            'Disconnected here. The other server has not confirmed yet.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function wellKnown(): array
    {
        $identity = $this->identities->get();
        if (!$identity instanceof FederationIdentity || !$identity->opened) {
            throw new FederationException('not_open', 'Partners is closed on this server.', 404);
        }

        return [
            'protocol' => 0,
            'domain' => $this->domain(),
            'key' => $identity->publicKey,
            'api' => $this->apiBase(),
            'name' => $identity->name,
            'software' => 'synaplan',
        ];
    }

    private function endPartner(FederationPartner $partner, int $actorId, bool $notify): bool
    {
        $confirmed = !$notify || $this->notify($partner, 'end');
        $partner->setStatus(FederationPartner::STATUS_ENDED);
        $partner->setPausedBy(null);
        $partner->setTokenHash(null);
        $this->partners->save($partner);
        $this->audit->record($actorId, 'federation.disconnect', 'federation_partner', (string) $partner->getId(), [
            'domain' => $partner->getPeerDomain() ?? '',
        ]);

        return $confirmed;
    }

    private function notify(FederationPartner $partner, string $action): bool
    {
        $api = $partner->getPeerApi();
        $identity = $this->identities->get();
        if (!is_string($api) || '' === $api || !$identity instanceof FederationIdentity) {
            return false;
        }
        try {
            $body = $this->signer->signBody([
                'protocol' => 0,
                'action' => $action,
                'from' => $this->domain(),
                'nonce' => bin2hex(random_bytes(16)),
                'issuedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ], $identity->secretKey);
            $this->peers->connect($api, $body);

            return true;
        } catch (FederationException $e) {
            $this->logger->info('Federation partner did not confirm', [
                'domain' => $partner->getPeerDomain(),
                'action' => $action,
                'code' => $e->errorCode,
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function inboundAccept(array $body): array
    {
        $key = $this->stringField($body, 'key');
        $this->signer->verifyBody($body, $key);
        $this->rememberNonce($body);
        $token = $this->stringField($body, 'invite');
        $partner = $this->usableInvite($token);
        $api = $this->stringField($body, 'api');
        $from = $this->stringField($body, 'from');
        $this->urls->assertOutbound($api);
        if ($this->domainOf($api) !== $from) {
            throw new FederationException('bad_request', 'The server address does not match the signature.');
        }
        if ($from === $this->domain()) {
            throw new FederationException('self', 'A server cannot connect to itself.');
        }
        $origin = $this->originOf($api);
        $wellKnown = $this->peers->wellKnown($origin);
        $this->assertWellKnown($wellKnown, $origin);
        if ($this->stringField($wellKnown, 'domain') !== $from || $this->stringField($wellKnown, 'key') !== $key || $this->stringField($wellKnown, 'api') !== $api) {
            throw new FederationException('bad_request', 'The server identity does not match its public address.');
        }
        if ($this->partners->findLiveByDomain($from) instanceof FederationPartner) {
            throw new FederationException('already_partner', 'You are already connected with this server.', 409);
        }
        $partner->setStatus(FederationPartner::STATUS_ACTIVE);
        $partner->setTokenHash(null);
        $partner->setExpiresAt(null);
        $partner->setPeerDomain($from);
        $partner->setPeerApi($api);
        $partner->setPeerKey($key);
        $partner->setPeerName($this->displayName($body, $from));
        $this->partners->save($partner);
        $this->audit->record(0, 'federation.connect', 'federation_partner', (string) $partner->getId(), ['domain' => $from]);

        return [
            'ok' => true,
            'domain' => $this->domain(),
            'name' => $this->requireOpened()->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundPause(FederationPartner $partner): array
    {
        $pausedBy = $partner->getPausedBy();
        $partner->setPausedBy(FederationPartner::PAUSE_LOCAL === $pausedBy || FederationPartner::PAUSE_BOTH === $pausedBy
            ? FederationPartner::PAUSE_BOTH
            : FederationPartner::PAUSE_REMOTE);
        $partner->setStatus(FederationPartner::STATUS_PAUSED);
        $this->partners->save($partner);

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundResume(FederationPartner $partner): array
    {
        $pausedBy = $partner->getPausedBy();
        if (FederationPartner::PAUSE_BOTH === $pausedBy) {
            $partner->setPausedBy(FederationPartner::PAUSE_LOCAL);
            $partner->setStatus(FederationPartner::STATUS_PAUSED);
        } elseif (FederationPartner::PAUSE_REMOTE === $pausedBy) {
            $partner->setPausedBy(null);
            $partner->setStatus(FederationPartner::STATUS_ACTIVE);
        }
        $this->partners->save($partner);

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundEnd(FederationPartner $partner): array
    {
        $partner->setStatus(FederationPartner::STATUS_ENDED);
        $partner->setPausedBy(null);
        $partner->setTokenHash(null);
        $this->partners->save($partner);
        $this->audit->record(0, 'federation.disconnect', 'federation_partner', (string) $partner->getId(), [
            'domain' => $partner->getPeerDomain() ?? '',
        ]);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertFresh(array $body): void
    {
        if (0 !== ($body['protocol'] ?? null)) {
            throw new FederationException('bad_request', 'This server does not speak that protocol version.');
        }
        $issuedAt = $body['issuedAt'] ?? null;
        if (!is_string($issuedAt)) {
            throw new FederationException('bad_request', 'The request time is missing.');
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $issuedAt, new \DateTimeZone('UTC'));
        if (!$parsed instanceof \DateTimeImmutable) {
            throw new FederationException('bad_request', 'The request time is not valid.');
        }
        if (abs(time() - $parsed->getTimestamp()) > self::SKEW_SECONDS) {
            throw new FederationException('bad_request', 'The request is too old.');
        }
        $nonce = $body['nonce'] ?? null;
        if (!is_string($nonce) || 1 !== preg_match('/^[a-f0-9]{16,64}$/', $nonce)) {
            throw new FederationException('bad_request', 'The request nonce is not valid.');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rememberNonce(array $body): void
    {
        $nonce = $body['nonce'] ?? '';
        if (!is_string($nonce)) {
            throw new FederationException('bad_request', 'The request nonce is not valid.');
        }
        $item = $this->cache->getItem('federation_nonce_'.hash('sha256', $nonce));
        if ($item->isHit()) {
            throw new FederationException('replay', 'This request was already used.', 409);
        }
        $item->set(1);
        $item->expiresAfter(self::SKEW_SECONDS);
        $this->cache->save($item);
    }

    /**
     * @param array<string, mixed> $wellKnown
     */
    private function assertWellKnown(array $wellKnown, string $origin): void
    {
        if (0 !== ($wellKnown['protocol'] ?? null)) {
            throw new FederationException('bad_request', 'The other server speaks a different version.');
        }
        $api = $this->stringField($wellKnown, 'api');
        $this->urls->assertOutbound($api);
        if ($this->originOf($api) !== $origin) {
            throw new FederationException('bad_request', 'The other server\'s address does not match the invite.');
        }
    }

    private function usableInvite(string $token): FederationPartner
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new FederationException('invite_invalid', 'This invite is not valid.', 404);
        }
        $partner = $this->partners->findByTokenHash(hash('sha256', $token));
        if (!$partner instanceof FederationPartner || FederationPartner::STATUS_INVITED !== $partner->getStatus()) {
            throw new FederationException('invite_invalid', 'This invite is not valid.', 404);
        }
        $expires = $partner->getExpiresAt();
        if (null === $expires || $expires < time()) {
            throw new FederationException('invite_expired', 'This invite has expired. Ask for a new one.', 410);
        }

        return $partner;
    }

    private function requireOpened(): FederationIdentity
    {
        $identity = $this->identities->get();
        if (!$identity instanceof FederationIdentity || !$identity->opened) {
            throw new FederationException('not_open', 'Open to partners before connecting.', 409);
        }

        return $identity;
    }

    private function assertReachable(): void
    {
        if (!$this->urls->isReachableAppUrl($this->appUrl)) {
            throw new FederationException('not_reachable', 'Your server runs on a private address, so other companies cannot reach it. Partners need a public https address.', 409);
        }
    }

    private function requireLive(int $id): FederationPartner
    {
        $partner = $this->findOwned($id);
        if (!$partner->isLive()) {
            throw new FederationException('not_partner', 'That connection is not active.', 404);
        }

        return $partner;
    }

    private function findOwned(int $id): FederationPartner
    {
        $partner = $this->partners->find($id);
        if (!$partner instanceof FederationPartner || FederationPartner::STATUS_ENDED === $partner->getStatus()) {
            throw new FederationException('not_partner', 'That connection was not found.', 404);
        }

        return $partner;
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX || str_contains($name, "\n") || str_contains($name, "\r")) {
            throw new FederationException('invalid_name', 'Enter a company name of up to 80 characters.');
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new FederationException('bad_request', 'The request is missing '.$key.'.');
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function displayName(array $body, string $domain): string
    {
        $name = $body['name'] ?? null;
        if (!is_string($name)) {
            return $domain;
        }
        $name = trim($name);

        return '' === $name || mb_strlen($name) > self::NAME_MAX ? $domain : $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerRow(FederationPartner $partner): array
    {
        return [
            'id' => $partner->getId(),
            'status' => $partner->getStatus(),
            'name' => $partner->getPeerName() ?? '',
            'domain' => $partner->getPeerDomain() ?? '',
            'pausedBy' => $partner->getPausedBy(),
            'expiresAt' => $partner->getExpiresAt(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionResult(FederationPartner $partner, bool $confirmed, string $ok, string $pending): array
    {
        return [
            'partner' => $this->partnerRow($partner),
            'peerConfirmed' => $confirmed,
            'message' => $confirmed ? $ok : $pending,
        ];
    }

    private function apiBase(): string
    {
        return rtrim($this->appUrl, '/').'/api/v1/federation';
    }

    private function domain(): string
    {
        return $this->domainOf($this->appUrl);
    }

    private function domainOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        if (is_int($port) && !in_array($port, [80, 443], true)) {
            return $host.':'.$port;
        }

        return $host;
    }

    private function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $origin = strtolower((string) ($parts['scheme'] ?? '')).'://'.strtolower((string) ($parts['host'] ?? ''));
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
