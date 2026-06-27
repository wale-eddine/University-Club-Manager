<?php
// Load session helpers, database connection, and required models.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/Club.php');
require_once('../../classes/Event.php');

// Require authentication before creating events.
redirectIfNotLoggedIn();

// Read and validate target club identifier.
$club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;

if ($club_id === 0) {
    header("Location: ../club_php/clubs.php");
    exit();
}

// Initialize models and load club ownership context.
$db = new Database();
$connection = $db->getConnection();
$club = new Club($connection);
$event = new Event($connection);

$club_info = $club->getClubById($club_id);

if (!$club_info || !canManageClubById((int)$club_info['id'])) {
    header("Location: ../dashboard.php");
    exit();
}

$budget_overview = $event->getClubBudgetOverview($club_id);

// Initialize feedback messages for the form view.
$error = '';
$success = '';

// Validate and store optional event image upload.
function uploadEventImage($file) {
    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return [null, 'Erreur lors du televersement de l\'image.'];
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return [null, 'Fichier image invalide.'];
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return [null, 'Le fichier doit etre une image valide.'];
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    $mime = $imageInfo['mime'] ?? '';
    if (!isset($allowedMime[$mime])) {
        return [null, 'Formats autorises: JPG, PNG, WEBP.'];
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return [null, 'La taille de l\'image ne doit pas depasser 5MB.'];
    }

    $uploadDir = __DIR__ . '/../../public/uploads/events';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return [null, 'Impossible de creer le dossier d\'upload.'];
    }

    $filename = 'event_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMime[$mime];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        return [null, 'Impossible d\'enregistrer l\'image de l\'evenement.'];
    }

    return ['uploads/events/' . $filename, ''];
}

// Validate form input and create the event.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titre = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $date_debut = $_POST['date_debut'] ?? '';
    $date_fin = $_POST['date_fin'] ?? '';
    $lieu = trim($_POST['lieu'] ?? '');
    $max_participants_raw = trim($_POST['max_participants'] ?? '');
    $max_participants = null;
    $allow_non_members = isset($_POST['allow_non_members']) ? 1 : 0;
    $is_paid_event = isset($_POST['is_paid_event']) ? 1 : 0;
    $estimated_cost_raw = trim($_POST['estimated_cost'] ?? '');
    $estimated_cost = $estimated_cost_raw !== '' ? (float)$estimated_cost_raw : null;
    $notification_scope = $_POST['notification_scope'] ?? 'club_members';

    if (empty($titre) || empty($description) || empty($date_debut) || empty($date_fin) || empty($lieu)) {
        $error = 'Veuillez remplir tous les champs.';
    }

    if (empty($error) && $max_participants_raw !== '') {
        if (!ctype_digit($max_participants_raw) || (int)$max_participants_raw < 1) {
            $error = 'La limite de participants doit être un nombre entier positif.';
        } else {
            $max_participants = (int)$max_participants_raw;
        }
    }

    if (empty($error)) {
        // Budget validation check for non-admins
        if (!isAdmin() && $estimated_cost !== null) {
            $eventYear = (int)date('Y', strtotime($date_debut));
            $budget_overview = $event->getClubBudgetOverview($club_id, $eventYear);
            $remaining = (float)($budget_overview['remaining_amount'] ?? 0);
            if ($estimated_cost > $remaining) {
                $error = 'Le coût estimé de cet événement (' . number_format($estimated_cost, 2, ',', ' ') . ' €) dépasse le budget restant du club pour l\'année ' . $eventYear . ' (' . number_format($remaining, 2, ',', ' ') . ' €).';
            }
        }
    }

    if (empty($error)) {
        [$imagePath, $uploadError] = uploadEventImage($_FILES['image'] ?? null);
        if (!empty($uploadError)) {
            $error = $uploadError;
        }

        if (!empty($error)) {
            include('../../html pages/event/event_create.html');
            exit();
        }

        if ($event->createEvent($club_id, $titre, $description, $date_debut, $date_fin, $lieu, $imagePath, $max_participants, $allow_non_members, $is_paid_event, $estimated_cost, $notification_scope)) {
            $success = 'Événement créé avec succès!';
            header("Refresh: 2; url=../club_php/club_detail.php?id=$club_id");
        } else {
            $error = 'Erreur lors de la création de l\'événement.';
        }
    }
}

// Render the event creation template.
include('../../html pages/event/event_create.html');
?>




