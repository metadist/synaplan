<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Security;

use App\AI\Credential\UserProviderKeyResolver;
use App\Command\SecretsReencryptCommand;
use App\Entity\InboundEmailHandler;
use App\Repository\ConfigRepository;
use App\Service\Security\EncryptionService;
use App\Service\Security\SecretReencryptor;
use App\Service\Security\SecretReencryptTally;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Secrets written by an earlier release are rewritten in place: readable by
 * their owner afterwards, and by nobody the row is copied to.
 */
final class SecretReencryptorTest extends KernelTestCase
{
    private const OWNER = 9_410_001;
    private const OTHER_OWNER = 9_410_002;
    private const GROUP = UserProviderKeyResolver::CONFIG_GROUP;

    private EntityManagerInterface $em;
    private ConfigRepository $configs;
    private EncryptionService $encryption;
    private SecretReencryptor $reencryptor;
    private UserProviderKeyResolver $userKeys;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->configs = $container->get(ConfigRepository::class);
        $this->encryption = $container->get(EncryptionService::class);
        $this->reencryptor = $container->get(SecretReencryptor::class);
        $this->userKeys = $container->get(UserProviderKeyResolver::class);
    }

    public function testAnOlderProviderKeyIsRewrittenAndStillReadByItsOwner(): void
    {
        $legacy = $this->legacyCiphertext('sk-legacy-user-key');
        $this->configs->setValue(self::OWNER, self::GROUP, 'openai', $legacy);

        $tally = $this->tally($this->reencryptor->run(true), 'BCONFIG '.self::GROUP);

        $stored = $this->storedConfig(self::OWNER, 'openai');
        self::assertGreaterThanOrEqual(1, $tally->rewritten);
        self::assertStringStartsWith('v2:', $stored);
        self::assertTrue($this->userKeys->hasUserKey(self::OWNER, 'openai'));
        self::assertSame('sk-l'.str_repeat('*', 14), $this->userKeys->maskedUserKey(self::OWNER, 'openai'));
    }

    public function testARewrittenKeyCopiedToAnotherOwnerIsNotRead(): void
    {
        $this->configs->setValue(self::OWNER, self::GROUP, 'openai', $this->legacyCiphertext('sk-legacy-user-key'));
        $this->reencryptor->run(true);

        $this->configs->setValue(self::OTHER_OWNER, self::GROUP, 'openai', $this->storedConfig(self::OWNER, 'openai'));

        self::assertFalse($this->userKeys->hasUserKey(self::OTHER_OWNER, 'openai'));
    }

    public function testASecondRunFindsNothingLeftToRewrite(): void
    {
        $this->configs->setValue(self::OWNER, self::GROUP, 'openai', $this->legacyCiphertext('sk-legacy-user-key'));
        $this->reencryptor->run(true);
        $stored = $this->storedConfig(self::OWNER, 'openai');

        $rewritten = array_sum(array_map(
            static fn (SecretReencryptTally $tally): int => $tally->rewritten,
            $this->reencryptor->run(true),
        ));

        self::assertSame(0, $rewritten);
        self::assertSame($stored, $this->storedConfig(self::OWNER, 'openai'));
    }

    public function testATrialRunCountsAndChangesNothing(): void
    {
        $legacy = $this->legacyCiphertext('sk-legacy-user-key');
        $this->configs->setValue(self::OWNER, self::GROUP, 'openai', $legacy);

        $tester = new CommandTester(static::getContainer()->get(SecretsReencryptCommand::class));
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
        self::assertSame($legacy, $this->storedConfig(self::OWNER, 'openai'));
    }

    public function testAValueThisInstallationCannotOpenIsReportedAndLeftAsItIs(): void
    {
        $foreign = (new EncryptionService('a-secret-this-installation-never-had', $this->createStub(\Psr\Log\LoggerInterface::class)))
            ->encrypt('sk-foreign');
        $row = $this->configs->setValue(self::OWNER, self::GROUP, 'anthropic', $foreign);

        $tally = $this->tally($this->reencryptor->run(true), 'BCONFIG '.self::GROUP);

        self::assertContains((int) $row->getId(), $tally->unreadableIds);
        self::assertSame($foreign, $this->storedConfig(self::OWNER, 'anthropic'));
    }

    public function testARowOfAGroupThatHoldsPlainSettingsIsNotTouched(): void
    {
        $this->configs->setValue(0, 'FREEAPI', 'REENCRYPT_TEST_PLAIN', 'plain-setting');

        $this->reencryptor->run(true);

        self::assertSame('plain-setting', $this->configs->getValue(0, 'FREEAPI', 'REENCRYPT_TEST_PLAIN'));
    }

    public function testTheSmtpPasswordInsideAHandlerConfigIsRewrittenAndTheRestKept(): void
    {
        $handler = (new InboundEmailHandler())
            ->setUserId(self::OWNER)
            ->setName('Support mailbox')
            ->setMailServer('imap.example.com')
            ->setPort(993)
            ->setProtocol('IMAP')
            ->setSecurity('SSL/TLS')
            ->setUsername('support@example.com')
            ->setPassword($this->legacyCiphertext('imap-password'))
            ->setCheckInterval(10)
            ->setDepartments([])
            ->setConfig([
                'email_filter' => ['mode' => 'new'],
                'smtp' => [
                    'server' => 'smtp.example.com',
                    'port' => 587,
                    'username' => 'support@example.com',
                    'password' => $this->legacyCiphertext('smtp-password'),
                    'security' => 'STARTTLS',
                ],
            ]);
        $this->em->persist($handler);
        $this->em->flush();
        $id = (int) $handler->getId();

        $this->reencryptor->run(true);

        $this->em->clear();
        $reloaded = $this->em->find(InboundEmailHandler::class, $id);
        self::assertInstanceOf(InboundEmailHandler::class, $reloaded);
        self::assertStringStartsWith('v2:', $reloaded->getPassword());
        self::assertSame('imap-password', $reloaded->getDecryptedPassword($this->encryption));
        self::assertSame([
            'server' => 'smtp.example.com',
            'port' => 587,
            'username' => 'support@example.com',
            'password' => 'smtp-password',
            'security' => 'STARTTLS',
        ], $reloaded->getSmtpCredentials($this->encryption));
        self::assertSame(['mode' => 'new'], $reloaded->getConfig()['email_filter'] ?? null);
        self::assertStringStartsWith('v2:', $reloaded->getConfig()['smtp']['password'] ?? '');
    }

    public function testAMailboxPasswordDoesNotOpenAsAnSmtpPassword(): void
    {
        $handler = (new InboundEmailHandler())->setUserId(self::OWNER);
        $handler->setDecryptedPassword('imap-password', $this->encryption);
        $handler->setConfig(['smtp' => ['password' => $handler->getPassword()]]);

        $this->expectException(\RuntimeException::class);

        $handler->getSmtpCredentials($this->encryption);
    }

    /**
     * @param list<SecretReencryptTally> $tallies
     */
    private function tally(array $tallies, string $location): SecretReencryptTally
    {
        foreach ($tallies as $tally) {
            if ($location === $tally->location) {
                return $tally;
            }
        }

        self::fail('No tally for '.$location);
    }

    private function storedConfig(int $ownerId, string $setting): string
    {
        $this->em->clear();

        return (string) $this->configs->getValue($ownerId, self::GROUP, $setting);
    }

    /** The format releases before v2 wrote: base64(IV . AES-256-CBC). */
    private function legacyCiphertext(string $plaintext): string
    {
        $secret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', hash('sha256', $secret, true), \OPENSSL_RAW_DATA, $iv);
        self::assertIsString($encrypted);

        return base64_encode($iv.$encrypted);
    }
}
