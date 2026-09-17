<?php

declare(strict_types=1);

namespace Guild\Grouper;

use Throwable;

/**
 * Grouper could not be reached, so this user's membership is unknown.
 *
 * This is deliberately **not** a Throwable. Whether an unreachable Grouper
 * means "deny everything", "serve a cached answer" or "degrade to read-only" is
 * an application policy question, and this library does not have an opinion
 * about it. Returning the condition as a value forces the caller to make that
 * decision explicitly instead of letting an exception sail past them.
 *
 * Misconfiguration is the opposite case and *is* thrown — see
 * GrouperConfigurationException.
 */
final readonly class GrouperUnavailable
{
    /**
     * @param  string  $reason  Human-readable summary, safe to log.
     * @param  int|null  $statusCode  The HTTP status when one was received; null when the
     *                                connection never completed.
     * @param  Throwable|null  $previous  The underlying transport error, kept for logging.
     */
    public function __construct(
        public string $reason,
        public ?int $statusCode = null,
        public ?Throwable $previous = null,
    ) {
    }
}
