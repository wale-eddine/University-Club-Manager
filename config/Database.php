<?php

class Database {
    private $host = 'localhost';
    private $db_name = 'university_clubs';
    private $user = 'root';
    private $password = '';
    private $pdo;

    // Ensures USERS.role enum includes responsable for role-based permissions.
    private function ensureUsersRoleEnum() {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM USERS LIKE 'role'");
            $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $type = strtolower((string)($column['Type'] ?? ''));

            if ($type !== '' && strpos($type, "'responsable'") === false) {
                $this->pdo->exec("ALTER TABLE USERS MODIFY role ENUM('admin', 'responsable', 'etudiant') DEFAULT 'etudiant'");
            }
        } catch (Exception $e) {
            // Keep app running if role migration cannot be applied automatically.
        }
    }

    // Add account lifecycle fields to USERS when missing.
    private function ensureUsersManagementColumns() {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM USERS LIKE 'account_status'");
            $hasAccountStatus = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            if (!$hasAccountStatus) {
                $this->pdo->exec("ALTER TABLE USERS ADD COLUMN account_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER role");
            }

            $stmt = $this->pdo->query("SHOW COLUMNS FROM USERS LIKE 'inactive_reason'");
            $hasInactiveReason = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            if (!$hasInactiveReason) {
                $this->pdo->exec("ALTER TABLE USERS ADD COLUMN inactive_reason VARCHAR(255) NULL AFTER account_status");
            }

            $this->pdo->exec("UPDATE USERS SET account_status = 'active' WHERE account_status IS NULL");
        } catch (Exception $e) {
            // Keep app running if automatic column migration fails.
        }
    }

    // Add special_id columns used by admin traceability.
    private function ensureSpecialIdColumns() {
        try {
            $tables = [
                'USERS' => "ALTER TABLE USERS ADD COLUMN special_id VARCHAR(255) NULL AFTER email",
                'CLUBS' => "ALTER TABLE CLUBS ADD COLUMN special_id VARCHAR(255) NULL AFTER nom",
                'EVENTS' => "ALTER TABLE EVENTS ADD COLUMN special_id VARCHAR(255) NULL AFTER titre",
            ];

            foreach ($tables as $table => $sql) {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM " . $table . " LIKE 'special_id'");
                $hasColumn = $stmt && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
                if (!$hasColumn) {
                    $this->pdo->exec($sql);
                }
            }

            $this->pdo->exec("UPDATE USERS
                              SET special_id = CONCAT(DATE_FORMAT(COALESCE(created_at, NOW()), '%y/%m/%d'), '/', LOWER(email), '/', COALESCE(account_status, 'active'))
                              WHERE special_id IS NULL OR special_id = ''");

            $this->pdo->exec("UPDATE CLUBS c
                              JOIN USERS u ON u.id = c.responsable_id
                              SET c.special_id = CONCAT(DATE_FORMAT(COALESCE(c.created_at, NOW()), '%y/%m/%d'), '/', LOWER(u.email), '/active')
                              WHERE c.special_id IS NULL OR c.special_id = ''");

            $this->pdo->exec("UPDATE EVENTS e
                              JOIN CLUBS c ON c.id = e.club_id
                              JOIN USERS u ON u.id = c.responsable_id
                              SET e.special_id = CONCAT(DATE_FORMAT(COALESCE(e.created_at, NOW()), '%y/%m/%d'), '/', LOWER(u.email), '/active')
                              WHERE e.special_id IS NULL OR e.special_id = ''");
        } catch (Exception $e) {
            // Keep app running if special_id migration cannot be applied automatically.
        }
    }

    // Create many-to-many responsibilities table for clubs.
    private function ensureClubResponsablesTable() {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS CLUB_RESPONSABLES (
                                id INT PRIMARY KEY AUTO_INCREMENT,
                                club_id INT NOT NULL,
                                user_id INT NOT NULL,
                                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                UNIQUE KEY unique_club_responsable (club_id, user_id),
                                FOREIGN KEY (club_id) REFERENCES CLUBS(id) ON DELETE CASCADE,
                                FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE
                            )");

            $this->pdo->exec("INSERT IGNORE INTO CLUB_RESPONSABLES (club_id, user_id)
                              SELECT id, responsable_id FROM CLUBS WHERE responsable_id IS NOT NULL");
        } catch (Exception $e) {
            // Keep app running if responsibility table migration fails.
        }
    }

    // Establishes and returns a PDO connection to the MySQL database.
    public function connect() {
        try {
            $this->pdo = new PDO(
                'mysql:host=' . $this->host . ';dbname=' . $this->db_name,
                $this->user,
                $this->password
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->ensureUsersRoleEnum();
            $this->ensureUsersManagementColumns();
            $this->ensureSpecialIdColumns();
            $this->ensureClubResponsablesTable();
            return $this->pdo;
        } catch (PDOException $e) {
            die('Database connection error: ' . $e->getMessage());
        }
    }

    // Returns the active PDO connection, creating it if needed.
    public function getConnection() {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }
}
?>
