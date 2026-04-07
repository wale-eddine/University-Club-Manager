<?php

class MembershipRequest {
    private $db;
    private $hasRequesterNotifiedColumn = false;

    // Initialize request repository and required schema fields.
    public function __construct($db) {
        $this->db = $db;
        $this->ensureRequesterNotifiedColumn();
        $this->ensureRequestCooldownsTable();
    }

    // Create cooldown table used to rate-limit new requests.
    private function ensureRequestCooldownsTable() {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS MEMBERSHIP_REQUEST_COOLDOWNS (
                                id INT PRIMARY KEY AUTO_INCREMENT,
                                club_id INT NOT NULL,
                                user_id INT NOT NULL,
                                blocked_until DATETIME NOT NULL,
                                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                UNIQUE KEY unique_club_user_cooldown (club_id, user_id),
                                FOREIGN KEY (club_id) REFERENCES CLUBS(id),
                                FOREIGN KEY (user_id) REFERENCES USERS(id)
                            )");
        } catch (Exception $e) {
            // Keep app functional if cooldown table creation fails.
        }
    }

    // Ensure request notification flag column exists.
    private function ensureRequesterNotifiedColumn() {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM MEMBERSHIP_REQUESTS LIKE 'requester_notified'");
            $this->hasRequesterNotifiedColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;

            if (!$this->hasRequesterNotifiedColumn) {
                $this->db->exec("ALTER TABLE MEMBERSHIP_REQUESTS ADD COLUMN requester_notified TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
                $stmt = $this->db->query("SHOW COLUMNS FROM MEMBERSHIP_REQUESTS LIKE 'requester_notified'");
                $this->hasRequesterNotifiedColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            }
        } catch (Exception $e) {
            $this->hasRequesterNotifiedColumn = false;
        }
    }

    // Create or refresh a pending membership request.
    public function createRequest($club_id, $user_id) {
        $statusStmt = $this->db->prepare("SELECT account_status FROM USERS WHERE id = ? LIMIT 1");
        $statusStmt->execute([(int)$user_id]);
        $status = (string)($statusStmt->fetchColumn() ?: 'inactive');
        if ($status !== 'active') {
            return false;
        }

        $sql = "INSERT INTO MEMBERSHIP_REQUESTS (club_id, user_id, status";
        $sql .= $this->hasRequesterNotifiedColumn ? ", requester_notified" : "";
        $sql .= ") VALUES (?, ?, 'pending";
        $sql .= $this->hasRequesterNotifiedColumn ? "', 0" : "'";
        $sql .= ") ON DUPLICATE KEY UPDATE status = 'pending', updated_at = CURRENT_TIMESTAMP";
        if ($this->hasRequesterNotifiedColumn) {
            $sql .= ", requester_notified = 0";
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$club_id, $user_id]);
    }

    // Cancel an existing pending membership request.
    public function cancelRequest($club_id, $user_id) {
        $club_id = (int)$club_id;
        $user_id = (int)$user_id;

        if ($club_id <= 0 || $user_id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM MEMBERSHIP_REQUESTS
                                    WHERE club_id = ? AND user_id = ? AND status = 'pending'");
        $stmt->execute([$club_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    // Set cooldown time before another request is allowed.
    public function setRequestCooldown($club_id, $user_id, $minutes = 10) {
        $club_id = (int)$club_id;
        $user_id = (int)$user_id;
        $minutes = max(1, (int)$minutes);

        if ($club_id <= 0 || $user_id <= 0) {
            return false;
        }

        $blockedUntil = (new DateTimeImmutable('now'))
            ->add(new DateInterval('PT' . $minutes . 'M'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare("INSERT INTO MEMBERSHIP_REQUEST_COOLDOWNS (club_id, user_id, blocked_until)
                                    VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until)");
        return $stmt->execute([$club_id, $user_id, $blockedUntil]);
    }

    // Get remaining cooldown seconds for request submission.
    public function getRequestCooldownSeconds($club_id, $user_id) {
        $club_id = (int)$club_id;
        $user_id = (int)$user_id;

        if ($club_id <= 0 || $user_id <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), blocked_until) AS seconds_left
                                    FROM MEMBERSHIP_REQUEST_COOLDOWNS
                                    WHERE club_id = ? AND user_id = ? AND blocked_until > NOW()");
        $stmt->execute([$club_id, $user_id]);
        $seconds = (int)$stmt->fetchColumn();

        return max(0, $seconds);
    }

    // Remove current request cooldown for a user and club.
    public function clearRequestCooldown($club_id, $user_id) {
        $club_id = (int)$club_id;
        $user_id = (int)$user_id;

        if ($club_id <= 0 || $user_id <= 0) {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM MEMBERSHIP_REQUEST_COOLDOWNS WHERE club_id = ? AND user_id = ?");
        $stmt->execute([$club_id, $user_id]);
    }

    // List pending requests for one club.
    public function getPendingRequests($club_id, $order = 'ASC') {
        $orderDirection = strtoupper((string)$order) === 'DESC' ? 'DESC' : 'ASC';
        $stmt = $this->db->prepare("SELECT mr.id, u.id as user_id, u.nom, u.prenom, u.email, mr.created_at 
                                    FROM MEMBERSHIP_REQUESTS mr 
                                    JOIN USERS u ON mr.user_id = u.id 
                                    WHERE mr.club_id = ? AND mr.status = 'pending' 
                                      AND COALESCE(u.account_status, 'active') = 'active'
                                    ORDER BY mr.created_at " . $orderDirection . ", mr.id " . $orderDirection);
        $stmt->execute([$club_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // List pending requests across clubs owned by user.
    public function getPendingRequestsForOwner($owner_id, $order = 'ASC') {
        $orderDirection = strtoupper((string)$order) === 'DESC' ? 'DESC' : 'ASC';
        $stmt = $this->db->prepare("SELECT mr.id, mr.club_id, u.id as user_id, u.nom, u.prenom, u.email, mr.created_at, c.nom AS club_nom
                                    FROM MEMBERSHIP_REQUESTS mr
                                    JOIN USERS u ON mr.user_id = u.id
                                    JOIN CLUBS c ON mr.club_id = c.id
                                    JOIN CLUB_RESPONSABLES cr ON cr.club_id = c.id
                                    WHERE cr.user_id = ? AND mr.status = 'pending'
                                      AND COALESCE(u.account_status, 'active') = 'active'
                                    ORDER BY mr.created_at " . $orderDirection . ", c.nom ASC, mr.id " . $orderDirection);
        $stmt->execute([$owner_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // List pending requests across all clubs for global admin moderation.
    public function getPendingRequestsForAdmin($order = 'ASC') {
        $orderDirection = strtoupper((string)$order) === 'DESC' ? 'DESC' : 'ASC';
        $stmt = $this->db->prepare("SELECT mr.id, mr.club_id, u.id as user_id, u.nom, u.prenom, u.email, mr.created_at, c.nom AS club_nom
                                    FROM MEMBERSHIP_REQUESTS mr
                                    JOIN USERS u ON mr.user_id = u.id
                                    JOIN CLUBS c ON mr.club_id = c.id
                                    WHERE mr.status = 'pending'
                                      AND COALESCE(u.account_status, 'active') = 'active'
                                    ORDER BY mr.created_at " . $orderDirection . ", c.nom ASC, mr.id " . $orderDirection);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Approve a request and add user to club members.
    public function approveRequest($request_id, $club_id, $user_id) {
        // Start transaction
        try {
            $this->db->beginTransaction();

            // Update request status
            $stmt = $this->db->prepare("UPDATE MEMBERSHIP_REQUESTS SET status = 'accepted'" . ($this->hasRequesterNotifiedColumn ? ", requester_notified = 0" : "") . " 
                                        WHERE id = ?");
            $stmt->execute([$request_id]);

            // Add member to club
            $stmt = $this->db->prepare("INSERT INTO CLUB_MEMBERS (club_id, user_id) 
                                        VALUES (?, ?)");
            $stmt->execute([$club_id, $user_id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    // Reject a membership request by id.
    public function rejectRequest($request_id) {
        $stmt = $this->db->prepare("UPDATE MEMBERSHIP_REQUESTS SET status = 'rejected'" . ($this->hasRequesterNotifiedColumn ? ", requester_notified = 0" : "") . " 
                                    WHERE id = ?");
        return $stmt->execute([$request_id]);
    }

    // Approve owner-visible request after ownership validation.
    public function approveRequestForOwner($request_id, $owner_id) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT mr.club_id, mr.user_id
                                        FROM MEMBERSHIP_REQUESTS mr
                                        JOIN CLUB_RESPONSABLES cr ON mr.club_id = cr.club_id
                                        WHERE mr.id = ? AND mr.status = 'pending' AND cr.user_id = ?");
            $stmt->execute([$request_id, $owner_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $this->db->rollBack();
                return false;
            }

            $clubId = (int)$request['club_id'];
            $userId = (int)$request['user_id'];

            $statusStmt = $this->db->prepare("SELECT account_status FROM USERS WHERE id = ? LIMIT 1");
            $statusStmt->execute([$userId]);
            if ((string)($statusStmt->fetchColumn() ?: 'inactive') !== 'active') {
                $this->db->rollBack();
                return false;
            }

            $stmt = $this->db->prepare("UPDATE MEMBERSHIP_REQUESTS SET status = 'accepted'" . ($this->hasRequesterNotifiedColumn ? ", requester_notified = 0" : "") . " WHERE id = ?");
            $stmt->execute([$request_id]);

            $stmt = $this->db->prepare("INSERT IGNORE INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
            $stmt->execute([$clubId, $userId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Reject owner-visible request after ownership validation.
    public function rejectRequestForOwner($request_id, $owner_id) {
        $setClause = "mr.status = 'rejected'";
        if ($this->hasRequesterNotifiedColumn) {
            $setClause .= ", mr.requester_notified = 0";
        }

        $stmt = $this->db->prepare("UPDATE MEMBERSHIP_REQUESTS mr
                                    JOIN CLUB_RESPONSABLES cr ON mr.club_id = cr.club_id
                                    SET " . $setClause . "
                                    WHERE mr.id = ? AND mr.status = 'pending' AND cr.user_id = ?");
        $stmt->execute([$request_id, $owner_id]);
        return $stmt->rowCount() > 0;
    }

    // Approve a pending request by id for global admin moderation.
    public function approveRequestByAdmin($request_id) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT club_id, user_id
                                        FROM MEMBERSHIP_REQUESTS
                                        WHERE id = ? AND status = 'pending'");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $this->db->rollBack();
                return false;
            }

            $clubId = (int)$request['club_id'];
            $userId = (int)$request['user_id'];

            $statusStmt = $this->db->prepare("SELECT account_status FROM USERS WHERE id = ? LIMIT 1");
            $statusStmt->execute([$userId]);
            if ((string)($statusStmt->fetchColumn() ?: 'inactive') !== 'active') {
                $this->db->rollBack();
                return false;
            }

            $stmt = $this->db->prepare("UPDATE MEMBERSHIP_REQUESTS
                                        SET status = 'accepted'" . ($this->hasRequesterNotifiedColumn ? ", requester_notified = 0" : "") . "
                                        WHERE id = ? AND status = 'pending'");
            $stmt->execute([$request_id]);

            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return false;
            }

            $stmt = $this->db->prepare("INSERT IGNORE INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
            $stmt->execute([$clubId, $userId]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Reject a pending request by id for global admin moderation.
    public function rejectRequestByAdmin($request_id) {
        $stmt = $this->db->prepare("UPDATE MEMBERSHIP_REQUESTS
                                    SET status = 'rejected'" . ($this->hasRequesterNotifiedColumn ? ", requester_notified = 0" : "") . "
                                    WHERE id = ? AND status = 'pending'");
        $stmt->execute([$request_id]);
        return $stmt->rowCount() > 0;
    }

    // Fetch unseen decision notifications for requester.
    public function getUnreadDecisionNotifications($user_id) {
        if (!$this->hasRequesterNotifiedColumn) {
            return [];
        }

        $stmt = $this->db->prepare("SELECT mr.id, mr.status, c.nom AS club_nom, mr.updated_at
                                    FROM MEMBERSHIP_REQUESTS mr
                                    JOIN CLUBS c ON mr.club_id = c.id
                                    WHERE mr.user_id = ?
                                      AND mr.status IN ('accepted', 'rejected')
                                      AND mr.requester_notified = 0
                                    ORDER BY mr.updated_at DESC
                                    LIMIT 10");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Mark selected decision notifications as seen.
    public function markDecisionNotificationsSeen($user_id, $request_ids) {
        if (!$this->hasRequesterNotifiedColumn || empty($request_ids)) {
            return;
        }

        $request_ids = array_values(array_filter(array_map('intval', $request_ids), function ($id) {
            return $id > 0;
        }));

        if (empty($request_ids)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($request_ids), '?'));
        $params = array_merge([$user_id], $request_ids);

        $stmt = $this->db->prepare("UPDATE MEMBERSHIP_REQUESTS
                                    SET requester_notified = 1
                                    WHERE user_id = ? AND id IN (" . $placeholders . ")");
        $stmt->execute($params);
    }

    // Check whether a pending request already exists.
    public function hasRequest($club_id, $user_id) {
        $stmt = $this->db->prepare("SELECT id FROM MEMBERSHIP_REQUESTS 
                                    WHERE club_id = ? AND user_id = ? AND status = 'pending'");
        $stmt->execute([$club_id, $user_id]);
        return $stmt->rowCount() > 0;
    }
}
?>
