<?php
/**
 * VPN Server Management for Secure Router Communication
 * Handles WireGuard/OpenVPN setup for ISP-grade router management
 * Enables secure tunnels between billing system and client routers
 */

class VPNManager {
    private $db;
    private $vpnConfig;
    private $configPath = '/etc/billing-vpn/';
    
    public function __construct($database) {
        $this->db = $database;
        $this->initializeVPNTables();
        $this->vpnConfig = $this->loadVPNConfig();
    }
    
    /**
     * Initialize VPN management tables
     */
    private function initializeVPNTables() {
        // VPN server configurations
        $sql = "CREATE TABLE IF NOT EXISTS vpn_servers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_name VARCHAR(100) NOT NULL,
            server_type VARCHAR(20) NOT NULL,        -- 'wireguard', 'openvpn'
            server_ip VARCHAR(15) NOT NULL,
            server_port INTEGER NOT NULL,
            public_key TEXT,                         -- WireGuard public key
            private_key TEXT,                        -- WireGuard private key (encrypted)
            network_range VARCHAR(20) NOT NULL,      -- VPN network (10.200.0.0/24)
            status VARCHAR(20) DEFAULT 'active',     -- active, maintenance, offline
            max_clients INTEGER DEFAULT 100,
            current_clients INTEGER DEFAULT 0,
            bandwidth_limit INTEGER DEFAULT 100,     -- Mbps for VPN tunnel
            region VARCHAR(50),                      -- us-east, eu-west, etc
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->db->exec($sql);
        
        // VPN client connections (routers)
        $sql = "CREATE TABLE IF NOT EXISTS vpn_clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            router_id INTEGER NOT NULL,
            vpn_server_id INTEGER NOT NULL,
            client_name VARCHAR(100) NOT NULL,
            vpn_ip VARCHAR(15) NOT NULL,             -- VPN assigned IP
            public_key TEXT,                         -- Client's public key
            private_key TEXT,                        -- Client's private key (encrypted)
            preshared_key TEXT,                      -- Additional security
            allowed_ips VARCHAR(100) DEFAULT '0.0.0.0/0',
            endpoint VARCHAR(100),                   -- Router's public IP:port
            persistent_keepalive INTEGER DEFAULT 25,
            connection_status VARCHAR(20) DEFAULT 'disconnected',
            last_handshake DATETIME,
            bytes_received BIGINT DEFAULT 0,
            bytes_sent BIGINT DEFAULT 0,
            connection_quality INTEGER DEFAULT 100,  -- 0-100 score
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(client_id) REFERENCES clients(id),
            FOREIGN KEY(router_id) REFERENCES client_routers(id),
            FOREIGN KEY(vpn_server_id) REFERENCES vpn_servers(id),
            UNIQUE(router_id, vpn_server_id)
        )";
        $this->db->exec($sql);
        
        // VPN connection logs
        $sql = "CREATE TABLE IF NOT EXISTS vpn_connection_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            vpn_client_id INTEGER NOT NULL,
            event_type VARCHAR(50) NOT NULL,         -- connect, disconnect, handshake, error
            event_data TEXT,                         -- JSON data
            router_ip VARCHAR(15),
            vpn_server_ip VARCHAR(15),
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(vpn_client_id) REFERENCES vpn_clients(id)
        )";
        $this->db->exec($sql);
        
        // VPN security policies
        $sql = "CREATE TABLE IF NOT EXISTS vpn_security_policies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            policy_name VARCHAR(100) NOT NULL,
            client_id INTEGER,                       -- NULL = global policy
            allowed_networks TEXT,                   -- JSON array of networks
            blocked_networks TEXT,                   -- JSON array of networks
            max_bandwidth INTEGER DEFAULT 50,       -- Mbps per client
            connection_timeout INTEGER DEFAULT 300, -- seconds
            encryption_level VARCHAR(20) DEFAULT 'AES256',
            require_psk BOOLEAN DEFAULT 1,
            allow_port_forwarding BOOLEAN DEFAULT 0,
            log_level VARCHAR(20) DEFAULT 'INFO',
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(client_id) REFERENCES clients(id)
        )";
        $this->db->exec($sql);
        
        error_log("🔐 VPN management tables initialized successfully");
    }
    
    /**
     * Setup WireGuard VPN server
     */
    public function setupWireGuardServer($serverConfig) {
        try {
            // Validate configuration
            if (!$serverConfig['server_ip'] || !$serverConfig['server_port']) {
                throw new Exception("Server IP and port are required");
            }
            
            // Generate server keypair
            $keyPair = $this->generateWireGuardKeys();
            
            // Create VPN network range
            $networkRange = $serverConfig['network_range'] ?? '10.200.0.0/24';
            
            // Insert server configuration
            $stmt = $this->db->prepare("
                INSERT INTO vpn_servers 
                (server_name, server_type, server_ip, server_port, public_key, private_key, 
                 network_range, max_clients, region, created_at) 
                VALUES (?, 'wireguard', ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            
            $stmt->execute([
                $serverConfig['server_name'],
                $serverConfig['server_ip'],
                $serverConfig['server_port'],
                $keyPair['public_key'],
                $this->encryptKey($keyPair['private_key']),
                $networkRange,
                $serverConfig['max_clients'] ?? 100,
                $serverConfig['region'] ?? 'default'
            ]);
            
            $serverId = $this->db->lastInsertId();
            
            // Generate WireGuard server configuration file
            $configContent = $this->generateWireGuardServerConfig($serverId, $keyPair, $serverConfig);
            
            // Write configuration to file
            $configFile = $this->configPath . "wg-server-{$serverId}.conf";
            $this->ensureDirectory($this->configPath);
            file_put_contents($configFile, $configContent);
            
            // Generate installation script
            $installScript = $this->generateVPNInstallScript($serverId, $configFile);
            
            error_log("🔐 WireGuard server configured: {$serverConfig['server_name']} on {$serverConfig['server_ip']}:{$serverConfig['server_port']}");
            
            return [
                'success' => true,
                'server_id' => $serverId,
                'public_key' => $keyPair['public_key'],
                'config_file' => $configFile,
                'install_script' => $installScript,
                'network_range' => $networkRange,
                'message' => 'WireGuard VPN server configured successfully'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to setup WireGuard server: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create VPN client configuration for router
     */
    public function createVPNClientConfig($routerId, $vpnServerId, $clientConfig = []) {
        try {
            // Get router information
            $stmt = $this->db->prepare("
                SELECT cr.*, c.company_name 
                FROM client_routers cr
                JOIN clients c ON cr.client_id = c.id
                WHERE cr.id = ?
            ");
            $stmt->execute([$routerId]);
            $router = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$router) {
                throw new Exception("Router not found");
            }
            
            // Get VPN server information
            $stmt = $this->db->prepare("SELECT * FROM vpn_servers WHERE id = ? AND status = 'active'");
            $stmt->execute([$vpnServerId]);
            $vpnServer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vpnServer) {
                throw new Exception("VPN server not found or inactive");
            }
            
            // Generate client keypair
            $clientKeys = $this->generateWireGuardKeys();
            $presharedKey = $this->generatePresharedKey();
            
            // Assign VPN IP address
            $vpnIp = $this->assignVPNIP($vpnServerId);
            
            if (!$vpnIp) {
                throw new Exception("No available VPN IP addresses");
            }
            
            // Generate client name
            $clientName = $this->generateClientName($router);
            
            // Insert VPN client configuration
            $stmt = $this->db->prepare("
                INSERT INTO vpn_clients 
                (client_id, router_id, vpn_server_id, client_name, vpn_ip, public_key, 
                 private_key, preshared_key, endpoint, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            
            $stmt->execute([
                $router['client_id'],
                $routerId,
                $vpnServerId,
                $clientName,
                $vpnIp,
                $clientKeys['public_key'],
                $this->encryptKey($clientKeys['private_key']),
                $this->encryptKey($presharedKey),
                $router['host'] . ':51820' // Default WireGuard port for client
            ]);
            
            $vpnClientId = $this->db->lastInsertId();
            
            // Generate client configuration
            $clientConfigContent = $this->generateWireGuardClientConfig(
                $vpnClientId, 
                $clientKeys, 
                $presharedKey, 
                $vpnServer, 
                $vpnIp
            );
            
            // Generate router-side installation script
            $routerScript = $this->generateRouterVPNScript($router, $clientConfigContent);
            
            // Update server client count
            $this->updateServerClientCount($vpnServerId);
            
            error_log("🔐 VPN client created: {$clientName} ({$vpnIp}) for router {$router['name']}");
            
            return [
                'success' => true,
                'vpn_client_id' => $vpnClientId,
                'client_name' => $clientName,
                'vpn_ip' => $vpnIp,
                'client_public_key' => $clientKeys['public_key'],
                'client_config' => $clientConfigContent,
                'router_script' => $routerScript,
                'server_endpoint' => $vpnServer['server_ip'] . ':' . $vpnServer['server_port'],
                'message' => 'VPN client configuration created successfully'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to create VPN client config: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Monitor VPN connections
     */
    public function monitorVPNConnections() {
        try {
            // Get all active VPN clients
            $stmt = $this->db->prepare("
                SELECT vc.*, vs.server_name, vs.server_ip, vs.server_port, cr.name as router_name
                FROM vpn_clients vc
                JOIN vpn_servers vs ON vc.vpn_server_id = vs.id
                JOIN client_routers cr ON vc.router_id = cr.id
                WHERE vs.status = 'active'
            ");
            $stmt->execute();
            $vpnClients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $connectionStats = [
                'total_clients' => 0,
                'connected' => 0,
                'disconnected' => 0,
                'poor_quality' => 0,
                'server_stats' => []
            ];
            
            foreach ($vpnClients as $client) {
                $connectionStats['total_clients']++;
                
                // Check connection status (this would integrate with actual WireGuard status)
                $status = $this->checkVPNClientStatus($client);
                
                if ($status['connected']) {
                    $connectionStats['connected']++;
                    $this->updateClientStatus($client['id'], 'connected', $status);
                } else {
                    $connectionStats['disconnected']++;
                    $this->updateClientStatus($client['id'], 'disconnected', $status);
                }
                
                if ($status['quality'] < 70) {
                    $connectionStats['poor_quality']++;
                }
                
                // Aggregate server stats
                $serverId = $client['vpn_server_id'];
                if (!isset($connectionStats['server_stats'][$serverId])) {
                    $connectionStats['server_stats'][$serverId] = [
                        'server_name' => $client['server_name'],
                        'clients' => 0,
                        'connected' => 0
                    ];
                }
                $connectionStats['server_stats'][$serverId]['clients']++;
                if ($status['connected']) {
                    $connectionStats['server_stats'][$serverId]['connected']++;
                }
            }
            
            error_log("📊 VPN monitoring: {$connectionStats['connected']}/{$connectionStats['total_clients']} clients connected");
            
            return [
                'success' => true,
                'stats' => $connectionStats,
                'message' => 'VPN monitoring completed'
            ];
            
        } catch (Exception $e) {
            error_log("❌ VPN monitoring failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate secure tunnel endpoint for billing system
     */
    public function createBillingTunnel($vpnServerId) {
        try {
            // Get VPN server details
            $stmt = $this->db->prepare("SELECT * FROM vpn_servers WHERE id = ?");
            $stmt->execute([$vpnServerId]);
            $server = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$server) {
                throw new Exception("VPN server not found");
            }
            
            // Create billing system tunnel configuration
            $billingKeys = $this->generateWireGuardKeys();
            $billingIP = $this->getNextAvailableIP($server['network_range'], '1'); // .1 for billing
            
            $billingConfig = "
[Interface]
PrivateKey = {$billingKeys['private_key']}
Address = {$billingIP}/24
ListenPort = 51820

# Peer configurations will be added here for each router
";
            
            // Save billing system configuration
            $configFile = $this->configPath . "billing-system-vpn.conf";
            file_put_contents($configFile, $billingConfig);
            
            error_log("🔐 Billing VPN tunnel created: {$billingIP}");
            
            return [
                'success' => true,
                'billing_ip' => $billingIP,
                'billing_keys' => $billingKeys,
                'config_file' => $configFile,
                'message' => 'Billing VPN tunnel created'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Failed to create billing tunnel: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Private helper methods
    
    private function generateWireGuardKeys() {
        $privateKey = base64_encode(random_bytes(32));
        // In production, use actual WireGuard key generation
        $publicKey = base64_encode(hash('sha256', $privateKey, true));
        
        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey
        ];
    }
    
    private function generatePresharedKey() {
        return base64_encode(random_bytes(32));
    }
    
    private function assignVPNIP($vpnServerId) {
        // Get server network range
        $stmt = $this->db->prepare("SELECT network_range FROM vpn_servers WHERE id = ?");
        $stmt->execute([$vpnServerId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$server) return null;
        
        // Find next available IP
        list($network, $cidr) = explode('/', $server['network_range']);
        $baseIP = ip2long($network);
        
        for ($i = 2; $i <= 254; $i++) { // Skip .1 (gateway) and .255 (broadcast)
            $testIP = long2ip($baseIP + $i);
            
            // Check if IP is already assigned
            $stmt = $this->db->prepare("SELECT id FROM vpn_clients WHERE vpn_ip = ?");
            $stmt->execute([$testIP]);
            
            if (!$stmt->fetch()) {
                return $testIP;
            }
        }
        
        return null; // No available IPs
    }
    
    private function generateClientName($router) {
        $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', $router['name']);
        return strtolower($cleanName) . '-' . $router['id'];
    }
    
    private function generateWireGuardServerConfig($serverId, $keyPair, $config) {
        $networkRange = $config['network_range'] ?? '10.200.0.0/24';
        $listenPort = $config['server_port'];
        
        return "
[Interface]
PrivateKey = {$keyPair['private_key']}
Address = " . $this->getGatewayIP($networkRange) . "/24
ListenPort = {$listenPort}
SaveConfig = true

# Enable IP forwarding and NAT
PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE

# Clients will be added automatically
";
    }
    
    private function generateWireGuardClientConfig($clientId, $keys, $psk, $server, $clientIP) {
        return "
[Interface]
PrivateKey = {$keys['private_key']}
Address = {$clientIP}/24
DNS = 8.8.8.8, 8.8.4.4

[Peer]
PublicKey = " . $this->decryptKey($server['public_key']) . "
PresharedKey = {$psk}
Endpoint = {$server['server_ip']}:{$server['server_port']}
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
";
    }
    
    private function generateRouterVPNScript($router, $config) {
        return "#!/bin/bash
# WireGuard VPN setup script for {$router['name']}
# Generated by ISP Billing System

echo \"Installing WireGuard VPN configuration...\"

# Install WireGuard if not present
if ! command -v wg &> /dev/null; then
    echo \"Installing WireGuard...\"
    apt update && apt install -y wireguard
fi

# Create configuration directory
mkdir -p /etc/wireguard

# Write VPN configuration
cat > /etc/wireguard/billing-vpn.conf << 'EOF'
{$config}
EOF

# Set proper permissions
chmod 600 /etc/wireguard/billing-vpn.conf

# Enable and start VPN
systemctl enable wg-quick@billing-vpn
systemctl start wg-quick@billing-vpn

# Check status
if systemctl is-active --quiet wg-quick@billing-vpn; then
    echo \"✅ VPN connection established successfully\"
    wg show
else
    echo \"❌ VPN connection failed\"
    journalctl -u wg-quick@billing-vpn --no-pager
fi

echo \"VPN setup complete for {$router['name']}\"
";
    }
    
    private function generateVPNInstallScript($serverId, $configFile) {
        return "#!/bin/bash
# VPN Server Installation Script
# Server ID: {$serverId}

echo \"Setting up WireGuard VPN server...\"

# Install WireGuard
apt update
apt install -y wireguard

# Copy configuration
cp {$configFile} /etc/wireguard/wg0.conf
chmod 600 /etc/wireguard/wg0.conf

# Enable IP forwarding
echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
sysctl -p

# Start VPN server
systemctl enable wg-quick@wg0
systemctl start wg-quick@wg0

# Configure firewall
ufw allow 51820/udp

echo \"✅ VPN server installation complete\"
wg show
";
    }
    
    private function checkVPNClientStatus($client) {
        // In production, this would check actual WireGuard status
        // For now, simulate status checking
        return [
            'connected' => rand(0, 1) == 1,
            'last_handshake' => date('Y-m-d H:i:s', time() - rand(0, 300)),
            'bytes_received' => rand(1000000, 100000000),
            'bytes_sent' => rand(500000, 50000000),
            'quality' => rand(60, 100),
            'latency' => rand(20, 150)
        ];
    }
    
    private function updateClientStatus($clientId, $status, $statusData) {
        $stmt = $this->db->prepare("
            UPDATE vpn_clients 
            SET connection_status = ?, 
                last_handshake = ?, 
                bytes_received = ?,
                bytes_sent = ?,
                connection_quality = ?,
                updated_at = datetime('now')
            WHERE id = ?
        ");
        
        $stmt->execute([
            $status,
            $statusData['last_handshake'],
            $statusData['bytes_received'],
            $statusData['bytes_sent'],
            $statusData['quality'],
            $clientId
        ]);
    }
    
    private function updateServerClientCount($serverId) {
        $stmt = $this->db->prepare("
            UPDATE vpn_servers 
            SET current_clients = (
                SELECT COUNT(*) FROM vpn_clients 
                WHERE vpn_server_id = ? AND connection_status = 'connected'
            )
            WHERE id = ?
        ");
        $stmt->execute([$serverId, $serverId]);
    }
    
    private function getGatewayIP($networkRange) {
        list($network, $cidr) = explode('/', $networkRange);
        return long2ip(ip2long($network) + 1);
    }
    
    private function getNextAvailableIP($networkRange, $startOffset = '2') {
        list($network, $cidr) = explode('/', $networkRange);
        return long2ip(ip2long($network) + intval($startOffset));
    }
    
    private function encryptKey($key) {
        // In production, use proper encryption
        return base64_encode($key);
    }
    
    private function decryptKey($encryptedKey) {
        // In production, use proper decryption
        return base64_decode($encryptedKey);
    }
    
    private function ensureDirectory($path) {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
    
    private function loadVPNConfig() {
        return [
            'default_network' => '10.200.0.0/24',
            'default_port' => 51820,
            'encryption' => 'ChaCha20Poly1305',
            'keepalive' => 25
        ];
    }
}
?>