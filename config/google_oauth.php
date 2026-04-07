<?php
// Returns true when HTTPS is active for the current request.
function isHttpsRequest() {
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

// Reads Google OAuth credentials from a local JSON file when present.
function getGoogleOAuthConfigFromFile() {
    $projectRoot = dirname(__DIR__);
    $explicitPath = trim((string)(getenv('GOOGLE_OAUTH_JSON_PATH') ?: ''));
    $candidatePaths = [];

    if ($explicitPath !== '') {
        $candidatePaths[] = $explicitPath;
    }

    $candidatePaths[] = $projectRoot . '/config/google_oauth_client.json';

    foreach ($candidatePaths as $path) {
        if (!is_string($path) || $path === '' || !is_file($path)) {
            continue;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }

        $payload = isset($decoded['web']) && is_array($decoded['web']) ? $decoded['web'] : $decoded;

        $clientId = trim((string)($payload['client_id'] ?? ''));
        $clientSecret = trim((string)($payload['client_secret'] ?? ''));
        $redirectUri = '';

        if (!empty($payload['redirect_uris']) && is_array($payload['redirect_uris'])) {
            $redirectUri = trim((string)$payload['redirect_uris'][0]);
        }

        if ($clientId !== '' && $clientSecret !== '') {
            return [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
            ];
        }
    }

    return [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => '',
    ];
}

// Builds the callback URL from current host when env override is not set.
function getGoogleOAuthDefaultRedirectUri() {
    $scheme = isHttpsRequest() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/backend/profile_php'));
    $scriptDir = rtrim($scriptDir, '/');

    if ($scriptDir === '' || $scriptDir === '.') {
        $scriptDir = '/backend/profile_php';
    }

    return $scheme . '://' . $host . $scriptDir . '/google_callback.php';
}

// Loads Google OAuth credentials from environment variables.
function getGoogleOAuthConfig() {
    $clientId = trim((string)(getenv('GOOGLE_OAUTH_CLIENT_ID') ?: ''));
    $clientSecret = trim((string)(getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: ''));
    $redirectUri = trim((string)(getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: ''));

    if ($clientId === '' || $clientSecret === '') {
        $fromFile = getGoogleOAuthConfigFromFile();

        if ($clientId === '') {
            $clientId = $fromFile['client_id'];
        }

        if ($clientSecret === '') {
            $clientSecret = $fromFile['client_secret'];
        }

        if ($redirectUri === '') {
            $redirectUri = $fromFile['redirect_uri'];
        }
    }

    if ($redirectUri === '') {
        $redirectUri = getGoogleOAuthDefaultRedirectUri();
    }

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
        'scope' => 'openid email profile',
    ];
}

// Checks if required client credentials were provided.
function isGoogleOAuthConfigured() {
    $config = getGoogleOAuthConfig();
    return $config['client_id'] !== '' && $config['client_secret'] !== '';
}

?>