<?php
// Load session helpers, database connection, and required models.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/Club.php');
require_once('../../classes/MembershipRequest.php');
require_once('../../classes/ActionLog.php');

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
$actionLog = new ActionLog($connection);

$club_info = $club->getClubById($club_id);

if (!$club_info || !canManageClubById((int)$club_info['id'])) {
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
        $requestContext = null;

        if ($request_id > 0) {
            $contextStmt = $connection->prepare("SELECT mr.id, mr.club_id, mr.user_id, c.nom AS club_nom, u.prenom, u.nom, u.email
                                                FROM MEMBERSHIP_REQUESTS mr
                                                JOIN CLUBS c ON c.id = mr.club_id
                                                JOIN USERS u ON u.id = mr.user_id
                                                WHERE mr.id = ?
                                                LIMIT 1");
            $contextStmt->execute([$request_id]);
            $requestContext = $contextStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($_POST['action'] === 'approve') {
            if ($membership->approveRequest($request_id, $club_id, $user_id)) {
                $message = 'Demande approuvée!';
                if ($requestContext) {
                    $requesterLabel = trim((string)($requestContext['prenom'] ?? '') . ' ' . (string)($requestContext['nom'] ?? ''));
                    if ($requesterLabel === '') {
                        $requesterLabel = (string)($requestContext['email'] ?? ('User #' . (int)($requestContext['user_id'] ?? 0)));
                    }
                    $actionLog->logAction(
                        (int)getCurrentUserId(),
                        (string)(getCurrentUser()['role'] ?? 'responsable'),
                        'approve_member_request',
                        'membership_request',
                        $request_id,
                        'Demande de ' . $requesterLabel . ' pour le club ' . (string)($requestContext['club_nom'] ?? ''),
                        (int)($requestContext['club_id'] ?? 0),
                        null,
                        'Demande d\'adhesion acceptee.'
                    );
                }
            }
        } elseif ($_POST['action'] === 'reject') {
            if ($membership->rejectRequest($request_id)) {
                $message = 'Demande refusée!';
                if ($requestContext) {
                    $requesterLabel = trim((string)($requestContext['prenom'] ?? '') . ' ' . (string)($requestContext['nom'] ?? ''));
                    if ($requesterLabel === '') {
                        $requesterLabel = (string)($requestContext['email'] ?? ('User #' . (int)($requestContext['user_id'] ?? 0)));
                    }
                    $actionLog->logAction(
                        (int)getCurrentUserId(),
                        (string)(getCurrentUser()['role'] ?? 'responsable'),
                        'reject_member_request',
                        'membership_request',
                        $request_id,
                        'Demande de ' . $requesterLabel . ' pour le club ' . (string)($requestContext['club_nom'] ?? ''),
                        (int)($requestContext['club_id'] ?? 0),
                        null,
                        'Demande d\'adhesion refusee.'
                    );
                }
            }
        }
    }
}

// Fetch all pending requests for this club.
$pending_requests = $membership->getPendingRequests($club_id, $requestSortOrder);

// Render the club requests template.
include('../../html pages/club/club_requests.html');
?>






