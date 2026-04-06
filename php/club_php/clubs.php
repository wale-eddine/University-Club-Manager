<?php
// Load session helpers, database connection, and club model.
require_once('../../methods/session.php');
require_once('../../config/Database.php');
require_once('../../methods/Club.php');

// Initialize dependencies for club listing.
$db = new Database();
$connection = $db->getConnection();
$club = new Club($connection);

// Read search input and fetch matching clubs.
$search = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $search = isset($_POST['search']) ? trim($_POST['search']) : '';
}
$clubs = !empty($search) ? $club->searchClubs($search) : $club->getClubs();

// Render the clubs listing template.
include('../../html pages/club/clubs.html');
?>






