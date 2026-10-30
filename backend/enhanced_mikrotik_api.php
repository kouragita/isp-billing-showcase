<?php
/**
 * Enhanced Production MikroTik RouterOS API
 * Real ISP-grade router management with bandwidth control
 * Supports queues, user profiles, hotspot management, and monitoring
 */

class EnhancedMikroTikAPI {
    private $socket;
    private $timeout = 10;
    private $connected = false;
    private $routerInfo = [];
    
    public function connect($host, $user, $pass, $port = 8728) {
        try {
            $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            
            if (!$this->socket) {
                throw new Exception('Failed to create socket');
            }
            
            socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
            socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
            
            $result = socket_connect($this->socket, $host, $port);
            if (!$result) {
                throw new Exception('Connection failed: ' . socket_strerror(socket_last_error($this->socket)));
            }
            
            // Perform login
            if ($this->login($user, $pass)) {
                $this->connected = true;
                $this->routerInfo = $this->getRouterInfo();
                error_log("🔗 Connected to MikroTik: {$host}:{$port} (RouterOS {$this->routerInfo['version']})");
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("❌ MikroTik connection error: " . $e->getMessage());
            $this->disconnect();
            return false;
        }
    }
    
    public function disconnect() {
        if ($this->socket && $this->connected) {
            socket_close($this->socket);
            $this->connected = false;
        }
    }
    
    /**
     * Create user bandwidth queue
     */
    public function createUserQueue($queueName, $targetIp, $downloadMbps, $uploadMbps, $burstDownloadMbps = null, $burstUploadMbps = null, $priority = 8) {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $burstDownloadMbps = $burstDownloadMbps ?? ($downloadMbps * 2);
            $burstUploadMbps = $burstUploadMbps ?? ($uploadMbps * 2);
            
            $response = $this->comm('/queue/simple/add', [
                'name' => $queueName,
                'target' => $targetIp . '/32',
                'max-limit' => $uploadMbps . 'M/' . $downloadMbps . 'M',
                'burst-limit' => $burstUploadMbps . 'M/' . $burstDownloadMbps . 'M',
                'burst-threshold' => '256k/256k',
                'burst-time' => '8s/8s',
                'priority' => $priority . '/' . $priority,
                'comment' => 'BILLING-' . date('Y-m-d-H:i:s')
            ]);
            
            if (isset($response[0]['!trap'])) {
                throw new Exception('Failed to create queue: ' . ($response[0]['!trap'] ?? 'Unknown error'));
            }
            
            error_log("⚡ Queue created: {$queueName} ({$uploadMbps}M/{$downloadMbps}M) for {$targetIp}");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Failed to create queue: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update existing queue speeds
     */
    public function updateQueueSpeed($queueName, $downloadMbps, $uploadMbps) {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $response = $this->comm('/queue/simple/set', [
                '?name=' . $queueName,
                'max-limit' => $uploadMbps . 'M/' . $downloadMbps . 'M'
            ]);
            
            if (isset($response[0]['!trap'])) {
                throw new Exception('Failed to update queue: ' . ($response[0]['!trap'] ?? 'Unknown error'));
            }
            
            error_log("🔧 Queue updated: {$queueName} to {$uploadMbps}M/{$downloadMbps}M");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Failed to update queue: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove user queue
     */
    public function removeUserQueue($queueName) {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $response = $this->comm('/queue/simple/remove', [
                '?name=' . $queueName
            ]);
            
            if (isset($response[0]['!trap'])) {
                throw new Exception('Failed to remove queue: ' . ($response[0]['!trap'] ?? 'Unknown error'));
            }
            
            error_log("🗑️ Queue removed: {$queueName}");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Failed to remove queue: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get queue statistics
     */
    public function getQueueStats($queueName = null) {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $params = [];
            if ($queueName) {
                $params[] = '?name=' . $queueName;
            }
            
            $response = $this->comm('/queue/simple/print', $params);
            
            $stats = [];
            foreach ($response as $queue) {
                if (isset($queue['name'])) {
                    $stats[] = [
                        'name' => $queue['name'],
                        'target' => $queue['target'] ?? '',
                        'bytes' => [
                            'upload' => (int)($queue['bytes'][0] ?? 0),
                            'download' => (int)($queue['bytes'][1] ?? 0)
                        ],
                        'packets' => [
                            'upload' => (int)($queue['packets'][0] ?? 0),
                            'download' => (int)($queue['packets'][1] ?? 0)
                        ],
                        'rate' => [
                            'upload' => (int)($queue['rate'][0] ?? 0),
                            'download' => (int)($queue['rate'][1] ?? 0)
                        ],
                        'max_limit' => $queue['max-limit'] ?? '',
                        'comment' => $queue['comment'] ?? ''
                    ];
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("❌ Failed to get queue stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create hotspot user profile with bandwidth limits
     */
    public function createUserProfile($profileName, $downloadMbps, $uploadMbps, $sessionTimeout = '0s', $dataLimit = null) {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $rateLimit = $uploadMbps . 'M/' . $downloadMbps . 'M';
            
            $params = [
                'name' => $profileName,
                'rate-limit' => $rateLimit,
                'session-timeout' => $sessionTimeout,
                'shared-users' => '1',
                'status-autorefresh' => '1m'
            ];
            
            if ($dataLimit) {
                $params['address-list-timeout'] = 'none';
            }
            
            $response = $this->comm('/ip/hotspot/user-profile/add', $params);
            
            if (isset($response[0]['!trap'])) {
                throw new Exception('Failed to create user profile: ' . ($response[0]['!trap'] ?? 'Unknown error'));
            }
            
            error_log("👤 User profile created: {$profileName} ({$rateLimit})");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Failed to create user profile: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create hotspot user
     */
    public function createHotspotUser($username, $password, $profileName, $timeLimit = null) {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $params = [
                'name' => $username,
                'password' => $password,
                'profile' => $profileName
            ];
            
            if ($timeLimit) {
                $params['limit-uptime'] = $timeLimit;
            }
            
            $response = $this->comm('/ip/hotspot/user/add', $params);
            
            if (isset($response[0]['!trap'])) {
                throw new Exception('Failed to create hotspot user: ' . ($response[0]['!trap'] ?? 'Unknown error'));
            }
            
            error_log("🆕 Hotspot user created: {$username} (Profile: {$profileName})");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Failed to create hotspot user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove hotspot user
     */
    public function removeHotspotUser($username) {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $response = $this->comm('/ip/hotspot/user/remove', [
                '?name=' . $username
            ]);
            
            if (isset($response[0]['!trap'])) {
                throw new Exception('Failed to remove hotspot user: ' . ($response[0]['!trap'] ?? 'Unknown error'));
            }
            
            error_log("🗑️ Hotspot user removed: {$username}");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Failed to remove hotspot user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get active hotspot users
     */
    public function getActiveUsers() {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $response = $this->comm('/ip/hotspot/active/print');
            
            $activeUsers = [];
            foreach ($response as $user) {
                if (isset($user['user'])) {
                    $activeUsers[] = [
                        'username' => $user['user'],
                        'address' => $user['address'] ?? '',
                        'mac_address' => $user['mac-address'] ?? '',
                        'uptime' => $user['uptime'] ?? '0s',
                        'bytes_in' => (int)($user['bytes-in'] ?? 0),
                        'bytes_out' => (int)($user['bytes-out'] ?? 0),
                        'packets_in' => (int)($user['packets-in'] ?? 0),
                        'packets_out' => (int)($user['packets-out'] ?? 0),
                        'server' => $user['server'] ?? ''
                    ];
                }
            }
            
            return $activeUsers;
            
        } catch (Exception $e) {
            error_log("❌ Failed to get active users: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get router system information
     */
    public function getSystemInfo() {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $resource = $this->comm('/system/resource/print');
            $identity = $this->comm('/system/identity/print');
            $clock = $this->comm('/system/clock/print');
            
            return [
                'identity' => $identity[0]['name'] ?? 'Unknown',
                'version' => $resource[0]['version'] ?? 'Unknown',
                'architecture' => $resource[0]['architecture-name'] ?? 'Unknown',
                'board' => $resource[0]['board-name'] ?? 'Unknown',
                'uptime' => $resource[0]['uptime'] ?? '0s',
                'cpu_load' => (int)($resource[0]['cpu-load'] ?? 0),
                'free_memory' => (int)($resource[0]['free-memory'] ?? 0),
                'total_memory' => (int)($resource[0]['total-memory'] ?? 0),
                'free_hdd_space' => (int)($resource[0]['free-hdd-space'] ?? 0),
                'total_hdd_space' => (int)($resource[0]['total-hdd-space'] ?? 0),
                'current_time' => $clock[0]['time'] ?? '',
                'current_date' => $clock[0]['date'] ?? ''
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to get system info: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get interface statistics
     */
    public function getInterfaceStats() {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $response = $this->comm('/interface/print');
            
            $interfaces = [];
            foreach ($response as $interface) {
                if (isset($interface['name'])) {
                    $interfaces[] = [
                        'name' => $interface['name'],
                        'type' => $interface['type'] ?? '',
                        'running' => ($interface['running'] ?? 'false') === 'true',
                        'disabled' => ($interface['disabled'] ?? 'false') === 'true',
                        'comment' => $interface['comment'] ?? '',
                        'mtu' => (int)($interface['mtu'] ?? 0),
                        'mac_address' => $interface['mac-address'] ?? ''
                    ];
                }
            }
            
            return $interfaces;
            
        } catch (Exception $e) {
            error_log("❌ Failed to get interface stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Execute custom RouterOS command
     */
    public function executeCommand($command, $params = []) {
        try {
            if (!$this->connected) {
                throw new Exception('Not connected to router');
            }
            
            $response = $this->comm($command, $params);
            
            if (isset($response[0]['!trap'])) {
                throw new Exception('Command failed: ' . ($response[0]['!trap'] ?? 'Unknown error'));
            }
            
            return $response;
            
        } catch (Exception $e) {
            error_log("❌ Failed to execute command '{$command}': " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Install billing system scripts
     */
    public function installBillingScripts($apiUrl, $clientId, $routerId) {
        try {
            // Create billing sync script
            $syncScript = '
:log info "Starting billing sync..."
:local apiUrl "' . $apiUrl . '"
:local clientId "' . $clientId . '"
:local routerId "' . $routerId . '"

# Get active users
:local activeUsers [/ip hotspot active print as-value]
:local userCount [:len $activeUsers]

# Create user list for API
:local userList ""
:foreach user in=$activeUsers do={
    :set userList ($userList . $user->"user" . ",")
}

# Send to billing system
:do {
    /tool fetch url=($apiUrl . "/sync?client_id=" . $clientId . "&router_id=" . $routerId . "&users=" . $userList) dst-path="billing-sync.txt"
    :log info ("Synced " . $userCount . " users with billing system")
} on-error={
    :log error "Failed to sync with billing system"
}
';

            // Install sync script
            $this->comm('/system/script/add', [
                'name' => 'billing-sync',
                'source' => $syncScript
            ]);
            
            // Schedule sync every minute
            $this->comm('/system/scheduler/add', [
                'name' => 'billing-sync-job',
                'interval' => '1m',
                'on-event' => 'billing-sync',
                'comment' => 'Billing system synchronization'
            ]);
            
            error_log("📜 Billing scripts installed successfully");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Failed to install billing scripts: " . $e->getMessage());
            return false;
        }
    }
    
    // Private methods for RouterOS API communication
    private function login($user, $pass) {
        $this->comm('/login');
        $response = $this->read();
        
        if (isset($response[0]['!trap'])) {
            return false;
        }
        
        $this->comm('/login', ['name' => $user, 'password' => $pass]);
        $response = $this->read();
        
        return !isset($response[0]['!trap']);
    }
    
    private function comm($command, $arguments = []) {
        $data = $this->encodeLength(strlen($command)) . $command;
        
        foreach ($arguments as $key => $value) {
            $argument = (substr($key, 0, 1) === '?' ? '' : '=') . $key . '=' . $value;
            $data .= $this->encodeLength(strlen($argument)) . $argument;
        }
        
        $data .= $this->encodeLength(0);
        
        socket_write($this->socket, $data);
        
        return $this->read();
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
        $response = [];
        
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
                if (substr($data, 1, 4) == 'trap') {
                    $response[]['!trap'] = substr($data, 6);
                } elseif (substr($data, 1, 4) == 'done') {
                    break;
                } else {
                    $response[] = $this->parseAttributes($data);
                }
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
        $attributes = [];
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
    
    private function getRouterInfo() {
        try {
            $resource = $this->comm('/system/resource/print');
            return [
                'version' => $resource[0]['version'] ?? 'Unknown',
                'board' => $resource[0]['board-name'] ?? 'Unknown'
            ];
        } catch (Exception $e) {
            return ['version' => 'Unknown', 'board' => 'Unknown'];
        }
    }
}
?>