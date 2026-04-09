<?php
// Load session helpers, database connection, and admin management models.
require_once('../classes/session.php');
require_once('../config/Database.php');
require_once('../classes/User.php');
require_once('../classes/Club.php');
require_once('../classes/ActionLog.php');

// Block access for non-admin users.
redirectIfNotLoggedIn();
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

// Initialize repositories and page state.
$db = new Database();
$connection = $db->getConnection();
$userModel = new User($connection);
$clubModel = new Club($connection);
$actionLogModel = new ActionLog($connection);

$success = $_SESSION['admin_flash_success'] ?? '';
$error = $_SESSION['admin_flash_error'] ?? '';
unset($_SESSION['admin_flash_success'], $_SESSION['admin_flash_error']);

$currentUserId = (int)getCurrentUserId();

// Normalize a flash redirect back to the admin page.
function adminRedirect($success = '', $error = '') {
    if ($success !== '') {
        $_SESSION['admin_flash_success'] = $success;
    }
    if ($error !== '') {
        $_SESSION['admin_flash_error'] = $error;
    }

    header('Location: admin.php');
    exit();
}

// Detect AJAX requests sent by admin panel forms.
function isAdminAjaxRequest() {
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $requestedWith === 'xmlhttprequest' || strpos($accept, 'application/json') !== false;
}

// Send JSON response for in-page admin interactions.
function adminJsonResponse($success, $message, $payload = []) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge([
        'success' => (bool)$success,
        'message' => (string)$message,
    ], is_array($payload) ? $payload : []));
    exit();
}

// Load a user in a safe way.
function getAdminUserId($value) {
    $id = (int)$value;
    return $id > 0 ? $id : 0;
}

// Check table existence before optional cleanup queries.
function adminTableExists($connection, $tableName) {
    try {
        $stmt = $connection->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([(string)$tableName]);
        return $stmt->fetch(PDO::FETCH_NUM) !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Remove pending membership requests for a user when account is deactivated.
function purgeUserPendingMembershipRequests($connection, $userId) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return 0;
    }

    try {
        $connection->beginTransaction();

        $stmt = $connection->prepare("DELETE FROM MEMBERSHIP_REQUESTS WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $deletedPending = (int)$stmt->rowCount();

        if (adminTableExists($connection, 'MEMBERSHIP_REQUEST_COOLDOWNS')) {
            $stmt = $connection->prepare("DELETE FROM MEMBERSHIP_REQUEST_COOLDOWNS WHERE user_id = ?");
            $stmt->execute([$userId]);
        }

        $connection->commit();
        return $deletedPending;
    } catch (Exception $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        return false;
    }
}

// Remove event participations for a user when account is deactivated.
function purgeUserEventParticipations($connection, $userId) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return 0;
    }

    try {
        $connection->beginTransaction();

        $stmt = $connection->prepare("DELETE FROM EVENT_PARTICIPANTS WHERE user_id = ?");
        $stmt->execute([$userId]);
        $deletedParticipations = (int)$stmt->rowCount();

        if (adminTableExists($connection, 'EVENT_REJOIN_COOLDOWNS')) {
            $stmt = $connection->prepare("DELETE FROM EVENT_REJOIN_COOLDOWNS WHERE user_id = ?");
            $stmt->execute([$userId]);
        }

        $connection->commit();
        return $deletedParticipations;
    } catch (Exception $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        return false;
    }
}

// Build synchronized datasets used by admin sections without full page reload.
function buildAdminSyncPayload($userModel, $clubModel, $currentUserId) {
    $allUsers = array_values(array_filter($userModel->getAllUsers(), function ($user) use ($currentUserId) {
        return (int)($user['id'] ?? 0) !== (int)$currentUserId;
    }));

    $responsableUsers = array_values(array_filter($allUsers, function ($user) {
        return (string)($user['role'] ?? '') === 'responsable'
            && (string)($user['account_status'] ?? 'active') === 'active';
    }));

    $responsableUsersPayload = array_map(function ($user) {
        return [
            'id' => (int)($user['id'] ?? 0),
            'prenom' => (string)($user['prenom'] ?? ''),
            'nom' => (string)($user['nom'] ?? ''),
            'role' => (string)($user['role'] ?? 'responsable'),
        ];
    }, $responsableUsers);

    $directStudents = array_values(array_filter($allUsers, function ($user) {
        return in_array((string)($user['role'] ?? ''), ['etudiant', 'responsable'], true)
            && (string)($user['account_status'] ?? 'active') === 'active';
    }));

    $directStudents = array_map(function ($user) use ($clubModel) {
        $userId = (int)($user['id'] ?? 0);
        $clubs = $userId > 0 ? $clubModel->getUserClubs($userId) : [];
        $clubIds = array_values(array_filter(array_map(function ($club) {
            return (int)($club['id'] ?? 0);
        }, $clubs), function ($id) {
            return $id > 0;
        }));
        $clubNames = array_values(array_filter(array_map(function ($club) {
            return trim((string)($club['nom'] ?? ''));
        }, $clubs), function ($name) {
            return $name !== '';
        }));

        $user['is_in_club'] = !empty($clubNames);
        $user['club_ids'] = $clubIds;
        $user['club_names'] = $clubNames;
        return $user;
    }, $directStudents);

    $directStudentsPayload = array_map(function ($user) {
        $role = (string)($user['role'] ?? 'etudiant');
        return [
            'id' => (int)($user['id'] ?? 0),
            'label' => trim((string)($user['prenom'] ?? '') . ' ' . (string)($user['nom'] ?? '')) . ' - ' . (string)($user['email'] ?? '') . ' (' . $role . ')',
            'inClub' => !empty($user['is_in_club']),
            'clubIds' => array_values($user['club_ids'] ?? []),
            'clubs' => array_values($user['club_names'] ?? []),
        ];
    }, $directStudents);

    $clubs = $clubModel->getClubs();
    $clubResponsablesMap = [];
    foreach ($clubs as $clubItem) {
        $clubId = (int)($clubItem['id'] ?? 0);
        if ($clubId > 0) {
            $responsables = $clubModel->getClubResponsables($clubId);
            // Admins are never shown in "Responsables actuels".
            $responsables = array_values(array_filter($responsables, function ($resp) {
                return ($resp['role'] ?? '') !== 'admin';
            }));
            $clubResponsablesMap[$clubId] = $responsables;
        }
    }

    return [
        'users' => $allUsers,
        'responsableUsers' => $responsableUsers,
        'responsableUsersPayload' => $responsableUsersPayload,
        'directStudents' => $directStudents,
        'directStudentsPayload' => $directStudentsPayload,
        'clubs' => $clubs,
        'clubResponsablesMap' => $clubResponsablesMap,
    ];
}

// Process admin actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'update_user') {
        $userId = getAdminUserId($_POST['user_id'] ?? 0);
        $targetUser = $userId > 0 ? $userModel->getUserById($userId) : null;

        if (!$targetUser) {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Utilisateur introuvable.', ['action' => $action]);
            }
            adminRedirect('', 'Utilisateur introuvable.');
        }

        $nom = trim((string)($_POST['nom'] ?? ''));
        $prenom = trim((string)($_POST['prenom'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $requestedRole = (string)($_POST['role'] ?? $targetUser['role']);
        $currentRole = (string)($targetUser['role'] ?? 'etudiant');
        $currentStatus = (string)($targetUser['account_status'] ?? 'active');
        $role = $currentRole === 'admin' ? 'admin' : (in_array($requestedRole, ['responsable', 'etudiant'], true) ? $requestedRole : 'etudiant');
        $status = (string)($_POST['account_status'] ?? ($targetUser['account_status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        $inactiveReason = trim((string)($_POST['inactive_reason'] ?? ''));

        if ($status === 'inactive' && $inactiveReason === '') {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Veuillez indiquer une raison pour désactiver le compte.', ['action' => $action, 'user_id' => $userId]);
            }
            adminRedirect('', 'Veuillez indiquer une raison pour désactiver le compte.');
        }

        if ($nom === '' || $prenom === '' || $email === '') {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Veuillez remplir tous les champs obligatoires.', ['action' => $action, 'user_id' => $userId]);
            }
            adminRedirect('', 'Veuillez remplir tous les champs obligatoires.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Adresse email invalide.', ['action' => $action, 'user_id' => $userId]);
            }
            adminRedirect('', 'Adresse email invalide.');
        }

        if ($userModel->emailExistsForOtherUser($email, $userId)) {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Cet email est déjà utilisé par un autre compte.', ['action' => $action, 'user_id' => $userId]);
            }
            adminRedirect('', 'Cet email est déjà utilisé par un autre compte.');
        }

        if ($userId === $currentUserId && $role !== 'admin') {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Vous ne pouvez pas retirer votre propre rôle admin.', ['action' => $action, 'user_id' => $userId]);
            }
            adminRedirect('', 'Vous ne pouvez pas retirer votre propre rôle admin.');
        }

        if ($userId === $currentUserId && $status !== 'active') {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Vous ne pouvez pas désactiver votre propre compte admin.', ['action' => $action, 'user_id' => $userId]);
            }
            adminRedirect('', 'Vous ne pouvez pas désactiver votre propre compte admin.');
        }

        $isRoleDemotion = ($currentRole === 'responsable' && $role === 'etudiant');
        $isBecomingInactive = ($currentStatus === 'active' && $status === 'inactive');
        $isReactivating = ($currentStatus === 'inactive' && $status === 'active');

        if ($isRoleDemotion) {
            if (!$clubModel->removeAllResponsibilitiesForUser($userId, $currentUserId, false)) {
                if (isAdminAjaxRequest()) {
                    adminJsonResponse(false, 'Impossible de retirer les responsabilités de club avant la modification du rôle.', ['action' => $action, 'user_id' => $userId]);
                }
                adminRedirect('', 'Impossible de retirer les responsabilités de club avant la modification du rôle.');
            }
        } elseif ($isBecomingInactive && $currentRole === 'responsable') {
            if (!$clubModel->removeAllResponsibilitiesForUser($userId, $currentUserId, true)) {
                if (isAdminAjaxRequest()) {
                    adminJsonResponse(false, 'Impossible de suspendre les responsabilités de club pour ce compte inactif.', ['action' => $action, 'user_id' => $userId]);
                }
                adminRedirect('', 'Impossible de suspendre les responsabilités de club pour ce compte inactif.');
            }
        }

        if ($userModel->updateUserByAdmin($userId, $nom, $prenom, $email, $role, $password, $status, $inactiveReason)) {
            $successMessage = 'Utilisateur mis à jour avec succès.';
            if ($isRoleDemotion) {
                $successMessage = 'Utilisateur mis à jour et retiré des responsabilités de club.';
            } elseif ($isBecomingInactive && $currentRole === 'responsable') {
                $successMessage = 'Compte désactivé et responsabilités de club suspendues.';
            }

            if ($isBecomingInactive) {
                $deletedPending = purgeUserPendingMembershipRequests($connection, $userId);
                if ($deletedPending === false) {
                    if (isAdminAjaxRequest()) {
                        adminJsonResponse(false, 'Compte désactivé, mais suppression des demandes en attente impossible.', ['action' => $action, 'user_id' => $userId]);
                    }
                    adminRedirect('', 'Compte désactivé, mais suppression des demandes en attente impossible.');
                }

                if ($deletedPending > 0) {
                    $successMessage .= ' ' . $deletedPending . ' demande(s) en attente supprimée(s).';
                }

                $deletedParticipations = purgeUserEventParticipations($connection, $userId);
                if ($deletedParticipations === false) {
                    if (isAdminAjaxRequest()) {
                        adminJsonResponse(false, 'Compte désactivé, mais suppression des participations aux événements impossible.', ['action' => $action, 'user_id' => $userId]);
                    }
                    adminRedirect('', 'Compte désactivé, mais suppression des participations aux événements impossible.');
                }

                if ($deletedParticipations > 0) {
                    $successMessage .= ' ' . $deletedParticipations . ' participation(s) à des événements supprimée(s).';
                }
            }

            if ($isReactivating && $role === 'responsable') {
                if (!$clubModel->restoreArchivedResponsibilitiesForUser($userId)) {
                    if (isAdminAjaxRequest()) {
                        adminJsonResponse(false, 'Compte réactivé, mais restauration des responsabilités impossible.', ['action' => $action, 'user_id' => $userId]);
                    }
                    adminRedirect('', 'Compte réactivé, mais restauration des responsabilités impossible.');
                }
                $successMessage = 'Compte réactivé et responsabilités restaurées.';
            }
            if (isAdminAjaxRequest()) {
                $sync = buildAdminSyncPayload($userModel, $clubModel, $currentUserId);
                adminJsonResponse(true, $successMessage, [
                    'action' => $action,
                    'user_id' => $userId,
                    'updated_user' => $userModel->getUserById($userId),
                    'sync' => [
                        'responsableUsers' => $sync['responsableUsersPayload'],
                        'directStudents' => $sync['directStudentsPayload'],
                        'clubResponsablesMap' => $sync['clubResponsablesMap'],
                    ],
                ]);
            }
            adminRedirect($successMessage, '');
        }

        if (isAdminAjaxRequest()) {
            adminJsonResponse(false, 'Impossible de mettre à jour cet utilisateur.', ['action' => $action, 'user_id' => $userId]);
        }
        adminRedirect('', 'Impossible de mettre à jour cet utilisateur.');
    }

    if ($action === 'assign_responsible') {
        $clubId = getAdminUserId($_POST['club_id'] ?? 0);
        $responsibleId = getAdminUserId($_POST['responsable_id'] ?? 0);

        $clubInfo = $clubId > 0 ? $clubModel->getClubById($clubId) : null;
        $responsibleUser = $responsibleId > 0 ? $userModel->getUserById($responsibleId) : null;

        if (!$clubInfo || !$responsibleUser) {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Club ou utilisateur introuvable.', ['action' => $action]);
            }
            adminRedirect('', 'Club ou utilisateur introuvable.');
        }

        if (($responsibleUser['role'] ?? 'etudiant') !== 'responsable') {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Seuls les utilisateurs avec le role responsable peuvent etre assignes.', ['action' => $action, 'club_id' => $clubId]);
            }
            adminRedirect('', 'Seuls les utilisateurs avec le role responsable peuvent etre assignes.');
        }

        if ((string)($responsibleUser['account_status'] ?? 'active') !== 'active') {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Un compte inactif ne peut pas etre assigne responsable.', ['action' => $action, 'club_id' => $clubId]);
            }
            adminRedirect('', 'Un compte inactif ne peut pas etre assigne responsable.');
        }

        if ($clubModel->addClubResponsable($clubId, $responsibleId)) {
            if (isAdminAjaxRequest()) {
                $clubResponsables = array_values(array_filter($clubModel->getClubResponsables($clubId), function ($resp) {
                    return ($resp['role'] ?? '') !== 'admin';
                }));
                adminJsonResponse(true, 'Responsable ajouté au club avec succès.', [
                    'action' => $action,
                    'club_id' => $clubId,
                    'club_responsables' => $clubResponsables,
                ]);
            }
            adminRedirect('Responsable ajouté au club avec succès.', '');
        }

        if (isAdminAjaxRequest()) {
            adminJsonResponse(false, 'Impossible d\'ajouter ce responsable au club.', ['action' => $action, 'club_id' => $clubId]);
        }
        adminRedirect('', 'Impossible d\'ajouter ce responsable au club.');
    }

    if ($action === 'remove_responsible') {
        $clubId = getAdminUserId($_POST['club_id'] ?? 0);
        $responsibleId = getAdminUserId($_POST['responsable_id'] ?? 0);

        $clubInfo = $clubId > 0 ? $clubModel->getClubById($clubId) : null;
        $responsibleUser = $responsibleId > 0 ? $userModel->getUserById($responsibleId) : null;

        if (!$clubInfo || !$responsibleUser) {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Club ou utilisateur introuvable.', ['action' => $action]);
            }
            adminRedirect('', 'Club ou utilisateur introuvable.');
        }

        if ($clubModel->removeClubResponsable($clubId, $responsibleId, $currentUserId)) {
            if (isAdminAjaxRequest()) {
                $clubResponsables = array_values(array_filter($clubModel->getClubResponsables($clubId), function ($resp) {
                    return ($resp['role'] ?? '') !== 'admin';
                }));
                adminJsonResponse(true, 'Responsable retiré du club avec succès.', [
                    'action' => $action,
                    'club_id' => $clubId,
                    'club_responsables' => $clubResponsables,
                ]);
            }
            adminRedirect('Responsable retiré du club avec succès.');
        }

        if (isAdminAjaxRequest()) {
            adminJsonResponse(false, 'Impossible de retirer ce responsable du club.', ['action' => $action, 'club_id' => $clubId]);
        }
        adminRedirect('', 'Impossible de retirer ce responsable du club.');
    }

    if ($action === 'add_member_direct') {
        $clubId = getAdminUserId($_POST['club_id'] ?? 0);
        $userId = getAdminUserId($_POST['user_id'] ?? 0);

        if ($clubId <= 0 || $userId <= 0) {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Club ou utilisateur invalide.', ['action' => $action]);
            }
            adminRedirect('', 'Club ou utilisateur invalide.');
        }

        $targetUser = $userModel->getUserById($userId);
        if (!$targetUser || !in_array((string)($targetUser['role'] ?? ''), ['etudiant', 'responsable'], true)) {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'L\'ajout direct est autorise uniquement pour les etudiants et responsables.', ['action' => $action]);
            }
            adminRedirect('', 'L\'ajout direct est autorise uniquement pour les etudiants et responsables.');
        }

        if ((string)($targetUser['account_status'] ?? 'active') !== 'active') {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Un compte inactif ne peut pas etre ajoute directement a un club.', ['action' => $action]);
            }
            adminRedirect('', 'Un compte inactif ne peut pas etre ajoute directement a un club.');
        }

        if ($clubModel->isMember($clubId, $userId)) {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(false, 'Cet etudiant est deja membre de ce club.', ['action' => $action]);
            }
            adminRedirect('', 'Cet etudiant est deja membre de ce club.');
        }

        if ($clubModel->addMemberDirect($clubId, $userId)) {
            if (isAdminAjaxRequest()) {
                adminJsonResponse(true, 'Utilisateur ajouté au club sans approbation.', [
                    'action' => $action,
                    'club_id' => $clubId,
                    'user_id' => $userId,
                ]);
            }
            adminRedirect('Utilisateur ajouté au club sans approbation.');
        }

        if (isAdminAjaxRequest()) {
            adminJsonResponse(false, 'Impossible d’ajouter cet utilisateur au club.', ['action' => $action]);
        }
        adminRedirect('', 'Impossible d’ajouter cet utilisateur au club.');
    }

    if (isAdminAjaxRequest()) {
        adminJsonResponse(false, 'Action invalide.', ['action' => $action]);
    }
    adminRedirect('', 'Action invalide.');
}

$syncData = buildAdminSyncPayload($userModel, $clubModel, $currentUserId);
$users = $syncData['users'];
$responsableUsers = $syncData['responsableUsers'];
$directStudents = $syncData['directStudents'];
$clubs = $syncData['clubs'];
$clubResponsablesMap = $syncData['clubResponsablesMap'];
$responsableUsersJson = json_encode($syncData['responsableUsersPayload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$directStudentsJson = json_encode($syncData['directStudentsPayload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$actionLogs = $actionLogModel->getRecentLogs(200);

// Render admin dashboard view.
include('../html pages/admin.html');
?>
