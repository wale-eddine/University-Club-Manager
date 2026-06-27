<?php
// API endpoint: returns approved/closed events as JSON for FullCalendar.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/Event.php');

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit();
}

$db = new Database();
$connection = $db->getConnection();
$event = new Event($connection);

// Get optional year filter from query params
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$agendaEvents = $event->getAgendaEvents($year);

$fcEvents = [];
foreach ($agendaEvents as $e) {
    $color = '#1f6feb'; // default blue
    if (($e['approval_status'] ?? '') === 'closed') {
        $color = '#6c757d'; // grey for closed
    }

    $fcEvents[] = [
        'id' => (int)$e['id'],
        'title' => $e['titre'],
        'start' => $e['date_debut'],
        'end' => $e['date_fin'],
        'color' => $color,
        'extendedProps' => [
            'club_nom' => $e['club_nom'] ?? '',
            'lieu' => $e['lieu'] ?? '',
            'description' => $e['description'] ?? '',
            'approval_status' => $e['approval_status'] ?? '',
            'participant_count' => (int)($e['participant_count'] ?? 0),
            'max_participants' => $e['max_participants'] ?? null,
            'image_path' => $e['image_path'] ?? '',
        ],
    ];
}

echo json_encode($fcEvents);
?>
