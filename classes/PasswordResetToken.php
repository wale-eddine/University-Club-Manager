<?php

class PasswordResetToken {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // Removes all reset tokens for a given user.
    public function deleteTokensForUser($userId) {
        $stmt = $this->db->prepare("DELETE FROM PASSWORD_RESET_TOKENS WHERE user_id = ?");
        return $stmt->execute([(int)$userId]);
    }

    // Stores a fresh token hash for a user.
    public function createToken($userId, $email, $token, $expiresAt) {
        $this->deleteTokensForUser($userId);

        $tokenHash = hash('sha256', (string)$token);
        $stmt = $this->db->prepare("INSERT INTO PASSWORD_RESET_TOKENS (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)");
        return $stmt->execute([(int)$userId, strtolower(trim((string)$email)), $tokenHash, $expiresAt]);
    }

    // Finds a valid token row by raw token value.
    public function getValidTokenByToken($token) {
        $tokenHash = hash('sha256', (string)$token);
        $stmt = $this->db->prepare("SELECT * FROM PASSWORD_RESET_TOKENS WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$tokenHash]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Deletes all tokens for the given user after a successful reset.
    public function purgeTokensForUser($userId) {
        return $this->deleteTokensForUser($userId);
    }
}

?>