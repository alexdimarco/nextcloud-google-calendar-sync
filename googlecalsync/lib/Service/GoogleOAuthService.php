<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Service;

use OCA\GoogleCalSync\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * Handles Google OAuth 2.0 flow and secure per-user token storage.
 *
 * Credentials flow:
 *   1. User pastes their credentials.json (Desktop app type) into the UI.
 *   2. We store it encrypted in Nextcloud user preferences.
 *   3. We build a Google_Client from it and redirect to Google's consent screen.
 *   4. Google redirects back with a code; we exchange it for tokens and store them.
 *   5. Future calls refresh the access token automatically.
 */
class GoogleOAuthService {

    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function getAuthorizationUrl(string $userId, array $credentialsJson, string $redirectUri): string {
        $this->saveCredentials($userId, $credentialsJson);

        $client = $this->buildClient($userId, $redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        // CSRF state: random token stored in user prefs
        $state = bin2hex(random_bytes(16));
        $this->config->setUserValue($userId, Application::APP_ID, 'oauth_state', $state);
        $client->setState($state);

        return $client->createAuthUrl();
    }

    public function handleCallback(string $userId, string $code, string $state, string $redirectUri): void {
        $savedState = $this->config->getUserValue($userId, Application::APP_ID, 'oauth_state', '');
        if (!hash_equals($savedState, $state)) {
            throw new \RuntimeException('OAuth state mismatch — possible CSRF attempt.');
        }
        $this->config->deleteUserValue($userId, Application::APP_ID, 'oauth_state');

        $client = $this->buildClient($userId, $redirectUri);
        $token  = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException('Google token error: ' . ($token['error_description'] ?? $token['error']));
        }

        $this->saveToken($userId, $token);

        // Fetch and store the user's Google email address
        $oauth2   = new \Google\Service\Oauth2($client);
        $userInfo = $oauth2->userinfo->get();
        $this->config->setUserValue($userId, Application::APP_ID, 'google_email', $userInfo->getEmail() ?? '');
    }

    public function disconnect(string $userId): void {
        foreach (['google_credentials', 'google_token', 'google_email', 'oauth_state'] as $key) {
            $this->config->deleteUserValue($userId, Application::APP_ID, $key);
        }
    }

    public function isConnected(string $userId): bool {
        $token = $this->config->getUserValue($userId, Application::APP_ID, 'google_token', '');
        return $token !== '';
    }

    public function getConnectedEmail(string $userId): ?string {
        $email = $this->config->getUserValue($userId, Application::APP_ID, 'google_email', '');
        return $email !== '' ? $email : null;
    }

    /**
     * Return an authenticated Google_Client for the given user,
     * refreshing the access token if necessary.
     */
    public function getAuthenticatedClient(string $userId): \Google\Client {
        $client = $this->buildClient($userId, '');
        $token  = $this->loadToken($userId);
        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            if (!$client->getRefreshToken()) {
                throw new \RuntimeException('Access token expired and no refresh token available. Please reconnect.');
            }
            $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            if (isset($newToken['error'])) {
                throw new \RuntimeException('Token refresh failed: ' . ($newToken['error_description'] ?? $newToken['error']));
            }
            // Merge new tokens (preserve refresh token if not returned again)
            $merged = array_merge($token, $newToken);
            $this->saveToken($userId, $merged);
            $client->setAccessToken($merged);
        }

        return $client;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildClient(string $userId, string $redirectUri): \Google\Client {
        $client = new \Google\Client();
        $client->setApplicationName('Nextcloud Google Calendar Sync');
        $client->setScopes(self::SCOPES);

        if ($redirectUri !== '') {
            $client->setRedirectUri($redirectUri);
        }

        $credentials = $this->loadCredentials($userId);
        $client->setAuthConfig($credentials);

        return $client;
    }

    private function saveCredentials(string $userId, array $credentials): void {
        $encrypted = $this->crypto->encrypt(json_encode($credentials));
        $this->config->setUserValue($userId, Application::APP_ID, 'google_credentials', $encrypted);
    }

    private function loadCredentials(string $userId): array {
        $encrypted = $this->config->getUserValue($userId, Application::APP_ID, 'google_credentials', '');
        if ($encrypted === '') {
            throw new \RuntimeException('No Google credentials stored. Please paste your credentials.json first.');
        }
        return json_decode($this->crypto->decrypt($encrypted), true);
    }

    private function saveToken(string $userId, array $token): void {
        $encrypted = $this->crypto->encrypt(json_encode($token));
        $this->config->setUserValue($userId, Application::APP_ID, 'google_token', $encrypted);
    }

    private function loadToken(string $userId): array {
        $encrypted = $this->config->getUserValue($userId, Application::APP_ID, 'google_token', '');
        if ($encrypted === '') {
            throw new \RuntimeException('No Google token stored. Please connect your account first.');
        }
        return json_decode($this->crypto->decrypt($encrypted), true);
    }
}
