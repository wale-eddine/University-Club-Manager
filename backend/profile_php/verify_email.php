<?php
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/User.php');
require_once('../../classes/EmailVerificationToken.php');

$error = '';
$success = '';
$token = trim((string)($_GET['token'] ?? ''));
$verificationRow = null;

if ($token !== '') {
    $db = new Database();
    $connection = $db->getConnection();
    $verificationModel = new EmailVerificationToken($connection);
    $verificationRow = $verificationModel->getValidTokenByToken($token);
}

if ($token === '') {
    $error = 'Lien de vérification manquant ou invalide.';
} elseif ($verificationRow === null) {
    $error = 'Ce lien de vérification est invalide ou expiré. Demandez un nouvel email de vérification.';
} else {
    $db = new Database();
    $connection = $db->getConnection();
    $userModel = new User($connection);
    $verificationModel = new EmailVerificationToken($connection);

    if ($userModel->markEmailVerified((int)$verificationRow['user_id'])) {
        $verificationModel->markVerified((int)$verificationRow['id']);
        $verificationModel->purgeTokensForUser((int)$verificationRow['user_id']);
        $success = 'Votre email a été vérifié. Vous pouvez maintenant vous connecter.';
    } else {
        $error = 'Impossible de vérifier votre email pour le moment.';
    }
}

include('../../html pages/profile/verify_email.html');
?>