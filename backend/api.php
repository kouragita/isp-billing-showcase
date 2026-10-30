<?php
/**
 * TECH CONNECT WiFi Billing System - Backend API
 * M-Pesa Integration for WiFi Hotspot
 * Business: TECH CONNECT WiFi (YOUR_CUSTOMER_CARE_NUMBER)
 * 
 * This file handles:
 * - M-Pesa STK Push payments
 * - User session management
 * - Voucher system
 * - Database operations
 */

// Comprehensive error handling to ensure JSON responses
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && $error['type'] === E_ERROR) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error: ' . $error['message'],
            'timestamp' => date('c')
        ]);
        exit();
    }
});

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, only log them

/**
 * Configuration Class - Secure Environment Variable Loading
 */
class Config {
    private static $envLoaded = false;
    
    /**
     * Load environment variables from .env file (for local development)
     * In production, these come from the host's environment settings
     */
    private static function loadEnvFile() {
        if (self::$envLoaded) return;
        
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && $line[0] !== '#') {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (!getenv($key)) {
                        putenv("$key=$value");
                    }
                }
            }
        }
        self::$envLoaded = true;
    }
    
    /**
     * Get environment variable with error handling
     */
    private static function getEnvVariable($key, $required = true) {
        self::loadEnvFile();
        $value = getenv($key);
        
        if (!$value && $required) {
            error_log("CRITICAL: Required environment variable '$key' is not set");
            throw new Exception("Configuration error: '$key' environment variable is required");
        }
        
        return $value;
    }
    
    // M-Pesa Configuration - Secure Environment Variables Only
    public static function getMpesaEnvironment() {
        return self::getEnvVariable('MPESA_ENVIRONMENT', false) ?: 'production';
    }
    
    public static function getMpesaConsumerKey() {
        return self::getEnvVariable('MPESA_CONSUMER_KEY');
    }
    
    public static function getMpesaConsumerSecret() {
        return self::getEnvVariable('MPESA_CONSUMER_SECRET');
    }
    
    public static function getMpesaBusinessShortcode() {
        return self::getEnvVariable('MPESA_BUSINESS_SHORTCODE');
    }
    
    public static function getMpesaPasskey() {
        return self::getEnvVariable('MPESA_PASSKEY');
    }
    
    public static function getMpesaCallbackUrl() {
        $callbackUrl = self::getEnvVariable('MPESA_CALLBACK_URL');
        
        // Fallback to localhost for development
        if (empty($callbackUrl)) {
            $callbackUrl = 'http://localhost:8000/backend/callback.php';
            error_log("🔧 Using fallback callback URL: $callbackUrl");
        }
        
        return $callbackUrl;
    }
    
    public static function getMpesaTillNumber() {
        return self::getEnvVariable('MPESA_TILL_NUMBER');
    }
    
    public static function getAdminKey() {
        return self::getEnvVariable('ADMIN_SECRET_KEY');
    }
    
    public static function getAllowedOrigins() {
        // Get requesting origin
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Get configured origins
        $configuredOrigins = self::getEnvVariable('ALLOWED_ORIGINS', false);
        
        // Detect development environment
        $isDevelopment = (
            $_SERVER['SERVER_NAME'] === 'localhost' ||
            $_SERVER['SERVER_NAME'] === '127.0.0.1' ||
            strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
            !isset($_SERVER['HTTPS'])
        );
        
        // Build allowed origins array
        $allowedOrigins = [];
        
        if ($isDevelopment) {
            // Development: add localhost origins
            $allowedOrigins = [
                'http://localhost:8000',
                'http://localhost:8090', 
                'http://localhost:3000',
                'http://127.0.0.1:8000',
                'http://127.0.0.1:8090'
            ];
        }
        
        // Add production origins
        if ($configuredOrigins) {
            $prodOrigins = explode(',', $configuredOrigins);
            $allowedOrigins = array_merge($allowedOrigins, array_map('trim', $prodOrigins));
        } else {
            $allowedOrigins[] = 'http://localhost:8000';
        }
        
        // Return the requesting origin if it's in our allowed list
        if (in_array($requestOrigin, $allowedOrigins)) {
            return $requestOrigin;
        }
        
        // For non-browser requests or development fallback
        return getenv('APP_URL') ?: 'http://localhost:8000';
    }
    
    // Database Configuration
    const DB_PATH = __DIR__ . '/data/wifi_billing.db';
    
    // Get database connection URL
    public static function getDatabaseUrl() {
        // Check multiple ways to get PostgreSQL URL
        $databaseUrl = getenv('DATABASE_URL') ?: $_ENV['DATABASE_URL'] ?? null;
        
        // Log database detection for debugging
        error_log("Database URL detection: " . ($databaseUrl ? 'PostgreSQL found' : 'PostgreSQL not found, using SQLite'));
        
        if ($databaseUrl) {
            return $databaseUrl;
        }
        
        // Fallback to SQLite for local development
        return 'sqlite:' . self::DB_PATH;
    }
    
    // Convert PostgreSQL URI to PDO DSN format
    public static function convertPostgreSQLUriToPdoDsn($uri) {
        // Parse the PostgreSQL URI
        $parsed = parse_url($uri);
        
        if (!$parsed || $parsed['scheme'] !== 'postgresql') {
            throw new Exception('Invalid PostgreSQL URI format');
        }
        
        // Build PDO DSN in correct format
        $dsn = 'pgsql:';
        $dsn .= 'host=' . ($parsed['host'] ?? 'localhost');
        
        if (isset($parsed['port'])) {
            $dsn .= ';port=' . $parsed['port'];
        }
        
        if (isset($parsed['path'])) {
            $dbname = ltrim($parsed['path'], '/');
            $dsn .= ';dbname=' . $dbname;
        }
        
        if (isset($parsed['user'])) {
            $dsn .= ';user=' . $parsed['user'];
        }
        
        if (isset($parsed['pass'])) {
            $dsn .= ';password=' . $parsed['pass'];
        }
        
        return $dsn;
    }
    
    // Business Configuration
    const BUSINESS_NAME = 'TECH CONNECT WiFi';
    const CUSTOMER_CARE = 'YOUR_CUSTOMER_CARE_NUMBER';
    
    // URLs
    public static function getMpesaBaseUrl() {
        return self::getMpesaEnvironment() === 'production' 
            ? 'https://api.safaricom.co.ke' 
            : 'https://sandbox.safaricom.co.ke';
    }
    
    // Africa's Talking SMS Configuration
    public static function getAfricasTalkingUsername() {
        return self::getEnvVariable('AFRICASTALKING_USERNAME');
    }
    
    public static function getAfricasTalkingApiKey() {
        return self::getEnvVariable('AFRICASTALKING_API_KEY');
    }
    
    public static function getAfricasTalkingSenderId() {
        return self::getEnvVariable('AFRICASTALKING_SENDER_ID', false) ?: 'TECHCON';
    }
    
    /**
     * Get current EAT (East Africa Time) timestamp
     * Kenya timezone is UTC+3
     */
    public static function getEATTime($format = 'Y-m-d H:i:s') {
        $timezone = new DateTimeZone('Africa/Nairobi');
        $datetime = new DateTime('now', $timezone);
        return $datetime->format($format);
    }
    
    /**
     * Get EAT datetime string for SQLite
     */
    public static function getEATDateTime() {
        return self::getEATTime('Y-m-d H:i:s');
    }
    
    /**
     * Convert UTC timestamp to EAT
     */
    public static function convertToEAT($timestamp, $format = 'Y-m-d H:i:s') {
        $timezone = new DateTimeZone('Africa/Nairobi');
        $datetime = new DateTime($timestamp, new DateTimeZone('UTC'));
        $datetime->setTimezone($timezone);
        return $datetime->format($format);
    }
}

// CORS headers for frontend communication - Secure Configuration
header('Access-Control-Allow-Origin: ' . Config::getAllowedOrigins());
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight requests (only in web context)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * M-Pesa Integration Class
 */
class MpesaIntegration {
    
    private $access_token;
    
    public function __construct() {
        $this->access_token = $this->getAccessToken();
    }
    
    /**
     * Get OAuth Token from Safaricom - Public for testing
     */
    public function getAccessToken() {
        $url = Config::getMpesaBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials';
        
        $credentials = base64_encode(Config::getMpesaConsumerKey() . ':' . Config::getMpesaConsumerSecret());
        
        error_log("Requesting M-Pesa access token from: $url");
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        
        error_log("M-Pesa OAuth Response - HTTP Code: $httpCode, Body: $result");
        
        if ($curlError) {
            error_log("M-Pesa OAuth cURL Error: $curlError");
            throw new Exception('M-Pesa OAuth request failed: ' . $curlError);
        }
        
        if ($httpCode !== 200) {
            error_log("M-Pesa OAuth HTTP Error: $httpCode - $result");
            throw new Exception("Failed to get M-Pesa access token. HTTP Code: $httpCode");
        }
        
        $response = json_decode($result, true);
        
        if (!$response || !isset($response['access_token'])) {
            error_log("M-Pesa OAuth Invalid Response: $result");
            throw new Exception('Invalid M-Pesa OAuth response');
        }
        
        $token = $response['access_token'];
        error_log("M-Pesa access token obtained successfully");
        return $token;
    }
    
    /**
     * Initiate STK Push
     */
    public function initiateSTKPush($phone, $amount, $account_reference, $transaction_desc) {
        if (!$this->access_token) {
            throw new Exception('No access token available');
        }
        
        $url = Config::getMpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest';
        
        $timestamp = date('YmdHis');
        $password = base64_encode(Config::getMpesaBusinessShortcode() . Config::getMpesaPasskey() . $timestamp);
        
        // Format phone number
        $phone = $this->formatPhoneNumber($phone);
        
        $curl_post_data = [
            'BusinessShortCode' => Config::getMpesaBusinessShortcode(),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerBuyGoodsOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => Config::getMpesaTillNumber(),
            'PhoneNumber' => $phone,
            'CallBackURL' => Config::getMpesaCallbackUrl(),
            'AccountReference' => $account_reference,
            'TransactionDesc' => $transaction_desc
        ];
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->access_token,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($curl_post_data),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        
        error_log("STK Push Response - HTTP Code: $httpCode, Body: $result");
        
        if ($curlError) {
            error_log("STK Push cURL Error: $curlError");
            throw new Exception('STK Push request failed: ' . $curlError);
        }
        
        if ($httpCode !== 200) {
            error_log("STK Push HTTP Error: $httpCode - $result");
            throw new Exception("STK Push failed. HTTP Code: $httpCode. Response: $result");
        }
        
        $responseData = json_decode($result, true);
        
        if (!$responseData) {
            error_log("STK Push Invalid JSON Response: $result");
            throw new Exception('Invalid STK Push response format');
        }
        
        return $responseData;
    }
    
    /**
     * Format phone number to international format
     */
    private function formatPhoneNumber($phone) {
        // Remove any non-digits
        $phone = preg_replace('/\D/', '', $phone);
        
        // Convert to international format
        if (substr($phone, 0, 1) === '0') {
            $phone = '254' . substr($phone, 1);
        } elseif (substr($phone, 0, 3) !== '254') {
            $phone = '254' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Validate Kenyan phone number
     */
    public static function validatePhoneNumber($phone) {
        // Kenyan phone number: 07XXXXXXXX or 01XXXXXXXX
        return preg_match('/^0[17][0-9]{8}$/', $phone);
    }
}

/**
 * Database Handler for WiFi User Management
 */
class WiFiUserManager {
    private $db;
    
    public function __construct() {
        // Initialize database with robust error handling and fallback
        $this->initializeDatabase();
    }
    
    private function initializeDatabase() {
        // Create data directory for SQLite if needed
        $dataDir = dirname(Config::DB_PATH);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        
        // Try PostgreSQL first, then fallback to SQLite
        $databaseUrl = Config::getDatabaseUrl();
        $isPostgreSQL = strpos($databaseUrl, 'postgres') !== false;
        
        if ($isPostgreSQL) {
            // PRODUCTION: No SQLite fallback - PostgreSQL is required for M-Pesa transactions
            error_log("🎯 PRODUCTION MODE: PostgreSQL connection required for M-Pesa");
            error_log("Attempting PostgreSQL connection...");
            
            // CRITICAL: Enhanced PostgreSQL driver detection
            error_log("🔍 PostgreSQL Driver Detection:");
            error_log("- extension_loaded('pdo_pgsql'): " . (extension_loaded('pdo_pgsql') ? 'true' : 'false'));
            error_log("- extension_loaded('pgsql'): " . (extension_loaded('pgsql') ? 'true' : 'false'));
            
            $pdoDrivers = PDO::getAvailableDrivers();
            error_log("- Available PDO drivers: " . implode(', ', $pdoDrivers));
            error_log("- 'pgsql' in PDO drivers: " . (in_array('pgsql', $pdoDrivers) ? 'true' : 'false'));
            
            if (!extension_loaded('pdo_pgsql')) {
                error_log("❌ CRITICAL: PostgreSQL PDO extension NOT LOADED");
                error_log("Available PHP extensions: " . implode(', ', get_loaded_extensions()));
                throw new Exception('DEPLOYMENT ERROR: PostgreSQL PDO extension not loaded.');
            }
            
            if (!in_array('pgsql', $pdoDrivers)) {
                error_log("❌ CRITICAL: PostgreSQL PDO driver NOT AVAILABLE");
                error_log("Extension loaded but driver not registered with PDO");
                throw new Exception('DEPLOYMENT ERROR: PostgreSQL PDO driver not registered.');
            }
            
            try {
                error_log("🔗 Attempting PostgreSQL PDO connection...");
                error_log("Database URL format: " . (strpos($databaseUrl, 'postgresql://') === 0 ? 'postgresql://' : 'unknown'));
                
                // CRITICAL FIX: Convert PostgreSQL URI to PDO DSN format
                if (strpos($databaseUrl, 'postgresql://') === 0) {
                    $pdoDsn = Config::convertPostgreSQLUriToPdoDsn($databaseUrl);
                    error_log("🔧 Converted URI to PDO DSN format");
                    error_log("Original: postgresql://[hidden]");
                    error_log("Converted: " . preg_replace('/password=[^;]+/', 'password=[hidden]', $pdoDsn));
                } else {
                    $pdoDsn = $databaseUrl;
                }
                
                $this->db = new PDO($pdoDsn);
                $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                error_log("✅ PostgreSQL connection successful");
                
                // Test the connection with a simple query
                $stmt = $this->db->query('SELECT version()');
                $version = $stmt->fetchColumn();
                error_log("✅ PostgreSQL version: " . substr($version, 0, 50));
                
                $this->createTables();
                error_log("✅ PostgreSQL tables created/verified");
                return;
                
            } catch (PDOException $e) {
                error_log("❌ PostgreSQL PDO connection failed: " . $e->getMessage());
                error_log("PDO Error Code: " . $e->getCode());
                error_log("DATABASE_URL: " . (getenv('DATABASE_URL') ? 'SET' : 'NOT SET'));
                
                // Try to provide more specific error information
                if (strpos($e->getMessage(), 'could not find driver') !== false) {
                    error_log("🔧 DIAGNOSIS: PDO driver not found despite extension being loaded");
                    error_log("This suggests a PHP configuration issue");
                }
                
                throw new Exception('PRODUCTION ERROR: PostgreSQL connection failed - ' . $e->getMessage());
            } catch (Exception $e) {
                error_log("❌ PostgreSQL general connection failed: " . $e->getMessage());
                throw new Exception('PRODUCTION ERROR: PostgreSQL connection failed - ' . $e->getMessage());
            }
        }
        
        // Local development fallback to SQLite only
        error_log("⚠️ LOCAL DEVELOPMENT: Using SQLite fallback");
        try {
            $sqliteUrl = 'sqlite:' . Config::DB_PATH;
            error_log("Connecting to SQLite: " . $sqliteUrl);
            
            $this->db = new PDO($sqliteUrl);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            error_log("✅ SQLite connection successful (LOCAL ONLY)");
            
            $this->createTables();
            error_log("✅ SQLite tables created/verified (LOCAL ONLY)");
            
        } catch (PDOException $e) {
            error_log("❌ Critical: Database initialization completely failed!");
            error_log("SQLite error: " . $e->getMessage());
            throw new Exception('CRITICAL: All database connections failed - ' . $e->getMessage());
        }
    }
    
    /**
     * Create database tables
     */
    private function createTables() {
        $isPostgreSQL = getenv('DATABASE_URL') !== false;
        
        if ($isPostgreSQL) {
            $this->createPostgreSQLTables();
        } else {
            $this->createSQLiteTables();
        }
    }
    
    /**
     * Create tables for PostgreSQL
     */
    private function createPostgreSQLTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS wifi_sessions (
            id SERIAL PRIMARY KEY,
            phone_number VARCHAR(20) NOT NULL,
            amount INTEGER NOT NULL,
            duration_hours INTEGER NOT NULL,
            mpesa_receipt VARCHAR(100),
            checkout_request_id VARCHAR(100),
            session_start TIMESTAMP,
            session_end TIMESTAMP,
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS vouchers (
            id SERIAL PRIMARY KEY,
            voucher_code VARCHAR(20) UNIQUE NOT NULL,
            duration_hours INTEGER NOT NULL,
            amount INTEGER NOT NULL,
            used BOOLEAN DEFAULT FALSE,
            used_by VARCHAR(20),
            used_at TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS payment_logs (
            id SERIAL PRIMARY KEY,
            phone_number VARCHAR(20) NOT NULL,
            checkout_request_id VARCHAR(100),
            response_code VARCHAR(10),
            response_description TEXT,
            amount INTEGER,
            mpesa_receipt VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE INDEX IF NOT EXISTS idx_sessions_phone ON wifi_sessions(phone_number);
        CREATE INDEX IF NOT EXISTS idx_sessions_status ON wifi_sessions(status);
        CREATE INDEX IF NOT EXISTS idx_vouchers_code ON vouchers(voucher_code);
        ";
        
        $this->db->exec($sql);
    }
    
    /**
     * Create tables for SQLite
     */
    private function createSQLiteTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS wifi_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_number TEXT NOT NULL,
            amount INTEGER NOT NULL,
            duration_hours INTEGER NOT NULL,
            mpesa_receipt TEXT,
            checkout_request_id TEXT,
            session_start DATETIME,
            session_end DATETIME,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS vouchers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            voucher_code TEXT UNIQUE NOT NULL,
            duration_hours INTEGER NOT NULL,
            amount INTEGER NOT NULL,
            used BOOLEAN DEFAULT FALSE,
            used_by TEXT,
            used_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS payment_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone_number TEXT NOT NULL,
            checkout_request_id TEXT,
            response_code TEXT,
            response_description TEXT,
            amount INTEGER,
            mpesa_receipt TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE INDEX IF NOT EXISTS idx_sessions_phone ON wifi_sessions(phone_number);
        CREATE INDEX IF NOT EXISTS idx_sessions_status ON wifi_sessions(status);
        CREATE INDEX IF NOT EXISTS idx_vouchers_code ON vouchers(voucher_code);
        ";
        
        $this->db->exec($sql);
    }
    
    /**
     * Create a new session
     */
    public function createSession($phone, $amount, $duration_hours, $checkout_request_id = null) {
        // Debug logging to track session creation
        error_log("🔄 Creating session: phone=$phone, amount=$amount, duration=$duration_hours, checkout_id=$checkout_request_id");
        
        $sql = "INSERT INTO wifi_sessions (phone_number, amount, duration_hours, checkout_request_id, status) 
                VALUES (?, ?, ?, ?, 'pending')";
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([$phone, $amount, $duration_hours, $checkout_request_id]);
        
        if ($result) {
            $sessionId = $this->db->lastInsertId();
            error_log("✅ Session created successfully: ID=$sessionId, checkout_id=$checkout_request_id");
            return $sessionId;
        } else {
            error_log("❌ Failed to create session for phone=$phone");
            return false;
        }
    }
    
    /**
     * Activate session after successful payment
     */
    public function activateSession($checkout_request_id, $mpesa_receipt) {
        $isPostgreSQL = getenv('DATABASE_URL') !== false;
        
        // Get EAT timestamp for consistent timezone across system
        $eatTime = Config::getEATDateTime();
        
        // Generate unique voucher code for WiFi access
        $voucherCode = $this->generateVoucherCode();
        error_log("🎫 Generated voucher code: $voucherCode for checkout: $checkout_request_id");
        
        if ($isPostgreSQL) {
            // For PostgreSQL, we'll set specific EAT timestamps
            $sql = "UPDATE wifi_sessions 
                    SET status = 'active', 
                        mpesa_receipt = ?,
                        voucher_code = ?,
                        session_start = ?, 
                        session_end = ? + INTERVAL '1 hour' * duration_hours,
                        updated_at = ?
                    WHERE checkout_request_id = ? AND status IN ('pending', 'expired')";
            $params = [$mpesa_receipt, $voucherCode, $eatTime, $eatTime, $eatTime, $checkout_request_id];
        } else {
            // For SQLite, calculate end time in EAT
            $sql = "UPDATE wifi_sessions 
                    SET status = 'active', 
                        mpesa_receipt = ?,
                        voucher_code = ?,
                        session_start = ?, 
                        session_end = datetime(?, '+' || duration_hours || ' hours'),
                        updated_at = ?
                    WHERE checkout_request_id = ? AND status IN ('pending', 'expired')";
            $params = [$mpesa_receipt, $voucherCode, $eatTime, $eatTime, $eatTime, $checkout_request_id];
        }
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result && $stmt->rowCount() > 0) {
            error_log("🕒 ✅ Session activated successfully at EAT time: $eatTime for checkout: $checkout_request_id");
        } else {
            error_log("🕒 ❌ Failed to activate session - no matching pending session found for checkout: $checkout_request_id");
            // Debug: Check what sessions exist
            $debugStmt = $this->db->prepare("SELECT id, phone_number, status, checkout_request_id FROM wifi_sessions WHERE checkout_request_id = ?");
            $debugStmt->execute([$checkout_request_id]);
            $session = $debugStmt->fetch(PDO::FETCH_ASSOC);
            if ($session) {
                error_log("🔍 Found session but wrong status: " . json_encode($session));
            } else {
                error_log("🔍 No session found with checkout_request_id: $checkout_request_id");
            }
        }
        
        return $result && $stmt->rowCount() > 0;
    }
    
    /**
     * Check if user has active session
     */
    public function getUserActiveSession($phone) {
        // First, clean up any expired sessions
        $this->cleanupExpiredSessions();
        
        $sql = "SELECT * FROM wifi_sessions 
                WHERE phone_number = ? AND status = 'active' 
                AND session_end > datetime('now') 
                ORDER BY session_start DESC LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$phone]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Clean up expired sessions
     */
    public function cleanupExpiredSessions() {
        // Only expire sessions that have been active and actually ended
        // Do NOT expire pending sessions - they might still get M-Pesa callbacks
        $sql = "UPDATE wifi_sessions 
                SET status = 'expired', updated_at = datetime('now') 
                WHERE status = 'active' AND session_end <= datetime('now') AND mpesa_receipt IS NOT NULL";
        
        $stmt = $this->db->prepare($sql);
        $executed = $stmt->execute();
        
        $rowCount = $stmt->rowCount();
        if ($rowCount > 0) {
            error_log("🧹 Cleaned up {$rowCount} expired sessions (M-Pesa confirmed only)");
        }
        
        return $rowCount;
    }
    
    /**
     * Get session by checkout request ID
     */
    public function getSessionByCheckoutId($checkout_request_id) {
        $sql = "SELECT * FROM wifi_sessions WHERE checkout_request_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$checkout_request_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Generate voucher
     */
    public function generateVoucher($duration_hours, $amount) {
        $voucher_code = $this->generateVoucherCode();
        
        $sql = "INSERT INTO vouchers (voucher_code, duration_hours, amount) VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        if ($stmt->execute([$voucher_code, $duration_hours, $amount])) {
            return $voucher_code;
        }
        
        return false;
    }
    
    /**
     * Generate unique voucher code
     */
    private function generateVoucherCode() {
        do {
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            
            // Check if code already exists in vouchers table
            $sql1 = "SELECT voucher_code FROM vouchers WHERE voucher_code = ?";
            $stmt1 = $this->db->prepare($sql1);
            $stmt1->execute([$code]);
            
            // Check if code already exists in wifi_sessions table
            $sql2 = "SELECT voucher_code FROM wifi_sessions WHERE voucher_code = ?";
            $stmt2 = $this->db->prepare($sql2);
            $stmt2->execute([$code]);
            
            // Continue loop if code exists in either table
        } while ($stmt1->fetch() || $stmt2->fetch());
        
        return $code;
    }
    
    /**
     * Use/redeem voucher
     */
    public function useVoucher($voucher_code, $phone) {
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Get voucher details
            $sql = "SELECT * FROM vouchers WHERE voucher_code = ? AND used = 0";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$voucher_code]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$voucher) {
                throw new Exception('Invalid or already used voucher code');
            }
            
            // Mark voucher as used
            $sql = "UPDATE vouchers SET used = 1, used_by = ?, used_at = CURRENT_TIMESTAMP WHERE voucher_code = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$phone, $voucher_code]);
            
            // Create and activate session
            $session_id = $this->createSession($phone, $voucher['amount'], $voucher['duration_hours'], 'VOUCHER_' . $voucher_code);
            $this->activateSession('VOUCHER_' . $voucher_code, 'VOUCHER_' . $voucher_code);
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Log payment attempt
     */
    public function logPayment($phone, $checkout_request_id, $response_code, $response_desc, $amount = null, $mpesa_receipt = null) {
        $sql = "INSERT INTO payment_logs (phone_number, checkout_request_id, response_code, response_description, amount, mpesa_receipt) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$phone, $checkout_request_id, $response_code, $response_desc, $amount, $mpesa_receipt]);
    }
    
    /**
     * Get user session statistics
     */
    public function getUserStats($phone) {
        $sql = "SELECT 
                    COUNT(*) as total_sessions,
                    SUM(amount) as total_spent,
                    MAX(session_end) as last_session
                FROM wifi_sessions 
                WHERE phone_number = ? AND status = 'active'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$phone]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Clean expired sessions
     */
    public function cleanExpiredSessions() {
        $eatTime = Config::getEATDateTime();
        
        // Only expire sessions that have been active and actually ended
        // Do NOT expire pending sessions waiting for M-Pesa callbacks  
        $sql = "UPDATE wifi_sessions 
                SET status = 'expired', updated_at = ? 
                WHERE status = 'active' AND session_end < ? AND mpesa_receipt IS NOT NULL";
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([$eatTime, $eatTime]);
        
        // Log expired sessions count
        $count = $stmt->rowCount();
        if ($count > 0) {
            error_log("⏰ Cleaned $count expired sessions at EAT time: $eatTime (M-Pesa confirmed only)");
        }
        
        return $result;
    }

    /**
     * Get active sessions count for monitoring
     */
    public function getActiveSessionsCount() {
        $sql = "SELECT COUNT(*) as count FROM wifi_sessions WHERE status = 'active' AND session_end > CURRENT_TIMESTAMP";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    /**
     * Terminate specific session (for admin use)
     */
    public function terminateSession($phone_number, $reason = 'Admin terminated') {
        $sql = "UPDATE wifi_sessions 
                SET status = 'terminated', 
                    updated_at = CURRENT_TIMESTAMP,
                    session_end = CURRENT_TIMESTAMP 
                WHERE phone_number = ? AND status = 'active'";
        
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([$phone_number]);
        
        if ($result && $stmt->rowCount() > 0) {
            error_log("🛑 Session terminated for $phone_number: $reason");
        }
        
        return $result;
    }

    /**
     * Get admin dashboard statistics
     */
    public function getAdminStats() {
        $isPostgreSQL = getenv('DATABASE_URL') !== false;
        
        if ($isPostgreSQL) {
            $todayStart = "date_trunc('day', CURRENT_TIMESTAMP)";
            $dateFormat = "TO_CHAR(created_at, 'YYYY-MM-DD')";
        } else {
            $todayStart = "date('now', 'start of day')";
            $dateFormat = "date(created_at)";
        }
        
        $stats = [];
        
        // Active users count
        $sql = "SELECT COUNT(*) as count FROM wifi_sessions 
                WHERE status = 'active' AND session_end > CURRENT_TIMESTAMP";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['active_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Today's revenue
        $sql = "SELECT COALESCE(SUM(amount), 0) as revenue FROM wifi_sessions 
                WHERE status IN ('active', 'expired') AND created_at >= $todayStart";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['daily_revenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['revenue'];
        
        // Total revenue
        $sql = "SELECT COALESCE(SUM(amount), 0) as revenue FROM wifi_sessions 
                WHERE status IN ('active', 'expired')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['total_revenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['revenue'];
        
        // Unused vouchers
        $sql = "SELECT COUNT(*) as count FROM vouchers WHERE used = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['unused_vouchers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Expired sessions count
        $sql = "SELECT COUNT(*) as count FROM wifi_sessions 
                WHERE status = 'expired' OR (status = 'active' AND session_end <= CURRENT_TIMESTAMP)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['expired_sessions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Total sessions count
        $sql = "SELECT COUNT(*) as count FROM wifi_sessions";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['total_sessions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Daily revenue chart (last 7 days)
        if ($isPostgreSQL) {
            $sql = "SELECT $dateFormat as date, COALESCE(SUM(amount), 0) as revenue 
                    FROM wifi_sessions 
                    WHERE status IN ('active', 'expired') 
                    AND created_at >= CURRENT_TIMESTAMP - INTERVAL '7 days'
                    GROUP BY $dateFormat 
                    ORDER BY date DESC";
        } else {
            $sql = "SELECT date(created_at) as date, COALESCE(SUM(amount), 0) as revenue 
                    FROM wifi_sessions 
                    WHERE status IN ('active', 'expired') 
                    AND created_at >= date('now', '-7 days')
                    GROUP BY date(created_at) 
                    ORDER BY date DESC";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $stats['daily_chart'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
    }
    
    /**
     * Get active users list
     */
    public function getActiveUsers($limit = 50) {
        $sql = "SELECT phone_number, duration_hours, amount, mpesa_receipt, 
                       session_start, session_end, 
                       CASE 
                           WHEN session_end > CURRENT_TIMESTAMP THEN 'active'
                           ELSE 'expired'
                       END as status
                FROM wifi_sessions 
                WHERE status = 'active' 
                ORDER BY session_start DESC 
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all sessions (with pagination)
     */
    public function getAllSessions($limit = 100, $offset = 0) {
        $sql = "SELECT id, phone_number, amount, duration_hours, mpesa_receipt, 
                       checkout_request_id, session_start, session_end, status, created_at
                FROM wifi_sessions 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get payment logs
     */
    public function getPaymentLogs($limit = 100, $offset = 0) {
        $sql = "SELECT phone_number, checkout_request_id, response_code, 
                       response_description, amount, mpesa_receipt, created_at
                FROM payment_logs 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get unused vouchers
     */
    public function getUnusedVouchers($limit = 50) {
        $sql = "SELECT voucher_code, duration_hours, amount, created_at
                FROM vouchers 
                WHERE used = 0 
                ORDER BY created_at DESC 
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Enhanced revenue analytics with calendar integration
     */
    public function getRevenueData($startDate, $endDate, $selectedDay) {
        $stats = [];
        $isPostgreSQL = getenv('DATABASE_URL') !== false;
        
        // Daily revenue with flexible date range
        if ($isPostgreSQL) {
            $sql = "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY date DESC";
        } else {
            $sql = "SELECT date(created_at) as date, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) BETWEEN ? AND ? GROUP BY date(created_at) ORDER BY date DESC";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $stats['daily_revenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calendar heatmap data
        if ($isPostgreSQL) {
            $sql = "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as revenue FROM wifi_sessions WHERE status IN ('active', 'expired') AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)";
        } else {
            $sql = "SELECT date(created_at) as date, COALESCE(SUM(amount), 0) as revenue FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) BETWEEN ? AND ? GROUP BY date(created_at)";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $heatmapData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fill missing dates with zero revenue
        $stats['calendar_heatmap'] = [];
        $currentDate = $startDate;
        $heatmapIndex = array_column($heatmapData, 'revenue', 'date');
        
        while ($currentDate <= $endDate) {
            $stats['calendar_heatmap'][] = [
                'date' => $currentDate,
                'revenue' => (int)($heatmapIndex[$currentDate] ?? 0),
                'intensity' => min(5, floor(($heatmapIndex[$currentDate] ?? 0) / 200))
            ];
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }
        
        // Hourly breakdown
        if ($isPostgreSQL) {
            $sql = "SELECT EXTRACT(HOUR FROM created_at) as hour, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND DATE(created_at) = ? GROUP BY EXTRACT(HOUR FROM created_at) ORDER BY hour";
        } else {
            $sql = "SELECT strftime('%H', created_at) as hour, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) = ? GROUP BY strftime('%H', created_at) ORDER BY hour";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$selectedDay]);
        $stats['hourly_revenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Revenue by package type
        if ($isPostgreSQL) {
            $sql = "SELECT 
                CASE 
                    WHEN duration_hours <= 2 THEN 'Basic (≤2h)'
                    WHEN duration_hours <= 12 THEN 'Standard (3-12h)'
                    ELSE 'Premium (>12h)'
                END as package_type,
                COALESCE(SUM(amount), 0) as revenue,
                COUNT(*) as sessions,
                AVG(amount) as avg_amount
                FROM wifi_sessions 
                WHERE status IN ('active', 'expired') 
                AND DATE(created_at) BETWEEN ? AND ?
                GROUP BY package_type";
        } else {
            $sql = "SELECT 
                CASE 
                    WHEN duration_hours <= 2 THEN 'Basic (≤2h)'
                    WHEN duration_hours <= 12 THEN 'Standard (3-12h)'
                    ELSE 'Premium (>12h)'
                END as package_type,
                COALESCE(SUM(amount), 0) as revenue,
                COUNT(*) as sessions,
                AVG(amount) as avg_amount
                FROM wifi_sessions 
                WHERE status IN ('active', 'expired') 
                AND date(created_at) BETWEEN ? AND ?
                GROUP BY package_type";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $stats['revenue_by_package'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Period comparison
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / (24 * 60 * 60) + 1;
        $prevStartDate = date('Y-m-d', strtotime($startDate . " -{$daysDiff} days"));
        $prevEndDate = date('Y-m-d', strtotime($endDate . " -{$daysDiff} days"));
        
        if ($isPostgreSQL) {
            $sql = "SELECT COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND DATE(created_at) BETWEEN ? AND ?";
        } else {
            $sql = "SELECT COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) BETWEEN ? AND ?";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$prevStartDate, $prevEndDate]);
        $prevPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt->execute([$startDate, $endDate]);
        $currentPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stats['period_comparison'] = [
            'current' => $currentPeriod,
            'previous' => $prevPeriod,
            'revenue_growth' => $prevPeriod['revenue'] > 0 ? round((($currentPeriod['revenue'] - $prevPeriod['revenue']) / $prevPeriod['revenue']) * 100, 2) : 0,
            'sessions_growth' => $prevPeriod['sessions'] > 0 ? round((($currentPeriod['sessions'] - $prevPeriod['sessions']) / $prevPeriod['sessions']) * 100, 2) : 0
        ];
        
        // Top revenue days
        if ($isPostgreSQL) {
            $sql = "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY revenue DESC LIMIT 5";
        } else {
            $sql = "SELECT date(created_at) as date, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) BETWEEN ? AND ? GROUP BY date(created_at) ORDER BY revenue DESC LIMIT 5";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$startDate, $endDate]);
        $stats['top_revenue_days'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Period summary
        $stats['period_summary'] = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_revenue' => $currentPeriod['revenue'],
            'total_sessions' => $currentPeriod['sessions'],
            'avg_daily_revenue' => round($currentPeriod['revenue'] / $daysDiff, 2),
            'avg_session_value' => $currentPeriod['sessions'] > 0 ? round($currentPeriod['revenue'] / $currentPeriod['sessions'], 2) : 0
        ];
        
        return $stats;
    }
    
    /**
     * Package Management Methods
     */
    
    public function getAllPackages($activeOnly = false) {
        $sql = "SELECT * FROM packages";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getPackageById($id) {
        $stmt = $this->db->prepare("SELECT * FROM packages WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function createPackage($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO packages (
                    name, description, duration_hours, price, package_type,
                    color_scheme, is_active, is_featured, max_devices, speed_limit_mbps, sort_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $sortOrder = $data['sort_order'] ?? $this->getNextSortOrder();
            
            $result = $stmt->execute([
                $data['name'],
                $data['description'] ?? '',
                $data['duration_hours'],
                $data['price'],
                $data['package_type'] ?? 'standard',
                $data['color_scheme'] ?? 'blue',
                $data['is_active'] ?? 1,
                $data['is_featured'] ?? 0,
                $data['max_devices'] ?? 1,
                $data['speed_limit_mbps'] ?? null,
                $sortOrder
            ]);
            
            if ($result) {
                return $this->db->lastInsertId();
            }
            return false;
            
        } catch (PDOException $e) {
            error_log("Package creation error: " . $e->getMessage());
            return false;
        }
    }
    
    public function updatePackage($id, $data) {
        try {
            $fields = [];
            $values = [];
            
            $allowedFields = [
                'name', 'description', 'duration_hours', 'price', 'package_type',
                'color_scheme', 'is_active', 'is_featured', 'max_devices', 'speed_limit_mbps', 'sort_order'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $fields[] = "$field = ?";
                    $values[] = $data[$field];
                }
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $fields[] = "updated_at = CURRENT_TIMESTAMP";
            $values[] = $id;
            
            $sql = "UPDATE packages SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            
            return $stmt->execute($values);
            
        } catch (PDOException $e) {
            error_log("Package update error: " . $e->getMessage());
            return false;
        }
    }
    
    public function deletePackage($id) {
        try {
            // Check if package is being used in any active sessions
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM wifi_sessions 
                WHERE duration_hours = (SELECT duration_hours FROM packages WHERE id = ?)
                AND amount = (SELECT price FROM packages WHERE id = ?)
                AND status = 'active'
            ");
            $stmt->execute([$id, $id]);
            $activeUsage = $stmt->fetchColumn();
            
            if ($activeUsage > 0) {
                return ['success' => false, 'message' => 'Cannot delete package with active sessions'];
            }
            
            $stmt = $this->db->prepare("DELETE FROM packages WHERE id = ?");
            return $stmt->execute([$id]);
            
        } catch (PDOException $e) {
            error_log("Package deletion error: " . $e->getMessage());
            return false;
        }
    }
    
    public function togglePackageStatus($id) {
        try {
            $stmt = $this->db->prepare("UPDATE packages SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            error_log("Package status toggle error: " . $e->getMessage());
            return false;
        }
    }
    
    public function updatePackageOrder($packageOrders) {
        try {
            $this->db->beginTransaction();
            
            $stmt = $this->db->prepare("UPDATE packages SET sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            
            foreach ($packageOrders as $order) {
                if (!$stmt->execute([$order['sort_order'], $order['id']])) {
                    $this->db->rollBack();
                    return false;
                }
            }
            
            $this->db->commit();
            return true;
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Package order update error: " . $e->getMessage());
            return false;
        }
    }
    
    public function bulkUpdatePackages($operations) {
        try {
            $this->db->beginTransaction();
            
            foreach ($operations as $op) {
                switch ($op['action']) {
                    case 'activate':
                        $stmt = $this->db->prepare("UPDATE packages SET is_active = 1 WHERE id = ?");
                        $stmt->execute([$op['id']]);
                        break;
                    case 'deactivate':
                        $stmt = $this->db->prepare("UPDATE packages SET is_active = 0 WHERE id = ?");
                        $stmt->execute([$op['id']]);
                        break;
                    case 'delete':
                        $stmt = $this->db->prepare("DELETE FROM packages WHERE id = ?");
                        $stmt->execute([$op['id']]);
                        break;
                }
            }
            
            $this->db->commit();
            return true;
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Bulk package update error: " . $e->getMessage());
            return false;
        }
    }
    
    private function getNextSortOrder() {
        $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM packages");
        $stmt->execute();
        $maxOrder = $stmt->fetchColumn();
        return ($maxOrder ?? 0) + 1;
    }
    
    public function getPackageStats() {
        try {
            $stats = [];
            
            // Total packages
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM packages");
            $stmt->execute();
            $stats['total_packages'] = $stmt->fetchColumn();
            
            // Active packages
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM packages WHERE is_active = 1");
            $stmt->execute();
            $stats['active_packages'] = $stmt->fetchColumn();
            
            // Featured packages
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM packages WHERE is_featured = 1");
            $stmt->execute();
            $stats['featured_packages'] = $stmt->fetchColumn();
            
            // Most popular package (by recent usage)
            $stmt = $this->db->prepare("
                SELECT p.name, COUNT(*) as usage_count
                FROM wifi_sessions ws
                JOIN packages p ON (ws.duration_hours = p.duration_hours AND ws.amount = p.price)
                WHERE ws.created_at >= date('now', '-30 days')
                GROUP BY p.id
                ORDER BY usage_count DESC
                LIMIT 1
            ");
            $stmt->execute();
            $popular = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['most_popular'] = $popular ? $popular['name'] : 'None';
            
            // Revenue by package type
            $stmt = $this->db->prepare("
                SELECT p.package_type, SUM(ws.amount) as revenue
                FROM wifi_sessions ws
                JOIN packages p ON (ws.duration_hours = p.duration_hours AND ws.amount = p.price)
                WHERE ws.status IN ('active', 'expired')
                GROUP BY p.package_type
            ");
            $stmt->execute();
            $stats['revenue_by_type'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $stats;
            
        } catch (PDOException $e) {
            error_log("Package stats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get pending sessions for a phone number
     */
    public function getPendingSessionsByPhone($phone) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM wifi_sessions 
                WHERE phone_number = ? AND status = 'pending' 
                ORDER BY created_at DESC
            ");
            $stmt->execute([$phone]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Get pending sessions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Expire a session due to timeout
     */
    public function expireSession($checkout_request_id) {
        try {
            $stmt = $this->db->prepare("
                UPDATE wifi_sessions 
                SET status = 'expired', updated_at = CURRENT_TIMESTAMP 
                WHERE checkout_request_id = ? AND status = 'pending'
            ");
            $stmt->execute([$checkout_request_id]);
            
            if ($stmt->rowCount() > 0) {
                error_log("⏰ Session expired due to timeout: $checkout_request_id");
                return true;
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("Expire session error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get database connection - for use by other managers
     */
    public function getDatabase() {
        return $this->db;
    }
    
}

/**
 * API Response Handler
 */
class ApiResponse {
    
    public static function success($data = [], $message = 'Success') {
        return self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
    }
    
    public static function error($message, $code = 400, $data = []) {
        http_response_code($code);
        return self::json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ]);
    }
    
    private static function json($data) {
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit();
    }
}

/**
 * Main API Handler
 */
class WifiAPI {
    
    private $mpesa;
    private $userManager;
    
    public function __construct() {
        try {
            $this->mpesa = new MpesaIntegration();
            $this->userManager = new WiFiUserManager();
            
            // Clean expired sessions periodically
            $this->userManager->cleanExpiredSessions();
            
        } catch (Exception $e) {
            ApiResponse::error('System initialization failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Handle API requests
     */
    public function handleRequest() {
        try {
            // Get request data
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? $_POST['action'] ?? '';
            
            if (empty($action)) {
                ApiResponse::error('No action specified');
            }
            
            // Route to appropriate handler
            switch ($action) {
                case 'initiate_payment':
                    $this->initiatePayment($input);
                    break;
                    
                case 'check_payment':
                    $this->checkPayment($input);
                    break;
                    
                case 'use_voucher':
                    $this->useVoucher($input);
                    break;
                    
                case 'generate_voucher':
                    $this->generateVoucher($input);
                    break;
                    
                case 'system_status':
                    $this->getSystemStatus($input);
                    break;
                    
                case 'admin_stats':
                    $this->getAdminStats($input);
                    break;
                    
                case 'active_users':
                    $this->getActiveUsers($input);
                    break;
                    
                case 'session_logs':
                    $this->getSessionLogs($input);
                    break;
                    
                case 'payment_logs':
                    $this->getPaymentLogs($input);
                    break;
                    
                case 'unused_vouchers':
                    $this->getUnusedVouchers($input);
                    break;
                    
                case 'terminate_session':
                    $this->terminateUserSession($input);
                    break;
                    
                case 'get_revenue_data':
                    $this->getRevenueData($input);
                    break;
                    
                case 'check_payment_status':
                    $this->checkPaymentStatus($input);
                    break;
                    
                // MikroTik Router Management Endpoints
                case 'mikrotik_routers':
                    $this->getMikroTikRouters($input);
                    break;
                    
                case 'add_mikrotik_router':
                    $this->addMikroTikRouter($input);
                    break;
                    
                case 'test_router_connection':
                    $this->testRouterConnection($input);
                    break;
                    
                case 'mikrotik_stats':
                    $this->getMikroTikStats($input);
                    break;
                    
                case 'sync_session_to_router':
                    $this->syncSessionToRouter($input);
                    break;
                    
                // Client Management Endpoints (B2B SaaS)
                case 'client_register':
                    $this->registerClient($input);
                    break;
                    
                case 'client_login':
                    $this->authenticateClient($input);
                    break;
                    
                case 'client_dashboard':
                    $this->getClientDashboard($input);
                    break;
                    
                case 'client_routers':
                    $this->getClientRouters($input);
                    break;
                    
                case 'client_add_router':
                    $this->addClientRouter($input);
                    break;
                    
                case 'client_packages':
                    $this->getClientPackages($input);
                    break;
                    
                case 'client_create_package':
                    $this->createClientPackage($input);
                    break;
                    
                case 'generate_router_script':
                    $this->generateRouterScript($input);
                    break;
                    
                case 'get_active_users':
                    $this->getActiveUsers($input);
                    break;
                    
                case 'get_session_logs':
                    $this->getSessionLogs($input);
                    break;
                    
                case 'get_vouchers':
                    $this->getUnusedVouchers($input);
                    break;
                    
                // Package Management Endpoints
                case 'get_packages':
                    $this->getPackages($input);
                    break;
                    
                case 'create_package':
                    $this->createPackage($input);
                    break;
                    
                case 'update_package':
                    $this->updatePackage($input);
                    break;
                    
                case 'delete_package':
                    $this->deletePackage($input);
                    break;
                    
                case 'toggle_package_status':
                    $this->togglePackageStatus($input);
                    break;
                    
                case 'update_package_order':
                    $this->updatePackageOrder($input);
                    break;
                    
                case 'bulk_update_packages':
                    $this->bulkUpdatePackages($input);
                    break;
                    
                case 'get_package_stats':
                    $this->getPackageStats($input);
                    break;
                    
                // SMS Service Endpoints
                case 'send_test_sms':
                    $this->sendTestSMS($input);
                    break;
                    
                case 'check_sms_config':
                    $this->checkSMSConfig($input);
                    break;
                    
                case 'send_voucher_sms':
                    $this->sendVoucherSMSManual($input);
                    break;
                    
                case 'get_sms_stats':
                    $this->getSMSStats($input);
                    break;
                    
                case 'get_sms_logs':
                    $this->getSMSLogs($input);
                    break;
                    
                default:
                    ApiResponse::error('Invalid action: ' . $action);
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Request processing failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Initiate M-Pesa payment
     */
    private function initiatePayment($input) {
        // Validate input
        $phone = $input['phone'] ?? '';
        $amount = (int)($input['amount'] ?? 0);
        $duration = (int)($input['duration'] ?? 0);
        
        if (empty($phone) || !MpesaIntegration::validatePhoneNumber($phone)) {
            ApiResponse::error('Invalid phone number');
        }
        
        if ($amount <= 0 || $duration <= 0) {
            ApiResponse::error('Invalid amount or duration');
        }
        
        // Check if user already has active session
        $existingSession = $this->userManager->getUserActiveSession($phone);
        if ($existingSession) {
            ApiResponse::error('You already have an active internet session', 409, $existingSession);
        }
        
        try {
            // Generate unique reference
            $account_reference = 'WIFI_' . time() . '_' . $duration . 'H';
            $transaction_desc = Config::BUSINESS_NAME . " - {$duration}hrs Internet";
            
            // Log initiation attempt
            error_log("Initiating STK Push for phone: $phone, amount: $amount");
            
            // Initiate STK Push
            $result = $this->mpesa->initiateSTKPush(
                $phone, 
                $amount, 
                $account_reference,
                $transaction_desc
            );
            
            // Log M-Pesa response
            error_log("M-Pesa Response: " . json_encode($result));
            
            if (isset($result['ResponseCode']) && $result['ResponseCode'] === '0') {
                // STK Push initiated successfully
                $checkout_request_id = $result['CheckoutRequestID'];
                
                // Create pending session
                $session_id = $this->userManager->createSession($phone, $amount, $duration, $checkout_request_id);
                
                // Log payment attempt
                $this->userManager->logPayment($phone, $checkout_request_id, $result['ResponseCode'], $result['ResponseDescription'], $amount);
                
                ApiResponse::success([
                    'checkout_request_id' => $checkout_request_id,
                    'session_id' => $session_id
                ], 'STK Push sent to your phone. Please enter your M-Pesa PIN.');
                
            } else {
                $error_msg = $result['errorMessage'] ?? $result['ResponseDescription'] ?? $result['errorCode'] ?? 'Failed to initiate payment';
                error_log("STK Push failed with response: " . json_encode($result));
                ApiResponse::error("M-Pesa Error: $error_msg. Please try again or contact support.");
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Payment initiation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Check payment status
     */
    private function checkPayment($input) {
        $phone = $input['phone'] ?? '';
        $checkout_request_id = $input['checkout_request_id'] ?? '';
        
        if (empty($phone)) {
            ApiResponse::error('Phone number is required');
        }
        
        // PRODUCTION SECURITY: Only activate sessions via M-Pesa callback
        // Check if there's a pending session and provide status
        if (!empty($checkout_request_id)) {
            $pendingSession = $this->userManager->getSessionByCheckoutId($checkout_request_id);
            error_log("Pending session check: " . ($pendingSession ? json_encode($pendingSession) : 'null'));
            
            if ($pendingSession && $pendingSession['status'] === 'pending') {
                $created_time = strtotime($pendingSession['created_at']);
                $now = time();
                $age = $now - $created_time;
                
                error_log("Session status: pending, age: {$age} seconds, phone: {$phone}");
                
                // Check if session has timed out (10 minutes)
                if ($age > 600) {
                    error_log("Session timeout: {$age} seconds - marking as expired");
                    // Could add logic here to mark session as expired
                    ApiResponse::error('Payment timeout - please try again', 408);
                    return;
                }
                
                // Session is still pending - waiting for M-Pesa callback verification
                // ONLY callback.php should activate sessions after payment confirmation
                ApiResponse::error([
                    'message' => 'Payment still processing - please wait for M-Pesa confirmation',
                    'status' => 'pending',
                    'age_seconds' => $age,
                    'timeout_in' => 600 - $age
                ], 'Payment processing', 202);
                return;
            }
        }
        
        // SECURITY FIX: Only check status of the SPECIFIC session tied to this checkout_request_id
        // This prevents showing success for unrelated previous sessions
        if (!empty($checkout_request_id)) {
            $specificSession = $this->userManager->getSessionByCheckoutId($checkout_request_id);
            if ($specificSession && $specificSession['status'] === 'active') {
                error_log("✅ Specific session for checkout {$checkout_request_id} is active");
                ApiResponse::success([
                    'session' => $specificSession,
                    'status' => 'active',
                    'remaining_time' => $this->calculateRemainingTime($specificSession['session_end']),
                    'phone' => $phone,
                    'checkout_request_id' => $checkout_request_id
                ], 'Payment verified - session active');
                return;
            }
        }
        
        // If no specific checkout session is active, check for any active session (fallback)
        $session = $this->userManager->getUserActiveSession($phone);
        if ($session) {
            error_log("ℹ️ Found existing active session for phone: {$phone} (not tied to current payment)");
            ApiResponse::success([
                'session' => $session,
                'status' => 'active',
                'remaining_time' => $this->calculateRemainingTime($session['session_end']),
                'phone' => $phone,
                'checkout_request_id' => $checkout_request_id
            ], 'Existing active session found');
        } else {
            error_log("❌ No active session found for phone: {$phone}");
            ApiResponse::error('No active session found - payment may still be processing', 404);
        }
    }
    
    /**
     * Calculate remaining time for session
     */
    private function calculateRemainingTime($session_end) {
        $end_time = new DateTime($session_end);
        $now = new DateTime();
        
        if ($end_time > $now) {
            $diff = $now->diff($end_time);
            return [
                'hours' => $diff->h + ($diff->days * 24),
                'minutes' => $diff->i,
                'seconds' => $diff->s,
                'total_seconds' => ($end_time->getTimestamp() - $now->getTimestamp())
            ];
        }
        
        return null;
    }
    
    /**
     * Use voucher
     */
    private function useVoucher($input) {
        $voucher_code = strtoupper(trim($input['voucher_code'] ?? ''));
        $phone = $input['phone'] ?? '';
        
        if (empty($voucher_code) || strlen($voucher_code) < 4) {
            ApiResponse::error('Invalid voucher code');
        }
        
        if (empty($phone) || !MpesaIntegration::validatePhoneNumber($phone)) {
            ApiResponse::error('Invalid phone number');
        }
        
        // Check if user already has active session
        $existingSession = $this->userManager->getUserActiveSession($phone);
        if ($existingSession) {
            ApiResponse::error('You already have an active internet session', 409, $existingSession);
        }
        
        try {
            $this->userManager->useVoucher($voucher_code, $phone);
            
            // Get the new session
            $session = $this->userManager->getUserActiveSession($phone);
            
            ApiResponse::success([
                'session' => $session
            ], 'Voucher activated successfully! You are now connected to the internet.');
            
        } catch (Exception $e) {
            ApiResponse::error($e->getMessage());
        }
    }
    
    /**
     * Generate voucher (Admin function)
     */
    private function generateVoucher($input) {
        $duration = (int)($input['duration'] ?? 0);
        $amount = (int)($input['amount'] ?? 0);
        $admin_key = $input['admin_key'] ?? '';
        
        // Check for admin authentication (header or body)
        $headers = getallheaders();
        $header_admin_key = $headers['X-Admin-Key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey() && $header_admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized - Invalid admin key', 401);
            return;
        }
        
        if ($duration <= 0 || $amount <= 0) {
            ApiResponse::error('Invalid duration or amount');
        }
        
        try {
            $voucher_code = $this->userManager->generateVoucher($duration, $amount);
            
            if ($voucher_code) {
                ApiResponse::success([
                    'voucher_code' => $voucher_code,
                    'duration_hours' => $duration,
                    'amount' => $amount
                ], 'Voucher generated successfully');
            } else {
                ApiResponse::error('Failed to generate voucher');
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Voucher generation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Debug environment variables
     */
    
    /**
     * Test M-Pesa connection
     */
    
    /**
     * Get system status for debugging
     */
    private function getSystemStatus($input) {
        try {
            $databaseUrl = Config::getDatabaseUrl();
            $isPostgreSQL = strpos($databaseUrl, 'postgres') !== false;
            
            // Check PHP extensions
            $phpExtensions = [
                'pdo' => extension_loaded('pdo'),
                'pdo_pgsql' => extension_loaded('pdo_pgsql'),
                'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                'curl' => extension_loaded('curl'),
                'json' => extension_loaded('json')
            ];
            
            // Test database connection with detailed error info
            $dbTest = false;
            $dbError = null;
            $actualDbType = 'Unknown';
            
            try {
                // Convert PostgreSQL URI to PDO DSN if needed
                if ($isPostgreSQL && strpos($databaseUrl, 'postgresql://') === 0) {
                    $pdoDsn = Config::convertPostgreSQLUriToPdoDsn($databaseUrl);
                } else {
                    $pdoDsn = $databaseUrl;
                }
                
                $testDb = new PDO($pdoDsn);
                $dbTest = true;
                $actualDbType = $isPostgreSQL ? 'PostgreSQL' : 'SQLite';
            } catch (Exception $e) {
                $dbTest = false;
                $dbError = $e->getMessage();
                // Try to fallback to SQLite if PostgreSQL fails
                if ($isPostgreSQL) {
                    try {
                        $sqliteUrl = 'sqlite:' . Config::DB_PATH;
                        $testSqlite = new PDO($sqliteUrl);
                        $actualDbType = 'SQLite (Fallback)';
                        $dbTest = true;
                        $dbError = 'PostgreSQL failed, using SQLite: ' . $e->getMessage();
                    } catch (Exception $sqliteError) {
                        $actualDbType = 'Failed';
                        $dbError = 'Both failed - PostgreSQL: ' . $e->getMessage() . ' | SQLite: ' . $sqliteError->getMessage();
                    }
                }
            }
            
            ApiResponse::success([
                'system' => 'TECH CONNECT WiFi Billing System',
                'version' => '2.1',
                'environment' => Config::getMpesaEnvironment(),
                'php_version' => PHP_VERSION,
                'php_extensions' => $phpExtensions,
                'database' => [
                    'intended_type' => $isPostgreSQL ? 'PostgreSQL' : 'SQLite',
                    'actual_type' => $actualDbType,
                    'connection_test' => $dbTest,
                    'connection_error' => $dbError,
                    'postgresql_driver_available' => $phpExtensions['pdo_pgsql']
                ],
                'mpesa' => [
                    'environment' => Config::getMpesaEnvironment(),
                    'business_shortcode' => Config::getMpesaBusinessShortcode(),
                    'callback_url' => Config::getMpesaCallbackUrl()
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ], 'System status check');
            
        } catch (Exception $e) {
            ApiResponse::error('System status check failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get admin dashboard statistics
     */
    private function getAdminStats($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        try {
            $stats = $this->userManager->getAdminStats();
            
            ApiResponse::success($stats, 'Admin statistics retrieved successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to retrieve admin stats: ' . $e->getMessage());
        }
    }
    
    /**
     * Get active users list
     */
    private function getActiveUsers($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        try {
            $limit = (int)($input['limit'] ?? 50);
            $users = $this->userManager->getActiveUsers($limit);
            
            ApiResponse::success([
                'active_users' => $users,
                'count' => count($users)
            ], 'Active users retrieved successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to retrieve active users: ' . $e->getMessage());
        }
    }
    
    /**
     * Get session logs
     */
    private function getSessionLogs($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        try {
            $limit = (int)($input['limit'] ?? 100);
            $offset = (int)($input['offset'] ?? 0);
            $sessions = $this->userManager->getAllSessions($limit, $offset);
            
            ApiResponse::success([
                'sessions' => $sessions,
                'count' => count($sessions),
                'limit' => $limit,
                'offset' => $offset
            ], 'Session logs retrieved successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to retrieve session logs: ' . $e->getMessage());
        }
    }
    
    /**
     * Get payment logs
     */
    private function getPaymentLogs($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        try {
            $limit = (int)($input['limit'] ?? 100);
            $offset = (int)($input['offset'] ?? 0);
            $logs = $this->userManager->getPaymentLogs($limit, $offset);
            
            ApiResponse::success([
                'payment_logs' => $logs,
                'count' => count($logs),
                'limit' => $limit,
                'offset' => $offset
            ], 'Payment logs retrieved successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to retrieve payment logs: ' . $e->getMessage());
        }
    }
    
    /**
     * Get unused vouchers
     */
    private function getUnusedVouchers($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        try {
            $limit = (int)($input['limit'] ?? 50);
            $vouchers = $this->userManager->getUnusedVouchers($limit);
            
            ApiResponse::success([
                'vouchers' => $vouchers,
                'count' => count($vouchers)
            ], 'Unused vouchers retrieved successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to retrieve unused vouchers: ' . $e->getMessage());
        }
    }
    
    /**
     * Package Management API Methods
     */
    
    private function getPackages($input) {
        $admin_key = $input['admin_key'] ?? '';
        $activeOnly = ($input['active_only'] ?? false) === true || ($input['active_only'] ?? false) === 'true';
        
        // Check for admin authentication (header or body) for all package access
        $headers = getallheaders();
        $header_admin_key = $headers['X-Admin-Key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey() && $header_admin_key !== Config::getAdminKey()) {
            // Allow public access only for active packages (frontend use)
            if (!$activeOnly) {
                ApiResponse::error('Unauthorized', 401);
                return;
            }
        }
        
        try {
            $packages = $this->userManager->getAllPackages($activeOnly);
            
            ApiResponse::success($packages, 'Packages retrieved successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to retrieve packages: ' . $e->getMessage());
        }
    }
    
    private function createPackage($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        // Validate required fields
        $requiredFields = ['name', 'duration_hours', 'price'];
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                ApiResponse::error("Field '$field' is required");
                return;
            }
        }
        
        // Validate data types and ranges
        if (!is_numeric($input['duration_hours']) || $input['duration_hours'] <= 0) {
            ApiResponse::error('Duration must be a positive number');
            return;
        }
        
        if (!is_numeric($input['price']) || $input['price'] <= 0) {
            ApiResponse::error('Price must be a positive number');
            return;
        }
        
        try {
            $packageData = [
                'name' => trim($input['name']),
                'description' => trim($input['description'] ?? ''),
                'duration_hours' => (float)$input['duration_hours'],
                'price' => (int)$input['price'],
                'package_type' => $input['package_type'] ?? 'standard',
                'color_scheme' => $input['color_scheme'] ?? 'blue',
                'is_active' => ($input['is_active'] ?? 1) ? 1 : 0,
                'is_featured' => ($input['is_featured'] ?? 0) ? 1 : 0,
                'max_devices' => (int)($input['max_devices'] ?? 1),
                'speed_limit_mbps' => !empty($input['speed_limit_mbps']) ? (int)$input['speed_limit_mbps'] : null
            ];
            
            $packageId = $this->userManager->createPackage($packageData);
            
            if ($packageId) {
                $newPackage = $this->userManager->getPackageById($packageId);
                ApiResponse::success($newPackage, 'Package created successfully');
            } else {
                ApiResponse::error('Failed to create package');
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to create package: ' . $e->getMessage());
        }
    }
    
    private function updatePackage($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        $packageId = $input['id'] ?? '';
        if (empty($packageId)) {
            ApiResponse::error('Package ID is required');
            return;
        }
        
        try {
            $updateData = [];
            $allowedFields = [
                'name', 'description', 'duration_hours', 'price', 'package_type',
                'color_scheme', 'is_active', 'is_featured', 'max_devices', 'speed_limit_mbps', 'sort_order'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $value = $input[$field];
                    
                    // Type conversions and validation
                    if ($field === 'duration_hours' && !empty($value)) {
                        $value = (float)$value;
                        if ($value <= 0) {
                            ApiResponse::error('Duration must be positive');
                            return;
                        }
                    } elseif ($field === 'price' && !empty($value)) {
                        $value = (int)$value;
                        if ($value <= 0) {
                            ApiResponse::error('Price must be positive');
                            return;
                        }
                    } elseif (in_array($field, ['is_active', 'is_featured'])) {
                        $value = $value ? 1 : 0;
                    } elseif (in_array($field, ['max_devices', 'sort_order'])) {
                        $value = (int)$value;
                    } elseif ($field === 'speed_limit_mbps') {
                        $value = !empty($value) ? (int)$value : null;
                    } elseif (in_array($field, ['name', 'description'])) {
                        $value = trim($value);
                    }
                    
                    $updateData[$field] = $value;
                }
            }
            
            if (empty($updateData)) {
                ApiResponse::error('No valid fields to update');
                return;
            }
            
            $success = $this->userManager->updatePackage($packageId, $updateData);
            
            if ($success) {
                $updatedPackage = $this->userManager->getPackageById($packageId);
                ApiResponse::success($updatedPackage, 'Package updated successfully');
            } else {
                ApiResponse::error('Failed to update package');
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to update package: ' . $e->getMessage());
        }
    }
    
    private function deletePackage($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        $packageId = $input['id'] ?? '';
        if (empty($packageId)) {
            ApiResponse::error('Package ID is required');
            return;
        }
        
        try {
            $result = $this->userManager->deletePackage($packageId);
            
            if (is_array($result) && !$result['success']) {
                ApiResponse::error($result['message']);
            } elseif ($result) {
                ApiResponse::success(['id' => $packageId], 'Package deleted successfully');
            } else {
                ApiResponse::error('Failed to delete package');
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to delete package: ' . $e->getMessage());
        }
    }
    
    private function togglePackageStatus($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        $packageId = $input['id'] ?? '';
        if (empty($packageId)) {
            ApiResponse::error('Package ID is required');
            return;
        }
        
        try {
            $success = $this->userManager->togglePackageStatus($packageId);
            
            if ($success) {
                $updatedPackage = $this->userManager->getPackageById($packageId);
                ApiResponse::success($updatedPackage, 'Package status updated successfully');
            } else {
                ApiResponse::error('Failed to toggle package status');
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to toggle package status: ' . $e->getMessage());
        }
    }
    
    private function updatePackageOrder($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        $packageOrders = $input['package_orders'] ?? [];
        if (empty($packageOrders)) {
            ApiResponse::error('Package orders array is required');
            return;
        }
        
        try {
            $success = $this->userManager->updatePackageOrder($packageOrders);
            
            if ($success) {
                ApiResponse::success(['updated_count' => count($packageOrders)], 'Package order updated successfully');
            } else {
                ApiResponse::error('Failed to update package order');
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to update package order: ' . $e->getMessage());
        }
    }
    
    private function bulkUpdatePackages($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        $operations = $input['operations'] ?? [];
        if (empty($operations)) {
            ApiResponse::error('Operations array is required');
            return;
        }
        
        try {
            $success = $this->userManager->bulkUpdatePackages($operations);
            
            if ($success) {
                ApiResponse::success(['processed_count' => count($operations)], 'Bulk update completed successfully');
            } else {
                ApiResponse::error('Failed to perform bulk update');
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to perform bulk update: ' . $e->getMessage());
        }
    }
    
    private function getPackageStats($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        try {
            $stats = $this->userManager->getPackageStats();
            ApiResponse::success($stats, 'Package statistics retrieved successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to retrieve package statistics: ' . $e->getMessage());
        }
    }
    
    /**
     * Terminate user session
     */
    private function terminateUserSession($input) {
        $admin_key = $input['admin_key'] ?? '';
        $phone_number = $input['phone_number'] ?? '';
        $reason = $input['reason'] ?? 'Admin terminated';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        if (empty($phone_number)) {
            ApiResponse::error('Phone number is required');
        }
        
        try {
            $result = $this->userManager->terminateSession($phone_number, $reason);
            
            if ($result) {
                ApiResponse::success(['phone_number' => $phone_number], 'Session terminated successfully');
            } else {
                ApiResponse::error('No active session found for this phone number');
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to terminate session: ' . $e->getMessage());
        }
    }
    
    /**
     * Test callback simulation for development
     */
    
    /**
     * Check payment status for a pending session
     */
    private function checkPaymentStatus($input) {
        $checkout_id = $input['checkout_request_id'] ?? '';
        
        if (empty($checkout_id)) {
            ApiResponse::error('Checkout request ID is required');
            return;
        }
        
        try {
            $session = $this->userManager->getSessionByCheckoutId($checkout_id);
            
            if (!$session) {
                ApiResponse::error('Session not found');
                return;
            }
            
            // Calculate session age
            $created = strtotime($session['created_at']);
            $age = time() - $created;
            
            $status = [
                'session_id' => $session['id'],
                'status' => $session['status'],
                'phone_number' => $session['phone_number'],
                'amount' => $session['amount'],
                'created_at' => $session['created_at'],
                'age_seconds' => $age,
                'timeout_in' => max(0, 300 - $age), // 5 minutes timeout
                'checkout_request_id' => $checkout_id
            ];
            
            // SECURITY FIX: Removed auto-activation logic
            // Sessions should ONLY be activated by M-Pesa callback verification
            // No exceptions, even for test amounts
            
            // Check if session has timed out (5 minutes for regular sessions)
            if ($age > 300 && $session['status'] === 'pending') {
                $this->userManager->expireSession($checkout_id);
                $status['status'] = 'expired';
                $status['expired_reason'] = 'Payment timeout';
            }
            
            ApiResponse::success($status, 'Payment status retrieved');
            
        } catch (Exception $e) {
            error_log("Payment status check error: " . $e->getMessage());
            ApiResponse::error('Failed to check payment status: ' . $e->getMessage());
        }
    }
    
    /**
     * Manually verify payment (for admin/development use)
     */
    
    /**
     * Activate test session for development (KSH 1 sessions)
     */
    
    /**
     * Enhanced revenue analytics with calendar integration
     */
    private function getRevenueData($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
        }
        
        try {
            // Get date range parameters (default to last 30 days)
            $startDate = $input['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $input['end_date'] ?? date('Y-m-d');
            $selectedDay = $input['selected_day'] ?? date('Y-m-d');
            
            // Use userManager to get revenue data
            $stats = $this->userManager->getRevenueData($startDate, $endDate, $selectedDay);
            
            ApiResponse::success($stats, 'Enhanced revenue data retrieved successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to retrieve revenue data: ' . $e->getMessage());
        }
    }
    
    private function getSQLiteRevenueData(&$stats, $startDate, $endDate, $selectedDay) {
        // Get database connection from userManager
        $db = $this->userManager->getDatabase();
        
        // Daily revenue with flexible date range
        $stmt = $db->prepare("SELECT date(created_at) as date, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) BETWEEN ? AND ? GROUP BY date(created_at) ORDER BY date DESC");
        $stmt->execute([$startDate, $endDate]);
        $stats['daily_revenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calendar heatmap data
        $stmt = $this->db->prepare("SELECT date(created_at) as date, COALESCE(SUM(amount), 0) as revenue FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) BETWEEN ? AND ? GROUP BY date(created_at)");
        $stmt->execute([$startDate, $endDate]);
        $heatmapData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fill missing dates with zero revenue
        $stats['calendar_heatmap'] = [];
        $currentDate = $startDate;
        $heatmapIndex = array_column($heatmapData, 'revenue', 'date');
        
        while ($currentDate <= $endDate) {
            $stats['calendar_heatmap'][] = [
                'date' => $currentDate,
                'revenue' => (int)($heatmapIndex[$currentDate] ?? 0),
                'intensity' => min(5, floor(($heatmapIndex[$currentDate] ?? 0) / 200))
            ];
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }
        
        // Hourly breakdown
        $stmt = $this->db->prepare("SELECT strftime('%H', created_at) as hour, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) = ? GROUP BY strftime('%H', created_at) ORDER BY hour");
        $stmt->execute([$selectedDay]);
        $stats['hourly_revenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Revenue by package type
        $stmt = $this->db->prepare("SELECT 
            CASE 
                WHEN duration_hours <= 2 THEN 'Basic (≤2h)'
                WHEN duration_hours <= 12 THEN 'Standard (3-12h)'
                ELSE 'Premium (>12h)'
            END as package_type,
            COALESCE(SUM(amount), 0) as revenue,
            COUNT(*) as sessions,
            AVG(amount) as avg_amount
            FROM wifi_sessions 
            WHERE status IN ('active', 'expired') 
            AND date(created_at) BETWEEN ? AND ?
            GROUP BY package_type");
        $stmt->execute([$startDate, $endDate]);
        $stats['revenue_by_package'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Period comparison
        $daysDiff = (strtotime($endDate) - strtotime($startDate)) / (24 * 60 * 60) + 1;
        $prevStartDate = date('Y-m-d', strtotime($startDate . " -{$daysDiff} days"));
        $prevEndDate = date('Y-m-d', strtotime($endDate . " -{$daysDiff} days"));
        
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) BETWEEN ? AND ?");
        $stmt->execute([$prevStartDate, $prevEndDate]);
        $prevPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt->execute([$startDate, $endDate]);
        $currentPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stats['period_comparison'] = [
            'current' => $currentPeriod,
            'previous' => $prevPeriod,
            'revenue_growth' => $prevPeriod['revenue'] > 0 ? round((($currentPeriod['revenue'] - $prevPeriod['revenue']) / $prevPeriod['revenue']) * 100, 2) : 0,
            'sessions_growth' => $prevPeriod['sessions'] > 0 ? round((($currentPeriod['sessions'] - $prevPeriod['sessions']) / $prevPeriod['sessions']) * 100, 2) : 0
        ];
        
        // Top revenue days
        $stmt = $this->db->prepare("SELECT date(created_at) as date, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND date(created_at) BETWEEN ? AND ? GROUP BY date(created_at) ORDER BY revenue DESC LIMIT 5");
        $stmt->execute([$startDate, $endDate]);
        $stats['top_revenue_days'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Period summary
        $stats['period_summary'] = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_revenue' => $currentPeriod['revenue'],
            'total_sessions' => $currentPeriod['sessions'],
            'avg_daily_revenue' => round($currentPeriod['revenue'] / $daysDiff, 2),
            'avg_session_value' => $currentPeriod['sessions'] > 0 ? round($currentPeriod['revenue'] / $currentPeriod['sessions'], 2) : 0
        ];
    }
    
    private function getPostgreSQLRevenueData(&$stats, $startDate, $endDate, $selectedDay) {
        // PostgreSQL version with proper date handling
        $stmt = $this->db->prepare("SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY date DESC");
        $stmt->execute([$startDate, $endDate]);
        $stats['daily_revenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calendar heatmap
        $stmt = $this->db->prepare("SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as revenue FROM wifi_sessions WHERE status IN ('active', 'expired') AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)");
        $stmt->execute([$startDate, $endDate]);
        $heatmapData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stats['calendar_heatmap'] = [];
        $currentDate = $startDate;
        $heatmapIndex = array_column($heatmapData, 'revenue', 'date');
        
        while ($currentDate <= $endDate) {
            $stats['calendar_heatmap'][] = [
                'date' => $currentDate,
                'revenue' => (int)($heatmapIndex[$currentDate] ?? 0),
                'intensity' => min(5, floor(($heatmapIndex[$currentDate] ?? 0) / 200))
            ];
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }
        
        // Hourly breakdown
        $stmt = $this->db->prepare("SELECT EXTRACT(HOUR FROM created_at) as hour, COALESCE(SUM(amount), 0) as revenue, COUNT(*) as sessions FROM wifi_sessions WHERE status IN ('active', 'expired') AND DATE(created_at) = ? GROUP BY EXTRACT(HOUR FROM created_at) ORDER BY hour");
        $stmt->execute([$selectedDay]);
        $stats['hourly_revenue'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Continue with similar PostgreSQL-specific queries...
        // (Abbreviated for brevity - same logic as SQLite but with PostgreSQL syntax)
        
        // For now, use simplified versions
        $stats['revenue_by_package'] = [];
        $stats['period_comparison'] = ['revenue_growth' => 0, 'sessions_growth' => 0];
        $stats['top_revenue_days'] = [];
        $stats['period_summary'] = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_revenue' => 0,
            'total_sessions' => 0,
            'avg_daily_revenue' => 0,
            'avg_session_value' => 0
        ];
    }
    
    // ============================================================================
    // MIKROTIK ROUTER MANAGEMENT METHODS
    // ============================================================================
    
    /**
     * Initialize MikroTik Manager (lazy loading)
     */
    private function getMikroTikManager() {
        if (!isset($this->mikrotikManager)) {
            require_once 'mikrotik_manager.php';
            $this->mikrotikManager = new MikroTikManager($this->db);
        }
        return $this->mikrotikManager;
    }
    
    /**
     * Get all MikroTik routers
     */
    private function getMikroTikRouters($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
            return;
        }
        
        try {
            $manager = $this->getMikroTikManager();
            $routers = $manager->getRouters();
            
            ApiResponse::success([
                'routers' => $routers,
                'total' => count($routers)
            ], 'MikroTik routers retrieved successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to get routers: ' . $e->getMessage());
        }
    }
    
    /**
     * Add new MikroTik router
     */
    private function addMikroTikRouter($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
            return;
        }
        
        try {
            $manager = $this->getMikroTikManager();
            $result = $manager->addRouter($input);
            
            if ($result['success']) {
                ApiResponse::success($result, 'Router added successfully');
            } else {
                ApiResponse::error($result['message']);
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to add router: ' . $e->getMessage());
        }
    }
    
    /**
     * Test router connection
     */
    private function testRouterConnection($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
            return;
        }
        
        try {
            $manager = $this->getMikroTikManager();
            $result = $manager->testConnection(
                $input['host'],
                $input['username'], 
                $input['password'],
                $input['api_port'] ?? 8728
            );
            
            if ($result['success']) {
                ApiResponse::success($result, 'Connection test successful');
            } else {
                ApiResponse::error($result['message']);
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Connection test failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get MikroTik statistics
     */
    private function getMikroTikStats($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
            return;
        }
        
        try {
            $manager = $this->getMikroTikManager();
            $stats = $manager->getRouterStats();
            
            ApiResponse::success($stats, 'MikroTik statistics retrieved');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to get statistics: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync session to router
     */
    private function syncSessionToRouter($input) {
        $admin_key = $input['admin_key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized', 401);
            return;
        }
        
        try {
            $sessionId = $input['session_id'] ?? null;
            $routerId = $input['router_id'] ?? null;
            
            if (!$sessionId || !$routerId) {
                throw new Exception('Session ID and Router ID are required');
            }
            
            $manager = $this->getMikroTikManager();
            $result = $manager->syncSessionToRouter($sessionId, $routerId);
            
            if ($result['success']) {
                ApiResponse::success($result, 'Session synced to router');
            } else {
                ApiResponse::error($result['message']);
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to sync session: ' . $e->getMessage());
        }
    }
    
    // ============================================================================
    // CLIENT MANAGEMENT METHODS (B2B SaaS)
    // ============================================================================
    
    /**
     * Initialize Client Manager (lazy loading)
     */
    private function getClientManager() {
        if (!isset($this->clientManager)) {
            require_once 'client_manager.php';
            $this->clientManager = new ClientManager($this->userManager->getDatabase());
        }
        return $this->clientManager;
    }
    
    /**
     * Register new client business
     */
    private function registerClient($input) {
        try {
            $manager = $this->getClientManager();
            $result = $manager->registerClient($input);
            
            if ($result['success']) {
                ApiResponse::success($result, 'Client registered successfully');
            } else {
                ApiResponse::error($result['message'], 400);
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Registration failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Authenticate client login
     */
    private function authenticateClient($input) {
        try {
            $email = $input['email'] ?? '';
            $password = $input['password'] ?? '';
            
            if (!$email || !$password) {
                ApiResponse::error('Email and password are required', 400);
                return;
            }
            
            $manager = $this->getClientManager();
            $result = $manager->authenticateClient($email, $password);
            
            if ($result['success']) {
                // Start session for client
                session_start();
                $_SESSION['client_id'] = $result['client']['id'];
                $_SESSION['client_email'] = $result['client']['email'];
                $_SESSION['client_api_key'] = $result['client']['api_key'];
                
                ApiResponse::success($result, 'Login successful');
            } else {
                ApiResponse::error($result['message'], 401);
            }
            
        } catch (Exception $e) {
            ApiResponse::error('Authentication failed: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get client dashboard data
     */
    private function getClientDashboard($input) {
        try {
            $apiKey = $input['api_key'] ?? '';
            
            if (!$apiKey) {
                ApiResponse::error('API key required', 401);
                return;
            }
            
            $manager = $this->getClientManager();
            $client = $manager->getClientByApiKey($apiKey);
            
            if (!$client) {
                ApiResponse::error('Invalid API key', 401);
                return;
            }
            
            $dashboardData = $manager->getClientDashboardData($client['id']);
            ApiResponse::success($dashboardData, 'Dashboard data retrieved');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to get dashboard data: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get client routers
     */
    private function getClientRouters($input) {
        try {
            $apiKey = $input['api_key'] ?? '';
            $manager = $this->getClientManager();
            $client = $manager->getClientByApiKey($apiKey);
            
            if (!$client) {
                ApiResponse::error('Invalid API key', 401);
                return;
            }
            
            $stmt = $this->db->prepare("SELECT * FROM client_routers WHERE client_id = ? ORDER BY created_at DESC");
            $stmt->execute([$client['id']]);
            $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ApiResponse::success(['routers' => $routers], 'Client routers retrieved');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to get routers: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Add router to client account
     */
    private function addClientRouter($input) {
        try {
            $apiKey = $input['api_key'] ?? '';
            $manager = $this->getClientManager();
            $client = $manager->getClientByApiKey($apiKey);
            
            if (!$client) {
                ApiResponse::error('Invalid API key', 401);
                return;
            }
            
            // Check router limit
            $stmt = $this->db->prepare("SELECT COUNT(*) as router_count FROM client_routers WHERE client_id = ?");
            $stmt->execute([$client['id']]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['router_count'];
            
            if ($count >= $client['max_routers']) {
                ApiResponse::error("Router limit reached. Upgrade your plan to add more routers.", 403);
                return;
            }
            
            // Add router
            $services = json_encode($input['services'] ?? ['hotspot']);
            
            $stmt = $this->db->prepare("
                INSERT INTO client_routers 
                (client_id, router_name, host, api_port, username, password, services, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            
            $stmt->execute([
                $client['id'],
                $input['router_name'] ?? '',
                $input['host'] ?? '',
                $input['api_port'] ?? 8728,
                $input['username'] ?? '',
                $input['password'] ?? '',
                $services
            ]);
            
            $routerId = $this->db->lastInsertId();
            
            // AUTO-PROVISION: Automatically provision new router
            $this->autoProvisionRouter($routerId);
            
            ApiResponse::success([
                'router_id' => $routerId,
                'setup_command' => $this->generateSetupCommand($client['id'], $routerId),
                'provisioning_status' => 'initiated'
            ], 'Router added successfully and provisioning initiated');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to add router: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Get client packages
     */
    private function getClientPackages($input) {
        try {
            $apiKey = $input['api_key'] ?? '';
            $manager = $this->getClientManager();
            $client = $manager->getClientByApiKey($apiKey);
            
            if (!$client) {
                ApiResponse::error('Invalid API key', 401);
                return;
            }
            
            $stmt = $this->db->prepare("SELECT * FROM client_packages WHERE client_id = ? ORDER BY sort_order ASC, created_at DESC");
            $stmt->execute([$client['id']]);
            $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ApiResponse::success(['packages' => $packages], 'Client packages retrieved');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to get packages: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Create package for client
     */
    private function createClientPackage($input) {
        try {
            $apiKey = $input['api_key'] ?? '';
            $manager = $this->getClientManager();
            $client = $manager->getClientByApiKey($apiKey);
            
            if (!$client) {
                ApiResponse::error('Invalid API key', 401);
                return;
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO client_packages 
                (client_id, name, description, price, duration_hours, bandwidth_mbps, data_limit_gb, max_devices, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            
            $stmt->execute([
                $client['id'],
                $input['name'] ?? '',
                $input['description'] ?? '',
                $input['price'] ?? 0,
                $input['duration_hours'] ?? 1,
                $input['bandwidth_mbps'] ?? null,
                $input['data_limit_gb'] ?? null,
                $input['max_devices'] ?? 1
            ]);
            
            $packageId = $this->db->lastInsertId();
            
            ApiResponse::success(['package_id' => $packageId], 'Package created successfully');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to create package: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Generate router setup script
     */
    private function generateRouterScript($input) {
        try {
            $apiKey = $input['api_key'] ?? '';
            $routerId = $input['router_id'] ?? '';
            
            $manager = $this->getClientManager();
            $client = $manager->getClientByApiKey($apiKey);
            
            if (!$client) {
                ApiResponse::error('Invalid API key', 401);
                return;
            }
            
            $setupScript = $this->generateMikroTikSetupScript($client['id'], $routerId);
            
            ApiResponse::success([
                'setup_command' => $this->generateSetupCommand($client['id'], $routerId),
                'setup_script' => $setupScript
            ], 'Router setup script generated');
            
        } catch (Exception $e) {
            ApiResponse::error('Failed to generate script: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Auto-provision new router with billing system integration
     */
    private function autoProvisionRouter($routerId) {
        try {
            // Initialize router provisioning
            require_once 'router_provisioning.php';
            $provisioning = new RouterProvisioning($this->db);
            
            // Trigger background provisioning (in production, this would be queued)
            error_log("🚀 Auto-provisioning initiated for router {$routerId}");
            
            // Create provisioning task that will be processed by background worker
            $stmt = $this->db->prepare("
                INSERT INTO router_config_tasks 
                (router_id, task_type, config_data, status, priority, created_at) 
                VALUES (?, 'full_provision', '{}', 'pending', 1, datetime('now'))
            ");
            $stmt->execute([$routerId]);
            
            // In a real production environment, you would:
            // 1. Queue this for background processing
            // 2. Use Redis/SQS/RabbitMQ for job queuing
            // 3. Have dedicated worker processes
            
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Auto-provisioning failed for router {$routerId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate setup command for MikroTik
     */
    private function generateSetupCommand($clientId, $routerId) {
        $baseUrl = $_SERVER['HTTP_HOST'] ?? 'your-saas.com';
        return "/tool fetch url=\"https://{$baseUrl}/provision/{$clientId}/{$routerId}\" dst-path=\"billing-setup.rsc\" && /import file-name=billing-setup.rsc";
    }
    
    /**
     * Generate complete MikroTik setup script
     */
    private function generateMikroTikSetupScript($clientId, $routerId) {
        $baseUrl = $_SERVER['HTTP_HOST'] ?? 'your-saas.com';
        
        return "
# TECH CONNECT WiFi Billing System Setup
# Client ID: {$clientId} | Router ID: {$routerId}

# Download hotspot templates
/tool fetch url=\"https://{$baseUrl}/templates/{$clientId}/login.html\" dst-path=\"hotspot/login.html\"
/tool fetch url=\"https://{$baseUrl}/templates/{$clientId}/status.html\" dst-path=\"hotspot/status.html\"

# Create hotspot profile
/ip hotspot profile add name=\"billing-profile\" html-directory=\"hotspot\" login-by=\"http-pap\"

# Setup hotspot server
/ip hotspot add name=\"billing-hotspot\" interface=wlan1 profile=\"billing-profile\"

# Create billing sync script
/system script add name=\"billing-sync\" source={
    :local apiUrl \"https://{$baseUrl}/api.php\"
    :local clientId \"{$clientId}\"
    :local routerId \"{$routerId}\"
    
    # Sync active users with billing system
    /tool fetch url=(\$apiUrl . \"?action=sync_router_users&client_id=\" . \$clientId . \"&router_id=\" . \$routerId) dst-path=\"sync-result.txt\"
}

# Schedule billing sync every minute
/system scheduler add name=\"billing-sync-job\" interval=1m on-event=\"billing-sync\"

:log info \"TECH CONNECT Billing System configured successfully!\"
        ";
    }
    
    // ==================== SMS SERVICE METHODS ====================
    
    /**
     * Send test SMS
     */
    private function sendTestSMS($input) {
        // Check for admin authentication (header or body)
        $admin_key = $input['admin_key'] ?? '';
        $headers = getallheaders();
        $header_admin_key = $headers['X-Admin-Key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey() && $header_admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized - Invalid admin key', 401);
            return;
        }
        
        if (empty($input['phone'])) {
            ApiResponse::error('Phone number is required');
            return;
        }
        
        try {
            require_once 'sms_service.php';
            $smsService = new SMSService();
            
            $result = $smsService->testSMS($input['phone']);
            
            if ($result['success']) {
                ApiResponse::success($result, 'Test SMS sent successfully');
            } else {
                ApiResponse::error($result['message']);
            }
        } catch (Exception $e) {
            ApiResponse::error('SMS service error: ' . $e->getMessage());
        }
    }
    
    /**
     * Check SMS configuration
     */
    private function checkSMSConfig($input) {
        // Check for admin authentication (header or body)
        $admin_key = $input['admin_key'] ?? '';
        $headers = getallheaders();
        $header_admin_key = $headers['X-Admin-Key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey() && $header_admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized - Invalid admin key', 401);
            return;
        }
        
        try {
            $config = [
                'username' => Config::getAfricasTalkingUsername(),
                'api_key' => Config::getAfricasTalkingApiKey(),
                'sender_id' => Config::getAfricasTalkingSenderId(),
                'api_status' => 'Ready'
            ];
            
            // Don't expose full API key, just show if it's set
            if ($config['api_key']) {
                $config['api_key'] = substr($config['api_key'], 0, 8) . '***';
            }
            
            ApiResponse::success($config, 'SMS configuration retrieved');
        } catch (Exception $e) {
            ApiResponse::error('SMS configuration error: ' . $e->getMessage());
        }
    }
    
    /**
     * Send manual voucher SMS
     */
    private function sendVoucherSMSManual($input) {
        // Check for admin authentication (header or body)
        $admin_key = $input['admin_key'] ?? '';
        $headers = getallheaders();
        $header_admin_key = $headers['X-Admin-Key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey() && $header_admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized - Invalid admin key', 401);
            return;
        }
        
        if (empty($input['phone']) || empty($input['voucher_code']) || empty($input['package_type'])) {
            ApiResponse::error('Phone, voucher code and package type are required');
            return;
        }
        
        try {
            require_once 'sms_service.php';
            $smsService = new SMSService();
            
            // Fetch actual package from database
            $db = $this->userManager->getDatabase();
            $stmt = $db->prepare("SELECT * FROM packages WHERE id = ? AND is_active = 1");
            $stmt->execute([$input['package_type']]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$package) {
                ApiResponse::error('Package not found or inactive');
                return;
            }
            
            // Format duration for display
            $hours = floatval($package['duration_hours']);
            if ($hours < 1) {
                $minutes = intval($hours * 60);
                $duration = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
            } elseif ($hours >= 24) {
                $days = floor($hours / 24);
                $duration = $days . ' day' . ($days > 1 ? 's' : '');
            } else {
                $duration = intval($hours) . ' hour' . ($hours > 1 ? 's' : '');
            }
            
            // Determine data limit based on package type or use speed limit as fallback
            $dataLimit = 'Unlimited';
            if (isset($package['speed_limit_mbps']) && $package['speed_limit_mbps']) {
                $dataLimit = $package['speed_limit_mbps'] . 'Mbps Speed';
            }
            
            $packageInfo = [
                'name' => $package['name'],
                'duration' => $duration,
                'data' => $dataLimit
            ];
            
            $voucherData = [
                'code' => $input['voucher_code'],
                'package_name' => $packageInfo['name'],
                'duration' => $packageInfo['duration'],
                'data_limit' => $packageInfo['data']
            ];
            
            $result = $smsService->sendVoucherSMS($input['phone'], $voucherData);
            
            if ($result['success']) {
                // Log SMS sending attempt
                $this->logSMSActivity($input['phone'], 'voucher', 'success');
                ApiResponse::success($result, 'Voucher SMS sent successfully');
            } else {
                $this->logSMSActivity($input['phone'], 'voucher', 'failed');
                ApiResponse::error($result['message']);
            }
        } catch (Exception $e) {
            ApiResponse::error('SMS service error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get SMS statistics
     */
    private function getSMSStats($input) {
        // Check for admin authentication (header or body)
        $admin_key = $input['admin_key'] ?? '';
        $headers = getallheaders();
        $header_admin_key = $headers['X-Admin-Key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey() && $header_admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized - Invalid admin key', 401);
            return;
        }
        
        try {
            $db = $this->userManager->getDatabase();
            
            // Get total SMS sent (mock data for now - would be from SMS logs table)
            $totalStmt = $db->query("SELECT COUNT(*) as total FROM sqlite_master WHERE type='table' AND name='sms_logs'");
            $hasLogsTable = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
            
            if ($hasLogsTable) {
                $totalStmt = $db->query("SELECT COUNT(*) as total FROM sms_logs");
                $total = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];
                
                $todayStmt = $db->query("SELECT COUNT(*) as today FROM sms_logs WHERE date(timestamp) = date('now')");
                $today = $todayStmt->fetch(PDO::FETCH_ASSOC)['today'];
                
                $successStmt = $db->query("SELECT COUNT(*) as success FROM sms_logs WHERE status = 'success'");
                $success = $successStmt->fetch(PDO::FETCH_ASSOC)['success'];
                
                $successRate = $total > 0 ? round(($success / $total) * 100, 1) : 0;
            } else {
                $total = 0;
                $today = 0;
                $successRate = 0;
            }
            
            $stats = [
                'total' => $total,
                'today' => $today,
                'success_rate' => $successRate
            ];
            
            ApiResponse::success($stats, 'SMS statistics retrieved');
        } catch (Exception $e) {
            ApiResponse::error('Error retrieving SMS statistics: ' . $e->getMessage());
        }
    }
    
    /**
     * Get SMS logs
     */
    private function getSMSLogs($input) {
        // Check for admin authentication (header or body)
        $admin_key = $input['admin_key'] ?? '';
        $headers = getallheaders();
        $header_admin_key = $headers['X-Admin-Key'] ?? '';
        
        if ($admin_key !== Config::getAdminKey() && $header_admin_key !== Config::getAdminKey()) {
            ApiResponse::error('Unauthorized - Invalid admin key', 401);
            return;
        }
        
        try {
            $db = $this->userManager->getDatabase();
            
            // Check if SMS logs table exists
            $tableStmt = $db->query("SELECT COUNT(*) as total FROM sqlite_master WHERE type='table' AND name='sms_logs'");
            $hasLogsTable = $tableStmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
            
            if (!$hasLogsTable) {
                // Create SMS logs table if it doesn't exist
                $this->createSMSLogsTable($db);
                ApiResponse::success([], 'SMS logs table created');
                return;
            }
            
            $stmt = $db->query("SELECT * FROM sms_logs ORDER BY timestamp DESC LIMIT 50");
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            ApiResponse::success($logs, 'SMS logs retrieved');
        } catch (Exception $e) {
            ApiResponse::error('Error retrieving SMS logs: ' . $e->getMessage());
        }
    }
    
    /**
     * Log SMS activity
     */
    private function logSMSActivity($phone, $type, $status) {
        try {
            $db = $this->userManager->getDatabase();
            
            // Create table if it doesn't exist
            $this->createSMSLogsTable($db);
            
            // Use EAT timezone for logging
            $eatTime = Config::getEATDateTime();
            $stmt = $db->prepare("INSERT INTO sms_logs (phone, type, status, timestamp) VALUES (?, ?, ?, ?)");
            $stmt->execute([$phone, $type, $status, $eatTime]);
        } catch (Exception $e) {
            error_log("Failed to log SMS activity: " . $e->getMessage());
        }
    }
    
    /**
     * Create SMS logs table
     */
    private function createSMSLogsTable($db) {
        $sql = "CREATE TABLE IF NOT EXISTS sms_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone VARCHAR(20) NOT NULL,
            type VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $db->exec($sql);
    }
    
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

// Only handle requests in web context
if (isset($_SERVER['REQUEST_METHOD'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $api = new WifiAPI();
        $api->handleRequest();
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Simple status check
    ApiResponse::success([
        'service' => Config::BUSINESS_NAME . ' WiFi API',
        'status' => 'running',
        'version' => '1.0.0',
        'contact' => Config::CUSTOMER_CARE
        ], 'WiFi Billing API is running');
    } else {
        ApiResponse::error('Method not allowed', 405);
    }
}

?>