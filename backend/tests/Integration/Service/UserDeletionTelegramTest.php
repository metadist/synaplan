<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\TelegramBot;
use App\Entity\User;
use App\Repository\TelegramBotRepository;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\UserDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deleting an account leaves no Telegram bot row and no stored bot token.
 */
final class UserDeletionTelegramTest extends KernelTestCase
{
    public function testDeletingTheAccountRemovesTheBotAndItsToken(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $vault = $container->get(CredentialVaultInterface::class);
        $bots = $container->get(TelegramBotRepository::class);

        $user = new User();
        $user->setMail('telegram-delete-'.bin2hex(random_bytes(4)).'@example.com');
        $user->setProviderId('local');
        $user->setPw('dummy_hash');
        $user->setUserLevel('NEW');
        $user->setEmailVerified(true);
        $user->setCreated(date('YmdHis'));
        $em->persist($user);
        $em->flush();
        $userId = (int) $user->getId();

        $credentialId = $vault->store($userId, TelegramBot::CREDENTIAL_KIND, '123456789:AAHexampleToken');
        $bot = new TelegramBot($userId, bin2hex(random_bytes(16)), 4242, 'synaplan_test_bot');
        $bot->setCredentialId($credentialId);
        $bot->setStatus(TelegramBot::STATUS_CONNECTED);
        $bots->save($bot);

        $container->get(UserDeletionService::class)->deleteUser($user);
        $em->clear();

        $this->assertNull($bots->findOneByOwner($userId));
        $this->expectException(\Throwable::class);
        $vault->reveal($credentialId, $userId);
    }
}
