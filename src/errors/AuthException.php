<?php

declare(strict_types=1);

namespace justinholtweb\telescope\errors;

/**
 * Raised when credentials are missing, malformed, or rejected by Google.
 *
 * Distinct from {@see ApiException} because the fix is different: an auth
 * failure means "go fix the settings", an API failure means "Google said no".
 */
class AuthException extends TelescopeException
{
}
