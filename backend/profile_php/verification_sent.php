<?php
require_once('../../classes/session.php');

$signupEmail = trim((string)($_SESSION['signup_verification_email'] ?? ''));
$signupName = trim((string)($_SESSION['signup_verification_name'] ?? ''));

unset($_SESSION['signup_verification_email'], $_SESSION['signup_verification_name']);

include('../../html pages/profile/verification_sent.html');
?>