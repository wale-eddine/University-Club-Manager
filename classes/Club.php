<?php

class Club {
    private $db;
    private $hasImageColumn = false;
    private $hasSpecialIdColumn = false;

    // Check if a table exists in the current database.
    private function tableExists($tableName) {
        $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$tableName]);
        return $stmt->fetch(PDO::FETCH_NUM) !== false;
    }

    // Initialize club repository and verify required schema.
    public function __construct($db) {
        $this->db = $db;
        $this->ensureImageColumn();
        $this->ensureSpecialIdColumn();
        $this->ensureClubResponsablesTable();
        $this->ensureResponsableRestoreTable();
    }

    // Build special id using date/email/status format.
    private function buildSpecialId($email, $status) {
        $datePart = date('y/m/d');
        $emailPart = preg_replace('/[^a-z0-9@._-]/i', '', strtolower((string)$email));
        $statusPart = in_array($status, ['active', 'inactive'], true) ? $status : 'active';
        return $datePart . '/' . $emailPart . '/' . $statusPart;
    }

    // Resolve a user email from id for special id generation.
    private function getUserEmail($userId) {
        $stmt = $this->db->prepare("SELECT email FROM USERS WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$userId]);
        return (string)($stmt->fetchColumn() ?: 'unknown@user.local');
    }

    // Ensure clubs special_id support exists.
    private function ensureSpecialIdColumn() {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM CLUBS LIKE 'special_id'");
            $this->hasSpecialIdColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;

            if (!$this->hasSpecialIdColumn) {
                $this->db->exec("ALTER TABLE CLUBS ADD COLUMN special_id VARCHAR(255) NULL AFTER nom");
                $stmt = $this->db->query("SHOW COLUMNS FROM CLUBS LIKE 'special_id'");
                $this->hasSpecialIdColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            }
        } catch (Exception $e) {
            $this->hasSpecialIdColumn = false;
        }
    }

    // Ensure many-to-many club responsibility table exists.
    private function ensureClubResponsablesTable() {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS CLUB_RESPONSABLES (
                                id INT PRIMARY KEY AUTO_INCREMENT,
                                club_id INT NOT NULL,
                                user_id INT NOT NULL,
                                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                UNIQUE KEY unique_club_responsable (club_id, user_id),
                                FOREIGN KEY (club_id) REFERENCES CLUBS(id) ON DELETE CASCADE,
                                FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE
                            )");

            $this->db->exec("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id)
                              SELECT id, responsable_id FROM CLUBS WHERE responsable_id IS NOT NULL");
        } catch (Exception $e) {
            // Keep app functional if table migration fails.
        }
    }

    // Keep a lightweight archive of responsibilities removed due to account inactivity.
    private function ensureResponsableRestoreTable() {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS CLUB_RESPONSABLES_ARCHIVE (
                                id INT PRIMARY KEY AUTO_INCREMENT,
                                user_id INT NOT NULL,
                                club_id INT NOT NULL,
                                archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                UNIQUE KEY unique_user_club_archive (user_id, club_id),
                                FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE,
                                FOREIGN KEY (club_id) REFERENCES CLUBS(id) ON DELETE CASCADE
                            )");
        } catch (Exception $e) {
            // Keep app functional if table migration fails.
        }
    }

    // Ensure clubs table supports optional image path storage.
    private function ensureImageColumn() {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM CLUBS LIKE 'image_path'");
            $this->hasImageColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;

            if (!$this->hasImageColumn) {
                $this->db->exec("ALTER TABLE CLUBS ADD COLUMN image_path VARCHAR(255) NULL AFTER description");
                $stmt = $this->db->query("SHOW COLUMNS FROM CLUBS LIKE 'image_path'");
                $this->hasImageColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            }
        } catch (Exception $e) {
            $this->hasImageColumn = false;
        }
    }

    // Create a club and auto-add its owner as a member.
    public function createClub($nom, $description, $responsable_id, $image_path = null) {
        try {
            $this->db->beginTransaction();
            $specialId = null;
            if ($this->hasSpecialIdColumn) {
                $specialId = $this->buildSpecialId($this->getUserEmail((int)$responsable_id), 'active');
            }

            if ($this->hasImageColumn) {
                if ($this->hasSpecialIdColumn) {
                    $stmt = $this->db->prepare("INSERT INTO CLUBS (nom, special_id, description, image_path, responsable_id) 
                                                VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$nom, $specialId, $description, $image_path, $responsable_id]);
                } else {
                    $stmt = $this->db->prepare("INSERT INTO CLUBS (nom, description, image_path, responsable_id) 
                                                VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nom, $description, $image_path, $responsable_id]);
                }
            } else {
                if ($this->hasSpecialIdColumn) {
                    $stmt = $this->db->prepare("INSERT INTO CLUBS (nom, special_id, description, responsable_id) 
                                                VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nom, $specialId, $description, $responsable_id]);
                } else {
                    $stmt = $this->db->prepare("INSERT INTO CLUBS (nom, description, responsable_id) 
                                                VALUES (?, ?, ?)");
                    $stmt->execute([$nom, $description, $responsable_id]);
                }
            }

            $club_id = (int)$this->db->lastInsertId();
            $stmt = $this->db->prepare("INSERT INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
            $stmt->execute([$club_id, $responsable_id]);
            $stmt = $this->db->prepare("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id) VALUES (?, ?)");
            $stmt->execute([$club_id, (int)$responsable_id]);

            $this->db->commit();
            return $club_id;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Return all clubs with responsible user names.
    public function getClubs() {
        $stmt = $this->db->prepare("SELECT c.*, u.nom AS responsable_nom, u.prenom FROM CLUBS c 
                                    JOIN USERS u ON c.responsable_id = u.id 
                                    WHERE COALESCE(u.account_status, 'active') = 'active'
                                    ORDER BY c.nom");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Return one club by id with responsible user info.
    public function getClubById($id) {
        $stmt = $this->db->prepare("SELECT c.*, u.nom AS responsable_nom, u.prenom FROM CLUBS c 
                                    JOIN USERS u ON c.responsable_id = u.id 
                                    WHERE c.id = ? AND COALESCE(u.account_status, 'active') = 'active'");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Update basic club information fields.
    public function updateClub($id, $nom, $description) {
        $stmt = $this->db->prepare("UPDATE CLUBS SET nom = ?, description = ? WHERE id = ?");
        return $stmt->execute([$nom, $description, $id]);
    }

    // Update club fields and image when available.
    public function updateClubWithImage($id, $nom, $description, $image_path = null) {
        if ($this->hasImageColumn && $image_path !== null) {
            $stmt = $this->db->prepare("UPDATE CLUBS SET nom = ?, description = ?, image_path = ? WHERE id = ?");
            return $stmt->execute([$nom, $description, $image_path, $id]);
        }

        return $this->updateClub($id, $nom, $description);
    }

    // Assign a club to a new responsible user.
    public function assignResponsible($club_id, $responsable_id) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("UPDATE CLUBS SET responsable_id = ? WHERE id = ?");
            $stmt->execute([(int)$responsable_id, (int)$club_id]);

            $stmt = $this->db->prepare("INSERT IGNORE INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
            $stmt->execute([(int)$club_id, (int)$responsable_id]);

            $stmt = $this->db->prepare("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id) VALUES (?, ?)");
            $stmt->execute([(int)$club_id, (int)$responsable_id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Reassign every club owned by one user to another user.
    public function reassignClubsFromUser($fromUserId, $toUserId) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT id FROM CLUBS WHERE responsable_id = ?");
            $stmt->execute([(int)$fromUserId]);
            $clubIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $stmt = $this->db->prepare("UPDATE CLUBS SET responsable_id = ? WHERE responsable_id = ?");
            $stmt->execute([(int)$toUserId, (int)$fromUserId]);

            if (!empty($clubIds)) {
                $membershipStmt = $this->db->prepare("INSERT IGNORE INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
                $responsableStmt = $this->db->prepare("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id) VALUES (?, ?)");
                $cleanupStmt = $this->db->prepare("DELETE FROM CLUB_RESPONSABLES WHERE club_id = ? AND user_id = ?");
                foreach ($clubIds as $clubId) {
                    $membershipStmt->execute([$clubId, (int)$toUserId]);
                    $responsableStmt->execute([$clubId, (int)$toUserId]);
                    $cleanupStmt->execute([$clubId, (int)$fromUserId]);
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Reassign every responsibility mapping from one user to another.
    public function reassignAllResponsibilitiesFromUser($fromUserId, $toUserId) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT club_id FROM CLUB_RESPONSABLES WHERE user_id = ?");
            $stmt->execute([(int)$fromUserId]);
            $clubIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($clubIds)) {
                $addRespStmt = $this->db->prepare("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id) VALUES (?, ?)");
                $removeRespStmt = $this->db->prepare("DELETE FROM CLUB_RESPONSABLES WHERE club_id = ? AND user_id = ?");
                $addMemberStmt = $this->db->prepare("INSERT IGNORE INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
                $primaryStmt = $this->db->prepare("UPDATE CLUBS SET responsable_id = ? WHERE id = ? AND responsable_id = ?");

                foreach ($clubIds as $clubId) {
                    $addRespStmt->execute([$clubId, (int)$toUserId]);
                    $addMemberStmt->execute([$clubId, (int)$toUserId]);
                    $primaryStmt->execute([(int)$toUserId, $clubId, (int)$fromUserId]);
                    $removeRespStmt->execute([$clubId, (int)$fromUserId]);
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Delete a club and all dependent related records.
    public function deleteClub($id) {
        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }

        try {
            $this->db->beginTransaction();

            $eventIdsStmt = $this->db->prepare("SELECT id FROM EVENTS WHERE club_id = ?");
            $eventIdsStmt->execute([$id]);
            $eventIds = array_map('intval', $eventIdsStmt->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($eventIds)) {
                $placeholders = implode(', ', array_fill(0, count($eventIds), '?'));

                if ($this->tableExists('EVENT_REJOIN_COOLDOWNS')) {
                    $stmt = $this->db->prepare("DELETE FROM EVENT_REJOIN_COOLDOWNS WHERE event_id IN (" . $placeholders . ")");
                    $stmt->execute($eventIds);
                }

                $stmt = $this->db->prepare("DELETE FROM EVENT_PARTICIPANTS WHERE event_id IN (" . $placeholders . ")");
                $stmt->execute($eventIds);
            }

            $stmt = $this->db->prepare("DELETE FROM EVENTS WHERE club_id = ?");
            $stmt->execute([$id]);

            if ($this->tableExists('MEMBERSHIP_REQUEST_COOLDOWNS')) {
                $stmt = $this->db->prepare("DELETE FROM MEMBERSHIP_REQUEST_COOLDOWNS WHERE club_id = ?");
                $stmt->execute([$id]);
            }

            $stmt = $this->db->prepare("DELETE FROM MEMBERSHIP_REQUESTS WHERE club_id = ?");
            $stmt->execute([$id]);

            $stmt = $this->db->prepare("DELETE FROM CLUB_MEMBERS WHERE club_id = ?");
            $stmt->execute([$id]);

            $stmt = $this->db->prepare("DELETE FROM CLUB_RESPONSABLES WHERE club_id = ?");
            $stmt->execute([$id]);

            $stmt = $this->db->prepare("DELETE FROM CLUBS WHERE id = ?");
            $stmt->execute([$id]);

            $deleted = $stmt->rowCount() > 0;
            if (!$deleted) {
                $this->db->rollBack();
                return false;
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // List members belonging to a specific club.
    public function getMembers($club_id, $sortBy = 'date', $order = 'DESC') {
        $sortBy = strtolower((string)$sortBy);
        if (!in_array($sortBy, ['date', 'role'], true)) {
            $sortBy = 'date';
        }

        $orderDirection = strtoupper((string)$order) === 'ASC' ? 'ASC' : 'DESC';
        $roleOrderBy = "CASE WHEN cr.id IS NOT NULL THEN 1 ELSE 2 END " . $orderDirection . ", COALESCE(cm.date_adhesion, cr.assigned_at) DESC, u.id DESC";
        $dateOrderBy = "COALESCE(cm.date_adhesion, cr.assigned_at) " . $orderDirection . ", u.id " . $orderDirection;
        $orderBySql = $sortBy === 'role' ? $roleOrderBy : $dateOrderBy;
         $stmt = $this->db->prepare("SELECT u.id,
                             u.nom,
                             u.prenom,
                             u.email,
                             COALESCE(cm.date_adhesion, cr.assigned_at) AS date_adhesion,
                             CASE WHEN cr.id IS NOT NULL THEN 1 ELSE 0 END AS is_responsable,
                             CASE WHEN cm.id IS NOT NULL THEN 1 ELSE 0 END AS is_member
                         FROM USERS u
                         LEFT JOIN CLUB_MEMBERS cm
                             ON cm.user_id = u.id AND cm.club_id = ?
                         LEFT JOIN CLUB_RESPONSABLES cr
                             ON cr.user_id = u.id AND cr.club_id = ?
                         WHERE (cm.id IS NOT NULL OR cr.id IS NOT NULL)
                           AND COALESCE(u.account_status, 'active') = 'active'
                                                 ORDER BY " . $orderBySql);
         $stmt->execute([(int)$club_id, (int)$club_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Add one user as a club member.
    public function addMember($club_id, $user_id) {
        $statusStmt = $this->db->prepare("SELECT account_status FROM USERS WHERE id = ? LIMIT 1");
        $statusStmt->execute([(int)$user_id]);
        $status = (string)($statusStmt->fetchColumn() ?: 'inactive');
        if ($status !== 'active') {
            return false;
        }

        $stmt = $this->db->prepare("INSERT INTO CLUB_MEMBERS (club_id, user_id) 
                                    VALUES (?, ?)");
        return $stmt->execute([$club_id, $user_id]);
    }

    // Add a member directly, ignoring existing membership rows.
    public function addMemberDirect($club_id, $user_id) {
        try {
            $this->db->beginTransaction();

            $statusStmt = $this->db->prepare("SELECT account_status FROM USERS WHERE id = ? LIMIT 1");
            $statusStmt->execute([(int)$user_id]);
            $status = (string)($statusStmt->fetchColumn() ?: 'inactive');
            if ($status !== 'active') {
                $this->db->rollBack();
                return false;
            }

            $stmt = $this->db->prepare("DELETE FROM MEMBERSHIP_REQUESTS WHERE club_id = ? AND user_id = ?");
            $stmt->execute([(int)$club_id, (int)$user_id]);

            if ($this->tableExists('MEMBERSHIP_REQUEST_COOLDOWNS')) {
                $stmt = $this->db->prepare("DELETE FROM MEMBERSHIP_REQUEST_COOLDOWNS WHERE club_id = ? AND user_id = ?");
                $stmt->execute([(int)$club_id, (int)$user_id]);
            }

            $stmt = $this->db->prepare("INSERT IGNORE INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
            $stmt->execute([(int)$club_id, (int)$user_id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Count the number of clubs currently managed by a user.
    public function countResponsibleClubs($user_id) {
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT club_id) FROM CLUB_RESPONSABLES WHERE user_id = ?");
        $stmt->execute([(int)$user_id]);
        return (int)$stmt->fetchColumn();
    }

    // Remove one member from a club.
    public function removeMember($club_id, $user_id) {
        $stmt = $this->db->prepare("DELETE FROM CLUB_MEMBERS WHERE club_id = ? AND user_id = ?");
        return $stmt->execute([$club_id, $user_id]);
    }

    // Remove member and clean event subscriptions in that club.
    public function removeMemberWithEventSubscriptions($club_id, $user_id) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("DELETE ep
                                        FROM EVENT_PARTICIPANTS ep
                                        JOIN EVENTS e ON ep.event_id = e.id
                                        WHERE e.club_id = ? AND ep.user_id = ?");
            $stmt->execute([$club_id, $user_id]);

            $stmt = $this->db->prepare("DELETE FROM CLUB_MEMBERS WHERE club_id = ? AND user_id = ?");
            $stmt->execute([$club_id, $user_id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Check whether a user is already a club member.
    public function isMember($club_id, $user_id) {
        $stmt = $this->db->prepare("SELECT id FROM CLUB_MEMBERS WHERE club_id = ? AND user_id = ?");
        $stmt->execute([$club_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    // Check whether a user belongs to at least one club.
    public function isUserInAnyClub($user_id) {
                $stmt = $this->db->prepare("SELECT COUNT(*)
                                                                        FROM CLUB_MEMBERS cm
                                                                        JOIN USERS u ON u.id = cm.user_id
                                                                        WHERE cm.user_id = ?
                                                                            AND COALESCE(u.account_status, 'active') = 'active'");
        $stmt->execute([(int)$user_id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Get all clubs where the user is a member.
    public function getUserClubs($user_id) {
        $stmt = $this->db->prepare("SELECT c.* FROM CLUBS c 
                                    JOIN CLUB_MEMBERS cm ON c.id = cm.club_id 
                                                                        JOIN USERS u ON u.id = cm.user_id
                                                                        WHERE cm.user_id = ?
                                                                            AND COALESCE(u.account_status, 'active') = 'active'");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get member-only clubs excluding those owned by the user.
    public function getUserMemberOnlyClubs($user_id) {
        $stmt = $this->db->prepare("SELECT c.* FROM CLUBS c
                                    JOIN CLUB_MEMBERS cm ON c.id = cm.club_id
                                    JOIN USERS u ON u.id = cm.user_id
                                    WHERE cm.user_id = ?
                                      AND COALESCE(u.account_status, 'active') = 'active'
                                      AND c.id NOT IN (
                                        SELECT cr.club_id FROM CLUB_RESPONSABLES cr WHERE cr.user_id = ?
                                    )
                                    ORDER BY c.nom");
        $stmt->execute([$user_id, $user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get clubs managed by the given responsible user.
    public function getResponsibleClubs($user_id) {
        $stmt = $this->db->prepare("SELECT DISTINCT c.*
                                    FROM CLUBS c
                                    JOIN CLUB_RESPONSABLES cr ON c.id = cr.club_id
                                                                        JOIN USERS u ON u.id = cr.user_id
                                                                        WHERE cr.user_id = ?
                                                                            AND COALESCE(u.account_status, 'active') = 'active'
                                    ORDER BY c.nom");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Check if a user is one of the responsables for the club.
    public function isResponsible($club_id, $user_id) {
                $stmt = $this->db->prepare("SELECT cr.id
                                                                        FROM CLUB_RESPONSABLES cr
                                                                        JOIN USERS u ON u.id = cr.user_id
                                                                        WHERE cr.club_id = ? AND cr.user_id = ?
                                                                            AND COALESCE(u.account_status, 'active') = 'active'
                                                                        LIMIT 1");
        $stmt->execute([(int)$club_id, (int)$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    // Return all responsables attached to a club.
    public function getClubResponsables($club_id) {
        $stmt = $this->db->prepare("SELECT u.id, u.nom, u.prenom, u.email, u.role
                                    FROM CLUB_RESPONSABLES cr
                                    JOIN USERS u ON u.id = cr.user_id
                                    WHERE cr.club_id = ?
                                      AND COALESCE(u.account_status, 'active') = 'active'
                                    ORDER BY u.prenom, u.nom");
        $stmt->execute([(int)$club_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Add one responsable authority to a club.
    public function addClubResponsable($club_id, $user_id) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id) VALUES (?, ?)");
            $stmt->execute([(int)$club_id, (int)$user_id]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Remove one responsable and ensure at least one remains.
    public function removeClubResponsable($club_id, $user_id, $fallbackAdminId) {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("DELETE FROM CLUB_RESPONSABLES WHERE club_id = ? AND user_id = ?");
            $stmt->execute([(int)$club_id, (int)$user_id]);

            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM CLUB_RESPONSABLES WHERE club_id = ?");
            $countStmt->execute([(int)$club_id]);
            $remaining = (int)$countStmt->fetchColumn();

            if ($remaining <= 0) {
                $fallbackAdminId = (int)$fallbackAdminId;
                if ($fallbackAdminId <= 0) {
                    $this->db->rollBack();
                    return false;
                }

                $stmt = $this->db->prepare("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id) VALUES (?, ?)");
                $stmt->execute([(int)$club_id, $fallbackAdminId]);

                $stmt = $this->db->prepare("INSERT IGNORE INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
                $stmt->execute([(int)$club_id, $fallbackAdminId]);

                $stmt = $this->db->prepare("UPDATE CLUBS SET responsable_id = ? WHERE id = ?");
                $stmt->execute([$fallbackAdminId, (int)$club_id]);
            } else {
                $stmt = $this->db->prepare("SELECT user_id FROM CLUB_RESPONSABLES WHERE club_id = ? ORDER BY user_id ASC LIMIT 1");
                $stmt->execute([(int)$club_id]);
                $primaryId = (int)$stmt->fetchColumn();
                if ($primaryId > 0) {
                    $stmt = $this->db->prepare("UPDATE CLUBS SET responsable_id = ? WHERE id = ?");
                    $stmt->execute([$primaryId, (int)$club_id]);
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Remove all responsibilities of one user and keep at least one responsible per club.
    public function removeAllResponsibilitiesForUser($user_id, $fallbackAdminId, $archiveForRestore = false) {
        $user_id = (int)$user_id;
        $fallbackAdminId = (int)$fallbackAdminId;

        if ($user_id <= 0 || $fallbackAdminId <= 0) {
            return false;
        }

        try {
            $this->db->beginTransaction();

            $clubsStmt = $this->db->prepare("SELECT DISTINCT club_id FROM CLUB_RESPONSABLES WHERE user_id = ?");
            $clubsStmt->execute([$user_id]);
            $clubIds = array_map('intval', $clubsStmt->fetchAll(PDO::FETCH_COLUMN));

            if (empty($clubIds)) {
                if (!$archiveForRestore) {
                    $this->clearResponsibilityArchiveForUser($user_id, false);
                }
                $this->db->commit();
                return true;
            }

            $deleteRespStmt = $this->db->prepare("DELETE FROM CLUB_RESPONSABLES WHERE club_id = ? AND user_id = ?");
            $countRespStmt = $this->db->prepare("SELECT COUNT(*) FROM CLUB_RESPONSABLES WHERE club_id = ?");
            $insertRespStmt = $this->db->prepare("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id) VALUES (?, ?)");
            $insertMemberStmt = $this->db->prepare("INSERT IGNORE INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
            $firstRespStmt = $this->db->prepare("SELECT user_id FROM CLUB_RESPONSABLES WHERE club_id = ? ORDER BY user_id ASC LIMIT 1");
            $setPrimaryStmt = $this->db->prepare("UPDATE CLUBS SET responsable_id = ? WHERE id = ?");
            $archiveStmt = $this->db->prepare("INSERT IGNORE INTO CLUB_RESPONSABLES_ARCHIVE (user_id, club_id) VALUES (?, ?)");

            foreach ($clubIds as $clubId) {
                if ($archiveForRestore) {
                    $archiveStmt->execute([$user_id, $clubId]);
                }

                $deleteRespStmt->execute([$clubId, $user_id]);

                $countRespStmt->execute([$clubId]);
                $remaining = (int)$countRespStmt->fetchColumn();

                if ($remaining <= 0) {
                    $insertRespStmt->execute([$clubId, $fallbackAdminId]);
                    $insertMemberStmt->execute([$clubId, $fallbackAdminId]);
                    $setPrimaryStmt->execute([$fallbackAdminId, $clubId]);
                } else {
                    $firstRespStmt->execute([$clubId]);
                    $primaryId = (int)$firstRespStmt->fetchColumn();
                    if ($primaryId > 0) {
                        $setPrimaryStmt->execute([$primaryId, $clubId]);
                    }
                }
            }

            if (!$archiveForRestore) {
                $this->clearResponsibilityArchiveForUser($user_id, false);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Restore archived responsibilities when an inactive responsible becomes active again.
    public function restoreArchivedResponsibilitiesForUser($user_id) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) {
            return false;
        }

        try {
            $this->db->beginTransaction();

            $archiveStmt = $this->db->prepare("SELECT club_id FROM CLUB_RESPONSABLES_ARCHIVE WHERE user_id = ?");
            $archiveStmt->execute([$user_id]);
            $clubIds = array_map('intval', $archiveStmt->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($clubIds)) {
                $insertRespStmt = $this->db->prepare("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id) VALUES (?, ?)");
                $insertMemberStmt = $this->db->prepare("INSERT IGNORE INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");

                foreach ($clubIds as $clubId) {
                    $insertRespStmt->execute([$clubId, $user_id]);
                    $insertMemberStmt->execute([$clubId, $user_id]);
                }
            }

            $this->clearResponsibilityArchiveForUser($user_id, false);
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Clear archived responsibility links for one user.
    public function clearResponsibilityArchiveForUser($user_id, $useTransaction = true) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) {
            return false;
        }

        try {
            if ($useTransaction) {
                $this->db->beginTransaction();
            }

            $stmt = $this->db->prepare("DELETE FROM CLUB_RESPONSABLES_ARCHIVE WHERE user_id = ?");
            $stmt->execute([$user_id]);

            if ($useTransaction) {
                $this->db->commit();
            }
            return true;
        } catch (Exception $e) {
            if ($useTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    // Search clubs by name or description text.
    public function searchClubs($query) {
        $stmt = $this->db->prepare("SELECT c.*, u.nom AS responsable_nom, u.prenom FROM CLUBS c 
                                    JOIN USERS u ON c.responsable_id = u.id 
                                    WHERE c.nom LIKE ? OR c.description LIKE ? 
                                    ORDER BY c.nom");
        $search = "%$query%";
        $stmt->execute([$search, $search]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
