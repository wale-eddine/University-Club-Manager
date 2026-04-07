<?php
// Load session helpers, database connection, and user model.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../config/app.php');
require_once('../../config/mail.php');
require_once('../../classes/User.php');
require_once('../../classes/EmailVerificationToken.php');

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
                $createdUser = $user->getUserByEmail($email);
                if ($createdUser) {
                    $verificationModel = new EmailVerificationToken($connection);
                    $rawToken = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', time() + 24 * 3600);
                    $verificationModel->createToken((int)$createdUser['id'], $createdUser['email'], $rawToken, $expiresAt);

                    $verificationUrl = buildAppUrl('backend/profile_php/verify_email.php?token=' . urlencode($rawToken));
                    $recipientName = trim((string)$createdUser['prenom'] . ' ' . (string)$createdUser['nom']);
                    $sent = sendVerificationEmail($createdUser['email'], $recipientName, $verificationUrl);

                    if ($sent) {
                        $_SESSION['signup_verification_email'] = $createdUser['email'];
                        $_SESSION['signup_verification_name'] = $recipientName;
                        header('Location: ' . phpRoute('profile_php/verification_sent.php'));
                        exit();
                    } else {
                        $error = 'Compte créé, mais l’email de vérification n’a pas pu être envoyé. Vérifiez la configuration mail du serveur.';
                    }
                } else {
                    $error = 'Compte créé, mais impossible de préparer la vérification email.';
                }
            } else {
                $error = 'Erreur lors de l\'inscription.';
            }
        }
    }
}

// Render the registration template.
include('../../html pages/profile/register.html');
?>






