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

    // Create a new user account with a hashed password.
    public function register($nom, $prenom, $email, $password) {
        $email = $this->normalizeEmail($email);

        $stmt = $this->db->prepare("INSERT INTO USERS (nom, prenom, email, mot_de_passe) 
                                    VALUES (?, ?, ?, ?)");
        
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        return $stmt->execute([$nom, $prenom, $email, $hashed_password]);
    }

    // Authenticate a user by email and password.
    public function login($email, $password) {
        $email = $this->normalizeEmail($email);

        $stmt = $this->db->prepare("SELECT * FROM USERS WHERE LOWER(email) = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['mot_de_passe'])) {
            return $user;
        }
        return false;
    }

    // Fetch one user record by its identifier.
    public function getUserById($id) {
        $stmt = $this->db->prepare("SELECT * FROM USERS WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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
