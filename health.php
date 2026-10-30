<?php
/**
 * Enhanced Health Check Endpoint
 * Comprehensive system status for PostgreSQL driver debugging
 */

// Set content type
header('Content-Type: text/plain');

echo "OK\n";
echo "Timestamp: " . date('Y-m-d H:i:s T') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";

// Critical extension check with detailed info
$extensions = ['pdo', 'pdo_pgsql', 'pgsql', 'curl', 'json'];
foreach ($extensions as $ext) {
    $status = extension_loaded($ext) ? 'OK' : 'MISSING';
    echo "$ext: $status\n";
}

// PDO Driver availability check
echo "\nPDO DRIVERS:\n";
if (extension_loaded('pdo')) {
    $drivers = PDO::getAvailableDrivers();
    echo "Available: " . implode(', ', $drivers) . "\n";
    echo "pgsql_driver: " . (in_array('pgsql', $drivers) ? 'OK' : 'MISSING') . "\n";
} else {
    echo "PDO extension not loaded\n";
}

// Database connection test
echo "\nDATABASE TEST:\n";
$dbUrl = getenv('DATABASE_URL');
if (!$dbUrl) {
    echo "Database: NOT_CONFIGURED\n";
} elseif (!extension_loaded('pdo_pgsql')) {
    echo "Database: EXTENSION_MISSING\n";
} elseif (!in_array('pgsql', PDO::getAvailableDrivers())) {
    echo "Database: DRIVER_NOT_REGISTERED\n";
} else {
    try {
        $pdo = new PDO($dbUrl);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->query('SELECT version()');
        $version = $stmt->fetchColumn();
        echo "Database: OK\n";
        echo "PG_Version: " . substr($version, 0, 30) . "...\n";
    } catch (PDOException $e) {
        echo "Database: ERROR\n";
        echo "Error: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "Database: GENERAL_ERROR\n";
        echo "Error: " . $e->getMessage() . "\n";
    }
}

echo "\nStatus: HEALTHY\n";
