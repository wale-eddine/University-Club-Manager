<?php
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/Event.php');
require_once('../../classes/Club.php');

redirectIfNotLoggedIn();

$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($event_id <= 0) {
    header('Location: ../event_php/events.php');
    exit();
}

$db = new Database();
$connection = $db->getConnection();
$event = new Event($connection);
$club = new Club($connection);

$event_info = $event->getEventById($event_id);
if (!$event_info) {
    header('Location: ../event_php/events.php');
    exit();
}

$is_manager = isAdmin() || canManageClubById((int)$event_info['club_id']);
$is_participant = $event->isParticipant($event_id, getCurrentUserId());
if (!$is_participant && !$is_manager) {
    header('Location: ../event_php/event_detail.php?id=' . $event_id);
    exit();
}

$message = '';
$error = '';
$submittedReview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = (int)($_POST['rating'] ?? 0);
    $feedback = trim((string)($_POST['feedback'] ?? ''));

    if ($event->saveEventReview($event_id, getCurrentUserId(), $rating, $feedback)) {
        $message = 'Votre avis a été enregistré.';
        $submittedReview = ['rating' => $rating, 'feedback' => $feedback];
    } else {
        $error = 'Impossible d’enregistrer votre avis.';
    }
}

$event_reviews = $event->getEventReviews($event_id);
include('../../html pages/event/event_feedback.html');
?>