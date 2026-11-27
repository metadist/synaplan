<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Exception\CloudDeliveryException;
use App\Repository\PlatformInstanceRepository;
use App\Security\OidcBearerAuthenticator;
use App\Service\PlatformLink\CloudCallerResolver;
use App\Service\PlatformLink\OidcCloud;
use App\Service\PlatformLink\SourceInstanceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CloudCallerResolverTest extends TestCase
{
    private const CLAIMS = ['sub' => 'person-1', 'preferred_username' => 'ada', 'employee_id' => 'e-17'];

    public function testEmptyMeansTheSubjectByDefault(): void
    {
        self::assertSame('person-1', $this->resolver()->externalId($this->request(self::CLAIMS), ' '));
    }

    public function testEmptyMeansTheConfiguredClaim(): void
    {
        self::assertSame('e-17', $this->resolver('employee_id')->externalId($this->request(self::CLAIMS), ''));
    }

    public function testEmptyIsRefusedWhenTheTokenLacksTheConfiguredClaim(): void
    {
        try {
            $this->resolver('employee_id')->externalId($this->request(['sub' => 'person-1']), '');
            self::fail('A token without the configured claim names nobody.');
        } catch (CloudDeliveryException $e) {
            self::assertSame(Response::HTTP_UNAUTHORIZED, $e->status);
        }
    }

    public function testTheSubjectTheLoginAndTheConfiguredClaimAreAccepted(): void
    {
        $resolver = $this->resolver('employee_id');
        $request = $this->request(self::CLAIMS);

        self::assertSame('person-1', $resolver->externalId($request, 'person-1'));
        self::assertSame('ada', $resolver->externalId($request, 'ada'));
        self::assertSame('e-17', $resolver->externalId($request, 'e-17'));
    }

    public function testAnotherClaimOfTheTokenIsRefusedByDefault(): void
    {
        try {
            $this->resolver()->externalId($this->request(self::CLAIMS), 'e-17');
            self::fail('Only the subject, the login and the configured claim name the person.');
        } catch (CloudDeliveryException $e) {
            self::assertSame(Response::HTTP_FORBIDDEN, $e->status);
            self::assertSame('external_id_mismatch', $e->errorCode);
        }
    }

    private function resolver(string $userClaim = ''): CloudCallerResolver
    {
        return new CloudCallerResolver(
            $this->createStub(SourceInstanceResolver::class),
            $this->createStub(PlatformInstanceRepository::class),
            new OidcCloud('opencloud', 'https://cloud.example', 'synaplan-opencloud', '', $userClaim),
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function request(array $claims): Request
    {
        $request = new Request();
        $request->attributes->set(OidcBearerAuthenticator::CLAIMS_ATTRIBUTE, $claims);

        return $request;
    }
}
