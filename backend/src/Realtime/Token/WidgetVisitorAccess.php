<?php

declare(strict_types=1);

namespace App\Realtime\Token;

/**
 * Outcome of {@see WidgetVisitorAccessChecker::check()}.
 *
 * Deliberately coarse: every lookup failure (unknown widget, unknown
 * session) collapses into NOT_FOUND so the HTTP layer cannot accidentally
 * re-introduce an enumeration oracle by mapping the cases to different
 * responses. An expired-but-existing session is NOT a lookup failure — see
 * {@see WidgetVisitorAccessChecker} for why it is treated as resumable.
 */
enum WidgetVisitorAccess
{
    case Granted;
    case NotFound;
    case OriginDenied;
}
