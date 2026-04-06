<?php
// Start output buffering to support JSON responses after includes.
if (ob_get_level() === 0) {
    ob_start();
}

// Load session helpers, database connection, and required models.
require_once('../../methods/session.php');
require_once('../../config/Database.php');
require_once('../../methods/Club.php');
require_once('../../methods/Event.php');
require_once('../../methods/MembershipRequest.php');

// Authentication check - redirect if not logged in
if (!isLoggedIn()) {
    $loginUrl = '../profile_php/login.php';
    $redirectTarget = urlencode((string)($_SERVER['REQUEST_URI'] ?? ''));
    if ($redirectTarget !== '') {
        $loginUrl .= '?redirect=' . $redirectTarget;
    }
    header("Location: " . $loginUrl);
    exit();
}

// Read and validate target club identifier.
$club_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($club_id === 0) {
    header("Location: ../club_php/clubs.php");
    exit();
}

// Initialize dependencies and fetch club context data.
$db = new Database();
$connection = $db->getConnection();
$club = new Club($connection);
$event = new Event($connection);
$membership = new MembershipRequest($connection);

$club_info = $club->getClubById($club_id);

if (!$club_info) {
    header("Location: ../club_php/clubs.php");
    exit();
}

$memberSortOrder = strtolower((string)($_GET['member_order'] ?? 'desc'));
if (!in_array($memberSortOrder, ['asc', 'desc'], true)) {
    $memberSortOrder = 'desc';
}
$memberSortToggle = $memberSortOrder === 'asc' ? 'desc' : 'asc';
$memberSortParams = $_GET;
$memberSortParams['member_order'] = $memberSortToggle;
$memberSortUrl = '?' . http_build_query($memberSortParams);
$memberSortIndicator = $memberSortOrder === 'asc' ? '&uarr;' : '&darr;';

$members = $club->getMembers($club_id, $memberSortOrder);
$events = $event->getClubEvents($club_id);
$is_member = isLoggedIn() ? $club->isMember($club_id, getCurrentUserId()) : false;
$has_pending_request = isLoggedIn() ? $membership->hasRequest($club_id, getCurrentUserId()) : false;
$club_join_cooldown_seconds = isLoggedIn() ? $membership->getRequestCooldownSeconds($club_id, getCurrentUserId()) : 0;
$is_owner = isLoggedIn() && getCurrentUserId() === (int)$club_info['responsable_id'];

// Build a contextual return link based on navigation source.
$returnUrl = 'clubs.php';
$returnLabel = 'Retour aux clubs';

$from = strtolower(trim((string)($_GET['from'] ?? '')));
if ($from === 'dashboard') {
    $returnUrl = '../dashboard.php';
    $returnLabel = 'Retour au dashboard';
} elseif ($from === 'requests') {
    $returnUrl = '../requests.php';
    $returnLabel = 'Retour aux demandes';
} elseif ($from === 'event') {
    $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
    if ($eventId > 0) {
        $returnUrl = '../event_php/event_detail.php?id=' . $eventId . '&from=club';
        $returnLabel = 'Retour a l\'evenement';
    }
} elseif ($from === 'events') {
    $returnUrl = '../event_php/events.php';
    $returnLabel = 'Retour aux événements';
}

if ($from === '') {
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $refParts = parse_url($ref);
        $refHost = strtolower((string)($refParts['host'] ?? ''));
        $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $refPath = (string)($refParts['path'] ?? '');

        if ($refHost === '' || $refHost === $currentHost) {
            if (preg_match('~/dashboard\.php$~i', $refPath)) {
                $returnUrl = '../dashboard.php';
                $returnLabel = 'Retour au dashboard';
            } elseif (preg_match('~/requests\.php$~i', $refPath)) {
                $returnUrl = '../requests.php';
                $returnLabel = 'Retour aux demandes';
            } elseif (preg_match('~/event_php/events\.php$~i', $refPath)) {
                $returnUrl = '../event_php/events.php';
                $returnLabel = 'Retour aux événements';
            } elseif (preg_match('~/event_php/event_detail\.php$~i', $refPath)) {
                $refQuery = [];
                parse_str((string)($refParts['query'] ?? ''), $refQuery);
                $refEventId = isset($refQuery['id']) ? (int)$refQuery['id'] : 0;
                if ($refEventId > 0) {
                    $returnUrl = '../event_php/event_detail.php?id=' . $refEventId . '&from=club';
                    $returnLabel = 'Retour a l\'evenement';
                }
            } elseif (preg_match('~/club_php/clubs\.php$~i', $refPath) || preg_match('~/clubs\.php$~i', $refPath)) {
                $returnUrl = 'clubs.php';
                $returnLabel = 'Retour aux clubs';
            }
        }
    }
}

// Initialize user-facing feedback messages.
$message = '';
$error = '';

// Detect AJAX requests for JSON action responses.
function isAjaxRequest() {
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $ajaxPost = isset($_POST['ajax']) && (string)$_POST['ajax'] === '1';
    return $requestedWith === 'xmlhttprequest' || $ajaxPost || strpos($accept, 'application/json') !== false;
}

// Send normalized JSON response and stop execution.
function respondJson($success, $message, $removedCount = 0) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => (bool)$success,
        'message' => (string)$message,
        'removed_count' => (int)$removedCount
    ]);
    exit();
}

// Handle membership, moderation, and event actions.
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Handle join request creation with cooldown checks.
        if ($_POST['action'] === 'join' && !$is_member && !$has_pending_request) {
            $clubJoinCooldownSeconds = $membership->getRequestCooldownSeconds($club_id, getCurrentUserId());

            if ($clubJoinCooldownSeconds > 0) {
                $minutesLeft = (int)ceil($clubJoinCooldownSeconds / 60);
                $error = 'Vous devez attendre encore ' . $minutesLeft . ' minute(s) avant de renvoyer une demande d\'adhesion au club.';
            } elseif ($membership->createRequest($club_id, getCurrentUserId())) {
                $message = 'Demande d\'adhésion envoyée!';
                $has_pending_request = true;
                $membership->clearRequestCooldown($club_id, getCurrentUserId());
            } else {
                $error = 'Impossible d\'envoyer la demande d\'adhésion.';
            }
        // Handle cancellation of pending join request.
        } elseif ($_POST['action'] === 'cancel_join' && !$is_member && !$is_owner && $has_pending_request) {
            if ($membership->cancelRequest($club_id, getCurrentUserId())) {
                $message = 'Demande d\'adhésion annulée.';
                $has_pending_request = false;
                $membership->setRequestCooldown($club_id, getCurrentUserId(), 10);
            } else {
                $error = 'Impossible d\'annuler la demande d\'adhésion.';
            }
        // Handle member leaving the club and notify owner.
        } elseif ($_POST['action'] === 'leave' && $is_member && !$is_owner) {
            if ($club->removeMember($club_id, getCurrentUserId())) {
                $message = 'Vous avez quitté le club.';
                $is_member = false;

                $currentUser = getCurrentUser();
                $memberName = trim((string)($currentUser['prenom'] ?? '') . ' ' . (string)($currentUser['nom'] ?? ''));
                if ($memberName === '') {
                    $memberName = 'Un membre';
                }

                $event->createUserNotification(
                    (int)$club_info['responsable_id'],
                    'club_member_left',
                    'Membre quitte le club',
                    $memberName . ' a quitte le club "' . ($club_info['nom'] ?? '') . '".'
                );
            } else {
                $error = 'Impossible de quitter le club.';
            }
        // Handle bulk member removal by club owner.
        } elseif ($_POST['action'] === 'kick_members' && $is_owner) {
            $rawUserIds = $_POST['user_ids'] ?? [];
            if (!is_array($rawUserIds)) {
                $rawUserIds = [$rawUserIds];
            }

            $targetUserIds = [];
            foreach ($rawUserIds as $rawId) {
                $targetId = (int)$rawId;
                if ($targetId > 0 && $targetId !== (int)$club_info['responsable_id']) {
                    $targetUserIds[$targetId] = $targetId;
                }
            }

            $removedCount = 0;

            if (empty($targetUserIds)) {
                $error = 'Aucun membre valide selectionne.';
            } else {
                foreach ($targetUserIds as $target_user_id) {
                    if (!$club->isMember($club_id, $target_user_id)) {
                        continue;
                    }

                    $eventRemovalCount = 0;
                    $countStmt = $connection->prepare("SELECT COUNT(*)
                                                       FROM EVENT_PARTICIPANTS ep
                                                       JOIN EVENTS e ON e.id = ep.event_id
                                                       WHERE e.club_id = ? AND ep.user_id = ?");
                    $countStmt->execute([$club_id, $target_user_id]);
                    $eventRemovalCount = (int)$countStmt->fetchColumn();

                    if ($club->removeMemberWithEventSubscriptions($club_id, $target_user_id)) {
                        $removedCount++;

                        $event->createUserNotification(
                            $target_user_id,
                            'club_kicked',
                            'Retire du club',
                            'Vous avez ete retire du club "' . ($club_info['nom'] ?? '') . '".'
                        );

                        if ($eventRemovalCount > 0) {
                            $event->createUserNotification(
                                $target_user_id,
                                'event_removed',
                                'Retire des evenements',
                                'Vous avez aussi ete retire de ' . $eventRemovalCount . ' evenement(s) de ce club.'
                            );
                        }
                    }
                }

                if ($removedCount > 0) {
                    $message = $removedCount === 1
                        ? '1 membre exclu du club.'
                        : $removedCount . ' membres exclus du club.';
                } else {
                    $error = 'Impossible d\'exclure les membres selectionnes.';
                }
            }

            if (isAjaxRequest()) {
                if ($removedCount > 0) {
                    respondJson(true, $message, $removedCount);
                }
                respondJson(false, $error !== '' ? $error : 'Impossible d\'exclure les membres selectionnes.', 0);
            }
        // Handle single member removal by club owner.
        } elseif ($_POST['action'] === 'kick' && $is_owner) {
            $target_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $eventRemovalCount = 0;

            if ($target_user_id > 0) {
                $countStmt = $connection->prepare("SELECT COUNT(*)
                                                   FROM EVENT_PARTICIPANTS ep
                                                   JOIN EVENTS e ON e.id = ep.event_id
                                                   WHERE e.club_id = ? AND ep.user_id = ?");
                $countStmt->execute([$club_id, $target_user_id]);
                $eventRemovalCount = (int)$countStmt->fetchColumn();
            }

            if ($target_user_id === 0) {
                $error = 'Membre invalide.';
            } elseif ($target_user_id === (int)$club_info['responsable_id']) {
                $error = 'Le responsable du club ne peut pas être exclu.';
            } elseif (!$club->isMember($club_id, $target_user_id)) {
                $error = 'Cet utilisateur n\'est pas membre du club.';
            } elseif ($club->removeMemberWithEventSubscriptions($club_id, $target_user_id)) {
                $message = 'Membre exclu du club.';

                $event->createUserNotification(
                    $target_user_id,
                    'club_kicked',
                    'Retire du club',
                    'Vous avez ete retire du club "' . ($club_info['nom'] ?? '') . '".'
                );

                if ($eventRemovalCount > 0) {
                    $event->createUserNotification(
                        $target_user_id,
                        'event_removed',
                        'Retire des evenements',
                        'Vous avez aussi ete retire de ' . $eventRemovalCount . ' evenement(s) de ce club.'
                    );
                }
            } else {
                $error = 'Impossible d\'exclure ce membre.';
            }
        // Handle event deletion requests from club owner.
        } elseif ($_POST['action'] === 'delete_event' && $is_owner) {
            $event_id = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
            $event_info = $event_id > 0 ? $event->getEventById($event_id) : null;

            if (!$event_info || (int)$event_info['club_id'] !== $club_id) {
                $error = 'Événement invalide.';
            } elseif ($event->deleteEvent($event_id)) {
                $message = 'Événement supprimé.';
            } else {
                $error = 'Impossible de supprimer cet événement.';
            }
        }
    }

    // Refresh page data after processing actions.
    $members = $club->getMembers($club_id);
    $events = $event->getClubEvents($club_id);
    $is_member = $club->isMember($club_id, getCurrentUserId());
    $has_pending_request = $membership->hasRequest($club_id, getCurrentUserId());
    $club_join_cooldown_seconds = $membership->getRequestCooldownSeconds($club_id, getCurrentUserId());
}

// Render the club detail template.
include('../../html pages/club/club_detail.html');
?>






