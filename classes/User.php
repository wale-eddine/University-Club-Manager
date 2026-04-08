<?php

class User {
    private $db;

    // Initialize the user repository with a database connection.
    public function __construct($db) {
        $this->db = $db;
        $this->ensureUserManagementColumns();
    }

    // Ensure account lifecycle and special id columns are available.
    private function ensureUserManagementColumns() {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM USERS LIKE 'account_status'");
            if (!($stmt && $stmt->fetch(PDO::FETCH_ASSOC))) {
                $this->db->exec("ALTER TABLE USERS ADD COLUMN account_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER role");
            }

            $stmt = $this->db->query("SHOW COLUMNS FROM USERS LIKE 'inactive_reason'");
            if (!($stmt && $stmt->fetch(PDO::FETCH_ASSOC))) {
                $this->db->exec("ALTER TABLE USERS ADD COLUMN inactive_reason VARCHAR(255) NULL AFTER account_status");
            }

            $stmt = $this->db->query("SHOW COLUMNS FROM USERS LIKE 'special_id'");
            if (!($stmt && $stmt->fetch(PDO::FETCH_ASSOC))) {
                $this->db->exec("ALTER TABLE USERS ADD COLUMN special_id VARCHAR(255) NULL AFTER email");
            }
        } catch (Exception $e) {
            // Keep app running if automatic migration fails.
        }
    }

    // Normalize email format for consistent comparisons.
    private function normalizeEmail($email) {
        return strtolower(trim((string)$email));
    }

    // Normalize display names before persistence.
    private function normalizeName($value, $fallback = 'Utilisateur') {
        $value = trim((string)$value);
        return $value !== '' ? $value : $fallback;
    }

    // Build special id using date/email/status format.
    private function buildSpecialId($email, $status) {
        $datePart = date('y/m/d');
        $emailPart = preg_replace('/[^a-z0-9@._-]/i', '', strtolower((string)$email));
        $statusPart = in_array($status, ['active', 'inactive'], true) ? $status : 'active';
        return $datePart . '/' . $emailPart . '/' . $statusPart;
    }

    // Create a new user account with a hashed password.
    public function register($nom, $prenom, $email, $password) {
        $email = $this->normalizeEmail($email);

        $status = 'active';
        $specialId = $this->buildSpecialId($email, $status);
        $stmt = $this->db->prepare("INSERT INTO USERS (nom, prenom, email, special_id, mot_de_passe, email_verified_at, account_status, inactive_reason) 
                                    VALUES (?, ?, ?, ?, ?, NULL, ?, NULL)");
        
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        return $stmt->execute([$nom, $prenom, $email, $specialId, $hashed_password, $status]);
    }

    // Authenticates a user by email and password, returning status information.
    public function authenticateWithStatus($email, $password) {
        $email = $this->normalizeEmail($email);

        $stmt = $this->db->prepare("SELECT * FROM USERS WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $hashedPassword = $user['mot_de_passe'] ?? null;
        $isVerified = !empty($user['email_verified_at']);
        $accountStatus = (string)($user['account_status'] ?? 'active');

        if (!$user || !is_string($hashedPassword) || $hashedPassword === '' || !password_verify($password, $hashedPassword)) {
            return [
                'status' => 'invalid',
                'user' => null,
            ];
        }

        if (!$isVerified) {
            return [
                'status' => 'unverified',
                'user' => $user,
            ];
        }

        if ($accountStatus === 'inactive') {
            return [
                'status' => 'inactive',
                'user' => $user,
            ];
        }

        return [
            'status' => 'success',
            'user' => $user,
        ];
    }

    // Fetch one user record by normalized email address.
    public function getUserByEmail($email) {
        $email = $this->normalizeEmail($email);

        $stmt = $this->db->prepare("SELECT * FROM USERS WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Fetch one user linked to a Google account identifier.
    public function getUserByGoogleId($googleId) {
        $stmt = $this->db->prepare("SELECT * FROM USERS WHERE google_id = ? LIMIT 1");
        $stmt->execute([trim((string)$googleId)]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Link an existing account to Google sign-in.
    public function linkGoogleIdToUser($userId, $googleId, $avatarUrl = null) {
        $stmt = $this->db->prepare("UPDATE USERS SET google_id = ?, avatar_url = ? WHERE id = ?");
        return $stmt->execute([trim((string)$googleId), $avatarUrl, (int)$userId]);
    }

    // Create a new user account from Google profile data.
    public function createGoogleUser($nom, $prenom, $email, $googleId, $avatarUrl = null, $password = null) {
        $email = $this->normalizeEmail($email);
        $nom = $this->normalizeName($nom, 'Google');
        $prenom = $this->normalizeName($prenom, 'Utilisateur');
        $specialId = $this->buildSpecialId($email, 'active');
        $hashedPassword = null;

        if (is_string($password) && trim($password) !== '') {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        }

        $stmt = $this->db->prepare("INSERT INTO USERS (nom, prenom, email, special_id, mot_de_passe, google_id, avatar_url, email_verified_at, account_status, inactive_reason)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'active', NULL)");

        return $stmt->execute([$nom, $prenom, $email, $specialId, $hashedPassword, trim((string)$googleId), $avatarUrl]);
    }

    // Marks a user's email as verified.
    public function markEmailVerified($userId) {
        $stmt = $this->db->prepare("UPDATE USERS SET email_verified_at = NOW() WHERE id = ?");
        return $stmt->execute([(int)$userId]);
    }

    // Fetch one user record by its identifier.
    public function getUserById($id) {
        $stmt = $this->db->prepare("SELECT * FROM USERS WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Fetch all users for admin management views.
    public function getAllUsers() {
        $stmt = $this->db->prepare("SELECT * FROM USERS ORDER BY FIELD(role, 'admin', 'responsable', 'etudiant'), prenom, nom, id");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Update a user from the admin panel, with optional password reset.
    public function updateUserByAdmin($id, $nom, $prenom, $email, $role, $password = '', $accountStatus = 'active', $inactiveReason = '') {
        $allowedRoles = ['admin', 'responsable', 'etudiant'];
        if (!in_array($role, $allowedRoles, true)) {
            return false;
        }

        $allowedStatuses = ['active', 'inactive'];
        if (!in_array($accountStatus, $allowedStatuses, true)) {
            return false;
        }

        $inactiveReason = trim((string)$inactiveReason);
        if ($accountStatus === 'inactive' && $inactiveReason === '') {
            return false;
        }
        if ($accountStatus === 'active') {
            $inactiveReason = null;
        }

        $email = $this->normalizeEmail($email);
        $id = (int)$id;
        $specialId = $this->buildSpecialId($email, $accountStatus);

        if ($id <= 0) {
            return false;
        }

        if (trim((string)$password) !== '') {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $this->db->prepare("UPDATE USERS SET nom = ?, prenom = ?, email = ?, role = ?, account_status = ?, inactive_reason = ?, special_id = ?, mot_de_passe = ? WHERE id = ?");
            return $stmt->execute([$nom, $prenom, $email, $role, $accountStatus, $inactiveReason, $specialId, $hashedPassword, $id]);
        }

        $stmt = $this->db->prepare("UPDATE USERS SET nom = ?, prenom = ?, email = ?, role = ?, account_status = ?, inactive_reason = ?, special_id = ? WHERE id = ?");
        return $stmt->execute([$nom, $prenom, $email, $role, $accountStatus, $inactiveReason, $specialId, $id]);
    }

    // Update only the user's role.
    public function updateRole($id, $role) {
        $allowedRoles = ['admin', 'responsable', 'etudiant'];
        if (!in_array($role, $allowedRoles, true)) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE USERS SET role = ? WHERE id = ?");
        return $stmt->execute([$role, (int)$id]);
    }

    // Update a user's password with a secure hash.
    public function updatePassword($id, $password) {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare("UPDATE USERS SET mot_de_passe = ? WHERE id = ?");
        return $stmt->execute([$hashedPassword, (int)$id]);
    }

    // Verify a plaintext password against the stored hash for a user.
    public function verifyPassword($id, $password) {
        $stmt = $this->db->prepare("SELECT mot_de_passe FROM USERS WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $hashedPassword = $row['mot_de_passe'] ?? null;
        if (!is_string($hashedPassword) || trim($hashedPassword) === '') {
            return false;
        }

        return password_verify($password, $hashedPassword);
    }

    // Update profile identity fields for a user.
    public function updateProfile($id, $nom, $prenom, $email) {
        $email = $this->normalizeEmail($email);
        $id = (int)$id;

        $stmt = $this->db->prepare("SELECT account_status FROM USERS WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $status = (string)($stmt->fetchColumn() ?: 'active');
        $specialId = $this->buildSpecialId($email, $status);

        $stmt = $this->db->prepare("UPDATE USERS SET nom = ?, prenom = ?, email = ?, special_id = ? 
                                    WHERE id = ?");
        return $stmt->execute([$nom, $prenom, $email, $specialId, $id]);
    }

    // Check whether an email is already used by any user.
    public function emailExists($email) {
        $email = $this->normalizeEmail($email);

        $stmt = $this->db->prepare("SELECT id FROM USERS WHERE LOWER(email) = ?");
        $stmt->execute([$email]);
        return $stmt->rowCount() > 0;
    }

    // Check whether an email is used by another user.
    public function emailExistsForOtherUser($email, $excludedUserId) {
        $email = $this->normalizeEmail($email);

        $stmt = $this->db->prepare("SELECT id FROM USERS WHERE LOWER(email) = ? AND id <> ?");
        $stmt->execute([$email, $excludedUserId]);
        return $stmt->rowCount() > 0;
    }
}
?>
