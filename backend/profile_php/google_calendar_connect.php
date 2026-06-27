<?php
require_once('../../classes/session.php');
require_once('../../config/google_oauth.php');

// Make sure the user is logged in.
redirectIfNotLoggedIn();

if (!isGoogleOAuthConfigured()) {
    header('Location: profile.php?error=' . urlencode('Google OAuth n\'est pas configuré.'));
    exit();
}

$config = getGoogleOAuthConfig();
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;
$_SESSION['google_oauth_state_created_at'] = time();

// Flag that we are linking a Google Calendar
$_SESSION['google_calendar_linking'] = true;

$params = [
    'client_id' => $config['client_id'],
    'redirect_uri' => $config['redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email profile https://www.googleapis.com/auth/calendar.events',
    'state' => $state,
    'prompt' => 'consent',
    'access_type' => 'offline',
];

$authorizationUrl = $config['auth_url'] . '?' . http_build_query($params);
header('Location: ' . $authorizationUrl);
exit();
?>
