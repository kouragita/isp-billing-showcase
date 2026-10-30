<?php
/**
 * Client Management System
 * Handles B2B SaaS client registration, authentication, and management
 */

class ClientManager {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
        $this->initializeClientTables();
    }
    
    /**
     * Initialize client management tables
     */
    private function initializeClientTables() {
        // Clients table (businesses renting your billing system)
        $sql = "CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            business_name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            phone VARCHAR(20),
            subscription_plan VARCHAR(50) DEFAULT 'starter',
            api_key VARCHAR(100) UNIQUE NOT NULL,
            domain VARCHAR(100),
            subdomain VARCHAR(50) UNIQUE,
            status VARCHAR(20) DEFAULT 'active',
            subscription_expires DATETIME,
            monthly_fee DECIMAL(10,2) DEFAULT 29.00,
            transaction_fee_percent DECIMAL(5,2) DEFAULT 2.00,
            max_routers INTEGER DEFAULT 1,
            max_users INTEGER DEFAULT 100,
            branding_enabled BOOLEAN DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->db->exec($sql);
        
        // Client routers (their MikroTik fleet)
        $sql = "CREATE TABLE IF NOT EXISTS client_routers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            router_name VARCHAR(100) NOT NULL,
            host VARCHAR(50) NOT NULL,
            api_port INTEGER DEFAULT 8728,
            username VARCHAR(50) NOT NULL,
            password VARCHAR(100) NOT NULL,
            services JSON,
            status VARCHAR(20) DEFAULT 'offline',
            last_sync DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(client_id) REFERENCES clients(id)
        )";
        $this->db->exec($sql);
        
        // Client packages (their internet offerings)
        $sql = "CREATE TABLE IF NOT EXISTS client_packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            duration_hours DECIMAL(10,2) NOT NULL,
            bandwidth_mbps INTEGER,
            data_limit_gb INTEGER,
            max_devices INTEGER DEFAULT 1,
            is_active BOOLEAN DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(client_id) REFERENCES clients(id)
        )";
        $this->db->exec($sql);
        
        // Client customers (their end users)  
        $sql = "CREATE TABLE IF NOT EXISTS client_customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            phone_number VARCHAR(20) NOT NULL,
            email VARCHAR(100),
            first_name VARCHAR(50),
            last_name VARCHAR(50),
            registration_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            total_spent DECIMAL(10,2) DEFAULT 0.00,
            last_activity DATETIME,
            status VARCHAR(20) DEFAULT 'active',
            FOREIGN KEY(client_id) REFERENCES clients(id),
            UNIQUE(client_id, phone_number)
        )";
        $this->db->exec($sql);
        
        // Client sessions (their user sessions)
        $sql = "CREATE TABLE IF NOT EXISTS client_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            customer_id INTEGER NOT NULL,
            package_id INTEGER NOT NULL,
            router_id INTEGER,
            session_token VARCHAR(100) UNIQUE NOT NULL,
            start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            end_time DATETIME,
            duration_hours DECIMAL(10,2) NOT NULL,
            data_used_mb INTEGER DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            ip_address VARCHAR(15),
            mac_address VARCHAR(18),
            device_info TEXT,
            FOREIGN KEY(client_id) REFERENCES clients(id),
            FOREIGN KEY(customer_id) REFERENCES client_customers(id),
            FOREIGN KEY(package_id) REFERENCES client_packages(id),
            FOREIGN KEY(router_id) REFERENCES client_routers(id)
        )";
        $this->db->exec($sql);
        
        // Client payments (their revenue tracking)
        $sql = "CREATE TABLE IF NOT EXISTS client_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            customer_id INTEGER NOT NULL,
            package_id INTEGER NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            mpesa_reference VARCHAR(100),
            checkout_request_id VARCHAR(100),
            payment_status VARCHAR(50) DEFAULT 'pending',
            phone_number VARCHAR(20) NOT NULL,
            transaction_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            callback_received BOOLEAN DEFAULT 0,
            service_fee DECIMAL(10,2) DEFAULT 0.00,
            net_amount DECIMAL(10,2) NOT NULL,
            FOREIGN KEY(client_id) REFERENCES clients(id),
            FOREIGN KEY(customer_id) REFERENCES client_customers(id),
            FOREIGN KEY(package_id) REFERENCES client_packages(id)
        )";
        $this->db->exec($sql);
        
        error_log("🏢 Client management tables initialized successfully");
    }
    
    /**
     * Register new client business
     */
    public function registerClient($data) {
        try {
            // Validate required fields
            $requiredFields = ['business_name', 'email', 'password', 'phone'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Check if email already exists
            $stmt = $this->db->prepare("SELECT id FROM clients WHERE email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                throw new Exception("Email already registered");
            }
            
            // Generate unique API key and subdomain
            $apiKey = $this->generateApiKey();
            $subdomain = $this->generateSubdomain($data['business_name']);
            
            // Hash password
            $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
            
            // Determine subscription plan and pricing
            $plan = $data['subscription_plan'] ?? 'starter';
            $planDetails = $this->getSubscriptionPlanDetails($plan);
            
            $sql = "INSERT INTO clients 
                    (business_name, email, password_hash, phone, subscription_plan, api_key, 
                     subdomain, monthly_fee, max_routers, max_users, subscription_expires) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+1 month'))";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['business_name'],
                $data['email'],
                $passwordHash,
                $data['phone'],
                $plan,
                $apiKey,
                $subdomain,
                $planDetails['monthly_fee'],
                $planDetails['max_routers'],
                $planDetails['max_users']
            ]);
            
            $clientId = $this->db->lastInsertId();
            
            error_log("🆕 New client registered: {$data['business_name']} (ID: $clientId)");
            
            return [
                'success' => true,
                'message' => 'Client registered successfully',
                'client_id' => $clientId,
                'api_key' => $apiKey,
                'subdomain' => $subdomain,
                'dashboard_url' => "https://your-saas.com/client/{$subdomain}"
            ];
            
        } catch (Exception $e) {
            error_log("❌ Client registration failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Authenticate client login
     */
    public function authenticateClient($email, $password) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM clients WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$client || !password_verify($password, $client['password_hash'])) {
                throw new Exception("Invalid email or password");
            }
            
            // Check if subscription is still valid
            if (strtotime($client['subscription_expires']) < time()) {
                throw new Exception("Subscription expired. Please renew to continue.");
            }
            
            // Update last login
            $stmt = $this->db->prepare("UPDATE clients SET updated_at = datetime('now') WHERE id = ?");
            $stmt->execute([$client['id']]);
            
            // Remove sensitive data
            unset($client['password_hash']);
            
            return [
                'success' => true,
                'message' => 'Authentication successful',
                'client' => $client
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get client by API key
     */
    public function getClientByApiKey($apiKey) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM clients WHERE api_key = ? AND status = 'active'");
            $stmt->execute([$apiKey]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$client) {
                throw new Exception("Invalid API key");
            }
            
            // Check subscription validity
            if (strtotime($client['subscription_expires']) < time()) {
                throw new Exception("Subscription expired");
            }
            
            unset($client['password_hash']);
            return $client;
            
        } catch (Exception $e) {
            error_log("API key validation failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get client dashboard data
     */
    public function getClientDashboardData($clientId) {
        try {
            // Client info
            $stmt = $this->db->prepare("SELECT * FROM clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            unset($client['password_hash']);
            
            // Router count
            $stmt = $this->db->prepare("SELECT COUNT(*) as total_routers FROM client_routers WHERE client_id = ?");
            $stmt->execute([$clientId]);
            $routerStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Customer count
            $stmt = $this->db->prepare("SELECT COUNT(*) as total_customers FROM client_customers WHERE client_id = ?");
            $stmt->execute([$clientId]);
            $customerStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Active sessions
            $stmt = $this->db->prepare("SELECT COUNT(*) as active_sessions FROM client_sessions WHERE client_id = ? AND status = 'active'");
            $stmt->execute([$clientId]);
            $sessionStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Revenue this month
            $stmt = $this->db->prepare("
                SELECT SUM(amount) as monthly_revenue, COUNT(*) as monthly_transactions 
                FROM client_payments 
                WHERE client_id = ? AND payment_status = 'success' 
                AND strftime('%Y-%m', transaction_date) = strftime('%Y-%m', 'now')
            ");
            $stmt->execute([$clientId]);
            $revenueStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'client' => $client,
                'stats' => [
                    'total_routers' => $routerStats['total_routers'] ?? 0,
                    'total_customers' => $customerStats['total_customers'] ?? 0,
                    'active_sessions' => $sessionStats['active_sessions'] ?? 0,
                    'monthly_revenue' => $revenueStats['monthly_revenue'] ?? 0,
                    'monthly_transactions' => $revenueStats['monthly_transactions'] ?? 0
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Error getting client dashboard data: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate unique API key
     */
    private function generateApiKey() {
        do {
            $apiKey = 'ck_' . bin2hex(random_bytes(16)) . '_' . time();
            $stmt = $this->db->prepare("SELECT id FROM clients WHERE api_key = ?");
            $stmt->execute([$apiKey]);
        } while ($stmt->fetch());
        
        return $apiKey;
    }
    
    /**
     * Generate subdomain from business name
     */
    private function generateSubdomain($businessName) {
        $subdomain = strtolower(trim($businessName));
        $subdomain = preg_replace('/[^a-z0-9\-]/', '', $subdomain);
        $subdomain = substr($subdomain, 0, 20);
        
        // Ensure uniqueness
        $counter = 1;
        $originalSubdomain = $subdomain;
        
        do {
            $stmt = $this->db->prepare("SELECT id FROM clients WHERE subdomain = ?");
            $stmt->execute([$subdomain]);
            if (!$stmt->fetch()) break;
            
            $subdomain = $originalSubdomain . $counter;
            $counter++;
        } while ($counter < 100);
        
        return $subdomain;
    }
    
    /**
     * Get subscription plan details
     */
    private function getSubscriptionPlanDetails($plan) {
        $plans = [
            'starter' => [
                'monthly_fee' => 29.00,
                'max_routers' => 1,
                'max_users' => 100,
                'features' => ['Basic Dashboard', 'Single Router', 'Email Support']
            ],
            'business' => [
                'monthly_fee' => 99.00,
                'max_routers' => 5,
                'max_users' => 500,
                'features' => ['Advanced Analytics', 'Multiple Routers', 'Priority Support']
            ],
            'enterprise' => [
                'monthly_fee' => 299.00,
                'max_routers' => 999,
                'max_users' => 9999,
                'features' => ['White Label', 'Unlimited Routers', '24/7 Support', 'Custom Integration']
            ]
        ];
        
        return $plans[$plan] ?? $plans['starter'];
    }
    
    /**
     * Update client subscription
     */
    public function updateSubscription($clientId, $plan) {
        try {
            $planDetails = $this->getSubscriptionPlanDetails($plan);
            
            $sql = "UPDATE clients 
                    SET subscription_plan = ?, monthly_fee = ?, max_routers = ?, max_users = ?,
                        subscription_expires = datetime('now', '+1 month'), updated_at = datetime('now')
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $plan,
                $planDetails['monthly_fee'],
                $planDetails['max_routers'],
                $planDetails['max_users'],
                $clientId
            ]);
            
            return [
                'success' => true,
                'message' => 'Subscription updated successfully',
                'plan_details' => $planDetails
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