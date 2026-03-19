<?php
class Database {
    private $db_file;
    private $pdo;
    
    public function __construct() {
        $this->db_file = __DIR__ . '/../prdp_database.sqlite';
        $this->connect();
        $this->migrate(); // Add this line
    }
    
    private function connect() {
        try {
            $this->pdo = new PDO("sqlite:" . $this->db_file);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
    
    // Add this migration method
    private function migrate() {
        try {
            // Check if allotment column exists
            $stmt = $this->pdo->query("PRAGMA table_info(funds)");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            
            if (!in_array('allotment', $columns)) {
                $this->pdo->exec("ALTER TABLE funds ADD COLUMN allotment DECIMAL(15,2) DEFAULT 0");
                echo "Added allotment column<br>";
            }
            
            if (!in_array('obligated', $columns)) {
                $this->pdo->exec("ALTER TABLE funds ADD COLUMN obligated DECIMAL(15,2) DEFAULT 0");
                echo "Added obligated column<br>";
            }
            
            if (!in_array('disbursed', $columns)) {
                $this->pdo->exec("ALTER TABLE funds ADD COLUMN disbursed DECIMAL(15,2) DEFAULT 0");
                echo "Added disbursed column<br>";
            }
            
            if (!in_array('balance', $columns)) {
                $this->pdo->exec("ALTER TABLE funds ADD COLUMN balance DECIMAL(15,2) DEFAULT 0");
                echo "Added balance column<br>";
            }
            
        } catch(PDOException $e) {
            // Columns might already exist, ignore error
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    public function initializeDatabase() {
        $sql = file_get_contents(__DIR__ . '/../database.sql');
        try {
            $this->pdo->exec($sql);
            return true;
        } catch(PDOException $e) {
            return false;
        }
    }
}

// Initialize database on first run
$db = new Database();
$db->initializeDatabase();
?>