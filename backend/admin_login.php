<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - TECH CONNECT WiFi</title>
    <style>
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
        
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 400px;
            position: relative;
            overflow: hidden;
        }
        
        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            color: #333;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .logo p {
            color: #666;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            color: #333;
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-control:invalid {
            border-color: #dc3545;
        }
        
        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-right: 8px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: none;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .remember-me input {
            margin-right: 8px;
        }
        
        .remember-me label {
            font-size: 14px;
            color: #666;
            cursor: pointer;
            margin: 0;
        }
        
        .footer-links {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e1e5e9;
        }
        
        .footer-links a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            margin: 0 10px;
        }
        
        .footer-links a:hover {
            text-decoration: underline;
        }
        
        .security-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
        
        .password-strength {
            display: none;
            margin-top: 5px;
        }
        
        .strength-bar {
            height: 4px;
            border-radius: 2px;
            background: #e1e5e9;
            overflow: hidden;
        }
        
        .strength-fill {
            height: 100%;
            transition: all 0.3s ease;
            width: 0%;
        }
        
        .strength-weak { background: #dc3545; }
        .strength-medium { background: #ffc107; }
        .strength-strong { background: #28a745; }
        
        /* Responsive Design */
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
                margin: 10px;
            }
            
            .logo h1 {
                font-size: 20px;
            }
            
            .form-control {
                padding: 12px 14px;
                font-size: 16px; /* Prevent zoom on iOS */
            }
            
            .btn-login {
                padding: 14px;
                font-size: 16px;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .form-control {
                background: #2d3748;
                border-color: #4a5568;
                color: white;
            }
            
            .form-control:focus {
                background: #1a202c;
            }
        }
        
        /* Animation for form appearance */
        .login-container {
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <h1>🏢 TECH CONNECT</h1>
            <p>WiFi Billing System Admin Panel</p>
        </div>
        
        <div class="security-info">
            🔒 Secure admin access required. Session timeout: 8 hours.
        </div>
        
        <div id="alertContainer"></div>
        
        <form id="loginForm">
            <div class="form-group">
                <label for="username">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    class="form-control" 
                    required 
                    autocomplete="username"
                    placeholder="Enter your username"
                >
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    class="form-control" 
                    required 
                    autocomplete="current-password"
                    placeholder="Enter your password"
                >
            </div>
            
            <div class="remember-me">
                <input type="checkbox" id="rememberMe" name="rememberMe">
                <label for="rememberMe">Remember me for 30 days</label>
            </div>
            
            <button type="submit" class="btn-login" id="loginButton">
                <span class="loading-spinner" id="loadingSpinner"></span>
                <span id="buttonText">Sign In</span>
            </button>
        </form>
        
        <div class="footer-links">
            <a href="mailto:admin@techconnect.com">Need Help?</a>
            <a href="#" onclick="showDefaultCredentials()">Default Login</a>
        </div>
    </div>

    <script>
        // Global variables
        let loginAttempts = 0;
        const maxAttempts = 5;
        
        // DOM elements
        const loginForm = document.getElementById('loginForm');
        const loginButton = document.getElementById('loginButton');
        const loadingSpinner = document.getElementById('loadingSpinner');
        const buttonText = document.getElementById('buttonText');
        const alertContainer = document.getElementById('alertContainer');
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Check if already authenticated
            checkAuthStatus();
            
            // Focus username field
            document.getElementById('username').focus();
            
            // Add enter key listener
            document.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !loginButton.disabled) {
                    loginForm.dispatchEvent(new Event('submit'));
                }
            });
        });
        
        // Check authentication status
        async function checkAuthStatus() {
            try {
                const response = await fetch('admin_auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=check_auth'
                });
                
                const result = await response.json();
                
                if (result.authenticated) {
                    // Already authenticated, redirect to admin panel
                    window.location.href = 'admin.php';
                }
            } catch (error) {
                console.log('Auth check failed:', error);
            }
        }
        
        // Form submission
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            if (loginAttempts >= maxAttempts) {
                showAlert('Too many failed attempts. Please refresh the page to try again.', 'error');
                return;
            }
            
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                showAlert('Please enter both username and password.', 'error');
                return;
            }
            
            setLoading(true);
            
            try {
                const response = await fetch('admin_auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=login&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('Login successful! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = 'admin.php';
                    }, 1000);
                } else {
                    loginAttempts++;
                    const remainingAttempts = maxAttempts - loginAttempts;
                    let message = result.message;
                    
                    if (remainingAttempts > 0) {
                        message += ` (${remainingAttempts} attempts remaining)`;
                    }
                    
                    showAlert(message, 'error');
                    
                    // Clear password field
                    document.getElementById('password').value = '';
                    document.getElementById('password').focus();
                }
            } catch (error) {
                showAlert('Login failed. Please check your connection and try again.', 'error');
                console.error('Login error:', error);
            } finally {
                setLoading(false);
            }
        });
        
        // Show alert message
        function showAlert(message, type) {
            alertContainer.innerHTML = `
                <div class="alert alert-${type}" style="display: block;">
                    ${message}
                </div>
            `;
            
            // Auto-hide success messages
            if (type === 'success') {
                setTimeout(() => {
                    alertContainer.innerHTML = '';
                }, 3000);
            }
        }
        
        // Set loading state
        function setLoading(loading) {
            loginButton.disabled = loading;
            loadingSpinner.style.display = loading ? 'inline-block' : 'none';
            buttonText.textContent = loading ? 'Signing In...' : 'Sign In';
        }
        
        // Show default credentials (for initial setup)
        function showDefaultCredentials() {
            showAlert(`
                <strong>Default Admin Credentials:</strong><br>
                Username: <code>admin</code><br>
                Password: <code>your_password</code><br>
                <em>Please change the password after first login.</em>
            `, 'success');
        }
        
        // Security: Clear sensitive data on page unload
        window.addEventListener('beforeunload', function() {
            document.getElementById('password').value = '';
            document.getElementById('username').value = '';
        });
        
        // Prevent right-click and F12 in production
        if (window.location.hostname !== 'localhost') {
            document.addEventListener('contextmenu', e => e.preventDefault());
            document.addEventListener('keydown', e => {
                if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && e.key === 'I')) {
                    e.preventDefault();
                }
            });
        }
    </script>
</body>
</html>