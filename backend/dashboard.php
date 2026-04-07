<?php
// Load session helpers, database connection, and page dependencies.
require_once('../classes/session.php');
require_once('../config/Database.php');
require_once('../classes/Club.php');
require_once('../classes/Event.php');

// Block access for unauthenticated users.
redirectIfNotLoggedIn();

// Build models and fetch dashboard data for the current user.
$db = new Database();
$connection = $db->getConnection();
$club = new Club($connection);
$event = new Event($connection);

$user_id = getCurrentUserId();
$user_clubs = isAdmin() ? $club->getClubs() : $club->getUserClubs($user_id);
$responsable_clubs = isAdmin() ? $club->getClubs() : $club->getResponsibleClubs($user_id);
$user_events = isAdmin() ? $event->getAllEvents() : $event->getUserEvents($user_id);
$dashboard_notifications = $_SESSION['dashboard_notifications'] ?? [];
$dashboard_notification_count = count($dashboard_notifications);

// Clear notifications only when explicitly requested.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_notifs'])) {
    unset($_SESSION['dashboard_notifications']);
    $dashboard_notifications = [];
    $dashboard_notification_count = 0;
}

// Render the dashboard template.
include('../html pages/dashboard.html');
?>



