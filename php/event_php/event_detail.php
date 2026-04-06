<?php
// Start output buffering to support JSON responses after includes.
if (ob_get_level() === 0) {
    ob_start();
}

// Load session helpers, database connection, and required models.
require_once('../../methods/session.php');
require_once('../../config/Database.php');
require_once('../../methods/Event.php');
require_once('../../methods/Club.php');
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

// Read and validate target event identifier.
$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($event_id === 0) {
    header("Location: ../event_php/events.php");
    exit();
}

// Initialize dependencies and load event context.
$db = new Database();
$connection = $db->getConnection();
$event = new Event($connection);
$club = new Club($connection);
$membership = new MembershipRequest($connection);

$event_info = $event->getEventById($event_id);

if (!$event_info) {
    header("Location: ../event_php/events.php");
    exit();
}

$participantSortOrder = strtolower((string)($_GET['participant_order'] ?? 'desc'));
if (!in_array($participantSortOrder, ['asc', 'desc'], true)) {
    $participantSortOrder = 'desc';
}
$participantSortToggle = $participantSortOrder === 'asc' ? 'desc' : 'asc';
$participantSortParams = $_GET;
$participantSortParams['participant_order'] = $participantSortToggle;
$participantSortUrl = '?' . http_build_query($participantSortParams);
$participantSortIndicator = $participantSortOrder === 'asc' ? '&uarr;' : '&darr;';

$participants = $event->getParticipants($event_id, $participantSortOrder);
$is_participant = isLoggedIn() ? $event->isParticipant($event_id, getCurrentUserId()) : false;
$is_club_owner = isLoggedIn() && (int)getCurrentUserId() === (int)$event_info['club_responsable_id'];
$is_club_member = isLoggedIn() ? $club->isMember((int)$event_info['club_id'], getCurrentUserId()) : false;
$has_pending_club_request = isLoggedIn() ? $membership->hasRequest((int)$event_info['club_id'], getCurrentUserId()) : false;
$club_join_cooldown_seconds = isLoggedIn() ? $membership->getRequestCooldownSeconds((int)$event_info['club_id'], getCurrentUserId()) : 0;
$allows_non_members = (int)($event_info['allow_non_members'] ?? 0) === 1;

// Build a contextual return link based on navigation source.
$returnUrl = 'events.php';
$returnLabel = 'Retour aux événements';

$from = strtolower(trim((string)($_GET['from'] ?? '')));
if ($from === 'dashboard') {
    $returnUrl = '../dashboard.php';
    $returnLabel = 'Retour au dashboard';
} elseif ($from === 'club') {
    $returnUrl = '../club_php/club_detail.php?id=' . (int)$event_info['club_id'];
    $returnLabel = 'Retour au club';
} elseif ($from === 'requests') {
    $returnUrl = '../requests.php';
    $returnLabel = 'Retour aux demandes';
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
            } elseif (preg_match('~/club_php/club_detail\.php$~i', $refPath)) {
                $refQuery = [];
                parse_str((string)($refParts['query'] ?? ''), $refQuery);
                $refClubId = isset($refQuery['id']) ? (int)$refQuery['id'] : (int)$event_info['club_id'];
                $returnUrl = '../club_php/club_detail.php?id=' . $refClubId;
                $returnLabel = 'Retour au club';
            } elseif (preg_match('~/event_php/events\.php$~i', $refPath) || preg_match('~/events\.php$~i', $refPath)) {
                $returnUrl = 'events.php';
                $returnLabel = 'Retour aux événements';
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

// Handle event participation and related club actions.
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $rejoinCooldownSeconds = $event->getRejoinCooldownSeconds($event_id, getCurrentUserId());

        // Handle event join action with eligibility checks.
        if ($_POST['action'] === 'join' && !$is_participant) {
            if (!$allows_non_members && !$is_club_member && !$is_club_owner) {
                $error = 'Cet événement est réservé aux membres du club.';
            } elseif ($rejoinCooldownSeconds > 0 && !$is_club_owner) {
                $minutesLeft = (int)ceil($rejoinCooldownSeconds / 60);
                $error = 'Vous devez attendre encore ' . $minutesLeft . ' minute(s) avant de vous réinscrire.';
            } elseif ($event->isEventFull($event_id)) {
                $error = 'La limite de participants est atteinte pour cet événement.';
            } elseif ($event->addParticipant($event_id, getCurrentUserId())) {
                $message = 'Inscription réussie!';
                $is_participant = true;
                $event->clearRejoinCooldown($event_id, getCurrentUserId());

                $currentUser = getCurrentUser();
                $participantName = trim((string)($currentUser['prenom'] ?? '') . ' ' . (string)($currentUser['nom'] ?? ''));
                if ($participantName === '') {
                    $participantName = 'Un utilisateur';
                }

                $ownerId = (int)$event_info['club_responsable_id'];
                $actorId = (int)getCurrentUserId();
                if ($ownerId > 0 && $ownerId !== $actorId) {
                    $event->createUserNotification(
                        $ownerId,
                        'event_subscribed',
                        'Nouvelle inscription',
                        $participantName . ' s\'est inscrit(e) à l\'événement "' . ($event_info['titre'] ?? '') . '".'
                    );
                }
            } else {
                $error = 'Impossible de finaliser l\'inscription.';
            }
        // Redirect owner to event edit page.
        } elseif ($_POST['action'] === 'edit_event' && $is_club_owner) {
            header("Location: ../event_php/event_edit.php?id=" . (int)$event_info['id'] . "&from=event");
            exit();
        // Handle join request to the parent club.
        } elseif ($_POST['action'] === 'join_club' && !$is_club_member && !$is_club_owner && !$has_pending_club_request) {
            $clubJoinCooldownSeconds = $membership->getRequestCooldownSeconds((int)$event_info['club_id'], getCurrentUserId());

            if ($clubJoinCooldownSeconds > 0) {
                $minutesLeft = (int)ceil($clubJoinCooldownSeconds / 60);
                $error = 'Vous devez attendre encore ' . $minutesLeft . ' minute(s) avant de renvoyer une demande d\'adhesion au club.';
            } elseif ($membership->createRequest((int)$event_info['club_id'], getCurrentUserId())) {
                $message = 'Demande d\'adhésion envoyée au club.';
                $has_pending_club_request = true;
                $membership->clearRequestCooldown((int)$event_info['club_id'], getCurrentUserId());
            } else {
                $error = 'Impossible d\'envoyer la demande d\'adhésion.';
            }
        // Handle cancellation of pending club join request.
        } elseif ($_POST['action'] === 'cancel_join_club' && !$is_club_member && !$is_club_owner && $has_pending_club_request) {
            if ($membership->cancelRequest((int)$event_info['club_id'], getCurrentUserId())) {
                $message = 'Demande d\'adhésion annulée.';
                $has_pending_club_request = false;
                $membership->setRequestCooldown((int)$event_info['club_id'], getCurrentUserId(), 10);
            } else {
                $error = 'Impossible d\'annuler la demande d\'adhésion.';
            }
        // Handle participant cancellation and optional cooldown.
        } elseif ($_POST['action'] === 'cancel' && $is_participant) {
            if ($event->removeParticipant($event_id, getCurrentUserId())) {
                $message = 'Inscription annulée.';
                $is_participant = false;
                if (!$is_club_owner) {
                    $event->setRejoinCooldown($event_id, getCurrentUserId(), 10);
                }

                $currentUser = getCurrentUser();
                $participantName = trim((string)($currentUser['prenom'] ?? '') . ' ' . (string)($currentUser['nom'] ?? ''));
                if ($participantName === '') {
                    $participantName = 'Un utilisateur';
                }

                $ownerId = (int)$event_info['club_responsable_id'];
                $actorId = (int)getCurrentUserId();
                if ($ownerId > 0 && $ownerId !== $actorId) {
                    $event->createUserNotification(
                        $ownerId,
                        'event_unsubscribed',
                        'Desinscription',
                        $participantName . ' s\'est desinscrit(e) de l\'événement "' . ($event_info['titre'] ?? '') . '".'
                    );
                }
            } else {
                $error = 'Impossible d\'annuler l\'inscription.';
            }
        // Handle bulk participant removal by event owner.
        } elseif ($_POST['action'] === 'remove_participants' && $is_club_owner) {
            $rawUserIds = $_POST['user_ids'] ?? [];
            if (!is_array($rawUserIds)) {
                $rawUserIds = [$rawUserIds];
            }
            if (empty($rawUserIds) && isset($_POST['user_id'])) {
                $rawUserIds = [$_POST['user_id']];
            }

            $targetUserIds = [];
            foreach ($rawUserIds as $rawId) {
                $targetId = (int)$rawId;
                if ($targetId > 0 && $targetId !== (int)$event_info['club_responsable_id']) {
                    $targetUserIds[$targetId] = $targetId;
                }
            }

            $removedCount = 0;

            if (empty($targetUserIds)) {
                $error = 'Aucun participant valide selectionne.';
            } else {
                foreach ($targetUserIds as $target_user_id) {
                    if (!$event->isParticipant($event_id, $target_user_id)) {
                        continue;
                    }

                    if ($event->removeParticipant($event_id, $target_user_id)) {
                        $removedCount++;
                        $event->createUserNotification(
                            $target_user_id,
                            'event_removed',
                            'Retire de l\'evenement',
                            'Vous avez ete retire de l\'evenement "' . ($event_info['titre'] ?? '') . '".'
                        );
                    }
                }

                if ($removedCount > 0) {
                    $message = $removedCount === 1
                        ? '1 participant retire de l\'evenement.'
                        : $removedCount . ' participants retires de l\'evenement.';
                } else {
                    $error = 'Impossible de retirer les participants selectionnes.';
                }
            }

            if (isAjaxRequest()) {
                if ($removedCount > 0) {
                    respondJson(true, $message, $removedCount);
                }
                respondJson(false, $error !== '' ? $error : 'Impossible de retirer les participants selectionnes.', 0);
            }
        // Handle single participant removal by event owner.
        } elseif ($_POST['action'] === 'remove_participant' && $is_club_owner) {
            $target_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

            if ($target_user_id <= 0) {
                $error = 'Participant invalide.';
            } elseif ($target_user_id === (int)$event_info['club_responsable_id']) {
                $error = 'Le responsable ne peut pas être retiré.';
            } elseif (!$event->isParticipant($event_id, $target_user_id)) {
                $error = 'Cet utilisateur n\'est pas inscrit à cet événement.';
            } elseif ($event->removeParticipant($event_id, $target_user_id)) {
                $message = 'Participant retiré de l\'événement.';

                $event->createUserNotification(
                    $target_user_id,
                    'event_removed',
                    'Retire de l\'evenement',
                    'Vous avez ete retire de l\'evenement "' . ($event_info['titre'] ?? '') . '".'
                );
            } else {
                $error = 'Impossible de retirer ce participant.';
            }
        // Handle event deletion by owner.
        } elseif ($_POST['action'] === 'delete_event' && $is_club_owner) {
            if ($event->deleteEvent($event_id)) {
                header("Location: ../club_php/club_detail.php?id=" . (int)$event_info['club_id']);
                exit();
            }
            $error = 'Impossible de supprimer cet événement.';
        }
    }

    // Refresh page data after action processing.
    if (!isset($_POST['action']) || $_POST['action'] !== 'delete_event') {
        $participants = $event->getParticipants($event_id);
        $is_participant = $event->isParticipant($event_id, getCurrentUserId());
        $is_club_member = $club->isMember((int)$event_info['club_id'], getCurrentUserId());
        $has_pending_club_request = $membership->hasRequest((int)$event_info['club_id'], getCurrentUserId());
        $club_join_cooldown_seconds = $membership->getRequestCooldownSeconds((int)$event_info['club_id'], getCurrentUserId());
    }
}

// Render the event detail template.
include('../../html pages/event/event_detail.html');
?>






