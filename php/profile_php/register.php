<?php
// Load session helpers, database connection, and user model.
require_once('../../methods/session.php');
require_once('../../config/Database.php');
require_once('../../methods/User.php');

// Prevent authenticated users from accessing registration page.
redirectIfLoggedIn();

// Initialize page feedback messages.
$error = '';
$success = '';

// Validate form data and create a new user account.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (empty($nom) || empty($prenom) || empty($email) || empty($password) || empty($password_confirm)) {
        $error = 'Veuillez remplir tous les champs.';
    } elseif ($password !== $password_confirm) {
        $error = 'Les mots de passe ne correspondent pas.';
    } elseif (strlen($password) < 6) {
        $error = 'Le mot de passe doit contenir au moins 6 caractères.';
    } else {
        $db = new Database();
        $connection = $db->getConnection();
        $user = new User($connection);

        if ($user->emailExists($email)) {
            $error = 'Cet email est déjà utilisé.';
        } else {
            if ($user->register($nom, $prenom, $email, $password)) {
                $success = 'Inscription réussie! Veuillez vous connecter.';
                header("Refresh: 2; url=login.php");
            } else {
                $error = 'Erreur lors de l\'inscription.';
            }
        }
    }
}

// Render the registration template.
include('../../html pages/profile/register.html');
?>






