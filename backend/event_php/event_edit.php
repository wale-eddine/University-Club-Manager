<?php
// Load session helpers, database connection, and event model.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/Event.php');
require_once('../../classes/ActionLog.php');

// Require authentication before editing events.
redirectIfNotLoggedIn();

// Read and validate target event identifier.
$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($event_id === 0) {
    header('Location: ../event_php/events.php');
    exit();
}

// Initialize dependencies and load current event data.
$db = new Database();
$connection = $db->getConnection();
$event = new Event($connection);
$actionLog = new ActionLog($connection);

$event_info = $event->getEventById($event_id);

if (!$event_info || !canManageClubById((int)$event_info['club_id'])) {
    header('Location: ../dashboard.php');
    exit();
}

// Initialize feedback state and return navigation source.
$error = '';
$success = '';
$from = $_GET['from'] ?? ($_POST['from'] ?? '');

// Build return link based on current navigation context.
if ($from === 'dashboard') {
    $returnUrl = '../dashboard.php';
    $returnLabel = 'Retour au dashboard';
} elseif ($from === 'club') {
    $returnUrl = '../club_php/club_detail.php?id=' . (int)$event_info['club_id'];
    $returnLabel = 'Retour au club';
} else {
    $returnUrl = '../event_php/event_detail.php?id=' . $event_id;
    $returnLabel = "Retour a l'evenement";
}

if ($from === 'dashboard') {
    $deleteReturnUrl = '../dashboard.php';
} elseif ($from === 'club') {
    $deleteReturnUrl = '../club_php/club_detail.php?id=' . (int)$event_info['club_id'];
} else {
    $deleteReturnUrl = '../event_php/events.php';
}

// Validate and store optional event image replacement.
function uploadEventImageForEdit($file) {
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

// Remove old event image file when replaced or deleted.
function deleteOldEventImage($oldImagePath) {
    if (!is_string($oldImagePath) || strpos($oldImagePath, 'uploads/events/') !== 0) {
        return;
    }

    $absolute = __DIR__ . '/../../public/' . $oldImagePath;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

// Handle delete and update actions from the edit form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_event';

    // Delete event and redirect to contextual return page.
    if ($action === 'delete_event') {
        $previousImagePath = $event_info['image_path'] ?? null;
        if ($event->deleteEvent($event_id)) {
            if (isResponsable()) {
                $actionLog->logAction(
                    (int)getCurrentUserId(),
                    'responsable',
                    'delete_event',
                    'event',
                    (int)$event_id,
                    (string)($event_info['titre'] ?? ('Evenement #' . (int)$event_id)),
                    (int)($event_info['club_id'] ?? 0),
                    (int)$event_id,
                    'Suppression de l\'evenement depuis la page de modification.'
                );
            }
            deleteOldEventImage($previousImagePath);
            header('Location: ' . $deleteReturnUrl);
            exit();
        }

        $error = 'Erreur lors de la suppression de l\'evenement.';
    }

    // Stop early if the request is not an update action.
    if ($action !== 'update_event') {
        include('../../html pages/event/event_edit.html');
        exit();
    }

    // Validate form fields for event update.
    $titre = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $date_debut = $_POST['date_debut'] ?? '';
    $date_fin = $_POST['date_fin'] ?? '';
    $lieu = trim($_POST['lieu'] ?? '');
    $max_participants_raw = trim($_POST['max_participants'] ?? '');
    $max_participants = null;
    $allow_non_members = isset($_POST['allow_non_members']) ? 1 : 0;

    if (empty($titre) || empty($description) || empty($date_debut) || empty($date_fin) || empty($lieu)) {
        $error = 'Veuillez remplir tous les champs.';
    }

    if (empty($error) && $max_participants_raw !== '') {
        if (!ctype_digit($max_participants_raw) || (int)$max_participants_raw < 1) {
            $error = 'La limite de participants doit etre un nombre entier positif.';
        } else {
            $max_participants = (int)$max_participants_raw;
            $currentParticipantCount = $event->getParticipantCount($event_id);
            if ($max_participants < $currentParticipantCount) {
                $error = 'Impossible de definir une limite inferieure au nombre actuel de participants (' . $currentParticipantCount . ').';
            }
        }
    }

    // Upload image if provided and persist event updates.
    if (empty($error)) {
        [$newImagePath, $uploadError] = uploadEventImageForEdit($_FILES['image'] ?? null);
        if (!empty($uploadError)) {
            $error = $uploadError;
        }

        if (!empty($error)) {
            include('../../html pages/event/event_edit.html');
            exit();
        }

        $previousImagePath = $event_info['image_path'] ?? null;
        $imageToSave = $newImagePath ?? $previousImagePath;

        if ($event->updateEvent($event_id, $titre, $description, $date_debut, $date_fin, $lieu, $imageToSave, $max_participants, $allow_non_members)) {
            if (isResponsable()) {
                $actionLog->logAction(
                    (int)getCurrentUserId(),
                    'responsable',
                    'edit_event',
                    'event',
                    (int)$event_id,
                    (string)($titre !== '' ? $titre : ('Evenement #' . (int)$event_id)),
                    (int)($event_info['club_id'] ?? 0),
                    (int)$event_id,
                    'Modification de l\'evenement (titre/details/date/lieu/image/limite).'
                );
            }
            $success = 'Evenement modifie avec succes!';
            $event_info['titre'] = $titre;
            $event_info['description'] = $description;
            $event_info['date_debut'] = $date_debut;
            $event_info['date_fin'] = $date_fin;
            $event_info['lieu'] = $lieu;
            $event_info['max_participants'] = $max_participants;
            $event_info['allow_non_members'] = $allow_non_members;
            $event_info['image_path'] = $imageToSave;

            if ($newImagePath !== null) {
                deleteOldEventImage($previousImagePath);
            }
        } else {
            $error = 'Erreur lors de la modification de l\'evenement.';
        }
    }
}

// Render the event edit template.
include('../../html pages/event/event_edit.html');
?>


