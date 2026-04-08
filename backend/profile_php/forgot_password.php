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
            if ((string)($user['account_status'] ?? 'active') !== 'active') {
                $error = 'Votre compte a ete desactive par un administrateur.';
            } else {
                $cooldownSeconds = 2 * 3600;
                $remainingSeconds = $resetModel->getCooldownRemainingSecondsForEmail($user['email'], $cooldownSeconds);

                if ($remainingSeconds > 0) {
                    $remainingMinutes = (int)ceil($remainingSeconds / 60);
                    $error = 'Un lien de reinitialisation a deja ete demande pour cette adresse. Reessayez dans environ ' . $remainingMinutes . ' minute' . ($remainingMinutes > 1 ? 's' : '') . '.';
                } else {
                    $rawToken = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
                    $stored = $resetModel->createToken((int)$user['id'], $user['email'], $rawToken, $expiresAt);

                    if ($stored) {
                        $resetUrl = buildAppUrl('backend/profile_php/reset_password.php?token=' . urlencode($rawToken));
                        $recipientName = trim((string)($user['prenom'] ?? '') . ' ' . (string)($user['nom'] ?? ''));
                        $sent = sendPasswordResetEmail($user['email'], $recipientName, $resetUrl);

                        if ($sent) {
                            $success = 'Si un compte existe pour cet email, nous avons envoye un lien de reinitialisation. Verifiez votre boite de reception et le dossier spam.';
                        } else {
                            $mailError = function_exists('getLastMailError') ? trim((string)getLastMailError()) : '';
                            $error = 'Le lien a ete genere, mais l email n a pas pu etre envoye. Verifiez la configuration mail du serveur.';
                            if ($mailError !== '') {
                                $error .= ' Detail: ' . $mailError;
                            }
                        }
                    } else {
                        $error = 'Impossible de preparer la reinitialisation pour le moment.';
                    }
                }
            }
        } else {
            $success = 'Si un compte existe pour cet email, nous avons envoye un lien de reinitialisation. Verifiez votre boite de reception et le dossier spam.';
        }
    }
}

include('../../html pages/profile/forgot_password.html');
?>