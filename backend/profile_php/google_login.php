<?php
require_once('../../classes/session.php');
require_once('../../config/google_oauth.php');

// Prevent authenticated users from restarting auth flow.
redirectIfLoggedIn();

// Sanitizes post-login redirects to local in-app paths.
function normalizePostLoginRedirect($value) {
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);
    if ($value === '' || strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
        return '';
    }

    $decoded = rawurldecode($value);

    if (preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $decoded) || strpos($decoded, '//') === 0) {
        return '';
    }

    $parts = parse_url($decoded);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return '';
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || $path[0] !== '/') {
        return '';
    }

    if (
        strpos($path, '/backend/profile_php/login.php') !== false ||
        strpos($path, '/backend/profile_php/register.php') !== false ||
        strpos($path, '/php/profile_php/login.php') !== false ||
        strpos($path, '/php/profile_php/register.php') !== false
    ) {
        return '';
    }

    return $decoded;
}

$redirectAfterLogin = normalizePostLoginRedirect($_GET['redirect'] ?? '');
if ($redirectAfterLogin !== '') {
    $_SESSION['oauth_post_login_redirect'] = $redirectAfterLogin;
}

if (!isGoogleOAuthConfigured()) {
    $loginHref = 'login.php?oauth_error=' . urlencode('Google OAuth n\'est pas configure. Ajoutez les variables d\'environnement Google.');
    if ($redirectAfterLogin !== '') {
        $loginHref .= '&redirect=' . urlencode($redirectAfterLogin);
    }
    header('Location: ' . $loginHref);
    exit();
}

$config = getGoogleOAuthConfig();
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;
$_SESSION['google_oauth_state_created_at'] = time();

$params = [
    'client_id' => $config['client_id'],
    'redirect_uri' => $config['redirect_uri'],
    'response_type' => 'code',
    'scope' => $config['scope'],
    'state' => $state,
    'prompt' => 'select_account',
    'access_type' => 'online',
];

$authorizationUrl = $config['auth_url'] . '?' . http_build_query($params);
header('Location: ' . $authorizationUrl);
exit();
?>