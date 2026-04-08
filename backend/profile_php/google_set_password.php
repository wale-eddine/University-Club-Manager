<?php
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/User.php');
require_once('../../classes/MembershipRequest.php');
require_once('../../classes/Event.php');

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

$pendingProfile = $_SESSION['google_oauth_profile'] ?? null;

if (!is_array($pendingProfile)) {
    redirectToLoginWithOAuthError('Session Google invalide. Reessayez.');
}

$createdAt = (int)($pendingProfile['created_at'] ?? 0);
if ($createdAt <= 0 || (time() - $createdAt) > 900) {
    redirectToLoginWithOAuthError('Session Google expiree. Reessayez.');
}

$googleId = trim((string)($pendingProfile['google_id'] ?? ''));
$email = trim((string)($pendingProfile['email'] ?? ''));
$nom = trim((string)($pendingProfile['nom'] ?? 'Google'));
$prenom = trim((string)($pendingProfile['prenom'] ?? 'Utilisateur'));
$avatarUrl = trim((string)($pendingProfile['avatar_url'] ?? ''));

if ($googleId === '' || $email === '') {
    redirectToLoginWithOAuthError('Profil Google incomplet. Reessayez.');
}

$error = '';
$fieldErrors = [
    'password' => '',
    'password_confirm' => '',
];
$formValues = [
    'password' => '',
    'password_confirm' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    $formValues['password'] = $password;
    $formValues['password_confirm'] = $passwordConfirm;

    if ($password === '') {
        $fieldErrors['password'] = 'Veuillez saisir un mot de passe.';
    }

    if ($passwordConfirm === '') {
        $fieldErrors['password_confirm'] = 'Veuillez confirmer votre mot de passe.';
    }

    if ($password !== '' && $passwordConfirm !== '' && $password !== $passwordConfirm) {
        $fieldErrors['password_confirm'] = 'Les mots de passe ne correspondent pas.';
    }

    if ($password !== '' && strlen($password) < 6) {
        $fieldErrors['password'] = 'Le mot de passe doit contenir au moins 6 caracteres.';
    }

    if ($fieldErrors['password'] === '' && $fieldErrors['password_confirm'] === '') {
        $db = new Database();
        $connection = $db->getConnection();
        $userModel = new User($connection);

        $user = $userModel->getUserByGoogleId($googleId);

        if (!$user) {
            $existingByEmail = $userModel->getUserByEmail($email);

            if ($existingByEmail) {
                $userModel->linkGoogleIdToUser((int)$existingByEmail['id'], $googleId, $avatarUrl !== '' ? $avatarUrl : null);
                if (empty($existingByEmail['email_verified_at'])) {
                    $userModel->markEmailVerified((int)$existingByEmail['id']);
                }

                if (empty($existingByEmail['mot_de_passe'])) {
                    $userModel->updatePassword((int)$existingByEmail['id'], $password);
                }

                $user = $userModel->getUserById((int)$existingByEmail['id']);
            } else {
                $created = $userModel->createGoogleUser(
                    $nom,
                    $prenom,
                    $email,
                    $googleId,
                    $avatarUrl !== '' ? $avatarUrl : null,
                    $password
                );

                if ($created) {
                    $user = $userModel->getUserByEmail($email);
                }
            }
        }

        if (!$user) {
            $error = 'Connexion Google impossible. Reessayez.';
        } elseif ((string)($user['account_status'] ?? 'active') !== 'active') {
            redirectToLoginWithOAuthError('Votre compte a ete desactive par un administrateur.');
        } else {
            $userModel->markEmailVerified((int)$user['id']);
            $user = $userModel->getUserById((int)$user['id']);

            if (!$user) {
                $error = 'Connexion Google impossible. Reessayez.';
            } elseif ((string)($user['account_status'] ?? 'active') !== 'active') {
                redirectToLoginWithOAuthError('Votre compte a ete desactive par un administrateur.');
            } else {
                unset($_SESSION['google_oauth_profile']);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user'] = $user;

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
            }
        }
    }
}

include('../../html pages/profile/google_set_password.html');
?>
