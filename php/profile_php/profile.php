<?php
// Load session helpers, database connection, and user model.
require_once('../../methods/session.php');
require_once('../../config/Database.php');
require_once('../../methods/User.php');

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

// Handle profile update form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (empty($nom) || empty($prenom) || empty($email)) {
        $error = 'Veuillez remplir tous les champs.';
    } elseif ($user->emailExistsForOtherUser($email, $user_id)) {
        $error = 'Cet email est déjà utilisé.';
    } else {
        try {
            if ($user->updateProfile($user_id, $nom, $prenom, $email)) {
                $success = 'Profil modifié avec succès!';
                $user_info = $user->getUserById($user_id);
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






