<?php
/**
 * Real-time Billing Synchronization Engine
 * Synchronizes billing system with MikroTik routers in real-time
 * Handles user creation, bandwidth enforcement, session management
 */

class BillingSyncEngine {
    private $db;
    private $bandwidthManager;
    private $mikrotikApi;
    
    public function __construct($database) {
        $this->db = $database;
        
        // Initialize managers
        require_once 'bandwidth_manager.php';
        require_once 'enhanced_mikrotik_api.php';
        
        $this->bandwidthManager = new BandwidthManager($database);
        $this->mikrotikApi = new EnhancedMikroTikAPI();
    }
    
    /**
     * Process M-Pesa payment and create router user
     * This is the core function that connects payments to bandwidth
     */
    public function processPaymentToRouter($paymentId) {
        try {
            // Get payment details
            $stmt = $this->db->prepare("
                SELECT p.*, pkg.*, c.phone_number, c.id as customer_id
                FROM payments p
                JOIN packages pkg ON p.package_id = pkg.id  
                JOIN customers c ON p.phone_number = c.phone_number
                WHERE p.id = ? AND p.payment_status = 'success'
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception("Payment not found or not successful");
            }
            
            // Find best available router for this client
            $routerInfo = $this->findAvailableRouter($payment);
            
            if (!$routerInfo) {
                throw new Exception("No available routers found");
            }
            
            // Generate unique username and password
            $username = $this->generateUsername($payment);
            $password = $this->generatePassword($payment);
            
            // Create WiFi session
            $sessionId = $this->createWiFiSession($payment, $username, $password, $routerInfo['router_id']);
            
            // Connect to router and create user
            $result = $this->createRouterUser($routerInfo, $payment, $username, $password, $sessionId);
            
            if ($result['success']) {
                // Update session with router details
                $this->updateSessionWithRouterInfo($sessionId, $result);
                
                error_log("🚀 Payment {$paymentId} processed: User {$username} created on router {$routerInfo['router_name']}");
                
                return [
                    'success' => true,
                    'username' => $username,
                    'password' => $password,
                    'router_info' => $routerInfo,
                    'session_id' => $sessionId,
                    'bandwidth' => "{$payment['download_speed']}/{$payment['upload_speed']} Mbps"
                ];
            } else {
                throw new Exception("Failed to create router user: " . $result['message']);
            }
            
        } catch (Exception $e) {
            error_log("❌ Failed to process payment to router: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Find best available router for client
     */
    private function findAvailableRouter($payment) {
        try {
            // For now, we'll use admin routers - in production this would be client-specific
            $stmt = $this->db->prepare("
                SELECT mr.*, 
                       COUNT(ws.id) as active_sessions,
                       (COUNT(ws.id) * 100.0 / 50) as load_percentage
                FROM mikrotik_routers mr
                LEFT JOIN wifi_sessions ws ON ws.router_id = mr.id AND ws.status = 'active'
                WHERE mr.status = 'online'
                GROUP BY mr.id
                ORDER BY load_percentage ASC, mr.id ASC
                LIMIT 1
            ");
            $stmt->execute();
            $router = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($router) {
                return [
                    'router_id' => $router['id'],
                    'router_name' => $router['name'],
                    'host' => $router['host'],
                    'username' => $router['username'],
                    'password' => $router['password'],
                    'api_port' => $router['api_port'],
                    'load_percentage' => $router['load_percentage']
                ];
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("❌ Failed to find available router: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create router user with bandwidth limits and VPN provisioning
     */
    private function createRouterUser($routerInfo, $payment, $username, $password, $sessionId) {
        try {
            // Connect to MikroTik router
            $connected = $this->mikrotikApi->connect(
                $routerInfo['host'],
                $routerInfo['username'],
                $routerInfo['password'],
                $routerInfo['api_port']
            );
            
            if (!$connected) {
                throw new Exception("Failed to connect to router");
            }
            
            // Create or verify user profile exists
            $profileName = $this->createUserProfile($payment);
            
            // Create hotspot user
            $timeLimit = $payment['duration_hours'] . 'h';
            $userCreated = $this->mikrotikApi->createHotspotUser($username, $password, $profileName, $timeLimit);
            
            if (!$userCreated) {
                throw new Exception("Failed to create hotspot user");
            }
            
            // We'll create bandwidth queue when user connects (in sync endpoint)
            
            // AUTO-PROVISION VPN ACCESS: Check if router needs VPN setup
            $this->provisionVPNAccess($routerInfo, $sessionId);
            
            $this->mikrotikApi->disconnect();
            
            return [
                'success' => true,
                'profile_name' => $profileName,
                'time_limit' => $timeLimit,
                'message' => 'Router user created successfully'
            ];
            
        } catch (Exception $e) {
            $this->mikrotikApi->disconnect();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create or get user profile for package
     */
    private function createUserProfile($payment) {
        try {
            $profileName = strtolower(str_replace(' ', '-', $payment['name'])) . '-profile';
            
            // Check if profile already exists (we'll assume it does for now)
            // In production, we'd check and create if needed
            
            if (!$this->mikrotikApi->createUserProfile(
                $profileName,
                $payment['download_speed'] ?? 5,
                $payment['upload_speed'] ?? 2,
                $payment['duration_hours'] . 'h'
            )) {
                // Profile might already exist, which is fine
            }
            
            return $profileName;
            
        } catch (Exception $e) {
            error_log("⚠️ Profile creation issue (might already exist): " . $e->getMessage());
            return 'default';
        }
    }
    
    /**
     * Generate unique username for user
     */
    private function generateUsername($payment) {
        $phoneNumber = substr($payment['phone_number'], -4); // Last 4 digits
        $timestamp = substr(time(), -4); // Last 4 digits of timestamp
        return "user{$phoneNumber}{$timestamp}";
    }
    
    /**
     * Generate secure password
     */
    private function generatePassword($payment) {
        return substr(md5($payment['checkout_request_id'] ?? $payment['phone_number'] . time()), 0, 8);
    }
    
    /**
     * Create WiFi session in database
     */
    private function createWiFiSession($payment, $username, $password, $routerId) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO wifi_sessions 
                (phone_number, package_id, duration_hours, amount_paid, checkout_request_id, 
                 voucher_code, password, router_id, created_at, expires_at, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), 
                        datetime('now', '+' || ? || ' hours'), 'active')
            ");
            
            $stmt->execute([
                $payment['phone_number'],
                $payment['package_id'],
                $payment['duration_hours'],
                $payment['amount'],
                $payment['checkout_request_id'],
                $username,
                $password,
                $routerId,
                $payment['duration_hours']
            ]);
            
            return $this->db->lastInsertId();
            
        } catch (Exception $e) {
            error_log("❌ Failed to create WiFi session: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update session with router information
     */
    private function updateSessionWithRouterInfo($sessionId, $routerResult) {
        try {
            $stmt = $this->db->prepare("
                UPDATE wifi_sessions 
                SET router_response = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            
            $stmt->execute([
                json_encode($routerResult),
                $sessionId
            ]);
            
        } catch (Exception $e) {
            error_log("❌ Failed to update session with router info: " . $e->getMessage());
        }
    }
    
    /**
     * Handle router sync request (called by MikroTik routers)
     */
    public function handleRouterSync($routerId, $activeUsers = []) {
        try {
            error_log("🔄 Router sync request from router {$routerId} with " . count($activeUsers) . " active users");
            
            // Get router info
            $stmt = $this->db->prepare("SELECT * FROM mikrotik_routers WHERE id = ?");
            $stmt->execute([$routerId]);
            $router = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$router) {
                throw new Exception("Router not found");
            }
            
            // Connect to router to get real-time data
            $connected = $this->mikrotikApi->connect(
                $router['host'],
                $router['username'],
                $router['password'],
                $router['api_port']
            );
            
            if (!$connected) {
                error_log("⚠️ Could not connect to router {$routerId} for sync");
                return ['success' => false, 'message' => 'Router connection failed'];
            }
            
            // Get active users from router
            $routerActiveUsers = $this->mikrotikApi->getActiveUsers();
            
            // Process each active user
            foreach ($routerActiveUsers as $user) {
                $this->processActiveUser($routerId, $user);
            }
            
            // Check for expired sessions and remove them
            $this->cleanupExpiredSessions($routerId);
            
            // Create bandwidth queues for users who need them
            $this->createMissingBandwidthQueues($routerId);
            
            $this->mikrotikApi->disconnect();
            
            // Update router last sync time
            $stmt = $this->db->prepare("UPDATE mikrotik_routers SET last_ping = datetime('now') WHERE id = ?");
            $stmt->execute([$routerId]);
            
            return [
                'success' => true,
                'active_users' => count($routerActiveUsers),
                'message' => 'Router sync completed successfully'
            ];
            
        } catch (Exception $e) {
            $this->mikrotikApi->disconnect();
            error_log("❌ Router sync failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process active user and create bandwidth queue if needed
     */
    private function processActiveUser($routerId, $user) {
        try {
            $username = $user['username'];
            $userIp = $user['address'];
            
            // Find session for this user
            $stmt = $this->db->prepare("
                SELECT ws.*, p.download_speed, p.upload_speed, p.name as package_name
                FROM wifi_sessions ws
                JOIN packages p ON ws.package_id = p.id
                WHERE ws.voucher_code = ? AND ws.router_id = ? AND ws.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$username, $routerId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                error_log("⚠️ No active session found for user {$username}");
                return;
            }
            
            // Check if user already has bandwidth queue
            $stmt = $this->db->prepare("
                SELECT * FROM user_bandwidth_queues 
                WHERE router_id = ? AND username = ? AND queue_status = 'active'
            ");
            $stmt->execute([$routerId, $username]);
            $existingQueue = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingQueue && $userIp) {
                // Create bandwidth queue for this user
                $result = $this->bandwidthManager->createUserBandwidthQueue(
                    $routerId,
                    $session['id'],
                    $session['package_id'],
                    $username,
                    $userIp
                );
                
                if ($result['success']) {
                    error_log("⚡ Bandwidth queue created for {$username} at {$userIp}");
                } else {
                    error_log("❌ Failed to create bandwidth queue for {$username}: " . $result['message']);
                }
            }
            
        } catch (Exception $e) {
            error_log("❌ Failed to process active user {$username}: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up expired sessions
     */
    private function cleanupExpiredSessions($routerId) {
        try {
            // Find expired sessions
            $stmt = $this->db->prepare("
                SELECT * FROM wifi_sessions 
                WHERE router_id = ? AND status = 'active' AND expires_at < datetime('now')
            ");
            $stmt->execute([$routerId]);
            $expiredSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($expiredSessions as $session) {
                // Remove user from router
                $this->mikrotikApi->removeHotspotUser($session['voucher_code']);
                
                // Remove bandwidth queue
                $this->bandwidthManager->removeUserBandwidthQueue($routerId, $session['voucher_code']);
                
                // Update session status
                $stmt = $this->db->prepare("
                    UPDATE wifi_sessions 
                    SET status = 'expired', updated_at = datetime('now') 
                    WHERE id = ?
                ");
                $stmt->execute([$session['id']]);
                
                error_log("🕐 Expired session cleaned up: {$session['voucher_code']}");
            }
            
        } catch (Exception $e) {
            error_log("❌ Failed to cleanup expired sessions: " . $e->getMessage());
        }
    }
    
    /**
     * Create missing bandwidth queues
     */
    private function createMissingBandwidthQueues($routerId) {
        try {
            // Find active sessions without bandwidth queues
            $stmt = $this->db->prepare("
                SELECT ws.*, p.download_speed, p.upload_speed
                FROM wifi_sessions ws
                JOIN packages p ON ws.package_id = p.id
                LEFT JOIN user_bandwidth_queues ubq ON ws.id = ubq.session_id AND ubq.queue_status = 'active'
                WHERE ws.router_id = ? AND ws.status = 'active' AND ubq.id IS NULL
            ");
            $stmt->execute([$routerId]);
            $sessionsNeedingQueues = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get active users to match IPs
            $activeUsers = $this->mikrotikApi->getActiveUsers();
            $userIpMap = [];
            foreach ($activeUsers as $user) {
                $userIpMap[$user['username']] = $user['address'];
            }
            
            foreach ($sessionsNeedingQueues as $session) {
                $username = $session['voucher_code'];
                $userIp = $userIpMap[$username] ?? null;
                
                if ($userIp) {
                    $result = $this->bandwidthManager->createUserBandwidthQueue(
                        $routerId,
                        $session['id'],
                        $session['package_id'],
                        $username,
                        $userIp
                    );
                    
                    if ($result['success']) {
                        error_log("⚡ Missing bandwidth queue created for {$username}");
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("❌ Failed to create missing bandwidth queues: " . $e->getMessage());
        }
    }
    
    /**
     * Auto-provision VPN access for new routers
     */
    private function provisionVPNAccess($routerInfo, $sessionId) {
        try {
            // Check if router already has VPN configuration
            $stmt = $this->db->prepare("
                SELECT id FROM vpn_clients 
                WHERE router_id = ? AND connection_status != 'removed'
                LIMIT 1
            ");
            $stmt->execute([$routerInfo['router_id']]);
            
            if ($stmt->fetch()) {
                // Router already has VPN access
                return;
            }
            
            // Initialize VPN manager
            require_once 'vpn_manager.php';
            $vpnManager = new VPNManager($this->db);
            
            // Find available VPN server
            $stmt = $this->db->prepare("
                SELECT id FROM vpn_servers 
                WHERE status = 'active' AND current_clients < max_clients 
                ORDER BY current_clients ASC 
                LIMIT 1
            ");
            $stmt->execute();
            $vpnServer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vpnServer) {
                error_log("⚠️ No available VPN servers for router {$routerInfo['router_id']}");
                return;
            }
            
            // Create VPN client configuration for router
            $result = $vpnManager->createVPNClientConfig($routerInfo['router_id'], $vpnServer['id']);
            
            if ($result['success']) {
                // Log successful VPN provisioning
                error_log("🔐 VPN auto-provisioned for router {$routerInfo['router_id']}: {$result['client_name']} ({$result['vpn_ip']})");
                
                // Store VPN configuration for router deployment
                $stmt = $this->db->prepare("
                    INSERT OR REPLACE INTO router_configurations 
                    (router_id, config_type, config_data, status, created_at) 
                    VALUES (?, 'vpn', ?, 'pending', datetime('now'))
                ");
                
                $vpnConfigData = [
                    'vpn_client_id' => $result['vpn_client_id'],
                    'client_config' => $result['client_config'],
                    'router_script' => $result['router_script'],
                    'server_endpoint' => $result['server_endpoint']
                ];
                
                $stmt->execute([
                    $routerInfo['router_id'],
                    json_encode($vpnConfigData)
                ]);
                
                // Create installation task for client's router
                $this->createRouterConfigTask($routerInfo['router_id'], 'vpn_setup', $vpnConfigData);
                
            } else {
                error_log("❌ Failed to provision VPN for router {$routerInfo['router_id']}: " . $result['message']);
            }
            
        } catch (Exception $e) {
            error_log("❌ VPN provisioning error for router {$routerInfo['router_id']}: " . $e->getMessage());
        }
    }
    
    /**
     * Create router configuration task
     */
    private function createRouterConfigTask($routerId, $taskType, $configData) {
        try {
            // Ensure router_config_tasks table exists
            $sql = "CREATE TABLE IF NOT EXISTS router_config_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                router_id INTEGER NOT NULL,
                task_type VARCHAR(50) NOT NULL,
                config_data TEXT NOT NULL,
                status VARCHAR(20) DEFAULT 'pending',
                priority INTEGER DEFAULT 5,
                attempts INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 3,
                next_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME,
                error_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(router_id) REFERENCES client_routers(id)
            )";
            $this->db->exec($sql);
            
            // Insert configuration task
            $stmt = $this->db->prepare("
                INSERT INTO router_config_tasks 
                (router_id, task_type, config_data, created_at) 
                VALUES (?, ?, ?, datetime('now'))
            ");
            
            $stmt->execute([
                $routerId,
                $taskType,
                json_encode($configData)
            ]);
            
            error_log("📋 Router config task created: {$taskType} for router {$routerId}");
            
        } catch (Exception $e) {
            error_log("❌ Failed to create router config task: " . $e->getMessage());
        }
    }
    
    /**
     * Process pending router configuration tasks
     */
    public function processRouterConfigTasks() {
        try {
            // Get pending configuration tasks
            $stmt = $this->db->prepare("
                SELECT rct.*, cr.host, cr.username, cr.password, cr.api_port, cr.name as router_name
                FROM router_config_tasks rct
                JOIN client_routers cr ON rct.router_id = cr.id
                WHERE rct.status = 'pending' 
                  AND rct.next_attempt <= datetime('now')
                  AND rct.attempts < rct.max_attempts
                ORDER BY rct.priority DESC, rct.created_at ASC
                LIMIT 10
            ");
            $stmt->execute();
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $processed = 0;
            $successful = 0;
            
            foreach ($tasks as $task) {
                $processed++;
                $result = $this->executeConfigTask($task);
                
                if ($result['success']) {
                    $successful++;
                    $this->markTaskCompleted($task['id']);
                } else {
                    $this->markTaskFailed($task['id'], $result['message']);
                }
            }
            
            if ($processed > 0) {
                error_log("📋 Router config tasks processed: {$successful}/{$processed} successful");
            }
            
            return [
                'success' => true,
                'processed' => $processed,
                'successful' => $successful,
                'message' => 'Router configuration tasks processed'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to process router config tasks: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Execute individual configuration task
     */
    private function executeConfigTask($task) {
        try {
            $configData = json_decode($task['config_data'], true);
            
            switch ($task['task_type']) {
                case 'vpn_setup':
                    return $this->executeVPNSetupTask($task, $configData);
                    
                case 'bandwidth_config':
                    return $this->executeBandwidthConfigTask($task, $configData);
                    
                case 'security_update':
                    return $this->executeSecurityUpdateTask($task, $configData);
                    
                default:
                    throw new Exception("Unknown task type: " . $task['task_type']);
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Execute VPN setup task on router
     */
    private function executeVPNSetupTask($task, $configData) {
        try {
            // Connect to router
            $connected = $this->mikrotikApi->connect(
                $task['host'],
                $task['username'],
                $task['password'],
                $task['api_port']
            );
            
            if (!$connected) {
                throw new Exception("Failed to connect to router");
            }
            
            // Create VPN configuration script on router
            $vpnScript = base64_decode($configData['router_script'] ?? '');
            if (empty($vpnScript)) {
                throw new Exception("VPN configuration script not found");
            }
            
            // Deploy VPN configuration via RouterOS script
            $scriptName = 'vpn-setup-' . time();
            $scriptResult = $this->mikrotikApi->executeCommand('/system/script/add', [
                'name' => $scriptName,
                'source' => $vpnScript
            ]);
            
            if ($scriptResult === false) {
                throw new Exception("Failed to create VPN setup script");
            }
            
            // Execute VPN setup script
            $executeResult = $this->mikrotikApi->executeCommand('/system/script/run', [
                'number' => $scriptName
            ]);
            
            if ($executeResult === false) {
                throw new Exception("Failed to execute VPN setup script");
            }
            
            // Clean up script
            $this->mikrotikApi->executeCommand('/system/script/remove', [
                'number' => $scriptName
            ]);
            
            $this->mikrotikApi->disconnect();
            
            // Update VPN client status to connected
            $stmt = $this->db->prepare("
                UPDATE vpn_clients 
                SET connection_status = 'connected', updated_at = datetime('now') 
                WHERE id = ?
            ");
            $stmt->execute([$configData['vpn_client_id']]);
            
            error_log("🔐 VPN setup completed on router {$task['router_name']}");
            
            return [
                'success' => true,
                'message' => 'VPN setup completed successfully'
            ];
            
        } catch (Exception $e) {
            $this->mikrotikApi->disconnect();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Mark configuration task as completed
     */
    private function markTaskCompleted($taskId) {
        $stmt = $this->db->prepare("
            UPDATE router_config_tasks 
            SET status = 'completed', completed_at = datetime('now') 
            WHERE id = ?
        ");
        $stmt->execute([$taskId]);
    }
    
    /**
     * Mark configuration task as failed
     */
    private function markTaskFailed($taskId, $errorMessage) {
        $stmt = $this->db->prepare("
            UPDATE router_config_tasks 
            SET attempts = attempts + 1, 
                error_message = ?,
                next_attempt = datetime('now', '+' || (attempts * 5) || ' minutes'),
                status = CASE 
                    WHEN attempts >= max_attempts THEN 'failed' 
                    ELSE 'pending' 
                END
            WHERE id = ?
        ");
        $stmt->execute([$errorMessage, $taskId]);
    }
    
    /**
     * Get sync statistics
     */
    public function getSyncStats() {
        try {
            $stats = [];
            
            // Active sessions per router
            $stmt = $this->db->prepare("
                SELECT mr.name, mr.id, mr.status, COUNT(ws.id) as active_sessions
                FROM mikrotik_routers mr
                LEFT JOIN wifi_sessions ws ON mr.id = ws.router_id AND ws.status = 'active'
                GROUP BY mr.id
            ");
            $stmt->execute();
            $routerStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Bandwidth queue statistics
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_queues,
                       SUM(current_download_speed) as total_download_allocation,
                       SUM(current_upload_speed) as total_upload_allocation,
                       COUNT(CASE WHEN fup_applied = 1 THEN 1 END) as fup_violations
                FROM user_bandwidth_queues
                WHERE queue_status = 'active'
            ");
            $stmt->execute();
            $queueStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'router_stats' => $routerStats,
                'queue_stats' => $queueStats,
                'total_routers' => count($routerStats),
                'online_routers' => count(array_filter($routerStats, fn($r) => $r['status'] === 'online'))
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to get sync stats: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
?>