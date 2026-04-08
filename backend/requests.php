<?php
// Load session helpers, database connection, and membership request model.
require_once('../classes/session.php');
require_once('../config/Database.php');
require_once('../classes/MembershipRequest.php');
require_once('../classes/ActionLog.php');

// Require authentication before accessing requests page.
redirectIfNotLoggedIn();

// Initialize dependencies and default page state.
$db = new Database();
$connection = $db->getConnection();
$membership = new MembershipRequest($connection);
$actionLog = new ActionLog($connection);

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
    $requestContext = null;

    if ($requestId > 0) {
        $contextStmt = $connection->prepare("SELECT mr.id, mr.club_id, mr.user_id, c.nom AS club_nom, u.prenom, u.nom, u.email
                                            FROM MEMBERSHIP_REQUESTS mr
                                            JOIN CLUBS c ON c.id = mr.club_id
                                            JOIN USERS u ON u.id = mr.user_id
                                            WHERE mr.id = ?
                                            LIMIT 1");
        $contextStmt->execute([$requestId]);
        $requestContext = $contextStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($requestId <= 0 || ($action !== 'approve' && $action !== 'reject')) {
        $error = 'Action invalide.';
    } else {
        if (isAdmin()) {
            if ($action === 'approve') {
                if ($membership->approveRequestByAdmin($requestId)) {
                    $message = 'Demande approuvee.';
                    if ($requestContext) {
                        $requesterLabel = trim((string)($requestContext['prenom'] ?? '') . ' ' . (string)($requestContext['nom'] ?? ''));
                        if ($requesterLabel === '') {
                            $requesterLabel = (string)($requestContext['email'] ?? ('User #' . (int)($requestContext['user_id'] ?? 0)));
                        }
                        $actionLog->logAction(
                            $userId,
                            (string)(getCurrentUser()['role'] ?? 'admin'),
                            'approve_member_request',
                            'membership_request',
                            $requestId,
                            'Demande de ' . $requesterLabel . ' pour le club ' . (string)($requestContext['club_nom'] ?? ''),
                            (int)($requestContext['club_id'] ?? 0),
                            null,
                            'Demande d\'adhesion acceptee.'
                        );
                    }
                } else {
                    $error = 'Impossible d\'approuver cette demande.';
                }
            }

            if ($action === 'reject') {
                if ($membership->rejectRequestByAdmin($requestId)) {
                    $message = 'Demande refusee.';
                    if ($requestContext) {
                        $requesterLabel = trim((string)($requestContext['prenom'] ?? '') . ' ' . (string)($requestContext['nom'] ?? ''));
                        if ($requesterLabel === '') {
                            $requesterLabel = (string)($requestContext['email'] ?? ('User #' . (int)($requestContext['user_id'] ?? 0)));
                        }
                        $actionLog->logAction(
                            $userId,
                            (string)(getCurrentUser()['role'] ?? 'admin'),
                            'reject_member_request',
                            'membership_request',
                            $requestId,
                            'Demande de ' . $requesterLabel . ' pour le club ' . (string)($requestContext['club_nom'] ?? ''),
                            (int)($requestContext['club_id'] ?? 0),
                            null,
                            'Demande d\'adhesion refusee.'
                        );
                    }
                } else {
                    $error = 'Impossible de refuser cette demande.';
                }
            }
        } else {
            if ($action === 'approve') {
                if ($membership->approveRequestForOwner($requestId, $userId)) {
                    $message = 'Demande approuvee.';
                    if ($requestContext) {
                        $requesterLabel = trim((string)($requestContext['prenom'] ?? '') . ' ' . (string)($requestContext['nom'] ?? ''));
                        if ($requesterLabel === '') {
                            $requesterLabel = (string)($requestContext['email'] ?? ('User #' . (int)($requestContext['user_id'] ?? 0)));
                        }
                        $actionLog->logAction(
                            $userId,
                            (string)(getCurrentUser()['role'] ?? 'responsable'),
                            'approve_member_request',
                            'membership_request',
                            $requestId,
                            'Demande de ' . $requesterLabel . ' pour le club ' . (string)($requestContext['club_nom'] ?? ''),
                            (int)($requestContext['club_id'] ?? 0),
                            null,
                            'Demande d\'adhesion acceptee.'
                        );
                    }
                } else {
                    $error = 'Impossible d\'approuver cette demande.';
                }
            }

            if ($action === 'reject') {
                if ($membership->rejectRequestForOwner($requestId, $userId)) {
                    $message = 'Demande refusee.';
                    if ($requestContext) {
                        $requesterLabel = trim((string)($requestContext['prenom'] ?? '') . ' ' . (string)($requestContext['nom'] ?? ''));
                        if ($requesterLabel === '') {
                            $requesterLabel = (string)($requestContext['email'] ?? ('User #' . (int)($requestContext['user_id'] ?? 0)));
                        }
                        $actionLog->logAction(
                            $userId,
                            (string)(getCurrentUser()['role'] ?? 'responsable'),
                            'reject_member_request',
                            'membership_request',
                            $requestId,
                            'Demande de ' . $requesterLabel . ' pour le club ' . (string)($requestContext['club_nom'] ?? ''),
                            (int)($requestContext['club_id'] ?? 0),
                            null,
                            'Demande d\'adhesion refusee.'
                        );
                    }
                } else {
                    $error = 'Impossible de refuser cette demande.';
                }
            }
        }
    }
}

// Fetch pending requests to display in the page.
$pending_requests = isAdmin()
    ? $membership->getPendingRequestsForAdmin($requestSortOrder)
    : $membership->getPendingRequestsForOwner($userId, $requestSortOrder);

// Render the requests template.
include('../html pages/requests.html');
?>

