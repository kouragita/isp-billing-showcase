<?php
/**
 * VPN Management API Endpoints
 * RESTful API for managing VPN servers and client connections
 * Integrates with billing system for automated router VPN setup
 */

require_once 'vpn_manager.php';
require_once 'api.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Initialize managers
$userManager = new WiFiUserManager();
$vpnManager = new VPNManager($userManager->db);

// Parse request
$request_method = $_SERVER['REQUEST_METHOD'];
$path_info = $_SERVER['PATH_INFO'] ?? '';
$path_parts = array_filter(explode('/', $path_info));

// Route requests
try {
    switch ($request_method) {
        case 'GET':
            handleGetRequests($path_parts, $vpnManager);
            break;
            
        case 'POST':
            handlePostRequests($path_parts, $vpnManager);
            break;
            
        case 'PUT':
            handlePutRequests($path_parts, $vpnManager);
            break;
            
        case 'DELETE':
            handleDeleteRequests($path_parts, $vpnManager);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
    
} catch (Exception $e) {
    error_log("🔥 VPN API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Handle GET requests
 */
function handleGetRequests($pathParts, $vpnManager) {
    $endpoint = $pathParts[0] ?? '';
    
    switch ($endpoint) {
        case 'servers':
            // GET /vpn/servers - List all VPN servers
            if (isset($pathParts[1])) {
                $serverId = intval($pathParts[1]);
                echo json_encode(getVPNServer($vpnManager, $serverId));
            } else {
                echo json_encode(getVPNServers($vpnManager));
            }
            break;
            
        case 'clients':
            // GET /vpn/clients - List VPN clients
            if (isset($pathParts[1])) {
                $clientId = intval($pathParts[1]);
                echo json_encode(getVPNClient($vpnManager, $clientId));
            } else {
                $routerId = $_GET['router_id'] ?? null;
                echo json_encode(getVPNClients($vpnManager, $routerId));
            }
            break;
            
        case 'status':
            // GET /vpn/status - VPN system status
            echo json_encode(getVPNStatus($vpnManager));
            break;
            
        case 'monitor':
            // GET /vpn/monitor - Real-time monitoring
            echo json_encode($vpnManager->monitorVPNConnections());
            break;
            
        case 'config':
            // GET /vpn/config/client/{client_id} - Get client configuration
            if ($pathParts[1] === 'client' && isset($pathParts[2])) {
                $clientId = intval($pathParts[2]);
                echo json_encode(getClientConfig($vpnManager, $clientId));
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Config endpoint not found']);
            }
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
            break;
    }
}

/**
 * Handle POST requests
 */
function handlePostRequests($pathParts, $vpnManager) {
    $endpoint = $pathParts[0] ?? '';
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($endpoint) {
        case 'servers':
            // POST /vpn/servers - Create VPN server
            echo json_encode(createVPNServer($vpnManager, $input));
            break;
            
        case 'clients':
            // POST /vpn/clients - Create VPN client
            echo json_encode(createVPNClient($vpnManager, $input));
            break;
            
        case 'setup':
            // POST /vpn/setup/router - Automatic router VPN setup
            if ($pathParts[1] === 'router') {
                echo json_encode(setupRouterVPN($vpnManager, $input));
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Setup endpoint not found']);
            }
            break;
            
        case 'tunnel':
            // POST /vpn/tunnel/billing - Create billing system tunnel
            if ($pathParts[1] === 'billing') {
                echo json_encode(createBillingTunnel($vpnManager, $input));
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Tunnel endpoint not found']);
            }
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
            break;
    }
}

/**
 * Handle PUT requests
 */
function handlePutRequests($pathParts, $vpnManager) {
    $endpoint = $pathParts[0] ?? '';
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($endpoint) {
        case 'clients':
            // PUT /vpn/clients/{id}/status - Update client status
            if (isset($pathParts[1]) && $pathParts[2] === 'status') {
                $clientId = intval($pathParts[1]);
                echo json_encode(updateClientStatus($vpnManager, $clientId, $input));
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Update endpoint not found']);
            }
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
            break;
    }
}

/**
 * Handle DELETE requests
 */
function handleDeleteRequests($pathParts, $vpnManager) {
    $endpoint = $pathParts[0] ?? '';
    
    switch ($endpoint) {
        case 'clients':
            // DELETE /vpn/clients/{id} - Remove VPN client
            if (isset($pathParts[1])) {
                $clientId = intval($pathParts[1]);
                echo json_encode(removeVPNClient($vpnManager, $clientId));
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Client ID required']);
            }
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
            break;
    }
}

// API Functions

function getVPNServers($vpnManager) {
    try {
        $stmt = $vpnManager->db->prepare("
            SELECT vs.*, 
                   COUNT(vc.id) as total_clients,
                   COUNT(CASE WHEN vc.connection_status = 'connected' THEN 1 END) as connected_clients
            FROM vpn_servers vs
            LEFT JOIN vpn_clients vc ON vs.id = vc.vpn_server_id
            GROUP BY vs.id
            ORDER BY vs.created_at DESC
        ");
        $stmt->execute();
        $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'servers' => $servers,
            'count' => count($servers)
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getVPNServer($vpnManager, $serverId) {
    try {
        $stmt = $vpnManager->db->prepare("
            SELECT vs.*, 
                   COUNT(vc.id) as total_clients,
                   COUNT(CASE WHEN vc.connection_status = 'connected' THEN 1 END) as connected_clients
            FROM vpn_servers vs
            LEFT JOIN vpn_clients vc ON vs.id = vc.vpn_server_id
            WHERE vs.id = ?
            GROUP BY vs.id
        ");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$server) {
            http_response_code(404);
            return ['success' => false, 'message' => 'VPN server not found'];
        }
        
        // Get connected clients details
        $stmt = $vpnManager->db->prepare("
            SELECT vc.*, cr.name as router_name, c.company_name
            FROM vpn_clients vc
            JOIN client_routers cr ON vc.router_id = cr.id
            JOIN clients c ON vc.client_id = c.id
            WHERE vc.vpn_server_id = ?
            ORDER BY vc.connection_status DESC, vc.created_at DESC
        ");
        $stmt->execute([$serverId]);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'server' => $server,
            'clients' => $clients
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function createVPNServer($vpnManager, $config) {
    // Validate required fields
    $required = ['server_name', 'server_ip', 'server_port'];
    foreach ($required as $field) {
        if (!isset($config[$field]) || empty($config[$field])) {
            http_response_code(400);
            return ['success' => false, 'message' => "Field '{$field}' is required"];
        }
    }
    
    return $vpnManager->setupWireGuardServer($config);
}

function getVPNClients($vpnManager, $routerId = null) {
    try {
        $whereClause = '';
        $params = [];
        
        if ($routerId) {
            $whereClause = 'WHERE vc.router_id = ?';
            $params[] = $routerId;
        }
        
        $stmt = $vpnManager->db->prepare("
            SELECT vc.*, vs.server_name, vs.server_ip, vs.server_port,
                   cr.name as router_name, c.company_name
            FROM vpn_clients vc
            JOIN vpn_servers vs ON vc.vpn_server_id = vs.id
            JOIN client_routers cr ON vc.router_id = cr.id
            JOIN clients c ON vc.client_id = c.id
            {$whereClause}
            ORDER BY vc.connection_status DESC, vc.created_at DESC
        ");
        $stmt->execute($params);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'clients' => $clients,
            'count' => count($clients)
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function createVPNClient($vpnManager, $config) {
    // Validate required fields
    $required = ['router_id', 'vpn_server_id'];
    foreach ($required as $field) {
        if (!isset($config[$field]) || empty($config[$field])) {
            http_response_code(400);
            return ['success' => false, 'message' => "Field '{$field}' is required"];
        }
    }
    
    return $vpnManager->createVPNClientConfig($config['router_id'], $config['vpn_server_id'], $config);
}

function setupRouterVPN($vpnManager, $config) {
    try {
        // Validate router exists and get details
        if (!isset($config['router_id'])) {
            http_response_code(400);
            return ['success' => false, 'message' => 'router_id is required'];
        }
        
        $routerId = $config['router_id'];
        
        // Find or create suitable VPN server
        $stmt = $vpnManager->db->prepare("
            SELECT * FROM vpn_servers 
            WHERE status = 'active' AND current_clients < max_clients 
            ORDER BY current_clients ASC 
            LIMIT 1
        ");
        $stmt->execute();
        $vpnServer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$vpnServer) {
            // Create new VPN server if none available
            $serverConfig = [
                'server_name' => 'Auto-VPN-' . date('Y-m-d-H-i'),
                'server_ip' => $config['server_ip'] ?? '10.100.0.1', // Default IP
                'server_port' => 51820,
                'network_range' => '10.200.0.0/24',
                'region' => $config['region'] ?? 'default'
            ];
            
            $serverResult = $vpnManager->setupWireGuardServer($serverConfig);
            if (!$serverResult['success']) {
                return $serverResult;
            }
            
            $vpnServerId = $serverResult['server_id'];
        } else {
            $vpnServerId = $vpnServer['id'];
        }
        
        // Create VPN client for router
        $clientResult = $vpnManager->createVPNClientConfig($routerId, $vpnServerId);
        
        if ($clientResult['success']) {
            // Log successful VPN setup
            error_log("🔐 Automatic VPN setup completed for router {$routerId}");
            
            return [
                'success' => true,
                'vpn_server_id' => $vpnServerId,
                'client_config' => $clientResult,
                'message' => 'Router VPN setup completed successfully'
            ];
        }
        
        return $clientResult;
        
    } catch (Exception $e) {
        error_log("❌ Router VPN setup failed: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function createBillingTunnel($vpnManager, $config) {
    $vpnServerId = $config['vpn_server_id'] ?? 1; // Default to first server
    return $vpnManager->createBillingTunnel($vpnServerId);
}

function getVPNStatus($vpnManager) {
    try {
        // Get overall VPN system status
        $stmt = $vpnManager->db->prepare("
            SELECT 
                COUNT(DISTINCT vs.id) as total_servers,
                COUNT(DISTINCT CASE WHEN vs.status = 'active' THEN vs.id END) as active_servers,
                COUNT(DISTINCT vc.id) as total_clients,
                COUNT(DISTINCT CASE WHEN vc.connection_status = 'connected' THEN vc.id END) as connected_clients,
                SUM(vs.current_clients) as total_connections
            FROM vpn_servers vs
            LEFT JOIN vpn_clients vc ON vs.id = vc.vpn_server_id
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get recent connection logs
        $stmt = $vpnManager->db->prepare("
            SELECT vcl.*, vc.client_name, vs.server_name
            FROM vpn_connection_logs vcl
            JOIN vpn_clients vc ON vcl.vpn_client_id = vc.id
            JOIN vpn_servers vs ON vc.vpn_server_id = vs.id
            ORDER BY vcl.timestamp DESC
            LIMIT 10
        ");
        $stmt->execute();
        $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'status' => 'operational',
            'stats' => $stats,
            'recent_logs' => $recentLogs,
            'timestamp' => date('c')
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

function getClientConfig($vpnManager, $clientId) {
    try {
        $stmt = $vpnManager->db->prepare("
            SELECT vc.*, vs.server_ip, vs.server_port, vs.public_key
            FROM vpn_clients vc
            JOIN vpn_servers vs ON vc.vpn_server_id = vs.id
            WHERE vc.id = ?
        ");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$client) {
            http_response_code(404);
            return ['success' => false, 'message' => 'VPN client not found'];
        }
        
        // Generate fresh configuration
        $config = "
[Interface]
PrivateKey = " . base64_decode($client['private_key']) . "
Address = {$client['vpn_ip']}/24
DNS = 8.8.8.8

[Peer]
PublicKey = " . base64_decode($client['public_key']) . "
PresharedKey = " . base64_decode($client['preshared_key']) . "
Endpoint = {$client['server_ip']}:{$client['server_port']}
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
";
        
        return [
            'success' => true,
            'client' => $client,
            'config' => trim($config),
            'qr_code_data' => base64_encode(trim($config))
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function updateClientStatus($vpnManager, $clientId, $statusData) {
    try {
        $stmt = $vpnManager->db->prepare("
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
            $statusData['status'] ?? 'unknown',
            $statusData['last_handshake'] ?? null,
            $statusData['bytes_received'] ?? 0,
            $statusData['bytes_sent'] ?? 0,
            $statusData['quality'] ?? 0,
            $clientId
        ]);
        
        return [
            'success' => true,
            'message' => 'Client status updated successfully'
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function removeVPNClient($vpnManager, $clientId) {
    try {
        // Get client info before removal
        $stmt = $vpnManager->db->prepare("SELECT * FROM vpn_clients WHERE id = ?");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$client) {
            http_response_code(404);
            return ['success' => false, 'message' => 'VPN client not found'];
        }
        
        // Remove from database
        $stmt = $vpnManager->db->prepare("DELETE FROM vpn_clients WHERE id = ?");
        $stmt->execute([$clientId]);
        
        // Update server client count
        $stmt = $vpnManager->db->prepare("
            UPDATE vpn_servers 
            SET current_clients = current_clients - 1 
            WHERE id = ? AND current_clients > 0
        ");
        $stmt->execute([$client['vpn_server_id']]);
        
        error_log("🗑️ VPN client removed: {$client['client_name']}");
        
        return [
            'success' => true,
            'message' => 'VPN client removed successfully'
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>