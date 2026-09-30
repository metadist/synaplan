<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Federation;

use App\Entity\FederationPartner;
use App\Repository\FederationPartnerRepository;
use App\Service\Federation\FederationException;
use App\Service\Federation\FederationIdentity;
use App\Service\Federation\FederationIdentityStore;
use App\Service\Federation\FederationLinkService;
use App\Service\Federation\FederationPeerClient;
use App\Service\Federation\FederationSigner;
use App\Service\Federation\FederationUrlGuard;
use App\Service\Iam\AuditLogWriter;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FederationLinkServiceTest extends TestCase
{
    public function testOpenRefusesAPrivateAddress(): void
    {
        $service = $this->service('http://localhost:8000', false);

        try {
            $service->open(1, 'Contoso GmbH');
            $this->fail('private server was opened');
        } catch (FederationException $e) {
            $this->assertSame('not_reachable', $e->errorCode);
        }
    }

    public function testAcceptPinsThePeerKeyAndRejectsAReplay(): void
    {
        if (!FederationSigner::available()) {
            self::markTestSkipped('ext-sodium is not loaded');
        }
        $signer = new FederationSigner();
        $theirs = $signer->generate();
        $token = '0123456789abcdef0123456789abcdef';
        $invite = new FederationPartner();
        $invite->setStatus(FederationPartner::STATUS_INVITED);
        $invite->setTokenHash(hash('sha256', $token));
        $invite->setExpiresAt(time() + 3600);

        $partners = $this->createMock(FederationPartnerRepository::class);
        $partners->method('findByTokenHash')->willReturn($invite);
        $partners->method('findLiveByDomain')->willReturn(null);
        $partners->expects($this->once())->method('save')->with($this->callback(
            static function (FederationPartner $partner) use ($theirs): bool {
                return FederationPartner::STATUS_ACTIVE === $partner->getStatus()
                    && null === $partner->getTokenHash()
                    && $partner->getPeerKey() === $theirs['publicKey']
                    && '127.0.0.1:9' === $partner->getPeerDomain();
            }
        ));

        $api = 'http://127.0.0.1:9/api/v1/federation';
        $peers = $this->createStub(FederationPeerClient::class);
        $peers->method('wellKnown')->willReturn([
            'protocol' => 0,
            'domain' => '127.0.0.1:9',
            'key' => $theirs['publicKey'],
            'api' => $api,
            'name' => 'Fleet Service',
        ]);

        $service = $this->service('https://contoso.example', true, $partners, $peers, $signer);
        $body = $signer->signBody([
            'protocol' => 0,
            'action' => 'accept',
            'from' => '127.0.0.1:9',
            'name' => 'Fleet Service',
            'key' => $theirs['publicKey'],
            'api' => $api,
            'invite' => $token,
            'nonce' => 'abcdabcdabcdabcd',
            'issuedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $theirs['secretKey']);

        $result = $service->handleConnect($body);
        $this->assertTrue($result['ok']);

        $this->expectException(FederationException::class);
        $service->handleConnect($body);
    }

    private function service(
        string $appUrl,
        bool $allowLocal,
        ?FederationPartnerRepository $partners = null,
        ?FederationPeerClient $peers = null,
        ?FederationSigner $signer = null,
    ): FederationLinkService {
        $signer ??= new FederationSigner();
        $ours = FederationSigner::available() ? $signer->generate() : ['publicKey' => 'ed25519:aa', 'secretKey' => 'ed25519:bb'];
        $identities = $this->createStub(FederationIdentityStore::class);
        $identities->method('get')->willReturn(new FederationIdentity($ours['publicKey'], $ours['secretKey'], 'Contoso GmbH', true));

        return new FederationLinkService(
            $identities,
            $partners ?? $this->createStub(FederationPartnerRepository::class),
            $peers ?? $this->createStub(FederationPeerClient::class),
            $signer,
            new FederationUrlGuard(new SsrfGuard(), $allowLocal),
            new ArrayAdapter(),
            $this->createStub(AuditLogWriter::class),
            new NullLogger(),
            $appUrl,
            'https://contoso.example',
        );
    }
}
