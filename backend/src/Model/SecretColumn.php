<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The entity columns that hold a ciphertext, for the one job that walks them
 * all: moving stored secrets to the current format.
 */
enum SecretColumn: string
{
    case MailboxPassword = 'BINBOUNDEMAILHANDLER.BPASSWORD';
    case MailboxSmtpPassword = 'BINBOUNDEMAILHANDLER.BCONFIG';
    case McpAuthToken = 'BMCPSERVERS.BAUTHTOKEN';
    case McpOAuthState = 'BMCPSERVERS.BOAUTH';
    case CredentialSecret = 'BCREDENTIALS.BSECRET';

    public function table(): string
    {
        return explode('.', $this->value)[0];
    }

    public function column(): string
    {
        return explode('.', $this->value)[1];
    }

    public function ownerColumn(): string
    {
        return self::CredentialSecret === $this ? 'BOWNERID' : 'BUSERID';
    }
}
