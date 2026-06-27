<?php

$GLOBALS['mail_last_error'] = '';

function setLastMailError($message) {
    $GLOBALS['mail_last_error'] = trim((string)$message);
}

function getLastMailError() {
    return $GLOBALS['mail_last_error'] ?? '';
}

// Loads optional local mail credentials file.
function loadLocalMailCredentials() {
    $path = __DIR__ . '/mail_credentials.php';
    if (!is_file($path)) {
        return [];
    }

    $data = require $path;
    return is_array($data) ? $data : [];
}

// Returns SMTP configuration from env and local overrides.
function getMailConfig() {
    $local = loadLocalMailCredentials();

    $smtpHost = trim((string)(getenv('MAIL_SMTP_HOST') ?: ($local['smtp_host'] ?? 'smtp.gmail.com')));
    $smtpPort = (int)(getenv('MAIL_SMTP_PORT') ?: ($local['smtp_port'] ?? 587));
    $smtpUsername = trim((string)(getenv('MAIL_SMTP_USERNAME') ?: ($local['smtp_username'] ?? '')));
    $smtpPassword = preg_replace('/\s+/', '', trim((string)(getenv('MAIL_SMTP_PASSWORD') ?: ($local['smtp_password'] ?? ''))));
    $fromEmail = trim((string)(getenv('MAIL_FROM_ADDRESS') ?: ($local['from_email'] ?? $smtpUsername)));
    $fromName = trim((string)(getenv('MAIL_FROM_NAME') ?: ($local['from_name'] ?? 'University Clubs')));

    return [
        'smtp_host' => $smtpHost,
        'smtp_port' => $smtpPort,
        'smtp_username' => $smtpUsername,
        'smtp_password' => $smtpPassword,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
    ];
}

// Reads SMTP server response, handling multiline replies.
function smtpReadResponse($socket) {
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

// Sends one command and validates the expected SMTP response code.
function smtpCommand($socket, $command, $expectedCode) {
    fwrite($socket, $command . "\r\n");
    $response = smtpReadResponse($socket);
    $ok = strpos($response, (string)$expectedCode) === 0;

    if (!$ok) {
        setLastMailError('SMTP command failed [' . $command . '] => ' . trim($response));
    }

    return $ok;
}

// Sends an email through authenticated SMTP with STARTTLS.
function sendSmtpMail($toEmail, $subject, $htmlBody, $textBody, $config) {
    $host = $config['smtp_host'];
    $port = (int)$config['smtp_port'];
    $username = $config['smtp_username'];
    $password = $config['smtp_password'];

    $fallback = function() use ($toEmail, $subject, $htmlBody, $config) {
        if (is_array($toEmail)) {
            $headers_native = "From: " . $config['from_name'] . " <" . $config['from_email'] . ">\r\n";
            $headers_native .= "Bcc: " . implode(', ', $toEmail) . "\r\n";
            $headers_native .= "MIME-Version: 1.0\r\n";
            $headers_native .= "Content-Type: text/html; charset=UTF-8\r\n";
            return @mail($config['from_email'], $subject, $htmlBody, $headers_native);
        } else {
            $headers_native = "From: " . $config['from_name'] . " <" . $config['from_email'] . ">\r\n";
            $headers_native .= "MIME-Version: 1.0\r\n";
            $headers_native .= "Content-Type: text/html; charset=UTF-8\r\n";
            return @mail($toEmail, $subject, $htmlBody, $headers_native);
        }
    };

    if ($host === '' || $username === '' || $password === '') {
        setLastMailError('SMTP config missing host/username/password. Trying native fallback.');
        return $fallback();
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]
    ]);

    $socket = @stream_socket_client($host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        setLastMailError('SMTP connection failed: ' . $errstr . ' (' . $errno . '). Trying native fallback.');
        return $fallback();
    }

    stream_set_timeout($socket, 20);
    $greeting = smtpReadResponse($socket);
    if (strpos($greeting, '220') !== 0) {
        setLastMailError('SMTP greeting invalid: ' . trim($greeting) . '. Trying native fallback.');
        fclose($socket);
        return $fallback();
    }

    if (!smtpCommand($socket, 'EHLO localhost', 250)) {
        fclose($socket);
        return $fallback();
    }

    if (!smtpCommand($socket, 'STARTTLS', 220)) {
        fclose($socket);
        return $fallback();
    }

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        setLastMailError('SMTP TLS negotiation failed. Trying native fallback.');
        fclose($socket);
        return $fallback();
    }

    if (!smtpCommand($socket, 'EHLO localhost', 250)) {
        fclose($socket);
        return $fallback();
    }

    if (!smtpCommand($socket, 'AUTH LOGIN', 334)) {
        fclose($socket);
        return $fallback();
    }

    if (!smtpCommand($socket, base64_encode($username), 334)) {
        fclose($socket);
        return $fallback();
    }

    if (!smtpCommand($socket, base64_encode($password), 235)) {
        fclose($socket);
        return $fallback();
    }

    if (!smtpCommand($socket, 'MAIL FROM:<' . $config['from_email'] . '>', 250)) {
        fclose($socket);
        return $fallback();
    }

    $recipients = is_array($toEmail) ? $toEmail : [$toEmail];
    $rcptSuccess = false;
    foreach ($recipients as $recipient) {
        if (trim($recipient) !== '') {
            if (smtpCommand($socket, 'RCPT TO:<' . trim($recipient) . '>', 250)) {
                $rcptSuccess = true;
            }
        }
    }

    if (!$rcptSuccess) {
        setLastMailError('All SMTP recipients failed.');
        fclose($socket);
        return $fallback();
    }

    if (!smtpCommand($socket, 'DATA', 354)) {
        fclose($socket);
        return $fallback();
    }

    $boundary = 'b1_' . bin2hex(random_bytes(8));
    $headers = [];
    $headers[] = 'From: ' . $config['from_name'] . ' <' . $config['from_email'] . '>';
    if (is_array($toEmail)) {
        $headers[] = 'To: <' . $config['from_email'] . '>';
    } else {
        $headers[] = 'To: <' . $toEmail . '>';
    }
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = '';
    $headers[] = '--' . $boundary;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = '';
    $headers[] = $textBody;
    $headers[] = '';
    $headers[] = '--' . $boundary;
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = '';
    $headers[] = $htmlBody;
    $headers[] = '';
    $headers[] = '--' . $boundary . '--';
    $headers[] = '';

    fwrite($socket, implode("\r\n", $headers) . "\r\n.\r\n");
    $dataResponse = smtpReadResponse($socket);

    smtpCommand($socket, 'QUIT', 221);
    fclose($socket);

    $ok = strpos($dataResponse, '250') === 0;
    if (!$ok) {
        setLastMailError('SMTP DATA rejected: ' . trim($dataResponse) . '. Trying native fallback.');
        return $fallback();
    }

    return $ok;
}

// Sends a password reset email using the local PHP mail setup.
function sendPasswordResetEmail($toEmail, $recipientName, $resetUrl) {
    setLastMailError('');
    $config = getMailConfig();
    $safeRecipient = trim((string)$recipientName);
    $subject = 'Réinitialisation de votre mot de passe';

    $htmlBody = '<!DOCTYPE html><html lang="fr"><body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#162033;">'
        . '<div style="max-width:620px;margin:0 auto;padding:32px 16px;">'
        . '<div style="background:#ffffff;border-radius:18px;padding:32px;border:1px solid #e5e9f2;box-shadow:0 12px 30px rgba(17,34,68,0.08);">'
        . '<h2 style="margin:0 0 16px;font-size:28px;line-height:1.2;">Réinitialisation du mot de passe</h2>'
        . '<p style="font-size:16px;line-height:1.7;margin:0 0 14px;">Bonjour ' . htmlspecialchars($safeRecipient !== '' ? $safeRecipient : 'utilisateur', ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p style="font-size:16px;line-height:1.7;margin:0 0 14px;">Nous avons reçu une demande pour réinitialiser votre mot de passe. Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe.</p>'
        . '<p style="text-align:center;margin:28px 0;">'
        . '<a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:14px 24px;border-radius:12px;font-weight:700;">Réinitialiser mon mot de passe</a>'
        . '</p>'
        . '<p style="font-size:15px;line-height:1.7;margin:0 0 10px;color:#4e5a72;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>'
        . '<p style="word-break:break-all;font-size:14px;line-height:1.6;margin:0 0 18px;color:#0d6efd;">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:14px;line-height:1.7;margin:0;color:#4e5a72;">Si vous n’êtes pas à l’origine de cette demande, ignorez simplement cet email. Votre compte reste protégé.</p>'
        . '<p style="font-size:14px;line-height:1.7;margin:18px 0 0;color:#4e5a72;">Pensez à vérifier votre dossier spam si vous ne voyez pas l’email dans votre boîte de réception.</p>'
        . '</div></div></body></html>';

    $textBody = "Bonjour " . ($safeRecipient !== '' ? $safeRecipient : 'utilisateur') . ",\n\n"
        . "Nous avons reçu une demande pour réinitialiser votre mot de passe.\n"
        . "Ouvrez ce lien pour choisir un nouveau mot de passe :\n"
        . $resetUrl . "\n\n"
        . "Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email.\n"
        . "Pensez à vérifier le dossier spam si vous ne voyez pas le message.\n";

    return sendSmtpMail($toEmail, $subject, $htmlBody, $textBody, $config);
}

// Sends a verification email for new accounts.
function sendVerificationEmail($toEmail, $recipientName, $verificationUrl) {
    setLastMailError('');
    $config = getMailConfig();
    $safeRecipient = trim((string)$recipientName);
    $subject = 'Vérifiez votre adresse email';

    $htmlBody = '<!DOCTYPE html><html lang="fr"><body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#162033;">'
        . '<div style="max-width:620px;margin:0 auto;padding:32px 16px;">'
        . '<div style="background:#ffffff;border-radius:18px;padding:32px;border:1px solid #e5e9f2;box-shadow:0 12px 30px rgba(17,34,68,0.08);">'
        . '<h2 style="margin:0 0 16px;font-size:28px;line-height:1.2;">Confirmez votre adresse email</h2>'
        . '<p style="font-size:16px;line-height:1.7;margin:0 0 14px;">Bonjour ' . htmlspecialchars($safeRecipient !== '' ? $safeRecipient : 'utilisateur', ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p style="font-size:16px;line-height:1.7;margin:0 0 14px;">Merci pour votre inscription. Cliquez sur le bouton ci-dessous pour activer votre compte et pouvoir vous connecter.</p>'
        . '<p style="text-align:center;margin:28px 0;">'
        . '<a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:14px 24px;border-radius:12px;font-weight:700;">Vérifier mon email</a>'
        . '</p>'
        . '<p style="font-size:15px;line-height:1.7;margin:0 0 10px;color:#4e5a72;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :</p>'
        . '<p style="word-break:break-all;font-size:14px;line-height:1.6;margin:0 0 18px;color:#0d6efd;">' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:14px;line-height:1.7;margin:0;color:#4e5a72;">Si vous n’êtes pas à l’origine de cette inscription, ignorez simplement cet email.</p>'
        . '<p style="font-size:14px;line-height:1.7;margin:18px 0 0;color:#4e5a72;">Pensez à vérifier votre dossier spam si vous ne voyez pas l’email dans votre boîte de réception.</p>'
        . '</div></div></body></html>';

    $textBody = "Bonjour " . ($safeRecipient !== '' ? $safeRecipient : 'utilisateur') . ",\n\n"
        . "Merci pour votre inscription. Ouvrez ce lien pour activer votre compte :\n"
        . $verificationUrl . "\n\n"
        . "Si vous n'êtes pas à l'origine de cette inscription, ignorez simplement cet email.\n"
        . "Pensez à vérifier le dossier spam si vous ne voyez pas le message.\n";

    return sendSmtpMail($toEmail, $subject, $htmlBody, $textBody, $config);
}

?>