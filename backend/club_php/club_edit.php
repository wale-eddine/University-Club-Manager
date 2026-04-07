<?php
// Load session helpers, database connection, and club model.
require_once('../../classes/session.php');
require_once('../../config/Database.php');
require_once('../../classes/Club.php');

// Require authentication before editing clubs.
redirectIfNotLoggedIn();

// Read target club identifier.
$club_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($club_id === 0) {
    header("Location: clubs.php");
    exit();
}

// Initialize dependencies and load current club details.
$db = new Database();
$connection = $db->getConnection();
$club = new Club($connection);

$club_info = $club->getClubById($club_id);

if (!$club_info || !canManageClubById((int)$club_info['id'])) {
    header("Location: ../dashboard.php");
    exit();
}

// Initialize view state and return navigation values.
$error = '';
$success = '';
$clubDeleted = false;
$from = $_GET['from'] ?? ($_POST['from'] ?? '');
$isFromDashboard = ($from === 'dashboard');
$returnUrl = $isFromDashboard ? '../dashboard.php' : ('club_detail.php?id=' . $club_id);
$returnLabel = $isFromDashboard ? 'Retour au dashboard' : 'Retour au club';
$deleteConfirmationTemplate = "supprimer le club " . ($club_info['nom'] ?? '');
$redirectBack = (string)($_POST['redirect_back'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
$postDeleteRedirectUrl = 'clubs.php';

// Resolve safe redirect target after club deletion.
function resolvePostDeleteRedirect($from, $redirectBack, $currentHost, $club_id) {
    if ($from === 'dashboard') {
        return '../dashboard.php';
    }

    $fallback = 'clubs.php';
    if (trim((string)$redirectBack) === '') {
        return $fallback;
    }

    $parts = parse_url((string)$redirectBack);
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');

    if ($host !== '' && $host !== strtolower((string)$currentHost)) {
        return $fallback;
    }

    if (preg_match('~/(?:php|backend)/dashboard\.php$~i', $path)) {
        return '../dashboard.php';
    }
    if (preg_match('~/(?:php|backend)/requests\.php$~i', $path)) {
        return '../requests.php';
    }
    if (preg_match('~/(?:php|backend)/event_php/events\.php$~i', $path)) {
        return '../event_php/events.php';
    }
    if (preg_match('~/(?:php|backend)/club_php/clubs\.php$~i', $path)) {
        return 'clubs.php';
    }
    if (preg_match('~/(?:php|backend)/club_php/club_detail\.php$~i', $path)) {
        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        $refClubId = isset($query['id']) ? (int)$query['id'] : 0;
        if ($refClubId === (int)$club_id) {
            return 'clubs.php';
        }
    }

    return $fallback;
}

// Validate and save optional club image update.
function uploadClubImage($file) {
    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
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

// Remove old club image file from disk when replaced/deleted.
function deleteOldClubImage($oldImagePath) {
    if (!is_string($oldImagePath) || strpos($oldImagePath, 'uploads/clubs/') !== 0) {
        return;
    }

    $absolute = __DIR__ . '/../../public/' . $oldImagePath;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

// Handle update and delete actions from the edit form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'update_club');

    // Validate confirmation credentials and delete the club.
    if ($action === 'delete_club') {
        $email = trim((string)($_POST['delete_email'] ?? ''));
        $password = (string)($_POST['delete_password'] ?? '');
        $confirmationText = trim((string)($_POST['delete_confirmation_text'] ?? ''));
        $expectedText = "supprimer le club " . ($club_info['nom'] ?? '');

        $ownerStmt = $connection->prepare("SELECT id, email, mot_de_passe FROM USERS WHERE id = ?");
        $ownerStmt->execute([(int)getCurrentUserId()]);
        $ownerUser = $ownerStmt->fetch(PDO::FETCH_ASSOC);

        if (empty($email) || empty($password) || empty($confirmationText)) {
            $error = 'Veuillez remplir tous les champs de confirmation de suppression.';
        } elseif (!$ownerUser) {
            $error = 'Utilisateur invalide.';
        } elseif (strcasecmp($email, (string)($ownerUser['email'] ?? '')) !== 0) {
            $error = 'Email incorrect.';
        } elseif (!password_verify($password, (string)($ownerUser['mot_de_passe'] ?? ''))) {
            $error = 'Mot de passe incorrect.';
        } elseif (strcasecmp($confirmationText, $expectedText) !== 0) {
            $error = 'Texte de confirmation incorrect. Respectez exactement la phrase demandee.';
        } else {
            $imagePathToDelete = $club_info['image_path'] ?? null;
            if ($club->deleteClub($club_id)) {
                deleteOldClubImage($imagePathToDelete);
                $clubDeleted = true;
                $success = 'Club supprimé avec succès! Redirection en cours...';
                $postDeleteRedirectUrl = resolvePostDeleteRedirect($from, $redirectBack, $_SERVER['HTTP_HOST'] ?? '', $club_id);
            }
            if (!$clubDeleted) {
                $error = 'Impossible de supprimer le club.';
            }
        }
    } else {
        // Validate input and persist club updates.
        $nom = trim($_POST['nom'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($nom) || empty($description)) {
            $error = 'Veuillez remplir tous les champs.';
        } else {
            [$newImagePath, $uploadError] = uploadClubImage($_FILES['image'] ?? null);
            if (!empty($uploadError)) {
                $error = $uploadError;
            }

            if (!empty($error)) {
                include('../../html pages/club/club_edit.html');
                exit();
            }

            $previousImagePath = $club_info['image_path'] ?? null;
            $imageToSave = $newImagePath ?? $previousImagePath;

            if ($club->updateClubWithImage($club_id, $nom, $description, $imageToSave)) {
                $success = 'Club modifié avec succès!';
                $club_info['nom'] = $nom;
                $club_info['description'] = $description;
                $club_info['image_path'] = $imageToSave;
                $deleteConfirmationTemplate = "supprimer le club " . ($club_info['nom'] ?? '');

                if ($newImagePath !== null) {
                    deleteOldClubImage($previousImagePath);
                }
            } else {
                $error = 'Erreur lors de la modification du club.';
            }
        }
    }
}

// Render the club edit template.
include('../../html pages/club/club_edit.html');
?>




