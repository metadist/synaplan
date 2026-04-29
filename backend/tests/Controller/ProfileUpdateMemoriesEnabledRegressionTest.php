<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\TokenService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Reproduces the *real* bug from the user report:
 *
 *   "Ich habe gerade versucht, den namen im profil als impersonater zu
 *    ändern, er hats zwar erfolgreich angezeigt aber beim refresh der
 *    seite hat er das nicht gespeichert und den alten angezeigt."
 *
 * Root cause: ProfileController::updateProfile builds a local `$details`
 * array, applies the new values from the request, then — when the request
 * contains `memoriesEnabled` (which the frontend always sends) — calls
 * `$user->setMemoriesEnabled()` and re-fetches `$details` from the entity,
 * silently dropping the new firstName/lastName/etc. that were just applied.
 */
class ProfileUpdateMemoriesEnabledRegressionTest extends WebTestCase
{
    public function testFirstNameMustPersistEvenWhenMemoriesEnabledIsAlsoSent(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();
        $tokenService = $client->getContainer()->get(TokenService::class);

        $user = new User();
        $user->setMail('memflag-regression@example.com');
        $user->setPw(password_hash('Pass123!', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $user->setUserDetails([
            'firstName' => 'OldFirst',
            'lastName' => 'OldLast',
            'memories_enabled' => true,
        ]);
        $em->persist($user);
        $em->flush();
        $userId = (int) $user->getId();

        $accessToken = $tokenService->generateAccessToken($user);
        $client->getCookieJar()->set(new Cookie(
            TokenService::ACCESS_COOKIE,
            $accessToken,
            (string) (time() + TokenService::ACCESS_TOKEN_TTL),
            '/',
            'localhost',
        ));

        // Send EXACTLY the payload shape the frontend ProfileView sends:
        // includes memoriesEnabled, which triggers the bug.
        $client->request(
            'PUT',
            '/api/v1/profile',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'firstName' => 'NewFirst',
                'lastName' => 'NewLast',
                'phone' => '',
                'companyName' => '',
                'vatId' => '',
                'street' => '',
                'zipCode' => '',
                'city' => '',
                'country' => 'DE',
                'language' => 'en',
                'timezone' => 'Europe/Berlin',
                'invoiceEmail' => '',
                'memoriesEnabled' => true,
            ]),
        );

        $this->assertResponseIsSuccessful();

        // Read back from a fresh DB query — simulates the page reload.
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($userId);
        $this->assertInstanceOf(User::class, $reloaded);
        $details = $reloaded->getUserDetails();

        $this->assertSame(
            'NewFirst',
            $details['firstName'] ?? null,
            'firstName must persist even when the request also contains memoriesEnabled.',
        );
        $this->assertSame(
            'NewLast',
            $details['lastName'] ?? null,
            'lastName must persist even when the request also contains memoriesEnabled.',
        );

        // Cleanup
        $em->remove($reloaded);
        $em->flush();
    }
}
