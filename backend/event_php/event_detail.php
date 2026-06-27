<?php
// Start output buffering to support JSON responses after includes.
if (ob_get_level() === 0) {
    ob_start();
}

// Load session helpers, database connection, and required models.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/Event.php');
require_once('../../classes/Club.php');
require_once('../../classes/MembershipRequest.php');

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

$event_status = (string)($event_info['approval_status'] ?? 'pending');
$can_view_event_details = isAdmin() || canManageClubById((int)$event_info['club_id']);
if (in_array($event_status, ['pending', 'rejected'], true) && !$can_view_event_details) {
    header("Location: ../event_php/events.php");
    exit();
}
if ($event_status === 'closed' && !$can_view_event_details) {
    header("Location: ../event_php/events.php");
    exit();
}
$event_join_allowed = $event_status === 'approved';

$participantSortBy = strtolower((string)($_GET['participant_sort_by'] ?? 'date'));
if (!in_array($participantSortBy, ['date', 'role', 'payment_date'], true)) {
    $participantSortBy = 'date';
}

$defaultParticipantOrder = $participantSortBy === 'role' ? 'asc' : 'desc';
$participantSortOrder = strtolower((string)($_GET['participant_order'] ?? $defaultParticipantOrder));
if (!in_array($participantSortOrder, ['asc', 'desc'], true)) {
    $participantSortOrder = $defaultParticipantOrder;
}

$participantDateSortParams = $_GET;
$participantDateSortParams['participant_sort_by'] = 'date';
$participantDateSortParams['participant_order'] = ($participantSortBy === 'date' && $participantSortOrder === 'asc') ? 'desc' : 'asc';
$participantSortUrl = '?' . http_build_query($participantDateSortParams);
$participantSortIndicator = $participantSortBy === 'date' ? ($participantSortOrder === 'asc' ? '&uarr;' : '&darr;') : '';

$participantRoleSortParams = $_GET;
$participantRoleSortParams['participant_sort_by'] = 'role';
$participantRoleSortParams['participant_order'] = ($participantSortBy === 'role' && $participantSortOrder === 'asc') ? 'desc' : 'asc';
$participantRoleSortUrl = '?' . http_build_query($participantRoleSortParams);
$participantRoleSortIndicator = $participantSortBy === 'role' ? ($participantSortOrder === 'asc' ? '&uarr;' : '&darr;') : '';

$participantPaymentDateSortParams = $_GET;
$participantPaymentDateSortParams['participant_sort_by'] = 'payment_date';
$participantPaymentDateSortParams['participant_order'] = ($participantSortBy === 'payment_date' && $participantSortOrder === 'asc') ? 'desc' : 'asc';
$participantPaymentDateSortUrl = '?' . http_build_query($participantPaymentDateSortParams);
$participantPaymentDateSortIndicator = $participantSortBy === 'payment_date' ? ($participantSortOrder === 'asc' ? '&uarr;' : '&darr;') : '';

$participants = $event->getParticipants($event_id, $participantSortBy, $participantSortOrder);
$is_participant = isLoggedIn() ? $event->isParticipant($event_id, getCurrentUserId()) : false;
$is_club_owner = isLoggedIn() && canManageClubById((int)$event_info['club_id']);
$can_manage_event = isLoggedIn() && canManageClubById((int)$event_info['club_id']);
$is_club_member = isLoggedIn() ? $club->isMember((int)$event_info['club_id'], getCurrentUserId()) : false;
$has_pending_club_request = isLoggedIn() ? $membership->hasRequest((int)$event_info['club_id'], getCurrentUserId()) : false;
$club_join_cooldown_seconds = isLoggedIn() ? $membership->getRequestCooldownSeconds((int)$event_info['club_id'], getCurrentUserId()) : 0;
$allows_non_members = (int)($event_info['allow_non_members'] ?? 0) === 1;
$is_paid_event = (int)($event_info['is_paid_event'] ?? 0) === 1;

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
function respondJson($success, $message, $removedCount = 0, $payload = []) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    $base = [
        'success' => (bool)$success,
        'message' => (string)$message,
        'removed_count' => (int)$removedCount
    ];
    if (!is_array($payload)) {
        $payload = [];
    }
    echo json_encode(array_merge($base, $payload));
    exit();
}

// Handle event participation and related club actions.
if (isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $rejoinCooldownSeconds = $event->getRejoinCooldownSeconds($event_id, getCurrentUserId());

        // Handle event join action with eligibility checks.
        if ($_POST['action'] === 'join' && !$is_participant) {
            if (!$allows_non_members && !$is_club_member && !$can_manage_event) {
                $error = 'Cet événement est réservé aux membres du club.';
            } elseif ($rejoinCooldownSeconds > 0 && !$can_manage_event) {
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
        } elseif ($_POST['action'] === 'edit_event' && $can_manage_event) {
            header("Location: ../event_php/event_edit.php?id=" . (int)$event_info['id'] . "&from=event");
            exit();
        // Handle join request to the parent club.
        } elseif ($_POST['action'] === 'join_club' && !$is_club_member && !$can_manage_event && !$has_pending_club_request) {
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
        } elseif ($_POST['action'] === 'cancel_join_club' && !$is_club_member && !$can_manage_event && $has_pending_club_request) {
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
                if (!$can_manage_event) {
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
        } elseif ($_POST['action'] === 'remove_participants' && $can_manage_event) {
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
                if ($targetId > 0 && !$club->isResponsible((int)$event_info['club_id'], $targetId)) {
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
        } elseif ($_POST['action'] === 'set_payment_status' && $can_manage_event) {
            $target_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $paidValue = isset($_POST['paid']) ? (int)$_POST['paid'] : -1;
            $paymentPayload = [];

            if (!$is_paid_event) {
                $error = 'Le suivi de paiement est desactive pour cet evenement.';
            } elseif ($target_user_id <= 0 || !in_array($paidValue, [0, 1], true)) {
                $error = 'Mise a jour du paiement invalide.';
            } elseif ($club->isResponsible((int)$event_info['club_id'], $target_user_id)) {
                $error = 'Le statut de paiement ne peut pas etre modifie pour un responsable.';
            } elseif (!$event->isParticipant($event_id, $target_user_id)) {
                $error = 'Cet utilisateur n est pas inscrit a cet evenement.';
            } elseif ($event->setParticipantPaymentStatus($event_id, $target_user_id, $paidValue)) {
                $message = $paidValue === 1
                    ? 'Participant marque comme paye.'
                    : 'Paiement annule pour ce participant.';

                $updatedParticipants = $event->getParticipants($event_id, 'date', 'desc');
                foreach ($updatedParticipants as $participant) {
                    if ((int)($participant['id'] ?? 0) === $target_user_id) {
                        $paymentDateRaw = (string)($participant['payment_date'] ?? '');
                        $paymentPayload = [
                            'user_id' => $target_user_id,
                            'paid' => (int)($participant['payment_status'] ?? 0) === 1,
                            'payment_date' => $paymentDateRaw,
                            'payment_date_display' => $paymentDateRaw !== '' ? date('d/m/Y H:i', strtotime($paymentDateRaw)) : '-',
                        ];
                        break;
                    }
                }
            } else {
                $error = 'Impossible de mettre a jour le statut de paiement.';
            }

            if (isAjaxRequest()) {
                if ($error !== '') {
                    respondJson(false, $error, 0);
                }
                respondJson(true, $message, 0, $paymentPayload);
            }
        // Handle single participant removal by event owner.
        } elseif ($_POST['action'] === 'remove_participant' && $can_manage_event) {
            $target_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

            if ($target_user_id <= 0) {
                $error = 'Participant invalide.';
            } elseif ($club->isResponsible((int)$event_info['club_id'], $target_user_id)) {
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
        } elseif ($_POST['action'] === 'delete_event' && $can_manage_event) {
            if ($event->deleteEvent($event_id)) {
                header("Location: ../club_php/club_detail.php?id=" . (int)$event_info['club_id']);
                exit();
            }
            $error = 'Impossible de supprimer cet événement.';
        }
    }

    // Refresh page data after action processing.
    if (!isset($_POST['action']) || $_POST['action'] !== 'delete_event') {
        $participants = $event->getParticipants($event_id, $participantSortBy, $participantSortOrder);
        $is_participant = $event->isParticipant($event_id, getCurrentUserId());
        $is_club_member = $club->isMember((int)$event_info['club_id'], getCurrentUserId());
        $has_pending_club_request = $membership->hasRequest((int)$event_info['club_id'], getCurrentUserId());
        $club_join_cooldown_seconds = $membership->getRequestCooldownSeconds((int)$event_info['club_id'], getCurrentUserId());
    }
}

// Render the event detail template.
include('../../html pages/event/event_detail.html');
?>






