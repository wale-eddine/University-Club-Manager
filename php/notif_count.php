<?php
// Load session helpers used by this notification endpoint.
require_once('../methods/session.php');

// Return JSON responses for AJAX polling.
header('Content-Type: application/json; charset=UTF-8');

// Return zero count when the user is not authenticated.
if (!isLoggedIn()) {
    echo json_encode(['count' => 0]);
    exit();
}

// Return current dashboard notification count.
$count = count($_SESSION['dashboard_notifications'] ?? []);
echo json_encode(['count' => (int)$count]);
