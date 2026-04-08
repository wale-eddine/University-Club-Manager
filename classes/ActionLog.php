<?php

class ActionLog {
    private $db;

    public function __construct($db) {
        $this->db = $db;
        $this->ensureTable();
    }

    private function ensureTable() {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS ACTION_LOGS (
                id INT PRIMARY KEY AUTO_INCREMENT,
                actor_user_id INT NULL,
                actor_role VARCHAR(30) NOT NULL,
                action_type VARCHAR(80) NOT NULL,
                target_type VARCHAR(40) NOT NULL,
                target_id INT NULL,
                target_label VARCHAR(255) NULL,
                club_id INT NULL,
                event_id INT NULL,
                details TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_action_logs_created_at (created_at),
                INDEX idx_action_logs_actor_role (actor_role),
                INDEX idx_action_logs_actor_user_id (actor_user_id),
                INDEX idx_action_logs_club_id (club_id),
                INDEX idx_action_logs_event_id (event_id),
                CONSTRAINT fk_action_logs_actor_user FOREIGN KEY (actor_user_id) REFERENCES USERS(id) ON DELETE SET NULL
            )");
        } catch (Exception $e) {
            // Keep app running if log table migration fails.
        }
    }

    public function logAction($actorUserId, $actorRole, $actionType, $targetType, $targetId = null, $targetLabel = '', $clubId = null, $eventId = null, $details = '') {
        try {
            $stmt = $this->db->prepare("INSERT INTO ACTION_LOGS (
                actor_user_id,
                actor_role,
                action_type,
                target_type,
                target_id,
                target_label,
                club_id,
                event_id,
                details
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            return $stmt->execute([
                $actorUserId ? (int)$actorUserId : null,
                trim((string)$actorRole) !== '' ? (string)$actorRole : 'unknown',
                (string)$actionType,
                (string)$targetType,
                $targetId !== null ? (int)$targetId : null,
                trim((string)$targetLabel) !== '' ? trim((string)$targetLabel) : null,
                $clubId !== null ? (int)$clubId : null,
                $eventId !== null ? (int)$eventId : null,
                trim((string)$details) !== '' ? trim((string)$details) : null,
            ]);
        } catch (Exception $e) {
            return false;
        }
    }

    public function getRecentLogs($limit = 200) {
        $safeLimit = (int)$limit;
        if ($safeLimit <= 0) {
            $safeLimit = 200;
        }
        if ($safeLimit > 1000) {
            $safeLimit = 1000;
        }

        try {
            $stmt = $this->db->prepare("SELECT al.*, u.prenom AS actor_prenom, u.nom AS actor_nom, u.email AS actor_email
                                       FROM ACTION_LOGS al
                                       LEFT JOIN USERS u ON u.id = al.actor_user_id
                                       ORDER BY al.created_at DESC, al.id DESC
                                       LIMIT :limit");
            $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}
?>
