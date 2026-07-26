<?php

declare(strict_types=1);

namespace justinholtweb\telescope\errors;

use RuntimeException;

/**
 * Base class for every error the plugin raises, so callers can catch the whole
 * family with one `catch` and still degrade to an empty report.
 */
class TelescopeException extends RuntimeException
{
}
