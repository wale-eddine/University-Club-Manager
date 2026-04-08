<?php

class PasswordResetToken {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // Normalizes email for stable lookups.
    private function normalizeEmail($email) {
        return strtolower(trim((string)$email));
    }

    // Removes all reset tokens for a given user.
    public function deleteTokensForUser($userId) {
        $stmt = $this->db->prepare("DELETE FROM PASSWORD_RESET_TOKENS WHERE user_id = ?");
        return $stmt->execute([(int)$userId]);
    }

    // Marks all currently active tokens as used for a user.
    public function invalidateActiveTokensForUser($userId) {
        $stmt = $this->db->prepare("UPDATE PASSWORD_RESET_TOKENS
                                   SET used_at = COALESCE(used_at, NOW()),
                                       expires_at = CASE WHEN expires_at > NOW() THEN NOW() ELSE expires_at END
                                   WHERE user_id = ? AND used_at IS NULL");
        return $stmt->execute([(int)$userId]);
    }

    // Returns remaining cooldown seconds for an email (0 when a new request is allowed).
    public function getCooldownRemainingSecondsForEmail($email, $cooldownSeconds = 1800) {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === '') {
            return 0;
        }

        $stmt = $this->db->prepare("SELECT created_at
                                   FROM PASSWORD_RESET_TOKENS
                                   WHERE LOWER(email) = ?
                                   ORDER BY created_at DESC
                                   LIMIT 1");
        $stmt->execute([$normalizedEmail]);
        $lastCreatedAt = $stmt->fetchColumn();

        if (!$lastCreatedAt) {
            return 0;
        }

        $lastTimestamp = strtotime((string)$lastCreatedAt);
        if ($lastTimestamp === false) {
            return 0;
        }

        $elapsed = time() - $lastTimestamp;
        $remaining = (int)$cooldownSeconds - (int)$elapsed;
        return $remaining > 0 ? $remaining : 0;
    }

    // Purges old token history rows to limit table growth.
    public function purgeExpiredHistory($daysToKeep = 30) {
        $daysToKeep = max(1, (int)$daysToKeep);
        $stmt = $this->db->prepare("DELETE FROM PASSWORD_RESET_TOKENS
                                   WHERE created_at < (NOW() - INTERVAL ? DAY)");
        return $stmt->execute([$daysToKeep]);
    }

    // Stores a fresh token hash for a user.
    public function createToken($userId, $email, $token, $expiresAt) {
        $this->invalidateActiveTokensForUser($userId);
        $this->purgeExpiredHistory(30);

        $tokenHash = hash('sha256', (string)$token);
        $stmt = $this->db->prepare("INSERT INTO PASSWORD_RESET_TOKENS (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)");
        return $stmt->execute([(int)$userId, $this->normalizeEmail($email), $tokenHash, $expiresAt]);
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
        return $this->invalidateActiveTokensForUser($userId);
    }
}

?>