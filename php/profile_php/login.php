<?php
// Load session helpers, database access, and models used after login.
require_once('../../methods/session.php');
require_once('../../config/Database.php');
require_once('../../methods/User.php');
require_once('../../methods/MembershipRequest.php');
require_once('../../methods/Event.php');

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

    if (strpos($path, '/php/profile_php/login.php') !== false || strpos($path, '/php/profile_php/register.php') !== false) {
        return '';
    }

    return $decoded;
}

// Initialize view state and redirect links.
$error = '';
$success = '';
$redirectAfterLogin = normalizePostLoginRedirect($_GET['redirect'] ?? '');
$registerHref = 'register.php' . ($redirectAfterLogin !== '' ? ('?redirect=' . urlencode($redirectAfterLogin)) : '');

// Validate credentials, create session, and prepare dashboard notifications.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectAfterLogin = normalizePostLoginRedirect($_POST['redirect'] ?? ($_GET['redirect'] ?? ''));
    $registerHref = 'register.php' . ($redirectAfterLogin !== '' ? ('?redirect=' . urlencode($redirectAfterLogin)) : '');

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        $db = new Database();
        $connection = $db->getConnection();
        $user = new User($connection);

        $result = $user->login($email, $password);

        if ($result) {
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
        } else {
            $error = 'Email ou mot de passe incorrect.';
        }
    }
}

// Render the login template.
include('../../html pages/profile/login.html');
?>






