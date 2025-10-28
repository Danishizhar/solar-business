<?php
class Database {
    private $host = "localhost";
    private $database_name = "sbm";
    private $username = "root";
    private $password = "";
    private $conn;

    public function getConnection() {
        try {
            // Close existing connection if any
            if ($this->conn !== null) {
                $this->conn = null;
            }

            // Create new connection
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->database_name, 
                $this->username, 
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false
                ]
            );

            return $this->conn;
        } catch(PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            if (strpos($_SERVER['REQUEST_URI'], '/actions/') !== false) {
                // For AJAX requests, return JSON error
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                echo json_encode([
                    'success' => false,
                    'message' => 'Database connection error. Please try again later.'
                ]);
                exit();
            } else {
                // For regular page loads, throw exception
                throw new PDOException('Unable to connect to database. Please try again later.');
            }
        }
    }

    public function beginTransaction() {
        if (!$this->conn) {
            return false;
        }
        try {
            return $this->conn->beginTransaction();
        } catch (PDOException $e) {
            error_log("Transaction Start Error: " . $e->getMessage());
            return false;
        }
    }

    public function commit() {
        if (!$this->conn) {
            return false;
        }
        try {
            return $this->conn->commit();
        } catch (PDOException $e) {
            error_log("Transaction Commit Error: " . $e->getMessage());
            return false;
        }
    }

    public function rollBack() {
        if (!$this->conn || !$this->conn->inTransaction()) {
            return false;
        }
        try {
            return $this->conn->rollBack();
        } catch (PDOException $e) {
            error_log("Transaction Rollback Error: " . $e->getMessage());
            return false;
        }
    }

    public function __destruct() {
        // Close connection when object is destroyed
        $this->conn = null;
    }
}
?>
