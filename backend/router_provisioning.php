<?php
/**
 * Production-Grade Router Provisioning System
 * Automated MikroTik router configuration for ISP billing system
 * Handles hotspot profiles, bandwidth packages, security, and monitoring
 */

class RouterProvisioning {
    private $db;
    private $mikrotikApi;
    private $vpnManager;
    private $bandwidthManager;
    
    public function __construct($database) {
        $this->db = $database;
        $this->initializeProvisioningTables();
        
        // Initialize managers
        require_once 'enhanced_mikrotik_api.php';
        require_once 'vpn_manager.php';
        require_once 'bandwidth_manager.php';
        
        $this->mikrotikApi = new EnhancedMikroTikAPI();
        $this->vpnManager = new VPNManager($database);
        $this->bandwidthManager = new BandwidthManager($database);
    }
    
    /**
     * Initialize provisioning tables
     */
    private function initializeProvisioningTables() {
        // Router provisioning templates
        $sql = "CREATE TABLE IF NOT EXISTS router_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_name VARCHAR(100) NOT NULL,
            template_type VARCHAR(50) NOT NULL,      -- 'hotspot', 'pppoe', 'wireless'
            description TEXT,
            mikrotik_config TEXT NOT NULL,           -- RouterOS commands
            default_packages TEXT,                   -- JSON array of package IDs
            security_settings TEXT,                  -- JSON security configuration
            monitoring_config TEXT,                  -- JSON monitoring setup
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->db->exec($sql);
        
        // Router provisioning history
        $sql = "CREATE TABLE IF NOT EXISTS router_provisioning_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            router_id INTEGER NOT NULL,
            template_id INTEGER,
            provisioning_type VARCHAR(50) NOT NULL,
            configuration_data TEXT,
            execution_status VARCHAR(20) DEFAULT 'pending',
            execution_log TEXT,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME,
            error_message TEXT,
            FOREIGN KEY(router_id) REFERENCES client_routers(id),
            FOREIGN KEY(template_id) REFERENCES router_templates(id)
        )";
        $this->db->exec($sql);
        
        // Package deployment status
        $sql = "CREATE TABLE IF NOT EXISTS package_deployments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            router_id INTEGER NOT NULL,
            package_id INTEGER NOT NULL,
            deployment_status VARCHAR(20) DEFAULT 'pending',
            mikrotik_profile_name VARCHAR(100),
            configuration_script TEXT,
            deployed_at DATETIME,
            last_sync DATETIME,
            sync_status VARCHAR(20) DEFAULT 'pending',
            FOREIGN KEY(router_id) REFERENCES client_routers(id),
            FOREIGN KEY(package_id) REFERENCES packages(id),
            UNIQUE(router_id, package_id)
        )";
        $this->db->exec($sql);
        
        error_log("🏗️ Router provisioning tables initialized");
    }
    
    /**
     * Provision new router with complete ISP setup
     */
    public function provisionRouter($routerId, $templateId = null, $options = []) {
        try {
            // Get router information
            $stmt = $this->db->prepare("
                SELECT cr.*, c.company_name, c.id as client_id
                FROM client_routers cr
                JOIN clients c ON cr.client_id = c.id
                WHERE cr.id = ?
            ");
            $stmt->execute([$routerId]);
            $router = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$router) {
                throw new Exception("Router not found");
            }
            
            // Start provisioning history
            $historyId = $this->startProvisioningHistory($routerId, $templateId, 'full_provision');
            
            error_log("🏗️ Starting router provisioning: {$router['name']} for {$router['company_name']}");
            
            // Connect to MikroTik router
            $connected = $this->mikrotikApi->connect(
                $router['host'],
                $router['username'], 
                $router['password'],
                $router['api_port']
            );
            
            if (!$connected) {
                throw new Exception("Failed to connect to router");
            }
            
            $provisioningSteps = [];
            
            // Step 1: Basic system configuration
            $step1 = $this->configureBasicSystem($router, $options);
            $provisioningSteps[] = $step1;
            
            // Step 2: Configure hotspot system
            $step2 = $this->configureHotspot($router, $options);
            $provisioningSteps[] = $step2;
            
            // Step 3: Deploy bandwidth packages
            $step3 = $this->deployBandwidthPackages($router, $options);
            $provisioningSteps[] = $step3;
            
            // Step 4: Configure security settings
            $step4 = $this->configureSecuritySettings($router, $options);
            $provisioningSteps[] = $step4;
            
            // Step 5: Setup monitoring and logging
            $step5 = $this->setupMonitoringAndLogging($router, $options);
            $provisioningSteps[] = $step5;
            
            // Step 6: Install billing system integration
            $step6 = $this->installBillingIntegration($router, $options);
            $provisioningSteps[] = $step6;
            
            $this->mikrotikApi->disconnect();
            
            // Mark provisioning as completed
            $this->completeProvisioningHistory($historyId, $provisioningSteps);
            
            // Update router status
            $stmt = $this->db->prepare("
                UPDATE client_routers 
                SET status = 'provisioned', last_config_update = datetime('now') 
                WHERE id = ?
            ");
            $stmt->execute([$routerId]);
            
            error_log("✅ Router provisioning completed successfully: {$router['name']}");
            
            return [
                'success' => true,
                'router_id' => $routerId,
                'provisioning_steps' => $provisioningSteps,
                'message' => 'Router provisioned successfully'
            ];
            
        } catch (Exception $e) {
            $this->mikrotikApi->disconnect();
            $this->failProvisioningHistory($historyId ?? null, $e->getMessage());
            
            error_log("❌ Router provisioning failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Configure basic system settings
     */
    private function configureBasicSystem($router, $options) {
        try {
            error_log("🔧 Configuring basic system settings...");
            
            $steps = [];
            
            // Set system identity
            $identity = $options['identity'] ?? "ISP-" . $router['name'];
            $result = $this->mikrotikApi->executeCommand('/system/identity/set', [
                'name' => $identity
            ]);
            $steps[] = ['action' => 'set_identity', 'result' => $result !== false, 'data' => $identity];
            
            // Configure system clock
            $clockResult = $this->mikrotikApi->executeCommand('/system/clock/set', [
                'time-zone-autodetect' => 'yes'
            ]);
            $steps[] = ['action' => 'configure_clock', 'result' => $clockResult !== false];
            
            // Enable SNMP for monitoring
            $snmpResult = $this->mikrotikApi->executeCommand('/snmp/set', [
                'enabled' => 'yes',
                'contact' => $router['company_name'] ?? 'ISP Admin'
            ]);
            $steps[] = ['action' => 'enable_snmp', 'result' => $snmpResult !== false];
            
            // Configure NTP client
            $ntpResult = $this->mikrotikApi->executeCommand('/system/ntp/client/set', [
                'enabled' => 'yes',
                'primary-ntp' => '0.pool.ntp.org',
                'secondary-ntp' => '1.pool.ntp.org'
            ]);
            $steps[] = ['action' => 'configure_ntp', 'result' => $ntpResult !== false];
            
            return [
                'step' => 'basic_system',
                'success' => true,
                'steps' => $steps,
                'message' => 'Basic system configuration completed'
            ];
            
        } catch (Exception $e) {
            return [
                'step' => 'basic_system',
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Configure MikroTik hotspot system
     */
    private function configureHotspot($router, $options) {
        try {
            error_log("🌐 Configuring hotspot system...");
            
            $steps = [];
            
            // Create hotspot interface pool
            $poolName = 'billing-pool';
            $poolResult = $this->mikrotikApi->executeCommand('/ip/pool/add', [
                'name' => $poolName,
                'ranges' => $options['ip_pool'] ?? '192.168.1.10-192.168.1.100'
            ]);
            $steps[] = ['action' => 'create_ip_pool', 'result' => $poolResult !== false, 'pool' => $poolName];
            
            // Create hotspot profile
            $profileName = 'billing-hotspot';
            $profileResult = $this->mikrotikApi->executeCommand('/ip/hotspot/profile/add', [
                'name' => $profileName,
                'hotspot-address' => $options['hotspot_ip'] ?? '192.168.1.1',
                'dns-name' => $options['dns_name'] ?? 'login.hotspot',
                'html-directory' => 'hotspot',
                'http-proxy' => '0.0.0.0:0',
                'smtp-server' => '0.0.0.0',
                'login-by' => 'username,mac,cookie',
                'split-user-domain' => 'no'
            ]);
            $steps[] = ['action' => 'create_hotspot_profile', 'result' => $profileResult !== false, 'profile' => $profileName];
            
            // Setup hotspot server
            $interface = $options['hotspot_interface'] ?? 'ether2';
            $serverResult = $this->mikrotikApi->executeCommand('/ip/hotspot/add', [
                'name' => 'billing-hotspot',
                'interface' => $interface,
                'address-pool' => $poolName,
                'profile' => $profileName,
                'idle-timeout' => '00:05:00',
                'keepalive-timeout' => '00:02:00',
                'addresses-per-mac' => '2'
            ]);
            $steps[] = ['action' => 'create_hotspot_server', 'result' => $serverResult !== false];
            
            // Configure DHCP for hotspot
            $dhcpResult = $this->mikrotikApi->executeCommand('/ip/dhcp-server/network/add', [
                'address' => $options['network'] ?? '192.168.1.0/24',
                'gateway' => $options['gateway'] ?? '192.168.1.1',
                'dns-server' => '8.8.8.8,8.8.4.4'
            ]);
            $steps[] = ['action' => 'configure_dhcp', 'result' => $dhcpResult !== false];
            
            return [
                'step' => 'hotspot_configuration',
                'success' => true,
                'steps' => $steps,
                'message' => 'Hotspot system configured successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'step' => 'hotspot_configuration',
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Deploy bandwidth packages as MikroTik profiles
     */
    private function deployBandwidthPackages($router, $options) {
        try {
            error_log("📦 Deploying bandwidth packages...");
            
            $steps = [];
            
            // Get client's packages
            $stmt = $this->db->prepare("
                SELECT * FROM packages 
                WHERE client_id = ? AND is_active = 1
                ORDER BY price ASC
            ");
            $stmt->execute([$router['client_id']]);
            $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($packages as $package) {
                $profileName = $this->generateProfileName($package);
                
                // Create user profile for each package
                $profileResult = $this->mikrotikApi->createUserProfile(
                    $profileName,
                    $package['download_speed'] ?? 5,
                    $package['upload_speed'] ?? 2,
                    $package['duration_hours'] . 'h'
                );
                
                if ($profileResult) {
                    // Record successful deployment
                    $stmt = $this->db->prepare("
                        INSERT OR REPLACE INTO package_deployments 
                        (router_id, package_id, deployment_status, mikrotik_profile_name, deployed_at) 
                        VALUES (?, ?, 'deployed', ?, datetime('now'))
                    ");
                    $stmt->execute([$router['id'], $package['id'], $profileName]);
                    
                    $steps[] = [
                        'action' => 'deploy_package',
                        'result' => true,
                        'package' => $package['name'],
                        'profile' => $profileName,
                        'speeds' => $package['download_speed'] . '/' . $package['upload_speed'] . ' Mbps'
                    ];
                } else {
                    $steps[] = [
                        'action' => 'deploy_package',
                        'result' => false,
                        'package' => $package['name'],
                        'error' => 'Failed to create profile'
                    ];
                }
            }
            
            return [
                'step' => 'bandwidth_packages',
                'success' => true,
                'steps' => $steps,
                'packages_deployed' => count($packages),
                'message' => 'Bandwidth packages deployed successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'step' => 'bandwidth_packages',
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Configure security settings
     */
    private function configureSecuritySettings($router, $options) {
        try {
            error_log("🔒 Configuring security settings...");
            
            $steps = [];
            
            // Enable firewall
            $firewallRules = [
                // Allow established connections
                ['/ip/firewall/filter/add', [
                    'chain' => 'input',
                    'connection-state' => 'established,related',
                    'action' => 'accept',
                    'comment' => 'Allow established'
                ]],
                // Allow ICMP
                ['/ip/firewall/filter/add', [
                    'chain' => 'input',
                    'protocol' => 'icmp',
                    'action' => 'accept',
                    'comment' => 'Allow ICMP'
                ]],
                // Allow SSH from specific networks
                ['/ip/firewall/filter/add', [
                    'chain' => 'input',
                    'protocol' => 'tcp',
                    'dst-port' => '22',
                    'src-address-list' => 'admin-networks',
                    'action' => 'accept',
                    'comment' => 'Allow SSH from admin networks'
                ]],
                // Allow API access from billing server
                ['/ip/firewall/filter/add', [
                    'chain' => 'input',
                    'protocol' => 'tcp',
                    'dst-port' => '8728,8729',
                    'src-address-list' => 'billing-servers',
                    'action' => 'accept',
                    'comment' => 'Allow API from billing servers'
                ]],
                // Drop everything else to router
                ['/ip/firewall/filter/add', [
                    'chain' => 'input',
                    'action' => 'drop',
                    'comment' => 'Drop all other input'
                ]]
            ];
            
            foreach ($firewallRules as $rule) {
                $result = $this->mikrotikApi->executeCommand($rule[0], $rule[1]);
                $steps[] = [
                    'action' => 'firewall_rule',
                    'result' => $result !== false,
                    'comment' => $rule[1]['comment']
                ];
            }
            
            // Create admin networks address list
            $adminNetworks = $options['admin_networks'] ?? ['10.0.0.0/8', '192.168.0.0/16'];
            foreach ($adminNetworks as $network) {
                $this->mikrotikApi->executeCommand('/ip/firewall/address-list/add', [
                    'list' => 'admin-networks',
                    'address' => $network
                ]);
            }
            
            // Add billing server to address list
            $billingServer = $options['billing_server'] ?? '10.0.0.1';
            $this->mikrotikApi->executeCommand('/ip/firewall/address-list/add', [
                'list' => 'billing-servers',
                'address' => $billingServer
            ]);
            
            $steps[] = ['action' => 'configure_address_lists', 'result' => true];
            
            return [
                'step' => 'security_settings',
                'success' => true,
                'steps' => $steps,
                'message' => 'Security settings configured successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'step' => 'security_settings',
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Setup monitoring and logging
     */
    private function setupMonitoringAndLogging($router, $options) {
        try {
            error_log("📊 Setting up monitoring and logging...");
            
            $steps = [];
            
            // Enable logging
            $logResult = $this->mikrotikApi->executeCommand('/system/logging/add', [
                'topics' => 'info,warning,error,critical',
                'action' => 'memory'
            ]);
            $steps[] = ['action' => 'enable_logging', 'result' => $logResult !== false];
            
            // Setup SNMP community
            $snmpCommunity = $options['snmp_community'] ?? 'billing' . rand(1000, 9999);
            $snmpResult = $this->mikrotikApi->executeCommand('/snmp/community/add', [
                'name' => $snmpCommunity,
                'addresses' => $options['monitoring_servers'] ?? '0.0.0.0/0',
                'security' => 'none'
            ]);
            $steps[] = ['action' => 'setup_snmp', 'result' => $snmpResult !== false, 'community' => $snmpCommunity];
            
            // Create monitoring user
            $monitorUser = 'monitor_' . substr(md5($router['id']), 0, 8);
            $monitorPass = bin2hex(random_bytes(8));
            $userResult = $this->mikrotikApi->executeCommand('/user/add', [
                'name' => $monitorUser,
                'password' => $monitorPass,
                'group' => 'read',
                'comment' => 'Monitoring user for billing system'
            ]);
            $steps[] = ['action' => 'create_monitor_user', 'result' => $userResult !== false, 'user' => $monitorUser];
            
            // Store monitoring credentials
            $stmt = $this->db->prepare("
                UPDATE client_routers 
                SET monitoring_config = ?
                WHERE id = ?
            ");
            $monitoringConfig = json_encode([
                'snmp_community' => $snmpCommunity,
                'monitor_user' => $monitorUser,
                'monitor_password' => $monitorPass,
                'created_at' => date('c')
            ]);
            $stmt->execute([$monitoringConfig, $router['id']]);
            
            return [
                'step' => 'monitoring_logging',
                'success' => true,
                'steps' => $steps,
                'monitoring_config' => [
                    'snmp_community' => $snmpCommunity,
                    'monitor_user' => $monitorUser
                ],
                'message' => 'Monitoring and logging configured successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'step' => 'monitoring_logging',
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Install billing system integration scripts
     */
    private function installBillingIntegration($router, $options) {
        try {
            error_log("🔗 Installing billing system integration...");
            
            $steps = [];
            
            // Create billing sync script
            $billingApiUrl = $options['billing_api_url'] ?? 'http://billing.isp.local:8000/backend/sync';
            $routerId = $router['id'];
            
            $syncScript = '
:log info "Starting billing sync for router ' . $routerId . '"
:local apiUrl "' . $billingApiUrl . '"
:local routerId "' . $routerId . '"

# Get active hotspot users
:local activeUsers [/ip hotspot active print as-value]
:local userCount [:len $activeUsers]
:local userList ""

# Build user list
:foreach user in=$activeUsers do={
    :if ([:len $userList] > 0) do={
        :set userList ($userList . ",")
    }
    :set userList ($userList . ($user->"user") . ":" . ($user->"address"))
}

# Send sync request to billing system
:do {
    /tool fetch url=($apiUrl . "?router_id=" . $routerId . "&users=" . $userList) \
        dst-path="billing-sync.txt" mode=http
    :log info ("Synced " . $userCount . " users with billing system")
} on-error={
    :log error "Failed to sync with billing system"
}
';
            
            // Install sync script
            $scriptResult = $this->mikrotikApi->executeCommand('/system/script/add', [
                'name' => 'billing-sync',
                'source' => $syncScript,
                'policy' => 'read,write,policy,test'
            ]);
            $steps[] = ['action' => 'install_sync_script', 'result' => $scriptResult !== false];
            
            // Schedule sync script
            $scheduleResult = $this->mikrotikApi->executeCommand('/system/scheduler/add', [
                'name' => 'billing-sync-job',
                'interval' => '2m',
                'on-event' => 'billing-sync',
                'comment' => 'Billing system synchronization'
            ]);
            $steps[] = ['action' => 'schedule_sync', 'result' => $scheduleResult !== false];
            
            // Create billing notification script
            $notificationScript = '
# Billing notification script
:local sendNotification do={
    :local message $1
    :local apiUrl "' . $billingApiUrl . '/notify"
    :local routerId "' . $routerId . '"
    
    :do {
        /tool fetch url=($apiUrl . "?router_id=" . $routerId . "&message=" . $message) \
            mode=http dst-path="notify.txt"
    } on-error={
        :log error ("Failed to send notification: " . $message)
    }
}

# Usage: $sendNotification "Router restarted"
';
            
            $notifyResult = $this->mikrotikApi->executeCommand('/system/script/add', [
                'name' => 'billing-notify',
                'source' => $notificationScript,
                'policy' => 'read,write,policy,test'
            ]);
            $steps[] = ['action' => 'install_notification_script', 'result' => $notifyResult !== false];
            
            return [
                'step' => 'billing_integration',
                'success' => true,
                'steps' => $steps,
                'billing_api_url' => $billingApiUrl,
                'message' => 'Billing system integration installed successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'step' => 'billing_integration',
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Helper methods
    
    private function generateProfileName($package) {
        $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($package['name']));
        return 'pkg_' . $cleanName . '_' . $package['id'];
    }
    
    private function startProvisioningHistory($routerId, $templateId, $type) {
        $stmt = $this->db->prepare("
            INSERT INTO router_provisioning_history 
            (router_id, template_id, provisioning_type, started_at) 
            VALUES (?, ?, ?, datetime('now'))
        ");
        $stmt->execute([$routerId, $templateId, $type]);
        return $this->db->lastInsertId();
    }
    
    private function completeProvisioningHistory($historyId, $steps) {
        $stmt = $this->db->prepare("
            UPDATE router_provisioning_history 
            SET execution_status = 'completed', 
                completed_at = datetime('now'),
                execution_log = ?
            WHERE id = ?
        ");
        $stmt->execute([json_encode($steps), $historyId]);
    }
    
    private function failProvisioningHistory($historyId, $error) {
        if (!$historyId) return;
        
        $stmt = $this->db->prepare("
            UPDATE router_provisioning_history 
            SET execution_status = 'failed',
                completed_at = datetime('now'),
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$error, $historyId]);
    }
    
    /**
     * Get provisioning status for router
     */
    public function getProvisioningStatus($routerId) {
        try {
            // Get latest provisioning history
            $stmt = $this->db->prepare("
                SELECT * FROM router_provisioning_history 
                WHERE router_id = ? 
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$routerId]);
            $history = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get package deployment status
            $stmt = $this->db->prepare("
                SELECT pd.*, p.name as package_name 
                FROM package_deployments pd
                JOIN packages p ON pd.package_id = p.id
                WHERE pd.router_id = ?
            ");
            $stmt->execute([$routerId]);
            $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'router_id' => $routerId,
                'provisioning_history' => $history,
                'packages' => $packages,
                'is_provisioned' => $history && $history['execution_status'] === 'completed'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Sync packages to already provisioned router
     */
    public function syncPackagesToRouter($routerId) {
        try {
            $router = $this->getRouterDetails($routerId);
            if (!$router) {
                throw new Exception("Router not found");
            }
            
            $connected = $this->mikrotikApi->connect(
                $router['host'],
                $router['username'],
                $router['password'], 
                $router['api_port']
            );
            
            if (!$connected) {
                throw new Exception("Failed to connect to router");
            }
            
            $result = $this->deployBandwidthPackages($router, []);
            $this->mikrotikApi->disconnect();
            
            return $result;
            
        } catch (Exception $e) {
            $this->mikrotikApi->disconnect();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    private function getRouterDetails($routerId) {
        $stmt = $this->db->prepare("
            SELECT cr.*, c.company_name, c.id as client_id
            FROM client_routers cr
            JOIN clients c ON cr.client_id = c.id
            WHERE cr.id = ?
        ");
        $stmt->execute([$routerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>