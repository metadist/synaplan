<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Security\OidcBearerAuthenticator;
use App\Service\PlatformLink\SourceInstanceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SourceInstanceResolverTest extends TestCase
{
    public function testOneActiveInstanceForTheBearersClientIsTheSource(): void
    {
        $resolver = $this->resolver([$this->instance('pi_one', PlatformInstance::STATUS_ACTIVE)]);
        $request = $this->bearer('synaplan-opencloud');

        self::assertSame('pi_one', $resolver->fromRequest($request));
        self::assertFalse($resolver->isAmbiguous($request));
    }

    public function testARevokedTwinDoesNotCount(): void
    {
        $resolver = $this->resolver([
            $this->instance('pi_old', PlatformInstance::STATUS_REVOKED),
            $this->instance('pi_new', PlatformInstance::STATUS_ACTIVE),
        ]);
        $request = $this->bearer('synaplan-opencloud');

        self::assertSame('pi_new', $resolver->fromRequest($request));
        self::assertFalse($resolver->isAmbiguous($request));
    }

    public function testTwoActiveInstancesAreAmbiguousNotUnknown(): void
    {
        $resolver = $this->resolver([
            $this->instance('pi_old', PlatformInstance::STATUS_ACTIVE),
            $this->instance('pi_new', PlatformInstance::STATUS_ACTIVE),
        ]);
        $request = $this->bearer('synaplan-opencloud');

        self::assertNull($resolver->fromRequest($request));
        self::assertTrue($resolver->isAmbiguous($request));
    }

    public function testNoInstanceIsNotAmbiguous(): void
    {
        $resolver = $this->resolver([]);
        $request = $this->bearer('synaplan-opencloud');

        self::assertNull($resolver->fromRequest($request));
        self::assertFalse($resolver->isAmbiguous($request));
    }

    public function testARequestWithoutClaimsResolvesToNothing(): void
    {
        $resolver = $this->resolver([$this->instance('pi_one', PlatformInstance::STATUS_ACTIVE)]);

        self::assertNull($resolver->fromRequest(new Request()));
        self::assertFalse($resolver->isAmbiguous(new Request()));
    }

    /**
     * @param list<PlatformInstance> $instances
     */
    private function resolver(array $instances): SourceInstanceResolver
    {
        $repository = $this->createStub(PlatformInstanceRepository::class);
        $repository->method('findBy')->willReturn($instances);

        return new SourceInstanceResolver($this->createStub(ExternalIdentityRepository::class), $repository);
    }

    private function instance(string $id, string $status): PlatformInstance
    {
        $instance = new PlatformInstance('opencloud', $id, 'cloud.example.org', null, $status);
        $instance->setOidcClientId('synaplan-opencloud');

        return $instance;
    }

    private function bearer(string $azp): Request
    {
        $request = new Request();
        $request->attributes->set(OidcBearerAuthenticator::CLAIMS_ATTRIBUTE, ['azp' => $azp, 'sub' => 'person']);

        return $request;
    }
}
