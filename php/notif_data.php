<?php
// Load session helpers used by this notification endpoint.
require_once('../methods/session.php');

// Return JSON responses for AJAX requests.
header('Content-Type: application/json; charset=UTF-8');

// Return empty payload when the user is not authenticated.
if (!isLoggedIn()) {
    echo json_encode([
        'count' => 0,
        'notifications' => [],
    ]);
    exit();
}

// Handle explicit request to clear dashboard notifications.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'clear') {
        unset($_SESSION['dashboard_notifications']);
        echo json_encode([
            'ok' => true,
            'count' => 0,
            'notifications' => [],
        ]);
        exit();
    }
}

// Read and normalize notification items stored in session.
$notifications = $_SESSION['dashboard_notifications'] ?? [];
if (!is_array($notifications)) {
    $notifications = [];
}

$notifications = array_values(array_map(function ($item) {
    return [
        'type' => (string)($item['type'] ?? 'info'),
        'title' => (string)($item['title'] ?? 'Notification'),
        'message' => (string)($item['message'] ?? ''),
    ];
}, $notifications));

// Return normalized notifications and total count.
echo json_encode([
    'count' => count($notifications),
    'notifications' => $notifications,
]);
