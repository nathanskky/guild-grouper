<?php

declare(strict_types=1);

namespace Guild\Grouper\Exception;

use LogicException;

/**
 * Bad or missing Grouper configuration.
 *
 * This extends LogicException on purpose: every condition that raises it is a
 * deployment or programming error that a human must fix — an unset environment
 * variable, a plaintext service URL, a service account whose credentials
 * Grouper rejects. None of them resolve on their own, so none of them should be
 * caught and retried.
 *
 * Contrast GrouperUnavailable, which is *returned* rather than thrown because
 * an unreachable Grouper is a transient condition the caller gets to have a
 * policy about.
 */
final class GrouperConfigurationException extends LogicException
{
}
