<?php

class User {
    private $db;

    // Initialize the user repository with a database connection.
    public function __construct($db) {
        $this->db = $db;
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

    // Create a new user account with a hashed password.
    public function register($nom, $prenom, $email, $password) {
        $email = $this->normalizeEmail($email);

        $stmt = $this->db->prepare("INSERT INTO USERS (nom, prenom, email, mot_de_passe, email_verified_at) 
                                    VALUES (?, ?, ?, ?, NULL)");
        
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        return $stmt->execute([$nom, $prenom, $email, $hashed_password]);
    }

    // Authenticates a user by email and password, returning status information.
    public function authenticateWithStatus($email, $password) {
        $email = $this->normalizeEmail($email);

        $stmt = $this->db->prepare("SELECT * FROM USERS WHERE LOWER(email) = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $hashedPassword = $user['mot_de_passe'] ?? null;
        $isVerified = !empty($user['email_verified_at']);

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
    public function createGoogleUser($nom, $prenom, $email, $googleId, $avatarUrl = null) {
        $email = $this->normalizeEmail($email);
        $nom = $this->normalizeName($nom, 'Google');
        $prenom = $this->normalizeName($prenom, 'Utilisateur');

        $stmt = $this->db->prepare("INSERT INTO USERS (nom, prenom, email, mot_de_passe, google_id, avatar_url, email_verified_at)
                                    VALUES (?, ?, ?, NULL, ?, ?, NOW())");

        return $stmt->execute([$nom, $prenom, $email, trim((string)$googleId), $avatarUrl]);
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

        $stmt = $this->db->prepare("UPDATE USERS SET nom = ?, prenom = ?, email = ? 
                                    WHERE id = ?");
        return $stmt->execute([$nom, $prenom, $email, $id]);
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
