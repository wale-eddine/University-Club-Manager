<?php

class Club {
    private $db;
    private $hasImageColumn = false;

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

            if ($this->hasImageColumn) {
                $stmt = $this->db->prepare("INSERT INTO CLUBS (nom, description, image_path, responsable_id) 
                                            VALUES (?, ?, ?, ?)");
                $stmt->execute([$nom, $description, $image_path, $responsable_id]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO CLUBS (nom, description, responsable_id) 
                                            VALUES (?, ?, ?)");
                $stmt->execute([$nom, $description, $responsable_id]);
            }

            $club_id = (int)$this->db->lastInsertId();
            $stmt = $this->db->prepare("INSERT INTO CLUB_MEMBERS (club_id, user_id) VALUES (?, ?)");
            $stmt->execute([$club_id, $responsable_id]);

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
                                    ORDER BY c.nom");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Return one club by id with responsible user info.
    public function getClubById($id) {
        $stmt = $this->db->prepare("SELECT c.*, u.nom AS responsable_nom, u.prenom FROM CLUBS c 
                                    JOIN USERS u ON c.responsable_id = u.id 
                                    WHERE c.id = ?");
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
    public function getMembers($club_id, $order = 'DESC') {
        $orderDirection = strtoupper((string)$order) === 'ASC' ? 'ASC' : 'DESC';
        $stmt = $this->db->prepare("SELECT u.id, u.nom, u.prenom, u.email, cm.date_adhesion 
                                    FROM CLUB_MEMBERS cm 
                                    JOIN USERS u ON cm.user_id = u.id 
                                    WHERE cm.club_id = ? ORDER BY cm.date_adhesion " . $orderDirection);
        $stmt->execute([$club_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Add one user as a club member.
    public function addMember($club_id, $user_id) {
        $stmt = $this->db->prepare("INSERT INTO CLUB_MEMBERS (club_id, user_id) 
                                    VALUES (?, ?)");
        return $stmt->execute([$club_id, $user_id]);
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

    // Get all clubs where the user is a member.
    public function getUserClubs($user_id) {
        $stmt = $this->db->prepare("SELECT c.* FROM CLUBS c 
                                    JOIN CLUB_MEMBERS cm ON c.id = cm.club_id 
                                    WHERE cm.user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get member-only clubs excluding those owned by the user.
    public function getUserMemberOnlyClubs($user_id) {
        $stmt = $this->db->prepare("SELECT c.* FROM CLUBS c
                                    JOIN CLUB_MEMBERS cm ON c.id = cm.club_id
                                    WHERE cm.user_id = ? AND c.responsable_id <> ?
                                    ORDER BY c.nom");
        $stmt->execute([$user_id, $user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get clubs managed by the given responsible user.
    public function getResponsibleClubs($user_id) {
        $stmt = $this->db->prepare("SELECT c.* FROM CLUBS c WHERE c.responsable_id = ? ORDER BY c.nom");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
