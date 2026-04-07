<?php

class Event {
    private $db;
    private $hasImageColumn = false;
    private $hasMaxParticipantsColumn = false;
    private $hasAllowNonMembersColumn = false;
    private $hasSpecialIdColumn = false;

    // Check if a table exists in the current database.
    private function tableExists($tableName) {
        $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->fetch(PDO::FETCH_NUM) !== false;
    }

    // Initialize event repository and required schema objects.
    public function __construct($db) {
        $this->db = $db;
        $this->ensureEventColumns();
        $this->ensureUserNotificationsTable();
        $this->ensureEventRejoinCooldownsTable();
    }

    // Create cooldown table used to block immediate rejoin.
    private function ensureEventRejoinCooldownsTable() {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS EVENT_REJOIN_COOLDOWNS (
                                id INT PRIMARY KEY AUTO_INCREMENT,
                                event_id INT NOT NULL,
                                user_id INT NOT NULL,
                                blocked_until DATETIME NOT NULL,
                                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                UNIQUE KEY unique_event_user_cooldown (event_id, user_id),
                                FOREIGN KEY (event_id) REFERENCES EVENTS(id),
                                FOREIGN KEY (user_id) REFERENCES USERS(id)
                            )");
        } catch (Exception $e) {
            // Keep app functional if cooldown table creation fails.
        }
    }

    // Create user notifications table when missing.
    private function ensureUserNotificationsTable() {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS USER_NOTIFICATIONS (
                                id INT PRIMARY KEY AUTO_INCREMENT,
                                user_id INT NOT NULL,
                                type VARCHAR(50) NOT NULL,
                                title VARCHAR(255) NOT NULL,
                                message TEXT NOT NULL,
                                is_read TINYINT(1) NOT NULL DEFAULT 0,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                FOREIGN KEY (user_id) REFERENCES USERS(id)
                            )");
        } catch (Exception $e) {
            // Keep app functional if notifications table creation fails.
        }
    }

    // Ensure optional event columns exist before queries use them.
    private function ensureEventColumns() {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM EVENTS LIKE 'special_id'");
            $this->hasSpecialIdColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;

            if (!$this->hasSpecialIdColumn) {
                $this->db->exec("ALTER TABLE EVENTS ADD COLUMN special_id VARCHAR(255) NULL AFTER titre");
                $stmt = $this->db->query("SHOW COLUMNS FROM EVENTS LIKE 'special_id'");
                $this->hasSpecialIdColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            }

            $stmt = $this->db->query("SHOW COLUMNS FROM EVENTS LIKE 'image_path'");
            $this->hasImageColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;

            if (!$this->hasImageColumn) {
                $this->db->exec("ALTER TABLE EVENTS ADD COLUMN image_path VARCHAR(255) NULL AFTER description");
                $stmt = $this->db->query("SHOW COLUMNS FROM EVENTS LIKE 'image_path'");
                $this->hasImageColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            }

            $stmt = $this->db->query("SHOW COLUMNS FROM EVENTS LIKE 'max_participants'");
            $this->hasMaxParticipantsColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;

            if (!$this->hasMaxParticipantsColumn) {
                $this->db->exec("ALTER TABLE EVENTS ADD COLUMN max_participants INT NULL AFTER lieu");
                $stmt = $this->db->query("SHOW COLUMNS FROM EVENTS LIKE 'max_participants'");
                $this->hasMaxParticipantsColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            }

            $stmt = $this->db->query("SHOW COLUMNS FROM EVENTS LIKE 'allow_non_members'");
            $this->hasAllowNonMembersColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;

            if (!$this->hasAllowNonMembersColumn) {
                $this->db->exec("ALTER TABLE EVENTS ADD COLUMN allow_non_members TINYINT(1) NOT NULL DEFAULT 0 AFTER max_participants");
                $stmt = $this->db->query("SHOW COLUMNS FROM EVENTS LIKE 'allow_non_members'");
                $this->hasAllowNonMembersColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            }
        } catch (Exception $e) {
            $this->hasSpecialIdColumn = false;
            $this->hasImageColumn = false;
            $this->hasMaxParticipantsColumn = false;
            $this->hasAllowNonMembersColumn = false;
        }
    }

    // Build special id using date/email/status format.
    private function buildSpecialId($email, $status) {
        $datePart = date('y/m/d');
        $emailPart = preg_replace('/[^a-z0-9@._-]/i', '', strtolower((string)$email));
        $statusPart = in_array($status, ['active', 'inactive'], true) ? $status : 'active';
        return $datePart . '/' . $emailPart . '/' . $statusPart;
    }

    // Resolve one responsable email for club-scoped event special id.
    private function getClubManagerEmail($clubId) {
        $stmt = $this->db->prepare("SELECT u.email
                                    FROM CLUBS c
                                    JOIN USERS u ON u.id = c.responsable_id
                                    WHERE c.id = ? LIMIT 1");
        $stmt->execute([(int)$clubId]);
        return (string)($stmt->fetchColumn() ?: 'unknown@club.local');
    }

    // Create a new event with optional dynamic fields.
    public function createEvent($club_id, $titre, $description, $date_debut, $date_fin, $lieu, $image_path = null, $max_participants = null, $allow_non_members = 0) {
        $columns = ['club_id', 'titre', 'description', 'date_debut', 'date_fin', 'lieu'];
        $values = [$club_id, $titre, $description, $date_debut, $date_fin, $lieu];

        if ($this->hasSpecialIdColumn) {
            $columns[] = 'special_id';
            $values[] = $this->buildSpecialId($this->getClubManagerEmail((int)$club_id), 'active');
        }

        if ($this->hasImageColumn) {
            $columns[] = 'image_path';
            $values[] = $image_path;
        }

        if ($this->hasMaxParticipantsColumn) {
            $columns[] = 'max_participants';
            $values[] = $max_participants;
        }

        if ($this->hasAllowNonMembersColumn) {
            $columns[] = 'allow_non_members';
            $values[] = (int)$allow_non_members === 1 ? 1 : 0;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $this->db->prepare("INSERT INTO EVENTS (" . implode(', ', $columns) . ") VALUES (" . $placeholders . ")");
        return $stmt->execute($values);
    }

    // List events belonging to one club with participant counts.
    public function getClubEvents($club_id) {
                $stmt = $this->db->prepare("SELECT e.*, 
                                                                                     (SELECT COUNT(*)
                                                                                        FROM EVENT_PARTICIPANTS ep
                                                                                        JOIN USERS u ON u.id = ep.user_id
                                                                                        WHERE ep.event_id = e.id
                                                                                            AND COALESCE(u.account_status, 'active') = 'active') AS participant_count
                                    FROM EVENTS e 
                                    WHERE e.club_id = ? 
                                    ORDER BY e.date_debut DESC");
        $stmt->execute([$club_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // List all events with club and participant metadata.
    public function getAllEvents() {
                $stmt = $this->db->prepare("SELECT e.*, c.nom as club_nom,
                                                                                     (SELECT COUNT(*)
                                                                                        FROM EVENT_PARTICIPANTS ep
                                                                                        JOIN USERS u ON u.id = ep.user_id
                                                                                        WHERE ep.event_id = e.id
                                                                                            AND COALESCE(u.account_status, 'active') = 'active') AS participant_count
                                    FROM EVENTS e 
                                    JOIN CLUBS c ON e.club_id = c.id 
                                    ORDER BY e.date_debut DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Return events ordered by most recently created.
    public function getLatestCreatedEvents() {
                $stmt = $this->db->prepare("SELECT e.*, c.nom as club_nom,
                                                                                     (SELECT COUNT(*)
                                                                                        FROM EVENT_PARTICIPANTS ep
                                                                                        JOIN USERS u ON u.id = ep.user_id
                                                                                        WHERE ep.event_id = e.id
                                                                                            AND COALESCE(u.account_status, 'active') = 'active') AS participant_count
                                    FROM EVENTS e
                                    JOIN CLUBS c ON e.club_id = c.id
                                    ORDER BY e.created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch one event by id with club ownership info.
    public function getEventById($id) {
        $stmt = $this->db->prepare("SELECT e.*, c.nom as club_nom, c.responsable_id as club_responsable_id FROM EVENTS e 
                                    JOIN CLUBS c ON e.club_id = c.id 
                                    WHERE e.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Update event fields including optional schema columns.
    public function updateEvent($id, $titre, $description, $date_debut, $date_fin, $lieu, $image_path = null, $max_participants = null, $allow_non_members = 0) {
        $assignments = [
            'titre = ?',
            'description = ?',
            'date_debut = ?',
            'date_fin = ?',
            'lieu = ?'
        ];
        $values = [$titre, $description, $date_debut, $date_fin, $lieu];

        if ($this->hasImageColumn) {
            $assignments[] = 'image_path = ?';
            $values[] = $image_path;
        }

        if ($this->hasMaxParticipantsColumn) {
            $assignments[] = 'max_participants = ?';
            $values[] = $max_participants;
        }

        if ($this->hasAllowNonMembersColumn) {
            $assignments[] = 'allow_non_members = ?';
            $values[] = (int)$allow_non_members === 1 ? 1 : 0;
        }

        $values[] = $id;
        $stmt = $this->db->prepare("UPDATE EVENTS SET " . implode(', ', $assignments) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    // Delete an event and notify subscribed participants.
    public function deleteEvent($id) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT e.titre, c.nom AS club_nom
                                        FROM EVENTS e
                                        JOIN CLUBS c ON c.id = e.club_id
                                        WHERE e.id = ?");
            $stmt->execute([$id]);
            $eventInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            $subscribers = [];
            $stmt = $this->db->prepare("SELECT user_id FROM EVENT_PARTICIPANTS WHERE event_id = ?");
            $stmt->execute([$id]);
            $subscribers = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($eventInfo && !empty($subscribers)) {
                $notifStmt = $this->db->prepare("INSERT INTO USER_NOTIFICATIONS (user_id, type, title, message, is_read)
                                                 VALUES (?, 'event_cancelled', ?, ?, 0)");
                $title = 'Evenement annule';
                $message = 'L\'evenement "' . ($eventInfo['titre'] ?? '') . '" du club "' . ($eventInfo['club_nom'] ?? '') . '" a ete annule.';

                foreach ($subscribers as $subscriberId) {
                    $subscriberId = (int)$subscriberId;
                    if ($subscriberId > 0) {
                        $notifStmt->execute([$subscriberId, $title, $message]);
                    }
                }
            }

            // Delete cooldown records if table exists
            if ($this->tableExists('EVENT_REJOIN_COOLDOWNS')) {
                $stmt = $this->db->prepare("DELETE FROM EVENT_REJOIN_COOLDOWNS WHERE event_id = ?");
                $stmt->execute([$id]);
            }

            $stmt = $this->db->prepare("DELETE FROM EVENT_PARTICIPANTS WHERE event_id = ?");
            $stmt->execute([$id]);

            $stmt = $this->db->prepare("DELETE FROM EVENTS WHERE id = ?");
            $stmt->execute([$id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Register a user as participant in an event.
    public function addParticipant($event_id, $user_id) {
        $statusStmt = $this->db->prepare("SELECT account_status FROM USERS WHERE id = ? LIMIT 1");
        $statusStmt->execute([(int)$user_id]);
        $status = (string)($statusStmt->fetchColumn() ?: 'inactive');
        if ($status !== 'active') {
            return false;
        }

        $stmt = $this->db->prepare("INSERT INTO EVENT_PARTICIPANTS (event_id, user_id) 
                                    VALUES (?, ?)");
        return $stmt->execute([$event_id, $user_id]);
    }

    // Remove a participant from an event.
    public function removeParticipant($event_id, $user_id) {
        $stmt = $this->db->prepare("DELETE FROM EVENT_PARTICIPANTS WHERE event_id = ? AND user_id = ?");
        return $stmt->execute([$event_id, $user_id]);
    }

    // Store temporary cooldown before user can rejoin event.
    public function setRejoinCooldown($event_id, $user_id, $minutes = 10) {
        $event_id = (int)$event_id;
        $user_id = (int)$user_id;
        $minutes = max(1, (int)$minutes);

        if ($event_id <= 0 || $user_id <= 0) {
            return false;
        }

        $blockedUntil = (new DateTimeImmutable('now'))
            ->add(new DateInterval('PT' . $minutes . 'M'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare("INSERT INTO EVENT_REJOIN_COOLDOWNS (event_id, user_id, blocked_until)
                                    VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until)");
        return $stmt->execute([$event_id, $user_id, $blockedUntil]);
    }

    // Get remaining cooldown seconds for event rejoin.
    public function getRejoinCooldownSeconds($event_id, $user_id) {
        $event_id = (int)$event_id;
        $user_id = (int)$user_id;

        if ($event_id <= 0 || $user_id <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), blocked_until) AS seconds_left
                                    FROM EVENT_REJOIN_COOLDOWNS
                                    WHERE event_id = ? AND user_id = ? AND blocked_until > NOW()");
        $stmt->execute([$event_id, $user_id]);
        $seconds = (int)$stmt->fetchColumn();

        return max(0, $seconds);
    }

    // Clear any existing rejoin cooldown for user/event.
    public function clearRejoinCooldown($event_id, $user_id) {
        $event_id = (int)$event_id;
        $user_id = (int)$user_id;

        if ($event_id <= 0 || $user_id <= 0) {
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM EVENT_REJOIN_COOLDOWNS WHERE event_id = ? AND user_id = ?");
        $stmt->execute([$event_id, $user_id]);
    }

    // Check if a user is currently participating in event.
    public function isParticipant($event_id, $user_id) {
                $stmt = $this->db->prepare("SELECT ep.id
                                                                        FROM EVENT_PARTICIPANTS ep
                                                                        JOIN USERS u ON u.id = ep.user_id
                                                                        WHERE ep.event_id = ? AND ep.user_id = ?
                                                                            AND COALESCE(u.account_status, 'active') = 'active'");
        $stmt->execute([$event_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    // Count participants currently registered for event.
    public function getParticipantCount($event_id) {
                $stmt = $this->db->prepare("SELECT COUNT(*)
                                                                        FROM EVENT_PARTICIPANTS ep
                                                                        JOIN USERS u ON u.id = ep.user_id
                                                                        WHERE ep.event_id = ?
                                                                            AND COALESCE(u.account_status, 'active') = 'active'");
        $stmt->execute([$event_id]);
        return (int)$stmt->fetchColumn();
    }

    // Check whether event reached max participant limit.
    public function isEventFull($event_id) {
        if (!$this->hasMaxParticipantsColumn) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT e.max_participants,
                           COUNT(CASE WHEN COALESCE(u.account_status, 'active') = 'active' THEN ep.id END) AS participant_count
                                    FROM EVENTS e
                                    LEFT JOIN EVENT_PARTICIPANTS ep ON ep.event_id = e.id
                                    LEFT JOIN USERS u ON u.id = ep.user_id
                                    WHERE e.id = ?
                                    GROUP BY e.id, e.max_participants");
        $stmt->execute([$event_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['max_participants'] === null) {
            return false;
        }

        return (int)$row['participant_count'] >= (int)$row['max_participants'];
    }

    // List event participants with membership role labels.
    public function getParticipants($event_id, $order = 'DESC') {
        $orderDirection = strtoupper((string)$order) === 'ASC' ? 'ASC' : 'DESC';
        $stmt = $this->db->prepare("SELECT u.id,
                                           u.nom,
                                           u.prenom,
                                           u.email,
                                           ep.date_inscription,
                                           CASE
                                               WHEN cr.id IS NOT NULL THEN 'Responsable'
                                               WHEN cm.id IS NOT NULL THEN 'Membre'
                                               ELSE 'Non membre'
                                           END AS participant_role
                                    FROM EVENT_PARTICIPANTS ep
                                    JOIN USERS u ON ep.user_id = u.id
                                    JOIN EVENTS e ON ep.event_id = e.id
                                    JOIN CLUBS c ON e.club_id = c.id
                                    LEFT JOIN CLUB_MEMBERS cm ON cm.club_id = c.id AND cm.user_id = u.id
                                    LEFT JOIN CLUB_RESPONSABLES cr ON cr.club_id = c.id AND cr.user_id = u.id
                                    WHERE ep.event_id = ?
                                                                            AND COALESCE(u.account_status, 'active') = 'active'
                                    ORDER BY ep.date_inscription " . $orderDirection);
        $stmt->execute([$event_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // List events linked to user as participant or owner.
    public function getUserEvents($user_id) {
        $stmt = $this->db->prepare("SELECT DISTINCT e.*, c.nom as club_nom, c.responsable_id as club_responsable_id,
                                                                                     (SELECT COUNT(*)
                                                                                        FROM EVENT_PARTICIPANTS ep2
                                                                                        JOIN USERS u2 ON u2.id = ep2.user_id
                                                                                        WHERE ep2.event_id = e.id
                                                                                            AND COALESCE(u2.account_status, 'active') = 'active') AS participant_count
                                    FROM EVENTS e
                                    JOIN CLUBS c ON e.club_id = c.id
                                    LEFT JOIN EVENT_PARTICIPANTS ep ON e.id = ep.event_id AND ep.user_id = ?
                                    LEFT JOIN CLUB_RESPONSABLES cr ON cr.club_id = c.id AND cr.user_id = ?
                                    WHERE ep.user_id IS NOT NULL OR cr.user_id IS NOT NULL
                                    ORDER BY e.date_debut DESC");
        $stmt->execute([$user_id, $user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Search events by title or description text.
    public function searchEvents($query) {
                $stmt = $this->db->prepare("SELECT e.*, c.nom as club_nom,
                                                                                     (SELECT COUNT(*)
                                                                                        FROM EVENT_PARTICIPANTS ep
                                                                                        JOIN USERS u ON u.id = ep.user_id
                                                                                        WHERE ep.event_id = e.id
                                                                                            AND COALESCE(u.account_status, 'active') = 'active') AS participant_count
                                    FROM EVENTS e 
                                    JOIN CLUBS c ON e.club_id = c.id 
                                    WHERE (e.titre LIKE ? OR e.description LIKE ?)
                                    ORDER BY e.date_debut DESC");
        $search = "%$query%";
        $stmt->execute([$search, $search]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Return unread notifications for a specific user.
    public function getUnreadUserNotifications($user_id, $limit = 20) {
        $limit = max(1, min(50, (int)$limit));
        $stmt = $this->db->prepare("SELECT id, type, title, message, created_at
                                    FROM USER_NOTIFICATIONS
                                    WHERE user_id = ? AND is_read = 0
                                    ORDER BY created_at DESC
                                    LIMIT " . $limit);
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Create a notification entry for one user.
    public function createUserNotification($user_id, $type, $title, $message) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("INSERT INTO USER_NOTIFICATIONS (user_id, type, title, message, is_read)
                                    VALUES (?, ?, ?, ?, 0)");
        return $stmt->execute([$user_id, (string)$type, (string)$title, (string)$message]);
    }

    // Mark selected notifications as read for the user.
    public function markUserNotificationsRead($user_id, $notification_ids) {
        if (empty($notification_ids)) {
            return;
        }

        $notification_ids = array_values(array_filter(array_map('intval', $notification_ids), function ($id) {
            return $id > 0;
        }));

        if (empty($notification_ids)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($notification_ids), '?'));
        $params = array_merge([$user_id], $notification_ids);

        $stmt = $this->db->prepare("UPDATE USER_NOTIFICATIONS
                                    SET is_read = 1
                                    WHERE user_id = ? AND id IN (" . $placeholders . ")");
        $stmt->execute($params);
    }
}
?>
