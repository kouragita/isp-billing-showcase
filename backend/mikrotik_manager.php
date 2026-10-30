<?php
/**
 * MikroTik RouterOS API Manager
 * Lightweight, performance-optimized integration
 * Extends existing billing system without breaking changes
 */

class MikroTikManager {
    private $db;
    private $cache = [];
    
    public function __construct($database) {
        $this->db = $database;
        $this->initializeTables();
    }
    
    /**
     * Initialize MikroTik tables if they don't exist
     */
    private function initializeTables() {
        // Create routers table
        $sql = "CREATE TABLE IF NOT EXISTS mikrotik_routers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(50) NOT NULL,
            host VARCHAR(50) NOT NULL,
            api_port INTEGER DEFAULT 8728,
            username VARCHAR(50) NOT NULL,
            password VARCHAR(100) NOT NULL,
            status VARCHAR(20) DEFAULT 'offline',
            last_ping DATETIME,
            firmware_version VARCHAR(20),
            model VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->db->exec($sql);
        
        // Create router user sessions table
        $sql = "CREATE TABLE IF NOT EXISTS router_user_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            router_id INTEGER NOT NULL,
            wifi_session_id INTEGER NOT NULL,
            mikrotik_username VARCHAR(50),
            profile VARCHAR(50) DEFAULT 'default',
            client_ip VARCHAR(15),
            mac_address VARCHAR(18),
            bytes_in BIGINT DEFAULT 0,
            bytes_out BIGINT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'inactive',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(wifi_session_id) REFERENCES wifi_sessions(id),
            FOREIGN KEY(router_id) REFERENCES mikrotik_routers(id)
        )";
        $this->db->exec($sql);
        
        error_log("🌐 MikroTik tables initialized successfully");
    }
    
    /**
     * Get all registered routers with status
     */
    public function getRouters() {
        $sql = "SELECT * FROM mikrotik_routers ORDER BY created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add live status check (cached for performance)
        foreach ($routers as &$router) {
            $router['live_status'] = $this->getRouterStatus($router['id'], true);
            $router['last_seen'] = $this->timeAgo($router['last_ping']);
        }
        
        return $routers;
    }
    
    /**
     * Add new router
     */
    public function addRouter($data) {
        try {
            // Validate input
            $requiredFields = ['name', 'host', 'username', 'password'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Test connection before saving
            $connectionTest = $this->testConnection(
                $data['host'],
                $data['username'],
                $data['password'],
                $data['api_port'] ?? 8728
            );
            
            if (!$connectionTest['success']) {
                throw new Exception("Connection test failed: " . $connectionTest['message']);
            }
            
            $sql = "INSERT INTO mikrotik_routers 
                    (name, host, api_port, username, password, status, firmware_version, model, updated_at) 
                    VALUES (?, ?, ?, ?, ?, 'online', ?, ?, datetime('now'))";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['name'],
                $data['host'],
                $data['api_port'] ?? 8728,
                $data['username'],
                $data['password'],
                $connectionTest['firmware'] ?? 'Unknown',
                $connectionTest['model'] ?? 'Unknown'
            ]);
            
            $routerId = $this->db->lastInsertId();
            
            error_log("🆕 MikroTik router added: {$data['name']} ({$data['host']})");
            
            return [
                'success' => true,
                'message' => 'Router added successfully',
                'router_id' => $routerId,
                'connection_info' => $connectionTest
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to add MikroTik router: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Test router connection (lightweight)
     */
    public function testConnection($host, $username, $password, $port = 8728) {
        try {
            $api = new RouterOSAPI();
            $connected = $api->connect($host, $username, $password, $port);
            
            if (!$connected) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to router'
                ];
            }
            
            // Get basic router info
            $identity = $api->comm('/system/identity/print');
            $resource = $api->comm('/system/resource/print');
            
            $api->disconnect();
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'identity' => $identity[0]['name'] ?? 'Unknown',
                'firmware' => $resource[0]['version'] ?? 'Unknown',
                'model' => $resource[0]['board-name'] ?? 'Unknown',
                'uptime' => $resource[0]['uptime'] ?? 'Unknown'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get router status (with caching for performance)
     */
    public function getRouterStatus($routerId, $useCache = true) {
        $cacheKey = "your_cache_key}";
        
        // Return cached status if available and recent (30 seconds)
        if ($useCache && isset($this->cache[$cacheKey])) {
            if (time() - $this->cache[$cacheKey]['timestamp'] < 30) {
                return $this->cache[$cacheKey]['status'];
            }
        }
        
        try {
            $sql = "SELECT host, username, password, api_port FROM mikrotik_routers WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$routerId]);
            $router = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$router) {
                return 'not_found';
            }
            
            // Quick ping test (lightweight)
            $status = $this->quickPing($router['host']) ? 'online' : 'offline';
            
            // Update database
            $sql = "UPDATE mikrotik_routers SET status = ?, last_ping = datetime('now') WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$status, $routerId]);
            
            // Cache result
            $this->cache[$cacheKey] = [
                'status' => $status,
                'timestamp' => time()
            ];
            
            return $status;
            
        } catch (Exception $e) {
            error_log("Error checking router status: " . $e->getMessage());
            return 'error';
        }
    }
    
    /**
     * Lightweight ping test
     */
    private function quickPing($host, $timeout = 2) {
        $context = stream_context_create([
            'socket' => [
                'timeout' => $timeout,
            ],
        ]);
        
        $fp = @stream_socket_client("tcp://{$host}:8728", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        
        if ($fp) {
            fclose($fp);
            return true;
        }
        
        return false;
    }
    
    /**
     * Sync WiFi session with MikroTik router
     */
    public function syncSessionToRouter($sessionId, $routerId) {
        try {
            // Get session details
            $sql = "SELECT * FROM wifi_sessions WHERE id = ? AND status = 'active'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                throw new Exception("Active session not found");
            }
            
            // Get router details  
            $sql = "SELECT * FROM mikrotik_routers WHERE id = ? AND status = 'online'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$routerId]);
            $router = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$router) {
                throw new Exception("Online router not found");
            }
            
            // Create unique username
            $mikrotikUsername = "user_" . $sessionId . "_" . substr($session['phone_number'], -4);
            $mikrotikPassword = substr(md5($session['checkout_request_id']), 0, 8);
            
            // Connect to router and create user
            $api = new RouterOSAPI();
            $connected = $api->connect($router['host'], $router['username'], $router['password'], $router['api_port']);
            
            if (!$connected) {
                throw new Exception("Failed to connect to router");
            }
            
            // Add hotspot user
            $api->comm('/ip/hotspot/user/add', [
                'name' => $mikrotikUsername,
                'password' => $mikrotikPassword,
                'profile' => 'default',
                'limit-uptime' => $this->formatDuration($session['duration_hours'])
            ]);
            
            $api->disconnect();
            
            // Save to database
            $sql = "INSERT INTO router_user_sessions 
                    (router_id, wifi_session_id, mikrotik_username, profile, status, updated_at) 
                    VALUES (?, ?, ?, 'default', 'active', datetime('now'))";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$routerId, $sessionId, $mikrotikUsername]);
            
            error_log("✅ Session synced to router: Session {$sessionId} → {$mikrotikUsername}");
            
            return [
                'success' => true,
                'message' => 'Session synced to router successfully',
                'mikrotik_username' => $mikrotikUsername,
                'mikrotik_password' => $mikrotikPassword
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to sync session to router: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Format duration for MikroTik
     */
    private function formatDuration($hours) {
        if ($hours < 1) {
            return (int)($hours * 60) . 'm';
        } else {
            return $hours . 'h';
        }
    }
    
    /**
     * Human-readable time ago
     */
    private function timeAgo($datetime) {
        if (!$datetime) return 'Never';
        
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'Just now';
        if ($time < 3600) return floor($time/60) . 'm ago';
        if ($time < 86400) return floor($time/3600) . 'h ago';
        return floor($time/86400) . 'd ago';
    }
    
    /**
     * Get router statistics
     */
    public function getRouterStats() {
        $sql = "SELECT 
                    COUNT(*) as total_routers,
                    SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online_routers,
                    SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline_routers
                FROM mikrotik_routers";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get active sessions on routers
        $sql = "SELECT COUNT(*) as active_sessions FROM router_user_sessions WHERE status = 'active'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $sessionStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return array_merge($stats, $sessionStats);
    }
}

/**
 * Lightweight RouterOS API Client
 * Optimized for minimal memory usage and fast connections
 */
class RouterOSAPI {
    private $socket;
    private $timeout = 5;
    
    public function connect($host, $user, $pass, $port = 8728) {
        try {
            $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
            socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
            
            $result = socket_connect($this->socket, $host, $port);
            if (!$result) {
                throw new Exception('Connection failed');
            }
            
            return $this->login($user, $pass);
            
        } catch (Exception $e) {
            error_log("RouterOS connection error: " . $e->getMessage());
            return false;
        }
    }
    
    public function disconnect() {
        if ($this->socket) {
            socket_close($this->socket);
        }
    }
    
    public function comm($command, $arguments = array()) {
        $data = $this->encodeLength(strlen($command)) . $command;
        
        foreach ($arguments as $key => $value) {
            $argument = '=' . $key . '=' . $value;
            $data .= $this->encodeLength(strlen($argument)) . $argument;
        }
        
        $data .= $this->encodeLength(0);
        
        socket_write($this->socket, $data);
        
        return $this->read();
    }
    
    private function login($user, $pass) {
        $this->comm('/login');
        $response = $this->read();
        
        if (isset($response[0]['!trap'])) {
            return false;
        }
        
        $this->comm('/login', array('name' => $user, 'password' => $pass));
        $response = $this->read();
        
        return !isset($response[0]['!trap']);
    }
    
    private function encodeLength($length) {
        if ($length < 0x80) {
            return chr($length);
        } else if ($length < 0x4000) {
            return chr(($length >> 8) | 0x80) . chr($length);
        } else if ($length < 0x200000) {
            return chr(($length >> 16) | 0xC0) . chr($length >> 8) . chr($length);
        } else if ($length < 0x10000000) {
            return chr(($length >> 24) | 0xE0) . chr($length >> 16) . chr($length >> 8) . chr($length);
        } else {
            return chr(0xF0) . chr($length >> 24) . chr($length >> 16) . chr($length >> 8) . chr($length);
        }
    }
    
    private function read() {
        $response = array();
        
        while (true) {
            $length = $this->readLength();
            if ($length === false) break;
            if ($length == 0) break;
            
            $data = '';
            while (strlen($data) < $length) {
                $buffer = socket_read($this->socket, $length - strlen($data));
                if ($buffer === false) break 2;
                $data .= $buffer;
            }
            
            if (substr($data, 0, 1) == '!') {
                $response[]['!trap'] = substr($data, 1);
                break;
            } else {
                $response[] = $this->parseAttributes($data);
            }
        }
        
        return $response;
    }
    
    private function readLength() {
        $byte = socket_read($this->socket, 1);
        if ($byte === false) return false;
        
        $byte = ord($byte);
        
        if ($byte < 0x80) {
            return $byte;
        } else if ($byte < 0xC0) {
            return (($byte & 0x7F) << 8) + ord(socket_read($this->socket, 1));
        } else if ($byte < 0xE0) {
            return (($byte & 0x3F) << 16) + (ord(socket_read($this->socket, 1)) << 8) + ord(socket_read($this->socket, 1));
        } else if ($byte < 0xF0) {
            return (($byte & 0x1F) << 24) + (ord(socket_read($this->socket, 1)) << 16) + (ord(socket_read($this->socket, 1)) << 8) + ord(socket_read($this->socket, 1));
        } else {
            return (ord(socket_read($this->socket, 1)) << 24) + (ord(socket_read($this->socket, 1)) << 16) + (ord(socket_read($this->socket, 1)) << 8) + ord(socket_read($this->socket, 1));
        }
    }
    
    private function parseAttributes($data) {
        $attributes = array();
        $offset = 1;
        
        while ($offset < strlen($data)) {
            $equalPos = strpos($data, '=', $offset);
            if ($equalPos === false) break;
            
            $key = substr($data, $offset, $equalPos - $offset);
            $offset = $equalPos + 1;
            
            $nextEqual = strpos($data, '=', $offset);
            if ($nextEqual === false) {
                $value = substr($data, $offset);
                $offset = strlen($data);
            } else {
                $value = substr($data, $offset, $nextEqual - $offset - 1);
                $offset = $nextEqual - 1;
            }
            
            $attributes[$key] = $value;
        }
        
        return $attributes;
    }
}
?>