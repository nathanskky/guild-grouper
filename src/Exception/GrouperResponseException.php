<?php

declare(strict_types=1);

namespace Guild\Grouper\Exception;

use RuntimeException;

/**
 * Grouper answered, but not with something this library recognises — a body
 * that is not JSON, an envelope missing its result wrapper, or a result whose
 * resultMetadata.success is not "T".
 *
 * This is thrown rather than returned because it means the integration itself
 * is wrong: a version segment that moved, a changed envelope, a request Grouper
 * rejected on its merits. None of those are conditions the caller can have a
 * sensible runtime policy about, and silently degrading them to "no groups"
 * would turn a broken integration into a silent authorization failure.
 */
final class GrouperResponseException extends RuntimeException
{
}
