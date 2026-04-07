<?php
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../config/app.php');
require_once('../../config/mail.php');
require_once('../../classes/User.php');
require_once('../../classes/PasswordResetToken.php');

redirectIfLoggedIn();

$error = '';
$success = '';
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = trim($_POST['email'] ?? '');

    if ($emailValue === '') {
        $error = 'Veuillez saisir votre adresse email.';
    } else {
        $db = new Database();
        $connection = $db->getConnection();
        $userModel = new User($connection);
        $resetModel = new PasswordResetToken($connection);

        $user = $userModel->getUserByEmail($emailValue);

        if ($user) {
            $rawToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);
            $stored = $resetModel->createToken((int)$user['id'], $user['email'], $rawToken, $expiresAt);

            if ($stored) {
                $resetUrl = buildAppUrl('backend/profile_php/reset_password.php?token=' . urlencode($rawToken));
                $recipientName = trim((string)($user['prenom'] ?? '') . ' ' . (string)($user['nom'] ?? ''));
                $sent = sendPasswordResetEmail($user['email'], $recipientName, $resetUrl);

                if ($sent) {
                    $success = 'Si un compte existe pour cet email, nous avons envoyé un lien de réinitialisation. Vérifiez votre boîte de réception et le dossier spam.';
                } else {
                    $mailError = function_exists('getLastMailError') ? trim((string)getLastMailError()) : '';
                    $error = 'Le lien a été généré, mais l’email n’a pas pu être envoyé. Vérifiez la configuration mail du serveur.';
                    if ($mailError !== '') {
                        $error .= ' Détail: ' . $mailError;
                    }
                }
            } else {
                $error = 'Impossible de préparer la réinitialisation pour le moment.';
            }
        } else {
            $success = 'Si un compte existe pour cet email, nous avons envoyé un lien de réinitialisation. Vérifiez votre boîte de réception et le dossier spam.';
        }
    }
}

include('../../html pages/profile/forgot_password.html');
?>