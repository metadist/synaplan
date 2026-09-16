<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Service\OidcUserService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Browser login and the token-exchanged bearer path must resolve to one BUSER
 * and one BEXTERNALIDENTITIES row for the same Keycloak `sub` (IAM34).
 */
final class OidcBearerIdentityTest extends KernelTestCase
{
    public function testBrowserAndBearerClaimsResolveToTheSameUser(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $service = $container->get(OidcUserService::class);
        $identities = $container->get(ExternalIdentityRepository::class);
        $em = $container->get('doctrine')->getManager();

        $sub = 'iam34-'.bin2hex(random_bytes(6));
        $email = $sub.'@synaplan.internal';
        $claims = [
            'sub' => $sub,
            'email' => $email,
            'preferred_username' => 'iam34',
            'iss' => 'https://idp.example/realms/synaplan',
            'groups' => ['sales'],
        ];

        $fromBrowser = $service->findOrCreateFromClaims($claims, 'refresh-token');
        $fromBearer = $service->findOrCreateFromClaims($claims);

        self::assertSame($fromBrowser->getId(), $fromBearer->getId());
        $rows = $identities->findByUserId((int) $fromBrowser->getId());
        $oidcRows = array_values(array_filter(
            $rows,
            static fn ($row): bool => str_starts_with($row->getSource(), 'oidc:'),
        ));
        self::assertCount(1, $oidcRows);
        self::assertSame($sub, $oidcRows[0]->getExternalId());

        $user = $em->getRepository(User::class)->find($fromBrowser->getId());
        if ($user instanceof User) {
            $em->remove($user);
            $em->flush();
        }
    }
}
