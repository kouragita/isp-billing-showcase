<?php
/**
 * Hotspot Template Generator
 * Generates branded hotspot pages for client businesses
 */

// Parse URL: /templates/{client_id}/{template_name}
$urlPath = trim($_SERVER['REQUEST_URI'], '/');
$pathParts = explode('/', $urlPath);

if (count($pathParts) < 3 || $pathParts[0] !== 'templates') {
    http_response_code(404);
    die("Template not found");
}

$clientId = (int)$pathParts[1];
$templateName = $pathParts[2];

if (!$clientId || !$templateName) {
    http_response_code(400);
    die("Invalid template request");
}

try {
    // Connect to database
    $db = new PDO('sqlite:backend/data/wifi_billing.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get client information
    $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND status = 'active'");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        http_response_code(404);
        die("Client not found");
    }
    
    // Get client packages for display
    $stmt = $db->prepare("SELECT * FROM client_packages WHERE client_id = ? AND is_active = 1 ORDER BY price ASC");
    $stmt->execute([$clientId]);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate template based on name
    switch ($templateName) {
        case 'login.html':
            generateLoginTemplate($client, $packages);
            break;
        case 'status.html':
            generateStatusTemplate($client);
            break;
        case 'logout.html':
            generateLogoutTemplate($client);
            break;
        case 'error.html':
            generateErrorTemplate($client);
            break;
        case 'style.css':
            generateStylesheet($client);
            break;
        case 'logo.png':
            generateLogo($client);
            break;
        default:
            http_response_code(404);
            die("Template not found");
    }
    
} catch (Exception $e) {
    error_log("Template generation error: " . $e->getMessage());
    http_response_code(500);
    die("Error generating template");
}

/**
 * Generate login page template
 */
function generateLoginTemplate($client, $packages) {
    header('Content-Type: text/html; charset=utf-8');
    
    $businessName = htmlspecialchars($client['business_name']);
    $primaryColor = '#667eea';
    $secondaryColor = '#764ba2';
    
    echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Welcome to {$businessName} WiFi</title>
    <link rel=\"stylesheet\" href=\"style.css\">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, {$primaryColor} 0%, {$secondaryColor} 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-width: 400px;
            width: 100%;
            text-align: center;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, {$primaryColor}, {$secondaryColor});
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: bold;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
            font-weight: 700;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        input[type=\"text\"], input[type=\"password\"], select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        
        input[type=\"text\"]:focus, input[type=\"password\"]:focus, select:focus {
            outline: none;
            border-color: {$primaryColor};
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .login-button {
            background: linear-gradient(135deg, {$primaryColor} 0%, {$secondaryColor} 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s ease;
        }
        
        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        
        .packages {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid #e9ecef;
        }
        
        .packages h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 15px;
        }
        
        .package-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .package-name {
            font-weight: 600;
            color: #333;
        }
        
        .package-price {
            color: {$primaryColor};
            font-weight: bold;
        }
        
        .package-duration {
            font-size: 12px;
            color: #666;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            font-size: 12px;
            color: #666;
        }
        
        .powered-by {
            margin-top: 10px;
            font-size: 11px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class=\"login-container\">
        <div class=\"logo\">" . strtoupper(substr($businessName, 0, 1)) . "</div>
        
        <h1>Welcome to {$businessName}</h1>
        <p class=\"subtitle\">Connect to our high-speed internet</p>
        
        <form name=\"login\" action=\"\$(link-login-only)\" method=\"post\" onSubmit=\"return openAgreement()\">
            <input type=\"hidden\" name=\"dst\" value=\"\$(link-orig)\" />
            <input type=\"hidden\" name=\"popup\" value=\"true\" />
            
            <div class=\"form-group\">
                <label for=\"username\">Phone Number</label>
                <input type=\"text\" name=\"username\" id=\"username\" placeholder=\"e.g., 254712345678\" required />
            </div>
            
            <div class=\"form-group\">
                <label for=\"password\">Voucher Code</label>
                <input type=\"password\" name=\"password\" id=\"password\" placeholder=\"Enter voucher code\" required />
            </div>
            
            <button type=\"submit\" class=\"login-button\">🚀 Connect Now</button>
        </form>";
    
    if (!empty($packages)) {
        echo "
        <div class=\"packages\">
            <h3>📦 Available Packages</h3>";
        
        foreach ($packages as $package) {
            $duration = formatDuration($package['duration_hours']);
            echo "
            <div class=\"package-item\">
                <div>
                    <div class=\"package-name\">{$package['name']}</div>
                    <div class=\"package-duration\">{$duration}</div>
                </div>
                <div class=\"package-price\">KES {$package['price']}</div>
            </div>";
        }
        
        echo "
            <p style=\"font-size: 12px; color: #666; margin-top: 15px;\">
                💡 Purchase packages via M-Pesa and get instant voucher codes
            </p>
        </div>";
    }
    
    echo "
        <div class=\"footer\">
            <p>📞 Need help? Contact: {$client['phone']}</p>
            <p>📧 Email: {$client['email']}</p>
            <div class=\"powered-by\">
                Powered by TECH CONNECT Billing System
            </div>
        </div>
    </div>
    
    <script>
        function openAgreement() {
            var username = document.getElementById('username').value;
            var password = document.getElementById('password').value;
            
            if (!username || !password) {
                alert('Please enter both phone number and voucher code');
                return false;
            }
            
            // Validate phone number format
            if (!username.match(/^254[0-9]{9}$/)) {
                alert('Please enter a valid phone number (e.g., 254712345678)');
                return false;
            }
            
            return true;
        }
        
        // Auto-format phone number
        document.getElementById('username').addEventListener('input', function(e) {
            var value = e.target.value.replace(/[^0-9]/g, '');
            if (value.length > 0 && !value.startsWith('254')) {
                if (value.startsWith('0')) {
                    value = '254' + value.substring(1);
                } else if (value.startsWith('7') || value.startsWith('1')) {
                    value = '254' + value;
                }
            }
            e.target.value = value;
        });
    </script>
</body>
</html>";
}

/**
 * Generate status page template
 */
function generateStatusTemplate($client) {
    header('Content-Type: text/html; charset=utf-8');
    
    $businessName = htmlspecialchars($client['business_name']);
    
    echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Connection Status - {$businessName}</title>
    <link rel=\"stylesheet\" href=\"style.css\">
    <meta http-equiv=\"refresh\" content=\"30\">
</head>
<body>
    <div class=\"status-container\">
        <h1>🌐 Connection Status</h1>
        <h2>{$businessName} WiFi</h2>
        
        <div class=\"status-info\">
            <div class=\"info-item\">
                <span class=\"label\">📱 Device:</span>
                <span class=\"value\">\$(mac)</span>
            </div>
            
            <div class=\"info-item\">
                <span class=\"label\">🌍 IP Address:</span>
                <span class=\"value\">\$(ip)</span>
            </div>
            
            <div class=\"info-item\">
                <span class=\"label\">⏰ Session Time:</span>
                <span class=\"value\">\$(uptime)</span>
            </div>
            
            <div class=\"info-item\">
                <span class=\"label\">📊 Data Used:</span>
                <span class=\"value\">\$(bytes-out-nice) / \$(bytes-in-nice)</span>
            </div>
            
            <div class=\"info-item\">
                <span class=\"label\">⚡ Status:</span>
                <span class=\"value connected\">✅ Connected</span>
            </div>
        </div>
        
        <div class=\"actions\">
            <a href=\"\$(link-logout)\" class=\"logout-button\">🚪 Logout</a>
            <button onclick=\"location.reload()\" class=\"refresh-button\">🔄 Refresh</button>
        </div>
        
        <div class=\"footer\">
            <p>Thank you for using {$businessName} WiFi</p>
            <p>📞 Support: {$client['phone']}</p>
        </div>
    </div>
</body>
</html>";
}

/**
 * Generate logout page template
 */
function generateLogoutTemplate($client) {
    header('Content-Type: text/html; charset=utf-8');
    
    $businessName = htmlspecialchars($client['business_name']);
    
    echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Goodbye - {$businessName}</title>
    <link rel=\"stylesheet\" href=\"style.css\">
</head>
<body>
    <div class=\"logout-container\">
        <div class=\"success-icon\">👋</div>
        <h1>Goodbye!</h1>
        <h2>Thank you for using {$businessName} WiFi</h2>
        
        <div class=\"message\">
            <p>✅ You have been successfully disconnected</p>
            <p>💡 We hope you had a great browsing experience</p>
        </div>
        
        <div class=\"actions\">
            <a href=\"\$(link-login-only)\" class=\"login-again-button\">🔄 Connect Again</a>
        </div>
        
        <div class=\"footer\">
            <p>📞 Contact us: {$client['phone']}</p>
            <p>📧 Email: {$client['email']}</p>
            <div class=\"powered-by\">
                Powered by TECH CONNECT Billing System
            </div>
        </div>
    </div>
</body>
</html>";
}

/**
 * Generate error page template
 */
function generateErrorTemplate($client) {
    header('Content-Type: text/html; charset=utf-8');
    
    $businessName = htmlspecialchars($client['business_name']);
    
    echo "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <title>Connection Error - {$businessName}</title>
    <link rel=\"stylesheet\" href=\"style.css\">
</head>
<body>
    <div class=\"error-container\">
        <div class=\"error-icon\">⚠️</div>
        <h1>Connection Error</h1>
        <h2>{$businessName} WiFi</h2>
        
        <div class=\"error-message\">
            <p><strong>Error:</strong> \$(error)</p>
        </div>
        
        <div class=\"solutions\">
            <h3>💡 Try these solutions:</h3>
            <ul>
                <li>✅ Check your voucher code</li>
                <li>📱 Verify your phone number format (254XXXXXXXXX)</li>
                <li>🔄 Try refreshing the page</li>
                <li>📞 Contact support if the problem persists</li>
            </ul>
        </div>
        
        <div class=\"actions\">
            <a href=\"\$(link-login-only)\" class=\"retry-button\">🔄 Try Again</a>
        </div>
        
        <div class=\"footer\">
            <p>📞 Support: {$client['phone']}</p>
            <p>📧 Email: {$client['email']}</p>
        </div>
    </div>
</body>
</html>";
}

/**
 * Generate CSS stylesheet
 */
function generateStylesheet($client) {
    header('Content-Type: text/css; charset=utf-8');
    
    echo "/* TECH CONNECT Billing System - Hotspot Styles */
/* Client: {$client['business_name']} */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.status-container, .logout-container, .error-container {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    padding: 40px;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    max-width: 500px;
    width: 100%;
    text-align: center;
}

.status-info {
    margin: 30px 0;
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #e9ecef;
}

.info-item:last-child {
    border-bottom: none;
}

.label {
    font-weight: 600;
    color: #333;
}

.value {
    color: #666;
    font-family: monospace;
}

.value.connected {
    color: #28a745;
    font-weight: bold;
}

.actions {
    margin: 30px 0;
    display: flex;
    gap: 15px;
    justify-content: center;
}

.logout-button, .refresh-button, .retry-button, .login-again-button {
    padding: 12px 24px;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
    font-size: 14px;
}

.logout-button {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: white;
}

.refresh-button {
    background: linear-gradient(135deg, #17a2b8, #138496);
    color: white;
}

.retry-button, .login-again-button {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.logout-button:hover, .refresh-button:hover, .retry-button:hover, .login-again-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
}

.success-icon, .error-icon {
    font-size: 48px;
    margin-bottom: 20px;
}

.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 15px;
    border-radius: 8px;
    margin: 20px 0;
    font-weight: 500;
}

.solutions {
    text-align: left;
    margin: 20px 0;
    background: #e2f3ff;
    padding: 20px;
    border-radius: 8px;
}

.solutions h3 {
    color: #333;
    margin-bottom: 15px;
}

.solutions ul {
    list-style: none;
    padding-left: 0;
}

.solutions li {
    padding: 5px 0;
    color: #555;
}

.footer {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e9ecef;
    font-size: 12px;
    color: #666;
}

.powered-by {
    margin-top: 10px;
    font-size: 11px;
    color: #999;
}

@media (max-width: 480px) {
    .actions {
        flex-direction: column;
    }
    
    .info-item {
        flex-direction: column;
        text-align: center;
        gap: 5px;
    }
}";
}

/**
 * Generate placeholder logo
 */
function generateLogo($client) {
    header('Content-Type: image/png');
    
    // Create a simple 200x200 logo with business name initial
    $image = imagecreate(200, 200);
    $bg = imagecolorallocate($image, 102, 126, 234);
    $text = imagecolorallocate($image, 255, 255, 255);
    
    $initial = strtoupper(substr($client['business_name'], 0, 1));
    imagestring($image, 5, 90, 90, $initial, $text);
    
    imagepng($image);
    imagedestroy($image);
}

/**
 * Format duration helper
 */
function formatDuration($hours) {
    if ($hours < 1) {
        return ($hours * 60) . " minutes";
    } else if ($hours < 24) {
        return $hours . " hour" . ($hours > 1 ? 's' : '');
    } else if ($hours < 168) {
        $days = floor($hours / 24);
        return $days . " day" . ($days > 1 ? 's' : '');
    } else {
        $weeks = floor($hours / 168);
        return $weeks . " week" . ($weeks > 1 ? 's' : '');
    }
}
?>