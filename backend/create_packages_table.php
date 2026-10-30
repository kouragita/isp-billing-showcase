<?php
/**
 * Create WiFi Packages Table
 * For dynamic package management system
 */

try {
    $dbFile = __DIR__ . '/data/wifi_billing.db';
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create packages table
    $sql = "CREATE TABLE IF NOT EXISTS packages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        duration_hours INTEGER NOT NULL,
        price INTEGER NOT NULL,
        package_type VARCHAR(20) DEFAULT 'standard',
        color_scheme VARCHAR(20) DEFAULT 'blue',
        is_active BOOLEAN DEFAULT 1,
        is_featured BOOLEAN DEFAULT 0,
        sort_order INTEGER DEFAULT 0,
        max_devices INTEGER DEFAULT 1,
        speed_limit_mbps INTEGER DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    
    $pdo->exec($sql);
    
    // Create indexes for better performance
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_packages_active ON packages(is_active)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_packages_type ON packages(package_type)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_packages_sort ON packages(sort_order)");
    
    // Insert default packages if none exist
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM packages");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        echo "Creating default packages...\n";
        
        $defaultPackages = [
            [
                'name' => '⚡ Quick Browse',
                'description' => 'Perfect for quick social media check and messages',
                'duration_hours' => 1,
                'price' => 20,
                'package_type' => 'basic',
                'color_scheme' => 'green',
                'is_featured' => 0,
                'sort_order' => 1,
                'max_devices' => 1,
                'speed_limit_mbps' => 5
            ],
            [
                'name' => '🌟 Popular Choice',
                'description' => 'Most popular package for streaming and browsing',
                'duration_hours' => 6,
                'price' => 50,
                'package_type' => 'standard',
                'color_scheme' => 'blue',
                'is_featured' => 1,
                'sort_order' => 2,
                'max_devices' => 2,
                'speed_limit_mbps' => 10
            ],
            [
                'name' => '🚀 Power User',
                'description' => 'Full day access with unlimited bandwidth',
                'duration_hours' => 24,
                'price' => 100,
                'package_type' => 'premium',
                'color_scheme' => 'purple',
                'is_featured' => 1,
                'sort_order' => 3,
                'max_devices' => 3,
                'speed_limit_mbps' => null
            ],
            [
                'name' => '📱 Mobile Express',
                'description' => 'Quick 30-minute session for urgent tasks',
                'duration_hours' => 0.5,
                'price' => 10,
                'package_type' => 'basic',
                'color_scheme' => 'orange',
                'is_featured' => 0,
                'sort_order' => 0,
                'max_devices' => 1,
                'speed_limit_mbps' => 3
            ],
            [
                'name' => '💼 Business Package',
                'description' => 'Professional package for work and conferences',
                'duration_hours' => 12,
                'price' => 80,
                'package_type' => 'premium',
                'color_scheme' => 'gray',
                'is_featured' => 0,
                'sort_order' => 4,
                'max_devices' => 5,
                'speed_limit_mbps' => 15
            ],
            [
                'name' => '🎮 Gaming Session',
                'description' => 'Low latency package optimized for gaming',
                'duration_hours' => 4,
                'price' => 60,
                'package_type' => 'premium',
                'color_scheme' => 'red',
                'is_featured' => 0,
                'sort_order' => 5,
                'max_devices' => 1,
                'speed_limit_mbps' => 20
            ]
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO packages (
                name, description, duration_hours, price, package_type, 
                color_scheme, is_featured, sort_order, max_devices, speed_limit_mbps
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($defaultPackages as $package) {
            $stmt->execute([
                $package['name'],
                $package['description'], 
                $package['duration_hours'],
                $package['price'],
                $package['package_type'],
                $package['color_scheme'],
                $package['is_featured'],
                $package['sort_order'],
                $package['max_devices'],
                $package['speed_limit_mbps']
            ]);
        }
        
        echo "✅ Created " . count($defaultPackages) . " default packages successfully!\n";
    } else {
        echo "✅ Packages table already exists with $count packages.\n";
    }
    
    // Show current packages
    $stmt = $pdo->prepare("SELECT name, duration_hours, price, package_type FROM packages ORDER BY sort_order");
    $stmt->execute();
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n📦 Current Packages:\n";
    foreach ($packages as $package) {
        $hours = $package['duration_hours'] == 0.5 ? '30min' : $package['duration_hours'] . 'h';
        echo "- {$package['name']} ({$hours}) - KES {$package['price']} [{$package['package_type']}]\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>