<?php
/**
 * SMS Service - Africa's Talking Integration
 * Handles voucher delivery via SMS
 */

require_once 'api.php';

class SMSService {
    private $username;
    private $apiKey;
    private $senderId;
    private $baseUrl;
    
    public function __construct() {
        $this->username = Config::getAfricasTalkingUsername();
        $this->apiKey = Config::getAfricasTalkingApiKey();
        $this->senderId = Config::getAfricasTalkingSenderId();
        $this->baseUrl = 'https://api.africastalking.com/version1/messaging';
    }
    
    /**
     * Format phone number to international format (+254...)
     */
    private function formatPhoneNumber($phone) {
        // Remove any spaces, dashes, or other characters
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // If starts with 254, add +
        if (substr($phone, 0, 3) === '254') {
            return '+' . $phone;
        }
        
        // If starts with 0, replace with +254
        if (substr($phone, 0, 1) === '0') {
            return '+254' . substr($phone, 1);
        }
        
        // If starts with 7 or 1 (common Kenya mobile prefixes), add +254
        if (preg_match('/^[71]/', $phone)) {
            return '+254' . $phone;
        }
        
        // If already has +, return as is
        if (substr($phone, 0, 1) === '+') {
            return $phone;
        }
        
        // Default: assume it's a Kenya number and add +254
        return '+254' . $phone;
    }
    
    /**
     * Send SMS via Africa's Talking API
     */
    public function sendSMS($phoneNumber, $message) {
        try {
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            
            // Prepare POST data
            $postData = [
                'username' => $this->username,
                'to' => $formattedPhone,
                'message' => $message,
                'from' => $this->senderId
            ];
            
            // Initialize cURL
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->baseUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($postData),
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                    'apiKey: ' . $this->apiKey
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);
            
            if ($curlError) {
                throw new Exception("cURL Error: " . $curlError);
            }
            
            $responseData = json_decode($response, true);
            
            if ($httpCode === 201 || $httpCode === 200) {
                error_log("📱 SMS sent successfully to $formattedPhone");
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'data' => $responseData,
                    'phone' => $formattedPhone
                ];
            } else {
                error_log("❌ SMS failed to $formattedPhone: HTTP $httpCode - " . $response);
                return [
                    'success' => false,
                    'message' => 'SMS sending failed',
                    'error' => $responseData ?? $response,
                    'http_code' => $httpCode
                ];
            }
            
        } catch (Exception $e) {
            error_log("💥 SMS Service Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'SMS service error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Send voucher SMS with formatted template
     */
    public function sendVoucherSMS($phoneNumber, $voucherData) {
        $businessName = Config::BUSINESS_NAME;
        $customerCare = Config::CUSTOMER_CARE;
        
        // Create voucher message template
        $message = " Payment Confirmed!\n\n";
        $message .= "Your WiFi Voucher: {$voucherData['code']}\n";
        $message .= "Package: {$voucherData['package_name']}\n";
        $message .= "Duration: {$voucherData['duration']}\n";
        $message .= "Data: {$voucherData['data_limit']}\n\n";
        $message .= "Connect to: TechConnect-WiFi\n";
        $message .= "Enter voucher code to start browsing!\n\n";
        $message .= "Support: {$customerCare}\n";
        $message .= "- {$businessName}";
        
        error_log("📤 Sending voucher SMS to: $phoneNumber");
        error_log("📝 Message: " . substr($message, 0, 100) . "...");
        
        return $this->sendSMS($phoneNumber, $message);
    }
    
    /**
     * Send generic notification SMS
     */
    public function sendNotificationSMS($phoneNumber, $title, $content) {
        $businessName = Config::BUSINESS_NAME;
        $customerCare = Config::CUSTOMER_CARE;
        
        $message = "📢 {$title}\n\n";
        $message .= "{$content}\n\n";
        $message .= "Support: {$customerCare}\n";
        $message .= "- {$businessName}";
        
        return $this->sendSMS($phoneNumber, $message);
    }
    
    /**
     * Test SMS functionality
     */
    public function testSMS($phoneNumber) {
        $message = "🧪 Test SMS from " . Config::BUSINESS_NAME . " WiFi System.\n";
        $message .= "Your SMS integration is working perfectly!\n";
        $message .= "Time: " . date('Y-m-d H:i:s');
        
        return $this->sendSMS($phoneNumber, $message);
    }
}
?>