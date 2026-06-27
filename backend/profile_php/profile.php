<?php
// Load session helpers, database connection, and user model.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/User.php');

// Require authentication before showing profile page.
redirectIfNotLoggedIn();

// Initialize user services and load current profile data.
$db = new Database();
$connection = $db->getConnection();
$user = new User($connection);

$user_id = getCurrentUserId();
$user_info = $user->getUserById($user_id);

$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Handle Google Calendar disconnection.
if (isset($_GET['action']) && $_GET['action'] === 'disconnect_google_calendar') {
    $stmt = $connection->prepare("UPDATE USERS SET google_access_token = NULL, google_refresh_token = NULL, google_token_expires_at = NULL, google_calendar_sync = 0 WHERE id = ?");
    if ($stmt->execute([$user_id])) {
        $success = "Google Calendar déconnecté avec succès.";
        $user_info = $user->getUserById($user_id);
        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user'] = $user_info;
        }
    } else {
        $error = "Erreur lors de la déconnexion de Google Calendar.";
    }
}

// Handle profile update form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $google_calendar_sync = isset($_POST['google_calendar_sync']) ? 1 : 0;

    if (empty($nom) || empty($prenom) || empty($email)) {
        $error = 'Veuillez remplir tous les champs.';
    } elseif ($user->emailExistsForOtherUser($email, $user_id)) {
        $error = 'Cet email est déjà utilisé.';
    } else {
        try {
            // Update profile
            $profileUpdated = $user->updateProfile($user_id, $nom, $prenom, $email);
            
            // Update Google Calendar sync toggle
            $stmt = $connection->prepare("UPDATE USERS SET google_calendar_sync = ? WHERE id = ?");
            $syncUpdated = $stmt->execute([$google_calendar_sync, $user_id]);

            if ($profileUpdated || $syncUpdated) {
                $success = 'Profil modifié avec succès!';
                $user_info = $user->getUserById($user_id);

                if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user'] = $user_info;
                }
            } else {
                $error = 'Erreur lors de la modification du profil.';
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Cet email est déjà utilisé.';
            } else {
                $error = 'Erreur lors de la modification du profil.';
            }
        }
    }
}

// Render the profile template.
include('../../html pages/profile/profile.html');
?>






