<?php
// Load session helpers, database access, and models used after login.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../config/google_oauth.php');
require_once('../../classes/User.php');
require_once('../../classes/MembershipRequest.php');
require_once('../../classes/Event.php');

// Prevent authenticated users from reopening login page.
redirectIfLoggedIn();

// Sanitize redirect target to keep post-login navigation safe.
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

// Initialize view state and redirect links.
$error = '';
$success = '';
$pendingVerificationEmail = '';
$showResendVerification = false;
$emailValue = '';
$redirectAfterLogin = normalizePostLoginRedirect($_GET['redirect'] ?? '');
$oauthError = trim((string)($_GET['oauth_error'] ?? ''));
$error = $oauthError !== '' ? $oauthError : $error;
$resetStatus = trim((string)($_GET['reset_status'] ?? ''));
if ($resetStatus === 'success') {
    $success = 'Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter.';
}
$resendStatus = trim((string)($_GET['verification_status'] ?? ''));
$resendStatusMessage = '';
if ($resendStatus === 'sent') {
    $resendStatusMessage = 'Un nouvel email de vérification a été envoyé. Vérifiez aussi le dossier spam.';
} elseif ($resendStatus === 'already_verified') {
    $resendStatusMessage = 'Cette adresse est déjà vérifiée. Vous pouvez vous connecter.';
} elseif ($resendStatus === 'missing') {
    $resendStatusMessage = 'Veuillez saisir votre email pour renvoyer le message de vérification.';
}
$registerHref = 'register.php' . ($redirectAfterLogin !== '' ? ('?redirect=' . urlencode($redirectAfterLogin)) : '');
$googleLoginHref = phpRoute('profile_php/google_login.php') . ($redirectAfterLogin !== '' ? ('?redirect=' . urlencode($redirectAfterLogin)) : '');
$isGoogleAuthConfigured = isGoogleOAuthConfigured();

// Validate credentials, create session, and prepare dashboard notifications.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectAfterLogin = normalizePostLoginRedirect($_POST['redirect'] ?? ($_GET['redirect'] ?? ''));
    $registerHref = 'register.php' . ($redirectAfterLogin !== '' ? ('?redirect=' . urlencode($redirectAfterLogin)) : '');
    $googleLoginHref = phpRoute('profile_php/google_login.php') . ($redirectAfterLogin !== '' ? ('?redirect=' . urlencode($redirectAfterLogin)) : '');

    $email = trim($_POST['email'] ?? '');
    $emailValue = $email;
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        $db = new Database();
        $connection = $db->getConnection();
        $user = new User($connection);

        $authResult = $user->authenticateWithStatus($email, $password);
        $result = $authResult['user'] ?? null;

        if (($authResult['status'] ?? 'invalid') === 'success' && $result) {
            // Persist authenticated user data in session.
            $_SESSION['user_id'] = $result['id'];
            $_SESSION['user'] = $result;

            // Gather unread membership and event notifications.
            $membership = new MembershipRequest($connection);
            $eventModel = new Event($connection);
            $decisionNotifications = $membership->getUnreadDecisionNotifications((int)$result['id']);
            $eventNotifications = $eventModel->getUnreadUserNotifications((int)$result['id']);
            $dashboardNotifications = [];

            // Convert membership decisions into dashboard-ready messages.
            foreach ($decisionNotifications as $notif) {
                $isAccepted = (($notif['status'] ?? '') === 'accepted');
                $dashboardNotifications[] = [
                    'type' => 'membership_decision',
                    'title' => $isAccepted ? 'Demande acceptee' : 'Demande refusee',
                    'message' => 'Votre demande pour "' . ($notif['club_nom'] ?? '') . '" a ete ' . ($isAccepted ? 'acceptee' : 'refusee') . '.',
                ];
            }

            // Add event notifications to dashboard messages.
            foreach ($eventNotifications as $notif) {
                $dashboardNotifications[] = [
                    'type' => $notif['type'] ?? 'info',
                    'title' => $notif['title'] ?? 'Notification',
                    'message' => $notif['message'] ?? '',
                ];
            }

            // Save notifications in session and mark them as seen/read.
            if (!empty($dashboardNotifications)) {
                $_SESSION['dashboard_notifications'] = $dashboardNotifications;
                $membership->markDecisionNotificationsSeen((int)$result['id'], array_column($decisionNotifications, 'id'));
                $eventModel->markUserNotificationsRead((int)$result['id'], array_column($eventNotifications, 'id'));
            } else {
                unset($_SESSION['dashboard_notifications']);
            }

            // Redirect to requested page or default dashboard.
            if ($redirectAfterLogin !== '') {
                header('Location: ' . $redirectAfterLogin);
                exit();
            }

            header("Location: ../dashboard.php");
            exit();
        } elseif (($authResult['status'] ?? 'invalid') === 'unverified') {
            $pendingVerificationEmail = $email;
            $showResendVerification = true;
            $error = 'Veuillez vérifier votre email avant de vous connecter. Consultez votre boîte de réception et votre dossier spam.';
        } elseif (($authResult['status'] ?? 'invalid') === 'inactive') {
            $inactiveReason = trim((string)($result['inactive_reason'] ?? ''));
            $error = 'Votre compte a ete desactive par un administrateur.' . ($inactiveReason !== '' ? (' Raison: ' . $inactiveReason) : '');
        } else {
            $error = 'Email ou mot de passe incorrect.';
        }
    }
}

if ($emailValue === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $emailValue = trim((string)($_GET['email'] ?? ''));
}

// Render the login template.
include('../../html pages/profile/login.html');
?>






