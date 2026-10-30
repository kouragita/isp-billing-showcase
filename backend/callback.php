<?php
/**
 * Production M-Pesa Callback Handler with IP Whitelisting
 * Handles callbacks from Safaricom M-Pesa API Gateway
 */

// Always respond with 200 first to acknowledge receipt
http_response_code(200);
header('Content-Type: application/json');

// M-Pesa Gateway IP whitelist
$allowedIPs = [
    '196.201.214.200',
    '196.201.214.206',
    '196.201.213.114',
    '196.201.214.207',
    '196.201.214.208',
    '196.201.213.44',
    '196.201.212.127',
    '196.201.212.138',
    '196.201.212.129',
    '196.201.212.136',
    '196.201.212.74',
    '196.201.212.69',
    '127.0.0.1', // For local testing
    '::1' // For local testing IPv6
];

// Get client IP (handle proxy/load balancer scenarios)
function getClientIP() {
    $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip = trim($_SERVER[$key]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
            // Handle comma-separated IPs (X-Forwarded-For)
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

$clientIP = getClientIP();

// Enhanced logging
$logData = [
    'timestamp' => date('c'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'client_ip' => $clientIP,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'headers' => getallheaders(),
    'body' => file_get_contents('php://input'),
    'ip_whitelisted' => in_array($clientIP, $allowedIPs)
];

// Always log for debugging
file_put_contents('mpesa_callback.log', json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);

// Respond to Safaricom immediately
echo json_encode([
    'ResultCode' => 0,
    'ResultDesc' => 'Success',
    'timestamp' => date('c')
]);

// Process callback after responding
$input = file_get_contents('php://input');

if (!empty($input)) {
    try {
        // Verify IP (log warning but don't block in production)
        if (!in_array($clientIP, $allowedIPs)) {
            error_log("⚠️ M-Pesa callback from non-whitelisted IP: $clientIP");
            // Continue processing but log the concern
        }

        // Process M-Pesa callback
        $data = json_decode($input, true);
        
        if ($data && isset($data['Body']['stkCallback'])) {
            $callback = $data['Body']['stkCallback'];
            $result_code = $callback['ResultCode'] ?? -1;
            $result_desc = $callback['ResultDesc'] ?? 'Unknown error';
            $checkout_request_id = $callback['CheckoutRequestID'] ?? '';
            $merchant_request_id = $callback['MerchantRequestID'] ?? '';
            
            error_log("📱 M-Pesa STK Callback: CheckoutID=$checkout_request_id, ResultCode=$result_code, IP=$clientIP");
            error_log("🔍 CALLBACK DEBUG: Processing payment for $checkout_request_id");
            
            if ($result_code == 0) {
                // Payment successful
                require_once 'api.php';
                $userManager = new WiFiUserManager();
                
                // Extract receipt number from callback metadata
                $receipt = 'MPESA_' . time();
                if (isset($callback['CallbackMetadata']['Item'])) {
                    foreach ($callback['CallbackMetadata']['Item'] as $item) {
                        if ($item['Name'] === 'MpesaReceiptNumber') {
                            $receipt = $item['Value'];
                            break;
                        }
                    }
                }
                
                // Activate the session
                error_log("🔧 CALLBACK DEBUG: Attempting to activate session $checkout_request_id with receipt $receipt");
                $activated = $userManager->activateSession($checkout_request_id, $receipt);
                error_log("🔧 CALLBACK DEBUG: Activation result: " . ($activated ? 'SUCCESS' : 'FAILED'));
                
                if ($activated) {
                    error_log("✅ Session activated successfully: CheckoutID=$checkout_request_id, Receipt=$receipt");
                    
                    // NEW: Process payment for real bandwidth management
                    require_once 'billing_sync_engine.php';
                    $billingSyncEngine = new BillingSyncEngine($userManager->db);
                    
                    // Find the payment record that was just activated
                    $stmt = $userManager->db->prepare("SELECT id FROM payments WHERE checkout_request_id = ? AND payment_status = 'success' ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([$checkout_request_id]);
                    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($payment) {
                        // Process payment to create actual router user with bandwidth controls
                        $routerResult = $billingSyncEngine->processPaymentToRouter($payment['id']);
                        
                        if ($routerResult['success']) {
                            error_log("🚀 REAL BANDWIDTH: User {$routerResult['username']} created on {$routerResult['router_info']['router_name']} with {$routerResult['bandwidth']}");
                        } else {
                            error_log("❌ Failed to create router user: " . $routerResult['message']);
                        }
                    } else {
                        error_log("⚠️ Could not find payment record for checkout_request_id: $checkout_request_id");
                    }
                    
                    // 📱 NEW: Send SMS voucher to customer
                    try {
                        require_once 'sms_service.php';
                        $smsService = new SMSService();
                        
                        // Get payment details including phone number and voucher info
                        $paymentStmt = $userManager->db->prepare("
                            SELECT 
                                phone_number as phone, 
                                voucher_code,
                                amount,
                                duration_hours,
                                session_start,
                                session_end
                            FROM wifi_sessions 
                            WHERE checkout_request_id = ? 
                            ORDER BY created_at DESC 
                            LIMIT 1
                        ");
                        $paymentStmt->execute([$checkout_request_id]);
                        $paymentData = $paymentStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($paymentData && $paymentData['phone'] && $paymentData['voucher_code']) {
                            // Format duration for display
                            $duration = $paymentData['duration_hours'] . ' hour' . ($paymentData['duration_hours'] > 1 ? 's' : '');
                            
                            // Create package name based on amount and duration
                            $packageName = "WiFi Package - KSH {$paymentData['amount']} ({$duration})";
                            
                            $voucherData = [
                                'code' => $paymentData['voucher_code'],
                                'package_name' => $packageName,
                                'duration' => $duration,
                                'data_limit' => 'Unlimited', // Default for now
                                'amount' => $paymentData['amount']
                            ];
                            
                            error_log("📱 CALLBACK DEBUG: Sending SMS to " . $paymentData['phone'] . " with voucher " . $voucherData['code']);
                            $smsResult = $smsService->sendVoucherSMS($paymentData['phone'], $voucherData);
                            error_log("📱 CALLBACK DEBUG: SMS result: " . json_encode($smsResult));
                            
                            if ($smsResult['success']) {
                                error_log("📱 SMS voucher sent successfully to: " . $paymentData['phone']);
                            } else {
                                error_log("❌ SMS voucher failed: " . $smsResult['message']);
                            }
                        } else {
                            error_log("⚠️ Cannot send SMS - missing phone or voucher data for checkout: $checkout_request_id");
                        }
                        
                    } catch (Exception $e) {
                        error_log("💥 SMS Service Error in callback: " . $e->getMessage());
                    }
                    
                } else {
                    error_log("❌ Failed to activate session: CheckoutID=$checkout_request_id");
                }
                
                // Log the successful payment
                $userManager->logPayment(
                    '', // Phone will be retrieved from session
                    $checkout_request_id,
                    $result_code,
                    $result_desc,
                    null, // Amount will be retrieved from session
                    $receipt
                );
                
            } else {
                // Payment failed
                error_log("❌ M-Pesa payment failed: $result_desc (Code: $result_code)");
                
                // Log the failed payment
                require_once 'api.php';
                $userManager = new WiFiUserManager();
                $userManager->logPayment(
                    '',
                    $checkout_request_id,
                    $result_code,
                    $result_desc,
                    null,
                    null
                );
            }
        } else {
            error_log("⚠️ Invalid M-Pesa callback structure: " . substr($input, 0, 200));
        }
        
    } catch (Exception $e) {
        error_log("💥 M-Pesa callback processing error: " . $e->getMessage());
        error_log("📄 Raw input: " . substr($input, 0, 500));
    }
}

// Additional debug endpoint for testing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'M-Pesa Callback Endpoint Active',
        'timestamp' => date('c'),
        'client_ip' => $clientIP,
        'ip_whitelisted' => in_array($clientIP, $allowedIPs),
        'allowed_ips' => $allowedIPs
    ]);
}
?>