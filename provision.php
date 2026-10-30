<?php
/**
 * Router Provisioning Endpoint
 * Generates MikroTik configuration scripts for client routers
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('log_errors', 1);

// Parse URL: /provision/{client_id}/{router_id}
$urlPath = trim($_SERVER['REQUEST_URI'], '/');
$pathParts = explode('/', $urlPath);

if (count($pathParts) < 3 || $pathParts[0] !== 'provision') {
    http_response_code(404);
    die("# Invalid provisioning URL\n# Usage: /provision/{client_id}/{router_id}");
}

$clientId = (int)$pathParts[1];
$routerId = (int)$pathParts[2];

if (!$clientId || !$routerId) {
    http_response_code(400);
    die("# Invalid client ID or router ID");
}

// Include necessary files
require_once 'backend/client_manager.php';

try {
    // Connect to database
    $db = new PDO('sqlite:backend/data/wifi_billing.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Initialize client manager
    $clientManager = new ClientManager($db);
    
    // Verify client exists
    $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND status = 'active'");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        http_response_code(404);
        die("# Client not found or inactive");
    }
    
    // Verify router belongs to client
    $stmt = $db->prepare("SELECT * FROM client_routers WHERE id = ? AND client_id = ?");
    $stmt->execute([$routerId, $clientId]);
    $router = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$router) {
        http_response_code(404);
        die("# Router not found or not authorized");
    }
    
    // Get client packages for configuration
    $stmt = $db->prepare("SELECT * FROM client_packages WHERE client_id = ? AND is_active = 1 ORDER BY price ASC");
    $stmt->execute([$clientId]);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate base URL
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];
    
    // Set content type for RouterOS script
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="billing-setup.rsc"');
    
    // Generate the configuration script
    echo generateMikroTikScript($client, $router, $packages, $baseUrl);
    
} catch (Exception $e) {
    error_log("Provisioning error: " . $e->getMessage());
    http_response_code(500);
    die("# Error generating configuration: " . $e->getMessage());
}

/**
 * Generate complete MikroTik RouterOS script
 */
function generateMikroTikScript($client, $router, $packages, $baseUrl) {
    $clientId = $client['id'];
    $routerId = $router['id'];
    $businessName = $client['business_name'];
    $services = json_decode($router['services'] ?? '["hotspot"]', true);
    
    $script = "# ============================================================================
# TECH CONNECT WiFi Billing System - Auto Configuration
# ============================================================================
# Business: {$businessName}
# Client ID: {$clientId} | Router ID: {$routerId}
# Generated: " . date('Y-m-d H:i:s') . "
# ============================================================================

:log info \"Starting TECH CONNECT billing system setup...\"

";

    // 1. Download hotspot templates if hotspot service is enabled
    if (in_array('hotspot', $services)) {
        $script .= generateHotspotConfiguration($clientId, $baseUrl, $businessName, $packages);
    }
    
    // 2. PPPoE server configuration
    if (in_array('pppoe', $services)) {
        $script .= generatePPPoEConfiguration($clientId, $baseUrl);
    }
    
    // 3. DHCP server configuration  
    if (in_array('dhcp', $services)) {
        $script .= generateDHCPConfiguration($clientId);
    }
    
    // 4. Billing integration scripts
    $script .= generateBillingIntegration($clientId, $routerId, $baseUrl);
    
    // 5. Monitoring and sync scripts
    $script .= generateMonitoringScripts($clientId, $routerId, $baseUrl);
    
    $script .= "
# ============================================================================
# SETUP COMPLETE
# ============================================================================

:log info \"TECH CONNECT billing system configured successfully!\"
:log info \"Business: {$businessName}\"
:log info \"Dashboard: {$baseUrl}/client/{$client['subdomain']}\"
:log info \"API Key: {$client['api_key']}\"

# Save configuration
/system backup save name=\"pre-billing-backup\"
:log info \"Backup saved as 'pre-billing-backup'\"
";

    return $script;
}

/**
 * Generate hotspot configuration
 */
function generateHotspotConfiguration($clientId, $baseUrl, $businessName, $packages) {
    return "
# ============================================================================
# HOTSPOT SERVICE CONFIGURATION
# ============================================================================

:log info \"Configuring hotspot service...\"

# Create hotspot directory
/file remove [find name=\"hotspot\"]
:delay 1s

# Download custom hotspot templates
/tool fetch url=\"{$baseUrl}/templates/{$clientId}/login.html\" dst-path=\"hotspot/login.html\"
/tool fetch url=\"{$baseUrl}/templates/{$clientId}/status.html\" dst-path=\"hotspot/status.html\"
/tool fetch url=\"{$baseUrl}/templates/{$clientId}/logout.html\" dst-path=\"hotspot/logout.html\"
/tool fetch url=\"{$baseUrl}/templates/{$clientId}/error.html\" dst-path=\"hotspot/error.html\"

# Download CSS and assets
/tool fetch url=\"{$baseUrl}/templates/{$clientId}/style.css\" dst-path=\"hotspot/style.css\"
/tool fetch url=\"{$baseUrl}/templates/{$clientId}/logo.png\" dst-path=\"hotspot/logo.png\"

# Create user profile for billing users
/ip hotspot user profile add name=\"billing-users\" \\
    idle-timeout=none \\
    keepalive-timeout=none \\
    status-autorefresh=1m \\
    session-timeout=none \\
    shared-users=1 \\
    rate-limit=\"\"

# Create hotspot profile  
/ip hotspot profile add name=\"{$businessName}-billing\" \\
    html-directory=\"hotspot\" \\
    http-proxy=\"\" \\
    login-by=\"http-pap,cookie\" \\
    use-radius=no \\
    nas-port-type=wireless-802.11

# Setup hotspot server (assuming wlan1 interface)
:if ([/interface print count-only where name=\"wlan1\"] > 0) do={
    /ip hotspot add name=\"{$businessName}-hotspot\" \\
        interface=wlan1 \\
        address-pool=dhcp_pool1 \\
        profile=\"{$businessName}-billing\"
    :log info \"Hotspot server created on wlan1\"
} else={
    :log warning \"wlan1 interface not found - please manually configure hotspot server\"
}

";
}

/**
 * Generate PPPoE server configuration  
 */
function generatePPPoEConfiguration($clientId, $baseUrl) {
    return "
# ============================================================================
# PPPOE SERVER CONFIGURATION
# ============================================================================

:log info \"Configuring PPPoE server...\"

# Create PPP profile for billing users
/ppp profile add name=\"billing-pppoe\" \\
    local-address=10.10.10.1 \\
    remote-address=pppoe-pool \\
    use-compression=no \\
    use-encryption=no \\
    only-one=yes

# Create address pool for PPPoE clients
/ip pool add name=\"pppoe-pool\" ranges=10.10.10.100-10.10.10.200

# Enable PPPoE server
/interface pppoe-server server add \\
    service-name=\"Billing-PPPoE\" \\
    interface=ether2 \\
    default-profile=billing-pppoe \\
    disabled=no

:log info \"PPPoE server configured\"

";
}

/**
 * Generate DHCP configuration
 */
function generateDHCPConfiguration($clientId) {
    return "
# ============================================================================  
# DHCP SERVER CONFIGURATION
# ============================================================================

:log info \"Configuring DHCP server...\"

# Create DHCP pool if not exists
:if ([/ip pool print count-only where name=\"dhcp_pool1\"] = 0) do={
    /ip pool add name=\"dhcp_pool1\" ranges=192.168.88.10-192.168.88.254
}

# Setup DHCP server
/ip dhcp-server network add \\
    address=192.168.88.0/24 \\
    gateway=192.168.88.1 \\
    dns-server=8.8.8.8,8.8.4.4 \\
    comment=\"Billing DHCP Network\"

# Enable DHCP server if not exists
:if ([/ip dhcp-server print count-only where name=\"dhcp1\"] = 0) do={
    /ip dhcp-server add name=\"dhcp1\" \\
        interface=bridge \\
        address-pool=dhcp_pool1 \\
        disabled=no
}

:log info \"DHCP server configured\"

";
}

/**
 * Generate billing integration scripts
 */
function generateBillingIntegration($clientId, $routerId, $baseUrl) {
    return "
# ============================================================================
# BILLING SYSTEM INTEGRATION
# ============================================================================

:log info \"Setting up billing integration...\"

# Create billing sync script
/system script add name=\"billing-sync\" source={
    :log info \"Starting billing sync...\"
    
    :local apiUrl \"{$baseUrl}/api.php\"
    :local clientId \"{$clientId}\"
    :local routerId \"{$routerId}\"
    
    # Get active hotspot users
    :local activeUsers [/ip hotspot active print as-value]
    :local userCount [:len \$activeUsers]
    
    # Sync user data with billing system
    :do {
        /tool fetch \\
            url=(\$apiUrl . \"?action=sync_router_users&client_id=\" . \$clientId . \"&router_id=\" . \$routerId . \"&user_count=\" . \$userCount) \\
            dst-path=\"billing-sync.txt\"
        :log info (\"Synced \" . \$userCount . \" active users with billing system\")
    } on-error={
        :log error \"Failed to sync with billing system\"
    }
}

# Create user authentication script
/system script add name=\"billing-auth\" source={
    # This script handles user authentication with the billing system
    :local username \$1
    :local password \$2
    
    :log info (\"Authenticating user: \" . \$username)
    
    # Add user validation logic here
    :return true
}

# Create session cleanup script  
/system script add name=\"billing-cleanup\" source={
    :log info \"Cleaning up expired sessions...\"
    
    # Remove inactive users after timeout
    :foreach user in=[/ip hotspot active find] do={
        :local uptime [/ip hotspot active get \$user uptime]
        :local username [/ip hotspot active get \$user user]
        
        # Check if session should be expired (implement your logic)
        # /ip hotspot active remove \$user
    }
}

:log info \"Billing integration scripts created\"

";
}

/**
 * Generate monitoring and sync scripts
 */
function generateMonitoringScripts($clientId, $routerId, $baseUrl) {
    return "
# ============================================================================
# MONITORING AND AUTOMATION
# ============================================================================

:log info \"Setting up monitoring and automation...\"

# Schedule billing sync every minute
/system scheduler add name=\"billing-sync-job\" \\
    interval=1m \\
    on-event=\"billing-sync\" \\
    comment=\"Sync active users with billing system\"

# Schedule session cleanup every 5 minutes
/system scheduler add name=\"billing-cleanup-job\" \\
    interval=5m \\
    on-event=\"billing-cleanup\" \\
    comment=\"Clean up expired user sessions\"

# Create system health monitor
/system script add name=\"health-monitor\" source={
    :local clientId \"{$clientId}\"
    :local routerId \"{$routerId}\"
    :local apiUrl \"{$baseUrl}/api.php\"
    
    # Get system resources
    :local cpuLoad [/system resource get cpu-load]
    :local freeMemory [/system resource get free-memory]
    :local uptime [/system resource get uptime]
    
    # Send health data to billing system
    :do {
        /tool fetch \\
            url=(\$apiUrl . \"?action=router_health&client_id=\" . \$clientId . \"&router_id=\" . \$routerId . \"&cpu=\" . \$cpuLoad . \"&memory=\" . \$freeMemory) \\
            dst-path=\"health-report.txt\"
    } on-error={
        :log warning \"Failed to send health report\"
    }
}

# Schedule health monitoring every 10 minutes
/system scheduler add name=\"health-monitor-job\" \\
    interval=10m \\
    on-event=\"health-monitor\" \\
    comment=\"Monitor router health and report to billing system\"

:log info \"Monitoring and automation configured\"

";
}
?>