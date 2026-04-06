<?php
// Load session helpers, database connection, and domain models.
require_once('../classes/session.php');
require_once('../config/Database.php');
require_once('../classes/Club.php');
require_once('../classes/Event.php');

// Initialize services and fetch homepage data.
$db = new Database();
$connection = $db->getConnection();

$club = new Club($connection);
$event = new Event($connection);

$clubs = $club->getClubs();
$events = $event->getLatestCreatedEvents();

// Render the homepage template.
include('../html pages/index.html');
?>



