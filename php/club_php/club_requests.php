<?php
// Load session helpers, database connection, and required models.
require_once('../../methods/session.php');
require_once('../../config/Database.php');
require_once('../../methods/Club.php');
require_once('../../methods/MembershipRequest.php');

// Require authentication before managing membership requests.
redirectIfNotLoggedIn();

// Read target club identifier.
$club_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($club_id === 0) {
    header("Location: ../club_php/clubs.php");
    exit();
}

$requestSortOrder = strtolower((string)($_GET['request_order'] ?? 'asc'));
if (!in_array($requestSortOrder, ['asc', 'desc'], true)) {
    $requestSortOrder = 'asc';
}
$requestSortToggle = $requestSortOrder === 'asc' ? 'desc' : 'asc';
$requestSortParams = $_GET;
$requestSortParams['request_order'] = $requestSortToggle;
$requestSortUrl = '?' . http_build_query($requestSortParams);
$requestSortIndicator = $requestSortOrder === 'asc' ? '&uarr;' : '&darr;';

// Initialize models and fetch club information.
$db = new Database();
$connection = $db->getConnection();
$club = new Club($connection);
$membership = new MembershipRequest($connection);

$club_info = $club->getClubById($club_id);

if (!$club_info || $club_info['responsable_id'] !== getCurrentUserId()) {
    header("Location: ../dashboard.php");
    exit();
}

// Initialize status message shown after request actions.
$message = '';

// Handle approve/reject actions submitted by club owner.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['request_id'])) {
        $request_id = (int)$_POST['request_id'];
        $user_id = (int)$_POST['user_id'];

        if ($_POST['action'] === 'approve') {
            if ($membership->approveRequest($request_id, $club_id, $user_id)) {
                $message = 'Demande approuvée!';
            }
        } elseif ($_POST['action'] === 'reject') {
            if ($membership->rejectRequest($request_id)) {
                $message = 'Demande refusée!';
            }
        }
    }
}

// Fetch all pending requests for this club.
$pending_requests = $membership->getPendingRequests($club_id, $requestSortOrder);

// Render the club requests template.
include('../../html pages/club/club_requests.html');
?>






