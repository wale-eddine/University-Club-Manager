<?php
// Load session helpers, database connection, and event/club models.
require_once('../../methods/session.php');
require_once('../../config/Database.php');
require_once('../../methods/Event.php');
require_once('../../methods/Club.php');

// Initialize dependencies for event listing.
$db = new Database();
$connection = $db->getConnection();
$event = new Event($connection);
$club = new Club($connection);

// Read search input and fetch matching events.
$search = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search = isset($_POST['search']) ? trim($_POST['search']) : '';
}
$events = !empty($search) ? $event->searchEvents($search) : $event->getAllEvents();

// Load clubs owned by current user for quick event actions.
$responsable_clubs = isLoggedIn() ? $club->getResponsibleClubs(getCurrentUserId()) : [];

// Render the events listing template.
include('../../html pages/event/events.html');
?>






