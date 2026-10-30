<?php
/**
 * Fair Usage Policy (FUP) Enforcement Engine
 * Advanced ISP-grade traffic management and policy enforcement
 * Handles data limits, speed throttling, congestion management, and time-based controls
 */

class FUPEngine {
    private $db;
    private $mikrotikApi;
    private $bandwidthManager;
    private $fupPolicies = [];
    
    public function __construct($database) {
        $this->db = $database;
        $this->initializeFUPTables();
        $this->loadFUPPolicies();
        
        // Initialize required managers
        require_once 'enhanced_mikrotik_api.php';
        require_once 'bandwidth_manager.php';
        
        $this->mikrotikApi = new EnhancedMikroTikAPI();
        $this->bandwidthManager = new BandwidthManager($database);
    }
    
    /**
     * Initialize FUP management tables
     */
    private function initializeFUPTables() {
        // FUP policies configuration
        $sql = "CREATE TABLE IF NOT EXISTS fup_policies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER,                           -- NULL = global policy
            policy_name VARCHAR(100) NOT NULL,
            policy_type VARCHAR(50) NOT NULL,            -- 'data_limit', 'time_limit', 'speed_limit', 'congestion'
            is_active BOOLEAN DEFAULT 1,
            
            -- Data limits
            daily_limit_gb DECIMAL(10,2),
            weekly_limit_gb DECIMAL(10,2),
            monthly_limit_gb DECIMAL(10,2),
            
            -- Speed throttling
            throttle_trigger_gb DECIMAL(10,2),           -- Data usage that triggers throttling
            throttle_speed_down INTEGER,                 -- Reduced download speed (Mbps)
            throttle_speed_up INTEGER,                   -- Reduced upload speed (Mbps)
            throttle_duration_hours INTEGER DEFAULT 24,  -- How long throttling lasts
            
            -- Time-based controls
            time_limit_hours DECIMAL(5,2),              -- Max session time
            daily_time_limit_hours DECIMAL(5,2),        -- Max daily usage time
            peak_hours_start TIME DEFAULT '18:00',      -- Peak hours start
            peak_hours_end TIME DEFAULT '23:00',        -- Peak hours end
            peak_speed_reduction DECIMAL(3,2) DEFAULT 0.5, -- Speed reduction during peak (50%)
            
            -- Congestion management
            congestion_threshold INTEGER DEFAULT 80,     -- Network utilization % to trigger
            congestion_speed_reduction DECIMAL(3,2) DEFAULT 0.7, -- Speed reduction during congestion
            priority_adjustment INTEGER DEFAULT 0,       -- Queue priority adjustment
            
            -- Actions
            warning_at_percentage INTEGER DEFAULT 80,    -- Send warning at % of limit
            suspend_at_limit BOOLEAN DEFAULT 0,         -- Disconnect when limit reached
            reset_period VARCHAR(20) DEFAULT 'daily',   -- 'daily', 'weekly', 'monthly'
            
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(client_id) REFERENCES clients(id)
        )";
        $this->db->exec($sql);
        
        // FUP user states and tracking
        $sql = "CREATE TABLE IF NOT EXISTS fup_user_states (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            router_id INTEGER NOT NULL,
            username VARCHAR(100) NOT NULL,
            session_id INTEGER,
            policy_id INTEGER NOT NULL,
            
            -- Current usage tracking
            current_download_gb DECIMAL(12,3) DEFAULT 0,
            current_upload_gb DECIMAL(12,3) DEFAULT 0,
            total_usage_gb DECIMAL(12,3) DEFAULT 0,
            session_time_minutes INTEGER DEFAULT 0,
            daily_time_minutes INTEGER DEFAULT 0,
            
            -- FUP status
            fup_status VARCHAR(20) DEFAULT 'normal',     -- 'normal', 'warning', 'throttled', 'suspended'
            fup_trigger VARCHAR(50),                     -- What triggered FUP
            original_download_speed INTEGER,
            original_upload_speed INTEGER,
            current_download_speed INTEGER,
            current_upload_speed INTEGER,
            
            -- Timestamps
            fup_applied_at DATETIME,
            fup_reset_at DATETIME,
            warning_sent_at DATETIME,
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
            reset_period_start DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY(router_id) REFERENCES client_routers(id),
            FOREIGN KEY(session_id) REFERENCES wifi_sessions(id),
            FOREIGN KEY(policy_id) REFERENCES fup_policies(id),
            UNIQUE(router_id, username, policy_id)
        )";
        $this->db->exec($sql);
        
        // FUP violation history
        $sql = "CREATE TABLE IF NOT EXISTS fup_violations_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_state_id INTEGER NOT NULL,
            router_id INTEGER NOT NULL,
            username VARCHAR(100) NOT NULL,
            violation_type VARCHAR(50) NOT NULL,
            policy_triggered TEXT,                       -- JSON of policy details
            
            -- Usage at violation
            usage_gb DECIMAL(12,3),
            time_minutes INTEGER,
            trigger_threshold DECIMAL(12,3),
            
            -- Action taken
            action_taken VARCHAR(100) NOT NULL,
            speed_before VARCHAR(20),
            speed_after VARCHAR(20),
            
            -- Recovery
            violation_start DATETIME DEFAULT CURRENT_TIMESTAMP,
            violation_end DATETIME,
            auto_recovered BOOLEAN DEFAULT 0,
            
            FOREIGN KEY(user_state_id) REFERENCES fup_user_states(id),
            FOREIGN KEY(router_id) REFERENCES client_routers(id)
        )";
        $this->db->exec($sql);
        
        // Network congestion monitoring
        $sql = "CREATE TABLE IF NOT EXISTS network_congestion_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            router_id INTEGER NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            -- Network metrics
            active_users INTEGER,
            total_download_mbps DECIMAL(10,2),
            total_upload_mbps DECIMAL(10,2),
            bandwidth_utilization DECIMAL(5,2),         -- Percentage
            
            -- System metrics
            cpu_usage DECIMAL(5,2),
            memory_usage DECIMAL(5,2),
            interface_errors INTEGER DEFAULT 0,
            
            -- Congestion status
            is_congested BOOLEAN DEFAULT 0,
            congestion_level VARCHAR(20),               -- 'low', 'medium', 'high', 'critical'
            actions_taken TEXT,                         -- JSON of actions
            
            FOREIGN KEY(router_id) REFERENCES client_routers(id)
        )";
        $this->db->exec($sql);
        
        error_log("📊 FUP enforcement tables initialized");
    }
    
    /**
     * Load FUP policies from database
     */
    private function loadFUPPolicies() {
        $stmt = $this->db->prepare("SELECT * FROM fup_policies WHERE is_active = 1");
        $stmt->execute();
        $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($policies as $policy) {
            $clientId = $policy['client_id'] ?? 'global';
            $this->fupPolicies[$clientId] = $policy;
        }
        
        error_log("📋 Loaded " . count($policies) . " FUP policies");
    }
    
    /**
     * Monitor and enforce FUP across all routers
     */
    public function monitorAndEnforceFUP() {
        try {
            $startTime = microtime(true);
            
            // Get all active user sessions
            $stmt = $this->db->prepare("
                SELECT ws.*, ubq.*, p.*, cr.host, cr.username, cr.password, cr.api_port,
                       c.id as client_id
                FROM wifi_sessions ws
                JOIN user_bandwidth_queues ubq ON ws.id = ubq.session_id
                JOIN packages p ON ws.package_id = p.id
                JOIN client_routers cr ON ws.router_id = cr.id
                JOIN clients c ON cr.client_id = c.id
                WHERE ws.status = 'active' AND ubq.queue_status = 'active'
            ");
            $stmt->execute();
            $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $processed = 0;
            $violations = 0;
            $warnings = 0;
            
            foreach ($activeSessions as $session) {
                $processed++;
                $result = $this->monitorUserSession($session);
                
                if ($result['violation_detected']) {
                    $violations++;
                }
                if ($result['warning_sent']) {
                    $warnings++;
                }
            }
            
            // Monitor network congestion
            $this->monitorNetworkCongestion();
            
            // Clean up expired FUP states
            $this->cleanupExpiredFUPStates();
            
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            error_log("📊 FUP monitoring completed: {$processed} sessions, {$violations} violations, {$warnings} warnings ({$executionTime}ms)");
            
            return [
                'success' => true,
                'processed_sessions' => $processed,
                'violations_detected' => $violations,
                'warnings_sent' => $warnings,
                'execution_time_ms' => $executionTime,
                'message' => 'FUP monitoring completed successfully'
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
     * Monitor individual user session for FUP violations
     */
    private function monitorUserSession($session) {
        try {
            $username = $session['voucher_code'];
            $routerId = $session['router_id'];
            $clientId = $session['client_id'];
            
            // Get or create user FUP state
            $userState = $this->getUserFUPState($routerId, $username, $clientId);
            
            // Get current usage from router
            $currentUsage = $this->getCurrentUsageFromRouter($session);
            
            if (!$currentUsage) {
                return ['violation_detected' => false, 'warning_sent' => false];
            }
            
            // Update user state with current usage
            $this->updateUserUsageState($userState['id'], $currentUsage);
            
            // Get applicable FUP policy
            $policy = $this->getApplicableFUPPolicy($clientId, $session);
            
            if (!$policy) {
                return ['violation_detected' => false, 'warning_sent' => false];
            }
            
            $violation = false;
            $warning = false;
            
            // Check data limit violations
            if ($this->checkDataLimitViolation($userState, $currentUsage, $policy)) {
                $this->enforceDataLimitViolation($session, $userState, $policy, $currentUsage);
                $violation = true;
            }
            
            // Check time limit violations
            if ($this->checkTimeLimitViolation($userState, $currentUsage, $policy)) {
                $this->enforceTimeLimitViolation($session, $userState, $policy, $currentUsage);
                $violation = true;
            }
            
            // Check peak hours restrictions
            if ($this->checkPeakHoursRestriction($userState, $policy)) {
                $this->enforcePeakHoursRestriction($session, $userState, $policy);
                $violation = true;
            }
            
            // Send warning if approaching limits
            if ($this->shouldSendWarning($userState, $currentUsage, $policy)) {
                $this->sendFUPWarning($session, $userState, $policy, $currentUsage);
                $warning = true;
            }
            
            return [
                'violation_detected' => $violation,
                'warning_sent' => $warning,
                'current_usage_gb' => $currentUsage['total_gb'],
                'session_time_minutes' => $currentUsage['session_time']
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to monitor user session {$username}: " . $e->getMessage());
            return ['violation_detected' => false, 'warning_sent' => false];
        }
    }
    
    /**
     * Get current usage data from MikroTik router
     */
    private function getCurrentUsageFromRouter($session) {
        try {
            // Connect to router
            $connected = $this->mikrotikApi->connect(
                $session['host'],
                $session['username'],
                $session['password'],
                $session['api_port']
            );
            
            if (!$connected) {
                return null;
            }
            
            // Get queue statistics for user
            $queueStats = $this->mikrotikApi->getQueueStats($session['queue_name']);
            
            $this->mikrotikApi->disconnect();
            
            if (empty($queueStats)) {
                return null;
            }
            
            $stats = $queueStats[0];
            $downloadBytes = $stats['bytes']['download'] ?? 0;
            $uploadBytes = $stats['bytes']['upload'] ?? 0;
            
            // Calculate session time (from session start)
            $sessionStart = new DateTime($session['created_at']);
            $now = new DateTime();
            $sessionTime = $now->diff($sessionStart)->h * 60 + $now->diff($sessionStart)->i;
            
            return [
                'download_gb' => $downloadBytes / (1024 * 1024 * 1024),
                'upload_gb' => $uploadBytes / (1024 * 1024 * 1024),
                'total_gb' => ($downloadBytes + $uploadBytes) / (1024 * 1024 * 1024),
                'session_time' => $sessionTime,
                'download_speed' => $stats['rate']['download'] ?? 0,
                'upload_speed' => $stats['rate']['upload'] ?? 0
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to get usage from router: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check for data limit violations
     */
    private function checkDataLimitViolation($userState, $usage, $policy) {
        $totalUsage = $usage['total_gb'];
        
        // Check daily limit
        if ($policy['daily_limit_gb'] && $totalUsage >= $policy['daily_limit_gb']) {
            error_log("🚨 Data limit violation: {$userState['username']} used {$totalUsage}GB (daily limit: {$policy['daily_limit_gb']}GB)");
            return true;
        }
        
        // Check monthly limit (simplified - in production, track monthly usage)
        if ($policy['monthly_limit_gb'] && $totalUsage >= $policy['monthly_limit_gb']) {
            error_log("🚨 Monthly data limit violation: {$userState['username']} used {$totalUsage}GB");
            return true;
        }
        
        // Check throttling trigger
        if ($policy['throttle_trigger_gb'] && $totalUsage >= $policy['throttle_trigger_gb'] && $userState['fup_status'] === 'normal') {
            error_log("⚠️ Throttling triggered: {$userState['username']} used {$totalUsage}GB");
            return true;
        }
        
        return false;
    }
    
    /**
     * Check for time limit violations
     */
    private function checkTimeLimitViolation($userState, $usage, $policy) {
        $sessionTime = $usage['session_time'];
        
        // Check session time limit
        if ($policy['time_limit_hours'] && $sessionTime >= ($policy['time_limit_hours'] * 60)) {
            error_log("🚨 Time limit violation: {$userState['username']} session time {$sessionTime} minutes");
            return true;
        }
        
        // Check daily time limit
        if ($policy['daily_time_limit_hours'] && $userState['daily_time_minutes'] >= ($policy['daily_time_limit_hours'] * 60)) {
            error_log("🚨 Daily time limit violation: {$userState['username']} daily time {$userState['daily_time_minutes']} minutes");
            return true;
        }
        
        return false;
    }
    
    /**
     * Check peak hours restrictions
     */
    private function checkPeakHoursRestriction($userState, $policy) {
        if (!$policy['peak_hours_start'] || !$policy['peak_hours_end']) {
            return false;
        }
        
        $currentTime = date('H:i');
        $peakStart = $policy['peak_hours_start'];
        $peakEnd = $policy['peak_hours_end'];
        
        // Check if current time is within peak hours
        $inPeakHours = ($currentTime >= $peakStart && $currentTime <= $peakEnd);
        
        if ($inPeakHours && $userState['fup_status'] === 'normal') {
            error_log("🕐 Peak hours restriction: {$userState['username']} active during peak hours");
            return true;
        }
        
        return false;
    }
    
    /**
     * Enforce data limit violation
     */
    private function enforceDataLimitViolation($session, $userState, $policy, $usage) {
        try {
            $action = 'speed_reduced';
            $newDownSpeed = $policy['throttle_speed_down'] ?? (int)($userState['original_download_speed'] * 0.1);
            $newUpSpeed = $policy['throttle_speed_up'] ?? (int)($userState['original_upload_speed'] * 0.1);
            
            // Apply speed reduction via router
            $connected = $this->mikrotikApi->connect(
                $session['host'],
                $session['username'],
                $session['password'],
                $session['api_port']
            );
            
            if ($connected) {
                $this->mikrotikApi->updateQueueSpeed($session['queue_name'], $newDownSpeed, $newUpSpeed);
                $this->mikrotikApi->disconnect();
                
                // Update user state
                $this->updateUserFUPStatus($userState['id'], 'throttled', 'data_limit', $newDownSpeed, $newUpSpeed);
                
                // Log violation
                $this->logFUPViolation($userState, 'data_limit', $policy, $usage, $action, 
                    $userState['current_download_speed'] . '/' . $userState['current_upload_speed'],
                    $newDownSpeed . '/' . $newUpSpeed);
                
                error_log("⚡ Speed reduced for {$userState['username']}: {$newDownSpeed}/{$newUpSpeed} Mbps");
            }
            
        } catch (Exception $e) {
            error_log("❌ Failed to enforce data limit violation: " . $e->getMessage());
        }
    }
    
    /**
     * Enforce time limit violation
     */
    private function enforceTimeLimitViolation($session, $userState, $policy, $usage) {
        try {
            if ($policy['suspend_at_limit']) {
                // Disconnect user
                $connected = $this->mikrotikApi->connect(
                    $session['host'],
                    $session['username'],
                    $session['password'],
                    $session['api_port']
                );
                
                if ($connected) {
                    $this->mikrotikApi->removeHotspotUser($session['voucher_code']);
                    $this->mikrotikApi->disconnect();
                    
                    // Update session status
                    $stmt = $this->db->prepare("
                        UPDATE wifi_sessions 
                        SET status = 'fup_suspended', updated_at = datetime('now') 
                        WHERE id = ?
                    ");
                    $stmt->execute([$session['id']]);
                    
                    $this->updateUserFUPStatus($userState['id'], 'suspended', 'time_limit', 0, 0);
                    
                    error_log("🚫 User suspended for time limit: {$userState['username']}");
                }
            } else {
                // Just reduce speed
                $this->enforceDataLimitViolation($session, $userState, $policy, $usage);
            }
            
        } catch (Exception $e) {
            error_log("❌ Failed to enforce time limit violation: " . $e->getMessage());
        }
    }
    
    /**
     * Enforce peak hours restriction
     */
    private function enforcePeakHoursRestriction($session, $userState, $policy) {
        try {
            $reduction = $policy['peak_speed_reduction'] ?? 0.5;
            $newDownSpeed = (int)($userState['original_download_speed'] * $reduction);
            $newUpSpeed = (int)($userState['original_upload_speed'] * $reduction);
            
            // Apply peak hours speed reduction
            $connected = $this->mikrotikApi->connect(
                $session['host'],
                $session['username'],
                $session['password'],
                $session['api_port']
            );
            
            if ($connected) {
                $this->mikrotikApi->updateQueueSpeed($session['queue_name'], $newDownSpeed, $newUpSpeed);
                $this->mikrotikApi->disconnect();
                
                $this->updateUserFUPStatus($userState['id'], 'throttled', 'peak_hours', $newDownSpeed, $newUpSpeed);
                
                error_log("🕐 Peak hours throttling applied: {$userState['username']} -> {$newDownSpeed}/{$newUpSpeed} Mbps");
            }
            
        } catch (Exception $e) {
            error_log("❌ Failed to enforce peak hours restriction: " . $e->getMessage());
        }
    }
    
    /**
     * Send FUP warning to user
     */
    private function sendFUPWarning($session, $userState, $policy, $usage) {
        try {
            $warningMessage = $this->generateWarningMessage($userState, $policy, $usage);
            
            // In production, this would send SMS, email, or in-app notification
            error_log("⚠️ FUP Warning sent to {$userState['username']}: {$warningMessage}");
            
            // Update warning timestamp
            $stmt = $this->db->prepare("
                UPDATE fup_user_states 
                SET warning_sent_at = datetime('now') 
                WHERE id = ?
            ");
            $stmt->execute([$userState['id']]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Failed to send FUP warning: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Monitor network congestion
     */
    private function monitorNetworkCongestion() {
        try {
            // Get all active routers
            $stmt = $this->db->prepare("
                SELECT cr.*, COUNT(ws.id) as active_users
                FROM client_routers cr
                LEFT JOIN wifi_sessions ws ON cr.id = ws.router_id AND ws.status = 'active'
                WHERE cr.status = 'online'
                GROUP BY cr.id
            ");
            $stmt->execute();
            $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($routers as $router) {
                $this->checkRouterCongestion($router);
            }
            
        } catch (Exception $e) {
            error_log("❌ Network congestion monitoring failed: " . $e->getMessage());
        }
    }
    
    /**
     * Check individual router for congestion
     */
    private function checkRouterCongestion($router) {
        try {
            // Connect and get system stats
            $connected = $this->mikrotikApi->connect(
                $router['host'],
                $router['username'],
                $router['password'],
                $router['api_port']
            );
            
            if (!$connected) {
                return;
            }
            
            $systemInfo = $this->mikrotikApi->getSystemInfo();
            $interfaceStats = $this->mikrotikApi->getInterfaceStats();
            
            $this->mikrotikApi->disconnect();
            
            // Calculate congestion metrics
            $cpuUsage = $systemInfo['cpu_load'] ?? 0;
            $memoryUsage = ($systemInfo['total_memory'] - $systemInfo['free_memory']) / $systemInfo['total_memory'] * 100;
            
            // Determine congestion level
            $congestionLevel = 'low';
            $isCongested = false;
            
            if ($cpuUsage > 80 || $memoryUsage > 85 || $router['active_users'] > 50) {
                $congestionLevel = 'critical';
                $isCongested = true;
            } elseif ($cpuUsage > 60 || $memoryUsage > 70 || $router['active_users'] > 30) {
                $congestionLevel = 'high';
                $isCongested = true;
            } elseif ($cpuUsage > 40 || $memoryUsage > 50 || $router['active_users'] > 20) {
                $congestionLevel = 'medium';
            }
            
            // Log congestion data
            $stmt = $this->db->prepare("
                INSERT INTO network_congestion_log 
                (router_id, active_users, cpu_usage, memory_usage, is_congested, congestion_level) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $router['id'], $router['active_users'], $cpuUsage, 
                $memoryUsage, $isCongested, $congestionLevel
            ]);
            
            // Apply congestion management if needed
            if ($isCongested) {
                $this->applyCongestionManagement($router, $congestionLevel);
            }
            
        } catch (Exception $e) {
            error_log("❌ Router congestion check failed for {$router['name']}: " . $e->getMessage());
        }
    }
    
    // Helper methods for FUP state management, policy application, etc.
    
    private function getUserFUPState($routerId, $username, $clientId) {
        // Implementation for getting/creating user FUP state
        $stmt = $this->db->prepare("
            SELECT * FROM fup_user_states 
            WHERE router_id = ? AND username = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$routerId, $username]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$state) {
            // Create new state
            $policy = $this->getApplicableFUPPolicy($clientId, null);
            $policyId = $policy ? $policy['id'] : 1; // Default policy
            
            $stmt = $this->db->prepare("
                INSERT INTO fup_user_states 
                (router_id, username, policy_id, reset_period_start) 
                VALUES (?, ?, ?, datetime('now'))
            ");
            $stmt->execute([$routerId, $username, $policyId]);
            
            return $this->getUserFUPState($routerId, $username, $clientId);
        }
        
        return $state;
    }
    
    private function getApplicableFUPPolicy($clientId, $session) {
        // Get client-specific policy or global policy
        return $this->fupPolicies[$clientId] ?? $this->fupPolicies['global'] ?? null;
    }
    
    private function updateUserUsageState($stateId, $usage) {
        $stmt = $this->db->prepare("
            UPDATE fup_user_states 
            SET current_download_gb = ?, current_upload_gb = ?, total_usage_gb = ?,
                session_time_minutes = ?, last_updated = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([
            $usage['download_gb'], $usage['upload_gb'], $usage['total_gb'],
            $usage['session_time'], $stateId
        ]);
    }
    
    private function shouldSendWarning($userState, $usage, $policy) {
        if (!$policy['warning_at_percentage'] || $userState['warning_sent_at']) {
            return false;
        }
        
        $warningThreshold = $policy['daily_limit_gb'] * ($policy['warning_at_percentage'] / 100);
        return $usage['total_gb'] >= $warningThreshold;
    }
    
    private function updateUserFUPStatus($stateId, $status, $trigger, $downSpeed, $upSpeed) {
        $stmt = $this->db->prepare("
            UPDATE fup_user_states 
            SET fup_status = ?, fup_trigger = ?, 
                current_download_speed = ?, current_upload_speed = ?,
                fup_applied_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$status, $trigger, $downSpeed, $upSpeed, $stateId]);
    }
    
    private function logFUPViolation($userState, $type, $policy, $usage, $action, $speedBefore, $speedAfter) {
        $stmt = $this->db->prepare("
            INSERT INTO fup_violations_history 
            (user_state_id, router_id, username, violation_type, policy_triggered,
             usage_gb, action_taken, speed_before, speed_after) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userState['id'], $userState['router_id'], $userState['username'],
            $type, json_encode($policy), $usage['total_gb'], $action, $speedBefore, $speedAfter
        ]);
    }
    
    private function generateWarningMessage($userState, $policy, $usage) {
        $limit = $policy['daily_limit_gb'] ?? 0;
        $used = round($usage['total_gb'], 2);
        $remaining = round($limit - $used, 2);
        
        return "Data usage warning: You have used {$used}GB of your {$limit}GB daily limit. {$remaining}GB remaining.";
    }
    
    private function applyCongestionManagement($router, $level) {
        error_log("🌐 Applying congestion management for {$router['name']}: {$level}");
        // Implementation for congestion management actions
    }
    
    private function cleanupExpiredFUPStates() {
        // Clean up old FUP states based on reset periods
        $stmt = $this->db->prepare("
            UPDATE fup_user_states 
            SET fup_status = 'normal', fup_trigger = NULL, warning_sent_at = NULL,
                current_download_gb = 0, current_upload_gb = 0, total_usage_gb = 0
            WHERE reset_period_start < datetime('now', '-1 day')
        ");
        $stmt->execute();
    }
    
    /**
     * Get FUP statistics and reports
     */
    public function getFUPStatistics($routerId = null, $clientId = null) {
        try {
            $whereClause = '';
            $params = [];
            
            if ($routerId) {
                $whereClause .= ' AND fus.router_id = ?';
                $params[] = $routerId;
            }
            
            if ($clientId) {
                $whereClause .= ' AND cr.client_id = ?';
                $params[] = $clientId;
            }
            
            // Get current FUP states
            $stmt = $this->db->prepare("
                SELECT fus.fup_status, COUNT(*) as count
                FROM fup_user_states fus
                JOIN client_routers cr ON fus.router_id = cr.id
                WHERE fus.last_updated >= datetime('now', '-1 hour') {$whereClause}
                GROUP BY fus.fup_status
            ");
            $stmt->execute($params);
            $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get recent violations
            $stmt = $this->db->prepare("
                SELECT violation_type, COUNT(*) as count
                FROM fup_violations_history fvh
                JOIN client_routers cr ON fvh.router_id = cr.id
                WHERE fvh.violation_start >= datetime('now', '-24 hours') {$whereClause}
                GROUP BY violation_type
            ");
            $stmt->execute($params);
            $violationCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'statistics' => [
                    'status_distribution' => $statusCounts,
                    'recent_violations' => $violationCounts,
                    'timestamp' => date('c')
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
?>