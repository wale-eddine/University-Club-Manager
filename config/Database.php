<?php

class Database {
    private $host = 'localhost';
    private $db_name = 'university_clubs';
    private $user = 'root';
    private $password = '';
    private $pdo;

    // Establishes and returns a PDO connection to the MySQL database.
    public function connect() {
        try {
            $this->pdo = new PDO(
                'mysql:host=' . $this->host . ';dbname=' . $this->db_name,
                $this->user,
                $this->password
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
