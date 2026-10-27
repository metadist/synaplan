<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpOAuthState;
use App\Service\Security\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A sign-in finishes after a network call. Whatever edit reached the row in
 * the meantime wins: the sign-in is stored only on the address it began on.
 */
final class McpServerConfigRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private McpServerConfigRepository $servers;
    private EncryptionService $encryption;
    private ?McpServerConfig $server = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->servers = static::getContainer()->get(McpServerConfigRepository::class);
        $this->encryption = static::getContainer()->get(EncryptionService::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->server?->getId()) {
            $this->em->getConnection()->executeStatement('DELETE FROM BMCPSERVERS WHERE BID = '.(int) $this->server->getId());
        }
        parent::tearDown();
    }

    public function testTheSignInIsStoredWhileTheRowIsUnchanged(): void
    {
        $server = $this->row('https://mcp.example.com/mcp', McpServerConfig::AUTH_MODE_OAUTH);

        self::assertTrue($this->servers->storeOAuthStateAt($server, 'https://mcp.example.com/mcp', $this->signedIn()));
        self::assertSame('at-1', $server->getDecryptedOAuthState($this->encryption)->accessToken);
    }

    public function testAnAddressEditDuringSignInWins(): void
    {
        $server = $this->row('https://mcp.example.com/mcp', McpServerConfig::AUTH_MODE_OAUTH);
        $this->em->getConnection()->executeStatement(
            "UPDATE BMCPSERVERS SET BURL = 'https://elsewhere.example/mcp' WHERE BID = ".(int) $server->getId()
        );

        self::assertFalse($this->servers->storeOAuthStateAt($server, 'https://mcp.example.com/mcp', $this->signedIn()));
        self::assertSame('https://elsewhere.example/mcp', $server->getUrl());
        self::assertSame('', $server->getDecryptedOAuthState($this->encryption)->accessToken);
    }

    public function testAMethodEditDuringSignInWins(): void
    {
        $server = $this->row('https://mcp.example.com/mcp', McpServerConfig::AUTH_MODE_OAUTH);
        $this->em->getConnection()->executeStatement(
            "UPDATE BMCPSERVERS SET BAUTHMODE = 'bearer' WHERE BID = ".(int) $server->getId()
        );

        self::assertFalse($this->servers->storeOAuthStateAt($server, 'https://mcp.example.com/mcp', $this->signedIn()));
        self::assertSame('', $server->getDecryptedOAuthState($this->encryption)->accessToken);
    }

    public function testStartingASignInSwitchesTheMethodAndDropsTheKey(): void
    {
        $server = $this->row('https://mcp.example.com/mcp', McpServerConfig::AUTH_MODE_BEARER);

        self::assertTrue($this->servers->storeOAuthStateAt($server, 'https://mcp.example.com/mcp', $this->signedIn(), startingSignIn: true));
        self::assertTrue($server->isOAuth());
        self::assertFalse($server->hasAuthToken());
    }

    private function row(string $url, string $mode): McpServerConfig
    {
        $server = (new McpServerConfig())
            ->setUserId(random_int(900_000_000, 999_999_999))
            ->setName('race test')
            ->setUrl($url)
            ->setAuthHeader('Authorization')
            ->setAuthMode($mode);
        $server->setDecryptedAuthToken('Bearer key', $this->encryption);
        $this->servers->save($server);

        return $this->server = $server;
    }

    private function signedIn(): string
    {
        return McpServerConfig::encryptOAuthState(new McpOAuthState(
            resource: 'https://mcp.example.com/mcp',
            accessToken: 'at-1',
            status: McpOAuthState::STATUS_CONNECTED,
        ), $this->encryption);
    }
}
