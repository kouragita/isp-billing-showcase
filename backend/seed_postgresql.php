<?php
/**
 * PostgreSQL Production Database Seeding Script
 * Seeds the production database with realistic WiFi billing data
 */

/*
 * Load DATABASE_URL from the project .env when it has not been exported, so
 * `php backend/seed_postgresql.php` works straight after copying .env.example.
 */
(static function (): void {
    if (getenv('DATABASE_URL')) {
        return;
    }
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
})();

class PostgreSQLSeeder {
    private $pdo;
    
    public function __construct($databaseUrl = null) {
        $this->initializeDatabase($databaseUrl);
    }
    
    private function initializeDatabase($databaseUrl = null) {
        // Use provided URL or get from environment
        if (!$databaseUrl) {
            $databaseUrl = getenv('DATABASE_URL');
            if (!$databaseUrl) {
                throw new Exception('DATABASE_URL environment variable not set');
            }
        }
        
        echo "🐘 Connecting to PostgreSQL...\n";
        echo "Database: " . $this->maskUrl($databaseUrl) . "\n\n";
        
        try {
            // Convert PostgreSQL URI to PDO DSN if needed
            if (strpos($databaseUrl, 'postgresql://') === 0) {
                $pdoDsn = $this->convertUriToPdoDsn($databaseUrl);
            } else {
                $pdoDsn = $databaseUrl;
            }
            
            $this->pdo = new PDO($pdoDsn);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            echo "✅ Connected to PostgreSQL successfully\n\n";
            
        } catch (PDOException $e) {
            throw new Exception('PostgreSQL connection failed: ' . $e->getMessage());
        }
    }
    
    private function convertUriToPdoDsn($uri) {
        $parsed = parse_url($uri);
        
        if (!$parsed || $parsed['scheme'] !== 'postgresql') {
            throw new Exception('Invalid PostgreSQL URI format');
        }
        
        $dsn = 'pgsql:';
        $dsn .= 'host=' . ($parsed['host'] ?? 'localhost');
        
        if (isset($parsed['port'])) {
            $dsn .= ';port=' . $parsed['port'];
        }
        
        if (isset($parsed['path'])) {
            $dbname = ltrim($parsed['path'], '/');
            $dsn .= ';dbname=' . $dbname;
        }
        
        if (isset($parsed['user'])) {
            $dsn .= ';user=' . $parsed['user'];
        }
        
        if (isset($parsed['pass'])) {
            $dsn .= ';password=' . $parsed['pass'];
        }
        
        return $dsn;
    }
    
    private function maskUrl($url) {
        return preg_replace('/\/\/[^:]+:[^@]+@/', '//***:***@', $url);
    }
    
    public function seed($productionSafe = true) {
        echo "🌱 Starting PostgreSQL database seeding...\n";
        echo "Mode: " . ($productionSafe ? 'PRODUCTION SAFE' : 'FULL TEST DATA') . "\n\n";
        
        $this->createTables();
        $this->seedWifiSessions($productionSafe);
        $this->seedPaymentLogs($productionSafe);
        $this->seedVouchers($productionSafe);
        
        echo "\n✅ PostgreSQL database seeding completed successfully!\n";
        echo "Admin panel: http://localhost:8000/backend/admin_login.php\n";
    }
    
    private function createTables() {
        echo "📋 Creating/verifying PostgreSQL tables...\n";
        
        // WiFi Sessions table
        $sql = "CREATE TABLE IF NOT EXISTS wifi_sessions (
            id SERIAL PRIMARY KEY,
            phone_number VARCHAR(20) NOT NULL,
            amount INTEGER NOT NULL,
            duration_hours INTEGER NOT NULL,
            mpesa_receipt VARCHAR(100),
            checkout_request_id VARCHAR(100),
            session_start TIMESTAMP,
            session_end TIMESTAMP,
            status VARCHAR(20) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
        
        // Payment Logs table
        $sql = "CREATE TABLE IF NOT EXISTS payment_logs (
            id SERIAL PRIMARY KEY,
            phone_number VARCHAR(20),
            checkout_request_id VARCHAR(100),
            response_code VARCHAR(10),
            response_description VARCHAR(255),
            amount INTEGER,
            mpesa_receipt VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
        
        // Vouchers table
        $sql = "CREATE TABLE IF NOT EXISTS vouchers (
            id SERIAL PRIMARY KEY,
            voucher_code VARCHAR(20) UNIQUE NOT NULL,
            duration_hours INTEGER NOT NULL,
            amount INTEGER NOT NULL,
            used BOOLEAN DEFAULT FALSE,
            used_by VARCHAR(20),
            used_at TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->pdo->exec($sql);
        
        // Create indexes for performance
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_sessions_phone ON wifi_sessions(phone_number)",
            "CREATE INDEX IF NOT EXISTS idx_sessions_status ON wifi_sessions(status)",
            "CREATE INDEX IF NOT EXISTS idx_sessions_created ON wifi_sessions(created_at)",
            "CREATE INDEX IF NOT EXISTS idx_vouchers_code ON vouchers(voucher_code)",
            "CREATE INDEX IF NOT EXISTS idx_payment_logs_phone ON payment_logs(phone_number)"
        ];
        
        foreach ($indexes as $index) {
            try {
                $this->pdo->exec($index);
            } catch (PDOException $e) {
                // Ignore if index already exists
            }
        }
        
        echo "   ✓ Tables and indexes created/verified\n";
    }
    
    private function seedWifiSessions($productionSafe = true) {
        echo "📊 Seeding wifi_sessions...\n";
        
        // Check if data already exists
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM wifi_sessions");
        $existingCount = $stmt->fetchColumn();
        
        if ($existingCount > 0 && $productionSafe) {
            echo "   ⚠️ Data already exists ({$existingCount} sessions). Skipping to preserve production data.\n";
            return;
        }
        
        $phones = [
            '254700000001', '254700000002', '254700000003', '254700000004', '254700000005',
            '254700000006', '254700000007', '254700000008', '254700000009', '254700000010'
        ];
        
        $stmt = $this->pdo->prepare("
            INSERT INTO wifi_sessions (phone_number, amount, duration_hours, mpesa_receipt, 
                                     session_start, session_end, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $sessionCount = 0;
        $sessionLimit = $productionSafe ? 20 : 100; // Fewer sessions for production
        
        foreach ($phones as $phone) {
            $sessionsPerUser = $productionSafe ? 2 : rand(3, 10);
            
            for ($i = 0; $i < $sessionsPerUser && $sessionCount < $sessionLimit; $i++) {
                $amounts = [100, 200, 300, 500, 1000];
                $durations = [1, 2, 3, 6, 12, 24];
                
                $amount = $amounts[array_rand($amounts)];
                $duration = $durations[array_rand($durations)];
                
                $createdAt = date('Y-m-d H:i:s', strtotime('-' . rand(1, 168) . ' hours'));
                $sessionStart = $createdAt;
                $sessionEnd = date('Y-m-d H:i:s', strtotime($sessionStart . " +{$duration} hours"));
                
                $statuses = ['active', 'expired', 'terminated'];
                $weights = [10, 80, 10]; // 10% active, 80% expired, 10% terminated
                $status = $this->weightedRandom($statuses, $weights);
                
                // Generate realistic M-Pesa receipt
                $mpesaReceipt = 'TXN' . strtoupper(substr(md5(uniqid()), 0, 10));
                
                $stmt->execute([
                    $phone, $amount, $duration, $mpesaReceipt,
                    $sessionStart, $sessionEnd, $status, $createdAt
                ]);
                $sessionCount++;
            }
        }
        
        echo "   ✓ Added {$sessionCount} wifi sessions\n";
    }
    
    private function seedPaymentLogs($productionSafe = true) {
        echo "💰 Seeding payment_logs...\n";
        
        // Check if data already exists
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM payment_logs");
        $existingCount = $stmt->fetchColumn();
        
        if ($existingCount > 0 && $productionSafe) {
            echo "   ⚠️ Data already exists ({$existingCount} payments). Skipping to preserve production data.\n";
            return;
        }
        
        // Get some session data for realistic payment logs
        $stmt = $this->pdo->query("SELECT phone_number, amount FROM wifi_sessions LIMIT 15");
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($sessions)) {
            echo "   ⚠️ No sessions found, skipping payment logs\n";
            return;
        }
        
        $paymentStmt = $this->pdo->prepare("
            INSERT INTO payment_logs (phone_number, checkout_request_id, response_code, 
                                    response_description, amount, mpesa_receipt, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $responses = [
            ['0', 'Success', 'TXN'],
            ['1032', 'Request cancelled by user', null],
            ['1037', 'Transaction timeout', null],
            ['0', 'Success', 'TXN'],
            ['0', 'Success', 'TXN']
        ];
        
        $paymentCount = 0;
        
        foreach ($sessions as $session) {
            if (rand(1, 10) > 6) continue; // Skip some for realism
            
            $response = $responses[array_rand($responses)];
            $checkoutId = 'ws_CO_' . date('dmY') . '_' . rand(100000000000, 999999999999);
            $receipt = $response[2] ? $response[2] . strtoupper(substr(md5(uniqid()), 0, 8)) : null;
            $createdAt = date('Y-m-d H:i:s', strtotime('-' . rand(1, 168) . ' hours'));
            
            $paymentStmt->execute([
                $session['phone_number'], $checkoutId, $response[0],
                $response[1], $session['amount'], $receipt, $createdAt
            ]);
            $paymentCount++;
        }
        
        echo "   ✓ Added {$paymentCount} payment logs\n";
    }
    
    private function seedVouchers($productionSafe = true) {
        echo "🎫 Seeding vouchers...\n";
        
        // Check if data already exists
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM vouchers");
        $existingCount = $stmt->fetchColumn();
        
        if ($existingCount > 0 && $productionSafe) {
            echo "   ⚠️ Data already exists ({$existingCount} vouchers). Skipping to preserve production data.\n";
            return;
        }
        
        $voucherStmt = $this->pdo->prepare("
            INSERT INTO vouchers (voucher_code, duration_hours, amount, used, used_by, used_at, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $durations = [1, 2, 3, 6, 12, 24];
        $amounts = [100, 200, 300, 500, 1000];
        
        $voucherCount = 0;
        $voucherLimit = $productionSafe ? 10 : 50; // Fewer vouchers for production
        
        for ($i = 0; $i < $voucherLimit; $i++) {
            $duration = $durations[array_rand($durations)];
            $amount = $amounts[array_rand($amounts)];
            
            // Generate unique voucher code
            do {
                $code = strtoupper(substr(md5(uniqid()), 0, 8));
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM vouchers WHERE voucher_code = ?");
                $stmt->execute([$code]);
                $exists = $stmt->fetchColumn() > 0;
            } while ($exists);
            
            $createdAt = date('Y-m-d H:i:s', strtotime('-' . rand(1, 72) . ' hours'));
            
            $used = rand(1, 10) > 7 ? true : false; // 30% used
            $usedBy = null;
            $usedAt = null;
            
            if ($used) {
                $usedBy = '254700000' . str_pad(rand(1, 10), 3, '0', STR_PAD_LEFT);
                $usedAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +' . rand(1, 48) . ' hours'));
            }
            
            $voucherStmt->execute([
                $code, $duration, $amount, $used, $usedBy, $usedAt, $createdAt
            ]);
            $voucherCount++;
        }
        
        echo "   ✓ Added {$voucherCount} vouchers\n";
    }
    
    private function weightedRandom($values, $weights) {
        $totalWeight = array_sum($weights);
        $random = rand(1, $totalWeight);
        
        for ($i = 0; $i < count($values); $i++) {
            $random -= $weights[$i];
            if ($random <= 0) {
                return $values[$i];
            }
        }
        
        return $values[0];
    }
    
    public function getStatistics() {
        echo "\n📊 DATABASE STATISTICS:\n";
        echo "=====================\n";
        
        $tables = ['wifi_sessions', 'payment_logs', 'vouchers'];
        
        foreach ($tables as $table) {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM {$table}");
            $count = $stmt->fetchColumn();
            echo "  {$table}: {$count} records\n";
        }
        
        // Revenue summary
        $stmt = $this->pdo->query("SELECT COALESCE(SUM(amount), 0) FROM wifi_sessions WHERE status IN ('active', 'expired')");
        $revenue = $stmt->fetchColumn();
        echo "  Total Revenue: KES {$revenue}\n";
        
        // Active sessions
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM wifi_sessions WHERE status = 'active' AND session_end > CURRENT_TIMESTAMP");
        $active = $stmt->fetchColumn();
        echo "  Active Sessions: {$active}\n";
    }
}

// CLI Usage
if (php_sapi_name() === 'cli') {
    try {
        $databaseUrl = $argv[1] ?? null;
        $productionSafe = !isset($argv[2]) || $argv[2] !== '--full-data';
        
        echo "🐘 PostgreSQL Database Seeder\n";
        echo "============================\n\n";
        
        if (!$databaseUrl && !getenv('DATABASE_URL')) {
            echo "Usage: php seed_postgresql.php [DATABASE_URL] [--full-data]\n";
            echo "   or: DATABASE_URL=postgresql://... php seed_postgresql.php\n\n";
            echo "Options:\n";
            echo "  --full-data    Seed with full test data (use for development only)\n";
            echo "  (default)      Production-safe seeding (minimal data)\n\n";
            exit(1);
        }
        
        $seeder = new PostgreSQLSeeder($databaseUrl);
        $seeder->seed($productionSafe);
        $seeder->getStatistics();
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>