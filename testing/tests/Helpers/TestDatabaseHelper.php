<?php
/**
 * Test Database Helper
 * 
 * Provides database setup and teardown for integration tests
 */

class TestDatabaseHelper {
    
    private static $connection = null;
    
    /**
     * Setup test database with sample data
     */
    public static function setupTestDatabase(): void {
        // Create test tables and sample data
        self::createTestTables();
        self::insertTestData();
    }
    
    /**
     * Clean up test database
     */
    public static function cleanupTestDatabase(): void {
        if (self::$connection) {
            // Clean up test data
            $tables = ['BMESSAGES', 'BUSER', 'BAPIKEYS', 'BCONFIG', 'BMESSAGEMETA'];
            foreach ($tables as $table) {
                mysqli_query(self::$connection, "DELETE FROM $table WHERE 1=1");
            }
        }
    }
    
    /**
     * Get test database connection
     */
    public static function getConnection() {
        if (!self::$connection) {
            $host = getenv('DB_HOST') ?: 'localhost';
            $name = getenv('DB_NAME') ?: 'synaplan_test';
            $user = getenv('DB_USER') ?: 'synaplan_test_user';
            $password = getenv('DB_PASSWORD') ?: 'synaplan_test_password';
            
            self::$connection = mysqli_connect($host, $user, $password, $name);
            if (!self::$connection) {
                throw new Exception("Could not connect to test database");
            }
        }
        return self::$connection;
    }
    
    /**
     * Create test tables (simplified for testing)
     */
    private static function createTestTables(): void {
        $connection = self::getConnection();
        
        // Create BUSER table
        $sql = "CREATE TABLE IF NOT EXISTS BUSER (
            BID INT AUTO_INCREMENT PRIMARY KEY,
            BCREATED VARCHAR(20),
            BINTYPE VARCHAR(10),
            BMAIL VARCHAR(255),
            BPW VARCHAR(255),
            BPROVIDERID VARCHAR(255),
            BUSERLEVEL VARCHAR(50),
            BUSERDETAILS TEXT
        )";
        mysqli_query($connection, $sql);
        
        // Create BAPIKEYS table
        $sql = "CREATE TABLE IF NOT EXISTS BAPIKEYS (
            BID INT AUTO_INCREMENT PRIMARY KEY,
            BOWNERID INT,
            BNAME VARCHAR(255),
            BKEY VARCHAR(255),
            BSTATUS VARCHAR(20),
            BCREATED INT,
            BLASTUSED INT
        )";
        mysqli_query($connection, $sql);
        
        // Create BMESSAGES table
        $sql = "CREATE TABLE IF NOT EXISTS BMESSAGES (
            BID INT AUTO_INCREMENT PRIMARY KEY,
            BUSERID INT,
            BTEXT TEXT,
            BFILE INT DEFAULT 0,
            BFILEPATH VARCHAR(255),
            BFILETYPE VARCHAR(50),
            BDIRECT VARCHAR(10),
            BTRACKID BIGINT,
            BUNIXTIMES INT,
            BDATETIME VARCHAR(20),
            BTOPIC VARCHAR(255),
            BMESSTYPE VARCHAR(20),
            BSTATUS VARCHAR(20)
        )";
        mysqli_query($connection, $sql);
    }
    
    /**
     * Insert test data
     */
    private static function insertTestData(): void {
        $connection = self::getConnection();
        
        // Insert test user
        $sql = "INSERT INTO BUSER (BID, BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID, BUSERLEVEL, BUSERDETAILS) 
                VALUES (12345, '20240101120000', 'MAIL', 'test@example.com', '" . md5('password123') . "', 'test@example.com', 'ACTIVE', '{\"firstName\":\"Test\",\"lastName\":\"User\"}')";
        mysqli_query($connection, $sql);
        
        // Insert test API key
        $sql = "INSERT INTO BAPIKEYS (BID, BOWNERID, BNAME, BKEY, BSTATUS, BCREATED, BLASTUSED) 
                VALUES (1, 12345, 'Test Key', 'sk_live_test123456789', 'active', " . time() . ", 0)";
        mysqli_query($connection, $sql);
        
        // Insert test message
        $sql = "INSERT INTO BMESSAGES (BID, BUSERID, BTEXT, BDIRECT, BTRACKID, BUNIXTIMES, BDATETIME, BTOPIC, BMESSTYPE, BSTATUS) 
                VALUES (1, 12345, 'Test message', 'IN', 123456789, " . time() . ", '" . date('YmdHis') . "', 'test', 'WEB', 'NEW')";
        mysqli_query($connection, $sql);
    }
}
