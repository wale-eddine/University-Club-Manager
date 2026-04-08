<?php
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../config/google_oauth.php');
require_once('../../classes/User.php');
require_once('../../classes/MembershipRequest.php');
require_once('../../classes/Event.php');

// Sends users back to login with a friendly OAuth error.
function redirectToLoginWithOAuthError($message) {
    $redirectAfterLogin = $_SESSION['oauth_post_login_redirect'] ?? '';
    $loginHref = 'login.php?oauth_error=' . urlencode((string)$message);

    if (is_string($redirectAfterLogin) && $redirectAfterLogin !== '') {
        $loginHref .= '&redirect=' . urlencode($redirectAfterLogin);
    }

    unset($_SESSION['google_oauth_profile']);

    header('Location: ' . $loginHref);
    exit();
}

// Normalizes and validates a redirect URL before using it.
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

// Executes a form-encoded POST request and returns decoded JSON.
function postFormForJson($url, $data) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [null, 0, $curlError !== '' ? $curlError : 'Erreur reseau OAuth'];
        }

        return [json_decode($response, true), $httpCode, null];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);

    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        return [null, 0, 'Erreur reseau OAuth'];
    }

    $httpCode = 0;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $httpCode = (int)$match[1];
    }

    return [json_decode($response, true), $httpCode, null];
}

// Executes a bearer-authenticated GET request and returns decoded JSON.
function getJsonWithBearer($url, $accessToken) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [null, 0, $curlError !== '' ? $curlError : 'Erreur reseau userinfo'];
        }

        return [json_decode($response, true), $httpCode, null];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer " . $accessToken . "\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);

    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        return [null, 0, 'Erreur reseau userinfo'];
    }

    $httpCode = 0;
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $httpCode = (int)$match[1];
    }

    return [json_decode($response, true), $httpCode, null];
}

if (!isGoogleOAuthConfigured()) {
    redirectToLoginWithOAuthError('Google OAuth n\'est pas configure.');
}

if (!empty($_GET['error'])) {
    redirectToLoginWithOAuthError('Connexion Google annulee ou refusee.');
}

$state = trim((string)($_GET['state'] ?? ''));
$sessionState = $_SESSION['google_oauth_state'] ?? '';
$stateCreatedAt = (int)($_SESSION['google_oauth_state_created_at'] ?? 0);

unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_state_created_at']);

if ($state === '' || !is_string($sessionState) || $sessionState === '' || !hash_equals($sessionState, $state)) {
    redirectToLoginWithOAuthError('Etat OAuth invalide. Reessayez.');
}

if ($stateCreatedAt > 0 && (time() - $stateCreatedAt) > 600) {
    redirectToLoginWithOAuthError('Session OAuth expiree. Reessayez.');
}

$authorizationCode = trim((string)($_GET['code'] ?? ''));
if ($authorizationCode === '') {
    redirectToLoginWithOAuthError('Code OAuth manquant.');
}

$config = getGoogleOAuthConfig();

list($tokenResponse, $tokenHttpCode, $tokenError) = postFormForJson($config['token_url'], [
    'code' => $authorizationCode,
    'client_id' => $config['client_id'],
    'client_secret' => $config['client_secret'],
    'redirect_uri' => $config['redirect_uri'],
    'grant_type' => 'authorization_code',
]);

if ($tokenError !== null || $tokenHttpCode < 200 || $tokenHttpCode >= 300 || !is_array($tokenResponse)) {
    redirectToLoginWithOAuthError('Echec de recuperation du token Google.');
}

$accessToken = trim((string)($tokenResponse['access_token'] ?? ''));
if ($accessToken === '') {
    redirectToLoginWithOAuthError('Token Google invalide.');
}

list($profile, $profileHttpCode, $profileError) = getJsonWithBearer($config['userinfo_url'], $accessToken);
if ($profileError !== null || $profileHttpCode < 200 || $profileHttpCode >= 300 || !is_array($profile)) {
    redirectToLoginWithOAuthError('Impossible de recuperer le profil Google.');
}

$googleId = trim((string)($profile['sub'] ?? ''));
$email = trim((string)($profile['email'] ?? ''));
$emailVerified = !empty($profile['email_verified']);
$prenom = trim((string)($profile['given_name'] ?? ''));
$nom = trim((string)($profile['family_name'] ?? ''));
$avatarUrl = trim((string)($profile['picture'] ?? ''));

if ($googleId === '' || $email === '' || !$emailVerified) {
    redirectToLoginWithOAuthError('Profil Google incomplet ou email non verifie.');
}

if ($prenom === '' || $nom === '') {
    $name = trim((string)($profile['name'] ?? ''));
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name);
        if (!empty($parts)) {
            if ($prenom === '') {
                $prenom = $parts[0];
            }
            if ($nom === '') {
                $nom = implode(' ', array_slice($parts, 1));
            }
        }
    }
}

if ($prenom === '') {
    $prenom = 'Utilisateur';
}
if ($nom === '') {
    $nom = 'Google';
}

$_SESSION['google_oauth_profile'] = [
    'google_id' => $googleId,
    'email' => $email,
    'nom' => $nom,
    'prenom' => $prenom,
    'avatar_url' => $avatarUrl,
    'created_at' => time(),
];

$db = new Database();
$connection = $db->getConnection();
$userModel = new User($connection);

$user = $userModel->getUserByGoogleId($googleId);

if (!$user) {
    $existingByEmail = $userModel->getUserByEmail($email);

    if ($existingByEmail) {
        $userModel->linkGoogleIdToUser((int)$existingByEmail['id'], $googleId, $avatarUrl !== '' ? $avatarUrl : null);
        $userModel->markEmailVerified((int)$existingByEmail['id']);
        $user = $userModel->getUserById((int)$existingByEmail['id']);
    } else {
        header('Location: google_set_password.php');
        exit();
    }
}

if (!$user) {
    redirectToLoginWithOAuthError('Connexion Google impossible.');
}

$userModel->markEmailVerified((int)$user['id']);
$user = $userModel->getUserById((int)$user['id']);

if (!$user) {
    redirectToLoginWithOAuthError('Connexion Google impossible.');
}

if ((string)($user['account_status'] ?? 'active') !== 'active') {
    redirectToLoginWithOAuthError('Votre compte a ete desactive par un administrateur.');
}

unset($_SESSION['google_oauth_profile']);

$_SESSION['user_id'] = $user['id'];
$_SESSION['user'] = $user;

// Prepare dashboard notifications similar to classic login.
$membership = new MembershipRequest($connection);
$eventModel = new Event($connection);
$decisionNotifications = $membership->getUnreadDecisionNotifications((int)$user['id']);
$eventNotifications = $eventModel->getUnreadUserNotifications((int)$user['id']);
$dashboardNotifications = [];

foreach ($decisionNotifications as $notif) {
    $isAccepted = (($notif['status'] ?? '') === 'accepted');
    $dashboardNotifications[] = [
        'type' => 'membership_decision',
        'title' => $isAccepted ? 'Demande acceptee' : 'Demande refusee',
        'message' => 'Votre demande pour "' . ($notif['club_nom'] ?? '') . '" a ete ' . ($isAccepted ? 'acceptee' : 'refusee') . '.',
    ];
}

foreach ($eventNotifications as $notif) {
    $dashboardNotifications[] = [
        'type' => $notif['type'] ?? 'info',
        'title' => $notif['title'] ?? 'Notification',
        'message' => $notif['message'] ?? '',
    ];
}

if (!empty($dashboardNotifications)) {
    $_SESSION['dashboard_notifications'] = $dashboardNotifications;
    $membership->markDecisionNotificationsSeen((int)$user['id'], array_column($decisionNotifications, 'id'));
    $eventModel->markUserNotificationsRead((int)$user['id'], array_column($eventNotifications, 'id'));
} else {
    unset($_SESSION['dashboard_notifications']);
}

$redirectAfterLogin = normalizePostLoginRedirect($_SESSION['oauth_post_login_redirect'] ?? '');
unset($_SESSION['oauth_post_login_redirect']);

if ($redirectAfterLogin !== '') {
    header('Location: ' . $redirectAfterLogin);
    exit();
}

header('Location: ../dashboard.php');
exit();
?>