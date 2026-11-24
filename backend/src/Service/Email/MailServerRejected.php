<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * A mail server could not be used: its address is not allowed, or the
 * connection or the sign-in failed. The message is written for the person
 * who entered the settings and carries no detail from the mail library.
 */
final class MailServerRejected extends \RuntimeException
{
    public static function address(): self
    {
        return new self('This mail server address is not allowed. Use the public host name of your mail provider.');
    }

    /**
     * @param list<int> $ports
     */
    public static function port(array $ports): self
    {
        return new self(sprintf('This port is not allowed. Use one of: %s.', implode(', ', $ports)));
    }

    public static function protocol(): self
    {
        return new self('The protocol must be IMAP or POP3.');
    }

    public static function signIn(): self
    {
        return new self('The mail server rejected the sign-in. Check the username and password.');
    }

    public static function connection(): self
    {
        return new self('Could not connect to the mail server. Check the server, port and security setting.');
    }
}
