<?php
// Load session helpers, database connection, and user model.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/User.php');

// Require authentication before allowing password change.
redirectIfNotLoggedIn();

$db = new Database();
$connection = $db->getConnection();
$user = new User($connection);

$userId = getCurrentUserId();
$userInfo = $user->getUserById($userId);

$error = '';
$success = '';
$fieldErrors = [
    'old_password' => '',
    'new_password' => '',
    'confirm_password' => '',
];
$formValues = [
    'old_password' => '',
    'new_password' => '',
    'confirm_password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPassword = (string)($_POST['old_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $currentHash = $userInfo['mot_de_passe'] ?? null;

    $formValues['old_password'] = $oldPassword;
    $formValues['new_password'] = $newPassword;
    $formValues['confirm_password'] = $confirmPassword;

    if (!is_string($currentHash) || trim($currentHash) === '') {
        $error = 'Ce compte n\'utilise pas de mot de passe local.';
    } elseif ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
        if ($oldPassword === '') {
            $fieldErrors['old_password'] = 'Veuillez saisir votre ancien mot de passe.';
        }
        if ($newPassword === '') {
            $fieldErrors['new_password'] = 'Veuillez saisir un nouveau mot de passe.';
        }
        if ($confirmPassword === '') {
            $fieldErrors['confirm_password'] = 'Veuillez confirmer le nouveau mot de passe.';
        }
    } elseif (!$user->verifyPassword($userId, $oldPassword)) {
        $fieldErrors['old_password'] = 'Ancien mot de passe incorrect.';
    } elseif (strlen($newPassword) < 6) {
        $fieldErrors['new_password'] = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
    } elseif ($newPassword !== $confirmPassword) {
        $fieldErrors['confirm_password'] = 'Les nouveaux mots de passe ne correspondent pas.';
    } elseif (password_verify($newPassword, $currentHash)) {
        $fieldErrors['new_password'] = 'Le nouveau mot de passe doit être différent de l\'ancien.';
    } elseif ($user->updatePassword($userId, $newPassword)) {
        $success = 'Mot de passe modifié avec succès!';
        $userInfo = $user->getUserById($userId);
        $formValues = [
            'old_password' => '',
            'new_password' => '',
            'confirm_password' => '',
        ];

        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user'] = $userInfo;
        }
    } else {
        $error = 'Erreur lors de la modification du mot de passe.';
    }
}

include('../../html pages/profile/change_password.html');
?>