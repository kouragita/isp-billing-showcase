<?php
/**
 * TECH CONNECT WiFi Admin Authentication System
 * Secure admin panel access with session management
 */

session_start();

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $envContent = file_get_contents(__DIR__ . '/../.env');
    $envLines = explode("\n", $envContent);
    foreach ($envLines as $line) {
        if (strpos($line, '=') !== false && !startsWith($line, '#')) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

function startsWith($haystack, $needle) {
    return strpos($haystack, $needle) === 0;
}

class AdminAuth {
    private $db;
    
    public function __construct() {
        $this->db = $this->initDatabase();
        $this->createAdminUsersTable();
    }
    
    private function initDatabase() {
        try {
            $dbFile = __DIR__ . '/data/wifi_billing.db';
            $pdo = new PDO("sqlite:$dbFile");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    private function createAdminUsersTable() {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                role VARCHAR(20) DEFAULT 'admin',
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_login DATETIME NULL,
                login_attempts INTEGER DEFAULT 0,
                locked_until DATETIME NULL
            )";
            
            $this->db->exec($sql);
            
            // Create default admin user if none exists
            $this->createDefaultAdmin();
            
        } catch (PDOException $e) {
            error_log("Failed to create admin_users table: " . $e->getMessage());
        }
    }
    
    private function createDefaultAdmin() {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users");
            $stmt->execute();
            $count = $stmt->fetchColumn();
            
            if ($count == 0) {
                // Create default admin user
                $username = 'admin';
                $email = 'admin@techconnect.com';
                $password = 'your_password'; // Change this in production
                $fullName = 'System Administrator';
                
                $this->createAdminUser($username, $email, $password, $fullName);
                error_log("✅ Default admin user created - Username: admin, Password: your_password");
            }
        } catch (PDOException $e) {
            error_log("Failed to create default admin: " . $e->getMessage());
        }
    }
    
    public function createAdminUser($username, $email, $password, $fullName, $role = 'admin') {
        try {
            $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
            
            $stmt = $this->db->prepare("
                INSERT INTO admin_users (username, email, password_hash, full_name, role) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            return $stmt->execute([$username, $email, $passwordHash, $fullName, $role]);
        } catch (PDOException $e) {
            throw new Exception("Failed to create admin user: " . $e->getMessage());
        }
    }
    
    public function authenticate($username, $password) {
        try {
            // Check for account lockout
            $stmt = $this->db->prepare("
                SELECT * FROM admin_users 
                WHERE username = ? AND is_active = 1
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            // Check if account is locked
            if ($user['locked_until'] && new DateTime() < new DateTime($user['locked_until'])) {
                return ['success' => false, 'message' => 'Account temporarily locked. Try again later.'];
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                $this->incrementLoginAttempts($user['id']);
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            // Reset login attempts and update last login
            $this->resetLoginAttempts($user['id']);
            $this->updateLastLogin($user['id']);
            
            // Create session
            $this->createSession($user);
            
            return ['success' => true, 'user' => $user];
            
        } catch (Exception $e) {
            error_log("Authentication error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Authentication failed'];
        }
    }
    
    private function incrementLoginAttempts($userId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE admin_users 
                SET login_attempts = login_attempts + 1,
                    locked_until = CASE 
                        WHEN login_attempts >= 4 THEN datetime('now', '+15 minutes')
                        ELSE locked_until 
                    END
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            error_log("Failed to increment login attempts: " . $e->getMessage());
        }
    }
    
    private function resetLoginAttempts($userId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE admin_users 
                SET login_attempts = 0, locked_until = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            error_log("Failed to reset login attempts: " . $e->getMessage());
        }
    }
    
    private function updateLastLogin($userId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE admin_users 
                SET last_login = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            error_log("Failed to update last login: " . $e->getMessage());
        }
    }
    
    private function createSession($user) {
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_user_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_full_name'] = $user['full_name'];
        $_SESSION['admin_role'] = $user['role'];
        $_SESSION['admin_login_time'] = time();
        
        // Regenerate session ID for security
        session_regenerate_id(true);
    }
    
    public function isAuthenticated() {
        if (!isset($_SESSION['admin_authenticated']) || !$_SESSION['admin_authenticated']) {
            return false;
        }
        
        // Check session timeout (8 hours)
        if (isset($_SESSION['admin_login_time'])) {
            $sessionAge = time() - $_SESSION['admin_login_time'];
            if ($sessionAge > 28800) { // 8 hours
                $this->logout();
                return false;
            }
        }
        
        return true;
    }
    
    public function logout() {
        session_unset();
        session_destroy();
        session_start(); // Start fresh session
    }
    
    public function requireAuth() {
        if (!$this->isAuthenticated()) {
            header('Location: admin_login.php');
            exit;
        }
    }
    
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['admin_user_id'],
            'username' => $_SESSION['admin_username'],
            'full_name' => $_SESSION['admin_full_name'],
            'role' => $_SESSION['admin_role']
        ];
    }
    
    public function changePassword($userId, $currentPassword, $newPassword) {
        try {
            $stmt = $this->db->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($currentPassword, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
            
            $newPasswordHash = password_hash($newPassword, PASSWORD_ARGON2ID);
            $stmt = $this->db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$newPasswordHash, $userId]);
            
            return ['success' => true, 'message' => 'Password changed successfully'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Failed to change password'];
        }
    }
}

// Handle AJAX authentication requests
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $auth = new AdminAuth();
    
    switch ($_POST['action']) {
        case 'login':
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                echo json_encode(['success' => false, 'message' => 'Username and password required']);
                exit;
            }
            
            $result = $auth->authenticate($username, $password);
            echo json_encode($result);
            break;
            
        case 'logout':
            $auth->logout();
            echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
            break;
            
        case 'check_auth':
            $isAuth = $auth->isAuthenticated();
            $user = $isAuth ? $auth->getCurrentUser() : null;
            echo json_encode(['authenticated' => $isAuth, 'user' => $user]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}
?>