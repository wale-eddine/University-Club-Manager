<?php
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/User.php');
require_once('../../classes/PasswordResetToken.php');

$error = '';
$success = '';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$resetRow = null;

if ($token !== '') {
    $db = new Database();
    $connection = $db->getConnection();
    $resetModel = new PasswordResetToken($connection);
    $resetRow = $resetModel->getValidTokenByToken($token);
}

if ($token === '') {
    $error = 'Lien de réinitialisation manquant ou invalide.';
} elseif ($resetRow === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = 'Ce lien de réinitialisation est invalide ou expiré. Demandez-en un nouveau.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $newPasswordConfirm = $_POST['new_password_confirm'] ?? '';

    if ($token === '') {
        $error = 'Lien de réinitialisation manquant ou invalide.';
    } elseif (!$resetRow) {
        $error = 'Ce lien de réinitialisation est invalide ou expiré. Demandez-en un nouveau.';
    } elseif ($newPassword === '' || $newPasswordConfirm === '') {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Le mot de passe doit contenir au moins 6 caractères.';
    } elseif ($newPassword !== $newPasswordConfirm) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        $db = new Database();
        $connection = $db->getConnection();
        $userModel = new User($connection);
        $resetModel = new PasswordResetToken($connection);

        if ($userModel->updatePassword((int)$resetRow['user_id'], $newPassword)) {
            $resetModel->purgeTokensForUser((int)$resetRow['user_id']);
            header('Location: login.php?reset_status=success');
            exit();
        } else {
            $error = 'Impossible de réinitialiser le mot de passe pour le moment.';
        }
    }
}

include('../../html pages/profile/reset_password.html');
?>