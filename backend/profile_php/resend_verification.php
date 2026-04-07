<?php
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../config/app.php');
require_once('../../config/mail.php');
require_once('../../classes/User.php');
require_once('../../classes/EmailVerificationToken.php');

redirectIfLoggedIn();

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

    return $decoded;
}

$email = trim((string)($_GET['email'] ?? ''));
$redirectAfterLogin = normalizePostLoginRedirect($_GET['redirect'] ?? '');

if ($email === '') {
    header('Location: ' . phpRoute('profile_php/login.php') . '?verification_status=missing' . ($redirectAfterLogin !== '' ? '&redirect=' . urlencode($redirectAfterLogin) : ''));
    exit();
}

$db = new Database();
$connection = $db->getConnection();
$userModel = new User($connection);
$verificationModel = new EmailVerificationToken($connection);

$user = $userModel->getUserByEmail($email);

if (!$user) {
    header('Location: ' . phpRoute('profile_php/login.php') . '?verification_status=sent' . ($redirectAfterLogin !== '' ? '&redirect=' . urlencode($redirectAfterLogin) : ''));
    exit();
}

if (!empty($user['email_verified_at'])) {
    header('Location: ' . phpRoute('profile_php/login.php') . '?verification_status=already_verified' . ($redirectAfterLogin !== '' ? '&redirect=' . urlencode($redirectAfterLogin) : ''));
    exit();
}

$rawToken = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', time() + 24 * 3600);
$verificationModel->createToken((int)$user['id'], $user['email'], $rawToken, $expiresAt);
$verificationUrl = buildAppUrl('backend/profile_php/verify_email.php?token=' . urlencode($rawToken));
$recipientName = trim((string)$user['prenom'] . ' ' . (string)$user['nom']);
$sent = sendVerificationEmail($user['email'], $recipientName, $verificationUrl);

if ($sent) {
    header('Location: ' . phpRoute('profile_php/login.php') . '?verification_status=sent' . ($redirectAfterLogin !== '' ? '&redirect=' . urlencode($redirectAfterLogin) : ''));
    exit();
}

$mailError = function_exists('getLastMailError') ? trim((string)getLastMailError()) : '';
header('Location: ' . phpRoute('profile_php/login.php') . '?verification_status=missing' . ($redirectAfterLogin !== '' ? '&redirect=' . urlencode($redirectAfterLogin) : '') . ($mailError !== '' ? '&oauth_error=' . urlencode($mailError) : ''));
exit();
?>