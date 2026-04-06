<?php
// Starts or resumes the PHP session for authentication state.
session_start();

// Checks whether a user is currently logged in.
function isLoggedIn() {
    return isset($_SESSION['user_id']);
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

// Determines relative PHP path prefix based on current script location.
function getPhpPathPrefix() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($script, '/php/club_php/') !== false || strpos($script, '/php/event_php/') !== false || strpos($script, '/php/profile_php/') !== false) {
        return '../';
    }
    return '';
}

// Builds a PHP route from project php root with the proper prefix.
function phpRoute($pathFromPhpRoot) {
    return getPhpPathPrefix() . ltrim($pathFromPhpRoot, '/');
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
}

// Redirects authenticated users away from guest-only pages.
function redirectIfLoggedIn() {
    if (isLoggedIn()) {
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

        $stmt = $connection->prepare("SELECT COUNT(*) AS total_pending, MIN(mr.club_id) AS first_club_id
                                      FROM MEMBERSHIP_REQUESTS mr
                                      JOIN CLUBS c ON c.id = mr.club_id
                                      WHERE c.responsable_id = ? AND mr.status = 'pending'");
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
                                      FROM CLUBS
                                      WHERE responsable_id = ?");
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

