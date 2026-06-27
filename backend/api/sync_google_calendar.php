<?php
// API endpoint: sync events to user's Google Calendar.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/GoogleCalendarHelper.php');

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit();
}

$userId = (int)getCurrentUserId();
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Utilisateur invalide']);
    exit();
}

$db = new Database();
$connection = $db->getConnection();

// Check if user has Google Calendar connected
$stmt = $connection->prepare("SELECT google_refresh_token FROM USERS WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || empty($user['google_refresh_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Google Calendar non connecté. Connectez votre compte depuis votre profil.']);
    exit();
}

$helper = new GoogleCalendarHelper($connection);
$result = $helper->syncUserEvents($userId);

echo json_encode($result);
?>
