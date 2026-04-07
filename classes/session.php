<?php
// Starts or resumes the PHP session for authentication state.
session_start();

// Checks whether a user is currently logged in.
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Checks database status for current session user and refreshes session payload.
function isCurrentSessionUserActive() {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    if (!isset($_SESSION['user_id'])) {
        $cache = false;
        return $cache;
    }

    $userId = (int)$_SESSION['user_id'];
    if ($userId <= 0) {
        $cache = false;
        return $cache;
    }

    try {
        require_once(__DIR__ . '/../config/Database.php');
        $db = new Database();
        $connection = $db->getConnection();
        $stmt = $connection->prepare("SELECT * FROM USERS WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (string)($user['account_status'] ?? 'active') !== 'active') {
            $cache = false;
            return $cache;
        }

        // Keep session user in sync with latest profile/role/status values.
        $_SESSION['user'] = $user;
        $cache = true;
        return $cache;
    } catch (Exception $e) {
        // Fail closed for safety.
        $cache = false;
        return $cache;
    }
}

// Returns the current user data from session, if available.
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

// Returns the current authenticated user ID from session.
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Checks whether the current logged-in user has admin role.
function isAdmin() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
}

// Checks whether the current logged-in user has responsable role.
function isResponsable() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'responsable';
}

// Returns true for users allowed to manage clubs/events.
function isManager() {
    return isAdmin() || isResponsable();
}

// Checks if current user can manage resources owned by a specific responsable.
function canManageClub($responsableId) {
    if (!isLoggedIn()) {
        return false;
    }

    if (isAdmin()) {
        return true;
    }

    return (int)getCurrentUserId() === (int)$responsableId;
}

// Checks if current user can manage a club by club id (supports multi-responsables).
function canManageClubById($clubId) {
    if (!isLoggedIn()) {
        return false;
    }

    if (isAdmin()) {
        return true;
    }

    $clubId = (int)$clubId;
    $userId = (int)getCurrentUserId();

    if ($clubId <= 0 || $userId <= 0) {
        return false;
    }

    try {
        require_once(__DIR__ . '/../config/Database.php');
        $db = new Database();
        $connection = $db->getConnection();
        $stmt = $connection->prepare("SELECT id FROM CLUB_RESPONSABLES WHERE club_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$clubId, $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Determines relative PHP path prefix based on current script location.
function getPhpPathPrefix() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($script, '/backend/club_php/') !== false || strpos($script, '/backend/event_php/') !== false || strpos($script, '/backend/profile_php/') !== false) {
        return '../';
    }
    return '';
}

// Builds a PHP route from project php root with the proper prefix.
function phpRoute($pathFromPhpRoot) {
    return getPhpPathPrefix() . ltrim($pathFromPhpRoot, '/');
}

// Returns the application base URL, including the project folder.
function getApplicationBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (preg_match('#^(.*?)/backend/#', $script, $matches)) {
        return $scheme . '://' . $host . $matches[1];
    }

    $fallback = rtrim(dirname(dirname(dirname($script))), '/');
    if ($fallback === '' || $fallback === '.') {
        $fallback = '';
    }

    return $scheme . '://' . $host . $fallback;
}

// Builds an absolute application URL from a project-relative path.
function buildApplicationUrl($path) {
    return rtrim(getApplicationBaseUrl(), '/') . '/' . ltrim($path, '/');
}

// Destroys the session and redirects the user to the home page.
function logout() {
    session_destroy();
    header("Location: " . phpRoute("index.php"));
    exit();
}

// Redirects guests to login page when authentication is required.
function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header("Location: " . phpRoute("profile_php/login.php"));
        exit();
    }

    if (!isCurrentSessionUserActive()) {
        session_unset();
        session_destroy();
        header("Location: " . phpRoute("profile_php/login.php?oauth_error=" . urlencode("Votre compte a ete desactive par un administrateur.")));
        exit();
    }
}

// Redirects authenticated users away from guest-only pages.
function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        if (!isCurrentSessionUserActive()) {
            session_unset();
            session_destroy();
            return;
        }
        header("Location: " . phpRoute("dashboard.php"));
        exit();
    }
}

// Retrieves cached profile request notification data for the current user.
function getProfileRequestNotification() {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [
        'has_pending' => false,
        'pending_count' => 0,
        'requests_link' => phpRoute('requests.php'),
        'can_view_requests' => false,
    ];

    if (!isLoggedIn()) {
        return $cache;
    }

    try {
        require_once(__DIR__ . '/../config/Database.php');
        $db = new Database();
        $connection = $db->getConnection();
        $userId = (int)getCurrentUserId();

        if (isAdmin()) {
            $stmt = $connection->prepare("SELECT COUNT(*) AS total_pending
                                                                                    FROM MEMBERSHIP_REQUESTS mr
                                                                                    JOIN USERS u ON u.id = mr.user_id
                                                                                    WHERE mr.status = 'pending'
                                                                                        AND COALESCE(u.account_status, 'active') = 'active'");
            $stmt->execute();
            $pendingCount = (int)$stmt->fetchColumn();

            $cache['has_pending'] = $pendingCount > 0;
            $cache['pending_count'] = $pendingCount;
            $cache['requests_link'] = phpRoute('requests.php');
            $cache['can_view_requests'] = true;
            return $cache;
        }

        $stmt = $connection->prepare("SELECT COUNT(*) AS total_pending, MIN(mr.club_id) AS first_club_id
                                      FROM MEMBERSHIP_REQUESTS mr
                                                                            JOIN USERS u ON u.id = mr.user_id
                                      JOIN CLUB_RESPONSABLES cr ON cr.club_id = mr.club_id
                                                                            WHERE cr.user_id = ? AND mr.status = 'pending'
                                                                                AND COALESCE(u.account_status, 'active') = 'active'");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $pendingCount = isset($row['total_pending']) ? (int)$row['total_pending'] : 0;

        if ($pendingCount > 0) {
            $cache['has_pending'] = true;
            $cache['pending_count'] = $pendingCount;
            $cache['requests_link'] = phpRoute('requests.php');
            $cache['can_view_requests'] = true;
            return $cache;
        }

        $stmt = $connection->prepare("SELECT COUNT(*)
                                      FROM CLUB_RESPONSABLES
                                      WHERE user_id = ?");
        $stmt->execute([$userId]);
        $ownerClubCount = (int)$stmt->fetchColumn();

        if ($ownerClubCount > 0) {
            $cache['requests_link'] = phpRoute('requests.php');
            $cache['can_view_requests'] = true;
        }
    } catch (Exception $e) {
        // Keep safe defaults when notification lookup fails.
    }

    return $cache;
}
?>

