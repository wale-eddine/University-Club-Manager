<?php
// Load session helpers, database connection, and event model.
require_once('../classes/session.php');
require_once('../config/Database.php');
require_once('../classes/Event.php');

redirectIfNotLoggedIn();

$db = new Database();
$connection = $db->getConnection();
$event = new Event($connection);

$agenda_year = (int)date('Y');
$agenda_events = $event->getAgendaEvents($agenda_year);

// Check if user has Google Calendar connected
$hasGoogleCalendar = false;
$userId = getCurrentUserId();
if ($userId) {
    $stmt = $connection->prepare("SELECT google_refresh_token FROM USERS WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasGoogleCalendar = !empty($row['google_refresh_token']);
}

include('../html pages/agenda.html');
?>