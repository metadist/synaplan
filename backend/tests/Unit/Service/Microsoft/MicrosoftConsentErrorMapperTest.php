<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Microsoft;

use App\Service\Microsoft\MicrosoftConsentErrorMapper;
use PHPUnit\Framework\TestCase;

final class MicrosoftConsentErrorMapperTest extends TestCase
{
    private MicrosoftConsentErrorMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MicrosoftConsentErrorMapper();
    }

    public function testPersonalAccountCodeWinsOverAccessDenied(): void
    {
        self::assertSame(
            'personal_account',
            $this->mapper->reason(
                'access_denied',
                'AADSTS50020: User account from identity provider does not exist in tenant',
            ),
        );
    }

    public function testAdminConsentCode(): void
    {
        self::assertSame(
            'admin_consent',
            $this->mapper->reason('access_denied', 'AADSTS90094: Admin consent is required'),
        );
        self::assertSame(
            'admin_consent',
            $this->mapper->reason('interaction_required', 'AADSTS65001: The user or administrator has not consented'),
        );
    }

    public function testPlainCancellationStaysAccessDenied(): void
    {
        self::assertSame('access_denied', $this->mapper->reason('access_denied', ''));
    }

    public function testUnknownProviderErrorDoesNotLeak(): void
    {
        self::assertSame('unknown', $this->mapper->reason('server_error', 'something exploded in tenant contoso'));
    }
}
