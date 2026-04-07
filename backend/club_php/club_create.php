<?php
// Load session helpers, database connection, and club model.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/Club.php');

// Require authentication before allowing club creation.
redirectIfNotLoggedIn();

// Restrict club creation to manager roles.
if (!isManager()) {
    header("Location: dashboard.php");
    exit();
}

// Initialize feedback messages.
$error = '';
$success = '';

// Validate and store uploaded club image.
function uploadClubImage($file, $isRequired = false) {
    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($isRequired) {
            return [null, 'Une image du club est requise.'];
        }
        return [null, ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return [null, 'Erreur lors du téléversement de l\'image.'];
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return [null, 'Fichier image invalide.'];
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return [null, 'Le fichier doit être une image valide.'];
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    $mime = $imageInfo['mime'] ?? '';
    if (!isset($allowedMime[$mime])) {
        return [null, 'Formats autorisés: JPG, PNG, WEBP.'];
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return [null, 'La taille de l\'image ne doit pas dépasser 5MB.'];
    }

    $uploadDir = __DIR__ . '/../../public/uploads/clubs';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return [null, 'Impossible de créer le dossier d\'upload.'];
    }

    $filename = 'club_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMime[$mime];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        return [null, 'Impossible d\'enregistrer l\'image du club.'];
    }

    return ['uploads/clubs/' . $filename, ''];
}

// Validate form input and create a new club.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = trim($_POST['nom'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($nom) || empty($description)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        [$imagePath, $uploadError] = uploadClubImage($_FILES['image'] ?? null, true);
        if (!empty($uploadError)) {
            $error = $uploadError;
        }

        if (!empty($error)) {
            include('../../html pages/club/club_create.html');
            exit();
        }

        $db = new Database();
        $connection = $db->getConnection();
        $club = new Club($connection);

        $newClubId = $club->createClub($nom, $description, getCurrentUserId(), $imagePath);
        if ($newClubId !== false) {
            $success = 'Club créé avec succès!';
            header("Refresh: 2; url=club_detail.php?id=" . (int)$newClubId);
        } else {
            $error = 'Erreur lors de la création du club.';
        }
    }
}

// Render the club creation template.
include('../../html pages/club/club_create.html');
?>




