<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\OidcAuthorizeParams;
use App\Tests\Support\OidcAccessPolicyFixture;
use PHPUnit\Framework\TestCase;

final class OidcAuthorizeParamsTest extends TestCase
{
    public function testNothingIsAddedForAPlainSetup(): void
    {
        $params = new OidcAuthorizeParams(OidcAccessPolicyFixture::open());

        self::assertSame([], $params->params());
    }

    public function testExplicitAudienceAndOrganizationAreRequested(): void
    {
        $params = new OidcAuthorizeParams(
            OidcAccessPolicyFixture::with(orgCode: 'org_acme'),
            ' https://synaplan.acme.example/api ',
        );

        self::assertSame(
            ['audience' => 'https://synaplan.acme.example/api', 'org_code' => 'org_acme'],
            $params->params(),
        );
    }
}
