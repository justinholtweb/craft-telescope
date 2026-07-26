<?php

declare(strict_types=1);

namespace justinholtweb\telescope\auth;

use justinholtweb\telescope\errors\AuthException;

/**
 * Supplies bearer tokens for the GA4 Data API.
 *
 * Two implementations ship with the plugin — a service account (recommended)
 * and an OAuth refresh token — plus a static provider for tests and for setups
 * where the token is minted elsewhere.
 */
interface TokenProviderInterface
{
    /**
     * @throws AuthException when a usable token cannot be obtained
     */
    public function getToken(): AccessToken;

    /**
     * A short identifier for the credentials in use, safe to log and to mix
     * into cache keys. Must never contain the secret itself.
     */
    public function fingerprint(): string;
}
