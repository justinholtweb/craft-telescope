<?php

declare(strict_types=1);

namespace justinholtweb\telescope\auth;

use justinholtweb\telescope\errors\AuthException;
use justinholtweb\telescope\http\HttpClientInterface;

/**
 * Exchanges a long-lived OAuth refresh token for access tokens.
 *
 * For sites that already completed a Google OAuth consent flow elsewhere and
 * have a refresh token to hand. A service account is the better default — this
 * path exists so an existing set of credentials does not have to be thrown away.
 */
final class RefreshTokenProvider implements TokenProviderInterface
{
    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private ?AccessToken $token = null;

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(): int|null $clock
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        private readonly HttpClientInterface $http,
        private readonly string $tokenUri = self::TOKEN_URI,
        ?callable $clock = null,
    ) {
        if (trim($clientId) === '' || trim($clientSecret) === '' || trim($refreshToken) === '') {
            throw new AuthException('OAuth authentication needs a client ID, client secret and refresh token.');
        }

        $this->clock = $clock ?? static fn(): int => time();
    }

    public function getToken(): AccessToken
    {
        $now = ($this->clock)();

        if ($this->token !== null && !$this->token->isExpired($now)) {
            return $this->token;
        }

        $response = $this->http->request(
            'POST',
            $this->tokenUri,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
            ]),
        );

        if (!$response->isSuccessful()) {
            $body = $response->json();
            $error = (string)($body['error'] ?? 'unknown_error');
            $description = (string)($body['error_description'] ?? '');

            throw new AuthException(trim(sprintf(
                'Could not refresh the Google access token (HTTP %d): %s %s. Re-authorise the connection to obtain a new refresh token.',
                $response->statusCode,
                $error,
                $description,
            )));
        }

        return $this->token = AccessToken::fromResponse($response->json(), $now);
    }

    public function fingerprint(): string
    {
        return 'oauth_' . substr(sha1($this->clientId . '|' . $this->refreshToken), 0, 12);
    }
}
