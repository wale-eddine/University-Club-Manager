<?php
// Load session helpers, database connection, and membership request model.
require_once('../methods/session.php');
require_once('../config/Database.php');
require_once('../methods/MembershipRequest.php');

// Require authentication before accessing requests page.
redirectIfNotLoggedIn();

// Initialize dependencies and default page state.
$db = new Database();
$connection = $db->getConnection();
$membership = new MembershipRequest($connection);

$userId = (int)getCurrentUserId();
$message = '';
$error = '';

$requestSortOrder = strtolower((string)($_GET['request_order'] ?? 'asc'));
if (!in_array($requestSortOrder, ['asc', 'desc'], true)) {
    $requestSortOrder = 'asc';
}
$requestSortToggle = $requestSortOrder === 'asc' ? 'desc' : 'asc';
$requestSortParams = $_GET;
$requestSortParams['request_order'] = $requestSortToggle;
$requestSortUrl = '?' . http_build_query($requestSortParams);
$requestSortIndicator = $requestSortOrder === 'asc' ? '&uarr;' : '&darr;';

// Handle approve/reject actions submitted by the owner.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $action = $_POST['action'] ?? '';

    if ($requestId <= 0 || ($action !== 'approve' && $action !== 'reject')) {
        $error = 'Action invalide.';
    } else {
        if ($action === 'approve') {
            if ($membership->approveRequestForOwner($requestId, $userId)) {
                $message = 'Demande approuvee.';
            } else {
                $error = 'Impossible d\'approuver cette demande.';
            }
        }

        if ($action === 'reject') {
            if ($membership->rejectRequestForOwner($requestId, $userId)) {
                $message = 'Demande refusee.';
            } else {
                $error = 'Impossible de refuser cette demande.';
            }
        }
    }
}

// Fetch pending requests to display in the page.
$pending_requests = $membership->getPendingRequestsForOwner($userId, $requestSortOrder);

// Render the requests template.
include('../html pages/requests.html');
?>

