<?php

class EmailVerificationToken {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // Removes all verification tokens for a given user.
    public function deleteTokensForUser($userId) {
        $stmt = $this->db->prepare("DELETE FROM EMAIL_VERIFICATION_TOKENS WHERE user_id = ?");
        return $stmt->execute([(int)$userId]);
    }

    // Stores a fresh verification token hash for a user.
    public function createToken($userId, $email, $token, $expiresAt) {
        $this->deleteTokensForUser($userId);

        $tokenHash = hash('sha256', (string)$token);
        $stmt = $this->db->prepare("INSERT INTO EMAIL_VERIFICATION_TOKENS (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)");
        return $stmt->execute([(int)$userId, strtolower(trim((string)$email)), $tokenHash, $expiresAt]);
    }

    // Finds a valid token row by raw token value.
    public function getValidTokenByToken($token) {
        $tokenHash = hash('sha256', (string)$token);
        $stmt = $this->db->prepare("SELECT * FROM EMAIL_VERIFICATION_TOKENS WHERE token_hash = ? AND verified_at IS NULL AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$tokenHash]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Marks a token row as verified.
    public function markVerified($tokenId) {
        $stmt = $this->db->prepare("UPDATE EMAIL_VERIFICATION_TOKENS SET verified_at = NOW() WHERE id = ?");
        return $stmt->execute([(int)$tokenId]);
    }

    // Removes all verification tokens for the given user after a successful verification.
    public function purgeTokensForUser($userId) {
        return $this->deleteTokensForUser($userId);
    }
}

?>