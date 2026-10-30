<?php
/**
 * Real Bandwidth Management Engine
 * Production-grade ISP bandwidth control and queue management
 * Integrates with MikroTik RouterOS API for actual speed enforcement
 */

class BandwidthManager {
    private $db;
    private $routerApi;
    private $cache = [];
    
    public function __construct($database) {
        $this->db = $database;
        $this->initializeBandwidthTables();
    }
    
    /**
     * Initialize bandwidth management tables
     */
    private function initializeBandwidthTables() {
        // Enhanced packages table with real bandwidth fields
        $sql = "CREATE TABLE IF NOT EXISTS bandwidth_packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            price INTEGER NOT NULL,
            duration_hours DECIMAL(10,2) NOT NULL,
            download_speed INTEGER NOT NULL,      -- Mbps guaranteed
            upload_speed INTEGER NOT NULL,        -- Mbps guaranteed  
            burst_download INTEGER,               -- Burst speed Mbps
            burst_upload INTEGER,                 -- Burst speed Mbps
            burst_threshold INTEGER DEFAULT 256, -- Burst threshold KB
            burst_time INTEGER DEFAULT 8,        -- Burst time seconds
            data_limit_gb INTEGER,                -- Monthly data limit
            fup_threshold_gb INTEGER,             -- FUP trigger point
            fup_speed_reduction DECIMAL(3,2) DEFAULT 0.5, -- FUP reduction (50%)
            priority INTEGER DEFAULT 8,          -- Queue priority (1-8)
            mikrotik_profile_name VARCHAR(100),
            pcq_classification VARCHAR(50) DEFAULT 'src-address',
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(client_id) REFERENCES clients(id)
        )";
        $this->db->exec($sql);
        
        // User bandwidth queues tracking
        $sql = "CREATE TABLE IF NOT EXISTS user_bandwidth_queues (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            router_id INTEGER NOT NULL,
            session_id INTEGER NOT NULL,
            username VARCHAR(100) NOT NULL,
            package_id INTEGER NOT NULL,
            queue_name VARCHAR(100) NOT NULL,
            target_ip VARCHAR(15),
            current_download_speed INTEGER,
            current_upload_speed INTEGER,
            bytes_downloaded BIGINT DEFAULT 0,
            bytes_uploaded BIGINT DEFAULT 0,
            queue_status VARCHAR(20) DEFAULT 'active',
            fup_applied BOOLEAN DEFAULT 0,
            fup_applied_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(client_id) REFERENCES clients(id),
            FOREIGN KEY(router_id) REFERENCES client_routers(id),
            FOREIGN KEY(package_id) REFERENCES bandwidth_packages(id),
            UNIQUE(router_id, queue_name)
        )";
        $this->db->exec($sql);
        
        // Bandwidth usage monitoring
        $sql = "CREATE TABLE IF NOT EXISTS bandwidth_usage_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            router_id INTEGER NOT NULL,
            username VARCHAR(100) NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            download_bytes BIGINT DEFAULT 0,
            upload_bytes BIGINT DEFAULT 0,
            download_speed INTEGER DEFAULT 0,     -- Current speed in bps
            upload_speed INTEGER DEFAULT 0,       -- Current speed in bps
            queue_utilization DECIMAL(5,2),       -- Queue usage percentage
            session_active BOOLEAN DEFAULT 1,
            FOREIGN KEY(client_id) REFERENCES clients(id),
            FOREIGN KEY(router_id) REFERENCES client_routers(id)
        )";
        $this->db->exec($sql);
        
        // Fair Usage Policy tracking
        $sql = "CREATE TABLE IF NOT EXISTS fup_violations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            router_id INTEGER NOT NULL,
            username VARCHAR(100) NOT NULL,
            package_id INTEGER NOT NULL,
            violation_type VARCHAR(50) NOT NULL,  -- 'data_limit', 'time_limit', 'congestion'
            threshold_value INTEGER NOT NULL,
            actual_value INTEGER NOT NULL,
            action_taken VARCHAR(100) NOT NULL,   -- 'speed_reduced', 'disconnected'
            original_speed INTEGER,
            reduced_speed INTEGER,
            violation_start DATETIME DEFAULT CURRENT_TIMESTAMP,
            violation_end DATETIME,
            status VARCHAR(20) DEFAULT 'active',
            FOREIGN KEY(client_id) REFERENCES clients(id),
            FOREIGN KEY(router_id) REFERENCES client_routers(id),
            FOREIGN KEY(package_id) REFERENCES bandwidth_packages(id)
        )";
        $this->db->exec($sql);
        
        error_log("🚀 Bandwidth management tables initialized successfully");
    }
    
    /**
     * Create bandwidth package with real MikroTik profiles
     */
    public function createBandwidthPackage($clientId, $packageData) {
        try {
            // Validate bandwidth settings
            if ($packageData['download_speed'] < 1 || $packageData['upload_speed'] < 1) {
                throw new Exception("Minimum speed must be at least 1 Mbps");
            }
            
            // Generate MikroTik profile name
            $profileName = strtolower(str_replace(' ', '-', $packageData['name'])) . '-' . $clientId;
            $packageData['mikrotik_profile_name'] = $profileName;
            
            // Insert package
            $sql = "INSERT INTO bandwidth_packages 
                    (client_id, name, price, duration_hours, download_speed, upload_speed, 
                     burst_download, burst_upload, burst_threshold, burst_time, data_limit_gb, 
                     fup_threshold_gb, fup_speed_reduction, priority, mikrotik_profile_name, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $clientId,
                $packageData['name'],
                $packageData['price'],
                $packageData['duration_hours'],
                $packageData['download_speed'],
                $packageData['upload_speed'],
                $packageData['burst_download'] ?? ($packageData['download_speed'] * 2),
                $packageData['burst_upload'] ?? ($packageData['upload_speed'] * 2),
                $packageData['burst_threshold'] ?? 256,
                $packageData['burst_time'] ?? 8,
                $packageData['data_limit_gb'] ?? null,
                $packageData['fup_threshold_gb'] ?? ($packageData['data_limit_gb'] * 0.8),
                $packageData['fup_speed_reduction'] ?? 0.5,
                $packageData['priority'] ?? 8,
                $profileName
            ]);
            
            $packageId = $this->db->lastInsertId();
            
            // Generate MikroTik configuration for this package
            $mikrotikConfig = $this->generatePackageMikroTikConfig($packageData, $profileName);
            
            error_log("📦 Bandwidth package created: {$packageData['name']} ({$packageData['download_speed']}/{$packageData['upload_speed']} Mbps)");
            
            return [
                'success' => true,
                'package_id' => $packageId,
                'mikrotik_profile' => $profileName,
                'mikrotik_config' => $mikrotikConfig,
                'message' => 'Bandwidth package created successfully'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to create bandwidth package: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create actual bandwidth queue on MikroTik router
     */
    public function createUserBandwidthQueue($routerId, $sessionId, $packageId, $username, $targetIp) {
        try {
            // Get router details
            $stmt = $this->db->prepare("SELECT * FROM client_routers WHERE id = ?");
            $stmt->execute([$routerId]);
            $router = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$router) {
                throw new Exception("Router not found");
            }
            
            // Get package bandwidth settings
            $stmt = $this->db->prepare("SELECT * FROM bandwidth_packages WHERE id = ?");
            $stmt->execute([$packageId]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$package) {
                throw new Exception("Bandwidth package not found");
            }
            
            // Connect to MikroTik RouterOS API
            require_once 'mikrotik_manager.php';
            $api = new RouterOSAPI();
            $connected = $api->connect($router['host'], $router['username'], $router['password'], $router['api_port']);
            
            if (!$connected) {
                throw new Exception("Failed to connect to router");
            }
            
            // Generate unique queue name
            $queueName = "user-{$username}-" . substr(md5($sessionId), 0, 8);
            
            // Create download queue (limit incoming traffic)
            $downloadLimit = $package['download_speed'] . 'M/' . $package['burst_download'] . 'M';
            $uploadLimit = $package['upload_speed'] . 'M/' . $package['burst_upload'] . 'M';
            
            // Create simple queue for user
            $api->comm('/queue/simple/add', [
                'name' => $queueName,
                'target' => $targetIp . '/32',
                'max-limit' => $uploadLimit . '/' . $downloadLimit,
                'burst-limit' => $package['burst_upload'] . 'M/' . $package['burst_download'] . 'M',
                'burst-threshold' => $package['burst_threshold'] . 'k/' . $package['burst_threshold'] . 'k',
                'burst-time' => $package['burst_time'] . 's/' . $package['burst_time'] . 's',
                'priority' => $package['priority'] . '/' . $package['priority'],
                'comment' => "Billing-{$package['name']}-Session{$sessionId}"
            ]);
            
            $api->disconnect();
            
            // Save queue info to database
            $stmt = $this->db->prepare("
                INSERT INTO user_bandwidth_queues 
                (client_id, router_id, session_id, username, package_id, queue_name, target_ip, 
                 current_download_speed, current_upload_speed, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            
            $stmt->execute([
                $router['client_id'],
                $routerId,
                $sessionId,
                $username,
                $packageId,
                $queueName,
                $targetIp,
                $package['download_speed'],
                $package['upload_speed']
            ]);
            
            error_log("⚡ Bandwidth queue created: {$queueName} ({$package['download_speed']}/{$package['upload_speed']} Mbps) for {$targetIp}");
            
            return [
                'success' => true,
                'queue_name' => $queueName,
                'download_speed' => $package['download_speed'],
                'upload_speed' => $package['upload_speed'],
                'target_ip' => $targetIp,
                'message' => 'User bandwidth queue created successfully'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to create bandwidth queue: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Monitor bandwidth usage and apply Fair Usage Policy
     */
    public function monitorAndApplyFUP() {
        try {
            // Get all active bandwidth queues
            $stmt = $this->db->prepare("
                SELECT ubq.*, bp.*, cr.host, cr.username, cr.password, cr.api_port 
                FROM user_bandwidth_queues ubq
                JOIN bandwidth_packages bp ON ubq.package_id = bp.id
                JOIN client_routers cr ON ubq.router_id = cr.id
                WHERE ubq.queue_status = 'active'
            ");
            $stmt->execute();
            $activeQueues = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($activeQueues as $queue) {
                $this->checkAndApplyFUP($queue);
                $this->updateUsageStatistics($queue);
            }
            
            error_log("📊 FUP monitoring completed for " . count($activeQueues) . " active queues");
            
            return [
                'success' => true,
                'monitored_queues' => count($activeQueues),
                'message' => 'FUP monitoring completed'
            ];
            
        } catch (Exception $e) {
            error_log("❌ FUP monitoring failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check and apply Fair Usage Policy for a user
     */
    private function checkAndApplyFUP($queue) {
        try {
            // Connect to router to get current usage
            $api = new RouterOSAPI();
            $connected = $api->connect($queue['host'], $queue['username'], $queue['password'], $queue['api_port']);
            
            if (!$connected) {
                return false;
            }
            
            // Get queue statistics
            $queueStats = $api->comm('/queue/simple/print', ['?name=' . $queue['queue_name']]);
            
            if (empty($queueStats)) {
                $api->disconnect();
                return false;
            }
            
            $stats = $queueStats[0];
            $downloadBytes = (int)$stats['bytes'][1] ?? 0; // Download bytes
            $uploadBytes = (int)$stats['bytes'][0] ?? 0;   // Upload bytes
            $totalGB = ($downloadBytes + $uploadBytes) / (1024 * 1024 * 1024);
            
            // Check if FUP threshold exceeded
            if ($queue['fup_threshold_gb'] && $totalGB > $queue['fup_threshold_gb'] && !$queue['fup_applied']) {
                $this->applyFUPSpeedReduction($api, $queue, $totalGB);
            }
            
            // Log usage statistics
            $this->logUsageData($queue, $downloadBytes, $uploadBytes);
            
            $api->disconnect();
            
        } catch (Exception $e) {
            error_log("❌ FUP check failed for queue {$queue['queue_name']}: " . $e->getMessage());
        }
    }
    
    /**
     * Apply FUP speed reduction
     */
    private function applyFUPSpeedReduction($api, $queue, $totalUsageGB) {
        try {
            // Calculate reduced speeds
            $newDownloadSpeed = (int)($queue['current_download_speed'] * $queue['fup_speed_reduction']);
            $newUploadSpeed = (int)($queue['current_upload_speed'] * $queue['fup_speed_reduction']);
            
            // Update queue with reduced speeds
            $newLimit = $newUploadSpeed . 'M/' . $newDownloadSpeed . 'M';
            
            $api->comm('/queue/simple/set', [
                '?name=' . $queue['queue_name'],
                'max-limit' => $newLimit,
                'comment' => 'FUP-APPLIED-' . date('Y-m-d-H:i') . '-' . round($totalUsageGB, 2) . 'GB'
            ]);
            
            // Update database
            $stmt = $this->db->prepare("
                UPDATE user_bandwidth_queues 
                SET fup_applied = 1, fup_applied_at = datetime('now'),
                    current_download_speed = ?, current_upload_speed = ?
                WHERE id = ?
            ");
            $stmt->execute([$newDownloadSpeed, $newUploadSpeed, $queue['id']]);
            
            // Log FUP violation
            $stmt = $this->db->prepare("
                INSERT INTO fup_violations 
                (client_id, router_id, username, package_id, violation_type, threshold_value, 
                 actual_value, action_taken, original_speed, reduced_speed, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            
            $stmt->execute([
                $queue['client_id'],
                $queue['router_id'],
                $queue['username'],
                $queue['package_id'],
                'data_limit',
                $queue['fup_threshold_gb'],
                $totalUsageGB,
                'speed_reduced',
                $queue['current_download_speed'],
                $newDownloadSpeed
            ]);
            
            error_log("🚨 FUP applied to {$queue['username']}: {$totalUsageGB}GB exceeded, speed reduced to {$newDownloadSpeed}/{$newUploadSpeed} Mbps");
            
        } catch (Exception $e) {
            error_log("❌ Failed to apply FUP to {$queue['username']}: " . $e->getMessage());
        }
    }
    
    /**
     * Log bandwidth usage data
     */
    private function logUsageData($queue, $downloadBytes, $uploadBytes) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO bandwidth_usage_logs 
                (client_id, router_id, username, download_bytes, upload_bytes, timestamp) 
                VALUES (?, ?, ?, ?, ?, datetime('now'))
            ");
            
            $stmt->execute([
                $queue['client_id'],
                $queue['router_id'],
                $queue['username'],
                $downloadBytes,
                $uploadBytes
            ]);
            
        } catch (Exception $e) {
            error_log("❌ Failed to log usage data: " . $e->getMessage());
        }
    }
    
    /**
     * Remove user bandwidth queue
     */
    public function removeUserBandwidthQueue($routerId, $username) {
        try {
            // Get queue info
            $stmt = $this->db->prepare("
                SELECT ubq.*, cr.host, cr.username as router_user, cr.password, cr.api_port 
                FROM user_bandwidth_queues ubq
                JOIN client_routers cr ON ubq.router_id = cr.id
                WHERE ubq.router_id = ? AND ubq.username = ? AND ubq.queue_status = 'active'
            ");
            $stmt->execute([$routerId, $username]);
            $queue = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$queue) {
                return ['success' => false, 'message' => 'Queue not found'];
            }
            
            // Connect to router and remove queue
            $api = new RouterOSAPI();
            $connected = $api->connect($queue['host'], $queue['router_user'], $queue['password'], $queue['api_port']);
            
            if ($connected) {
                $api->comm('/queue/simple/remove', ['?name=' . $queue['queue_name']]);
                $api->disconnect();
            }
            
            // Update database
            $stmt = $this->db->prepare("
                UPDATE user_bandwidth_queues 
                SET queue_status = 'removed', updated_at = datetime('now') 
                WHERE id = ?
            ");
            $stmt->execute([$queue['id']]);
            
            error_log("🗑️ Bandwidth queue removed: {$queue['queue_name']} for {$username}");
            
            return [
                'success' => true,
                'message' => 'User bandwidth queue removed successfully'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to remove bandwidth queue: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate MikroTik configuration for package
     */
    private function generatePackageMikroTikConfig($package, $profileName) {
        $downloadSpeed = $package['download_speed'] . 'M';
        $uploadSpeed = $package['upload_speed'] . 'M';
        $burstDownload = ($package['burst_download'] ?? ($package['download_speed'] * 2)) . 'M';
        $burstUpload = ($package['burst_upload'] ?? ($package['upload_speed'] * 2)) . 'M';
        
        return "
# Bandwidth Package: {$package['name']}
# Profile: {$profileName}

# Create user profile for package
/ip hotspot user profile add name=\"{$profileName}\" \\
    rate-limit=\"{$uploadSpeed}/{$downloadSpeed}\" \\
    session-timeout={$package['duration_hours']}h \\
    shared-users=1 \\
    status-autorefresh=1m

# Create PCQ queue types for this package  
/queue type add name=\"{$profileName}-download\" kind=pcq \\
    pcq-rate={$downloadSpeed} pcq-limit=50 \\
    pcq-classifier=dst-address

/queue type add name=\"{$profileName}-upload\" kind=pcq \\
    pcq-rate={$uploadSpeed} pcq-limit=50 \\
    pcq-classifier=src-address
";
    }
    
    /**
     * Get bandwidth usage statistics
     */
    public function getBandwidthStats($clientId, $routerId = null) {
        try {
            $whereClause = "WHERE client_id = ?";
            $params = [$clientId];
            
            if ($routerId) {
                $whereClause .= " AND router_id = ?";
                $params[] = $routerId;
            }
            
            // Active queues
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as active_queues,
                       SUM(current_download_speed) as total_download_allocation,
                       SUM(current_upload_speed) as total_upload_allocation,
                       COUNT(CASE WHEN fup_applied = 1 THEN 1 END) as fup_violations
                FROM user_bandwidth_queues 
                {$whereClause} AND queue_status = 'active'
            ");
            $stmt->execute($params);
            $queueStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Usage statistics
            $stmt = $this->db->prepare("
                SELECT SUM(download_bytes) as total_download_bytes,
                       SUM(upload_bytes) as total_upload_bytes,
                       COUNT(DISTINCT username) as unique_users,
                       AVG(download_bytes + upload_bytes) as avg_user_usage
                FROM bandwidth_usage_logs 
                {$whereClause} AND DATE(timestamp) = DATE('now')
            ");
            $stmt->execute($params);
            $usageStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'stats' => [
                    'active_queues' => $queueStats['active_queues'] ?? 0,
                    'total_download_allocation' => $queueStats['total_download_allocation'] ?? 0,
                    'total_upload_allocation' => $queueStats['total_upload_allocation'] ?? 0,
                    'fup_violations' => $queueStats['fup_violations'] ?? 0,
                    'total_download_gb' => round(($usageStats['total_download_bytes'] ?? 0) / (1024*1024*1024), 2),
                    'total_upload_gb' => round(($usageStats['total_upload_bytes'] ?? 0) / (1024*1024*1024), 2),
                    'unique_users_today' => $usageStats['unique_users'] ?? 0,
                    'avg_user_usage_gb' => round(($usageStats['avg_user_usage'] ?? 0) / (1024*1024*1024), 2)
                ]
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to get bandwidth stats: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update usage statistics (called by cron job)
     */
    private function updateUsageStatistics($queue) {
        // This would be called by a background process every few minutes
        // to update real-time usage data from MikroTik routers
    }
}
?>