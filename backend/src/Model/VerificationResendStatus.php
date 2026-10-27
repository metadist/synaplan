<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Outcome of asking for another verification email.
 */
enum VerificationResendStatus
{
    /** This install cannot send mail. Nothing was stored. */
    case Unconfigured;

    /** The message was handed to the transport. */
    case Sent;

    /**
     * The address is unknown or already verified. The response stays generic
     * so it does not reveal which, and the attempt is counted.
     */
    case Accepted;

    /** Cooldown or the resend allowance is used up. */
    case RateLimited;

    /** The transport rejected the message. The attempt is not counted. */
    case Failed;
}
