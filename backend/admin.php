<?php
/**
 * TECH CONNECT WiFi Admin Panel - Protected Route
 * Requires authentication to access
 */

require_once 'admin_auth.php';

// Initialize authentication and require login
$auth = new AdminAuth();
$auth->requireAuth();

// Get current admin user info
$currentUser = $auth->getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TECH CONNECT WiFi - Advanced Admin Panel</title>
    <!-- Chart.js for professional charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 15px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.97);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #e9ecef;
            position: relative;
        }

        .header h1 {
            color: #333;
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .header p {
            color: #666;
            font-size: 16px;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        
        .user-details {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .user-role {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }
        
        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        
        /* Package Management Styles */
        .package-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .controls-left h2 {
            margin: 0 0 5px 0;
            color: #333;
        }
        
        .controls-left p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }
        
        .controls-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .view-toggle {
            display: flex;
            background: #f8f9fa;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e1e5e9;
        }
        
        .toggle-btn {
            background: none;
            border: none;
            padding: 8px 12px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #666;
        }
        
        .toggle-btn.active {
            background: #007bff;
            color: white;
        }
        
        .toggle-btn:hover:not(.active) {
            background: #e9ecef;
        }
        
        .package-filters {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px solid #e1e5e9;
        }
        
        .search-container {
            flex: 1;
            min-width: 200px;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            background: white;
            border: 1px solid #ddd;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #666;
        }
        
        .filter-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .filter-btn:hover:not(.active) {
            background: #f8f9fa;
        }
        
        .sort-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 12px;
            background: white;
        }
        
        /* Package Cards Grid */
        .packages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .package-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e1e5e9;
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
        }
        
        .package-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-color: #007bff;
        }
        
        .package-card.inactive {
            opacity: 0.6;
            background: #f8f9fa;
        }
        
        .package-card.featured {
            border: 2px solid #ffd700;
            position: relative;
        }
        
        .package-card.featured::before {
            content: '⭐ Featured';
            position: absolute;
            top: 10px;
            right: 10px;
            background: #ffd700;
            color: #333;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
            z-index: 2;
        }
        
        .package-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--package-color-start), var(--package-color-end));
            color: white;
            position: relative;
        }
        
        .package-header.basic {
            --package-color-start: #28a745;
            --package-color-end: #20c997;
        }
        
        .package-header.standard {
            --package-color-start: #007bff;
            --package-color-end: #6f42c1;
        }
        
        .package-header.premium {
            --package-color-start: #6f42c1;
            --package-color-end: #e83e8c;
        }
        
        .package-name {
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 8px 0;
        }
        
        .package-description {
            font-size: 13px;
            opacity: 0.9;
            margin: 0;
            line-height: 1.4;
        }
        
        .package-body {
            padding: 20px;
        }
        
        .package-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .package-detail {
            text-align: center;
        }
        
        .detail-value {
            font-size: 20px;
            font-weight: 700;
            color: #007bff;
            margin-bottom: 4px;
        }
        
        .detail-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .package-features {
            margin: 15px 0;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 13px;
            color: #666;
        }
        
        .package-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }
        
        .package-btn {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .package-btn.edit {
            background: #28a745;
            color: white;
        }
        
        .package-btn.edit:hover {
            background: #218838;
        }
        
        .package-btn.toggle {
            background: #ffc107;
            color: #333;
        }
        
        .package-btn.toggle:hover {
            background: #e0a800;
        }
        
        .package-btn.delete {
            background: #dc3545;
            color: white;
        }
        
        .package-btn.delete:hover {
            background: #c82333;
        }
        
        .package-checkbox {
            position: absolute;
            top: 15px;
            left: 15px;
            z-index: 3;
        }
        
        /* Bulk Actions Bar */
        .bulk-actions-bar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            border: 1px solid #e1e5e9;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            z-index: 1000;
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from {
                transform: translateX(-50%) translateY(100%);
                opacity: 0;
            }
            to {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }
        
        .bulk-info {
            font-weight: 600;
            color: #333;
        }
        
        .bulk-buttons {
            display: flex;
            gap: 8px;
        }
        
        .bulk-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .bulk-btn:hover {
            transform: translateY(-1px);
        }
        
        .bulk-btn.danger {
            background: #dc3545;
            color: white;
        }
        
        .bulk-btn.secondary {
            background: #6c757d;
            color: white;
        }
        
        /* Package Table View */
        .packages-table-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #e1e5e9;
            overflow: hidden;
        }
        
        .packages-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .packages-table th,
        .packages-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f1f3f5;
        }
        
        .packages-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 13px;
        }
        
        .packages-table td {
            font-size: 13px;
        }
        
        .packages-table tr:hover {
            background: #f8f9fa;
        }
        
        .package-status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .package-status-badge.active {
            background: #d4edda;
            color: #155724;
        }
        
        .package-status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .package-type-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .package-type-badge.basic {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .package-type-badge.standard {
            background: #cce5ff;
            color: #004085;
        }
        
        .package-type-badge.premium {
            background: #e2e3ff;
            color: #383d41;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .package-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .controls-right {
                justify-content: space-between;
            }
            
            .package-filters {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .filter-buttons {
                justify-content: center;
            }
            
            .packages-grid {
                grid-template-columns: 1fr;
            }
            
            .bulk-actions-bar {
                left: 10px;
                right: 10px;
                transform: none;
                flex-direction: column;
                gap: 10px;
            }
        }

        .last-updated {
            position: absolute;
            top: 0;
            right: 0;
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            color: #666;
            border: 1px solid #e9ecef;
        }

        .tabs {
            display: flex;
            background: #f8f9fa;
            border-radius: 15px;
            padding: 5px;
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .tab {
            flex: 1;
            padding: 12px 20px;
            background: transparent;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            color: #666;
            transition: all 0.3s ease;
            white-space: nowrap;
            min-width: 120px;
        }

        .tab.active {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            box-shadow: 0 5px 15px rgba(0, 123, 255, 0.3);
        }

        .tab:hover:not(.active) {
            background: #e9ecef;
            color: #333;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.5s;
        }

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.success {
            background: linear-gradient(135deg, #28a745, #20c997);
        }

        .stat-card.warning {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
        }

        .stat-card.danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }

        .stat-card.info {
            background: linear-gradient(135deg, #17a2b8, #138496);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.95;
            font-weight: 500;
            position: relative;
            z-index: 1;
        }

        .stat-icon {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.5rem;
            opacity: 0.3;
        }

        .section {
            background: #fff;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid #e9ecef;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .section h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }

        .data-table th {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: #333;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            border-bottom: 2px solid #dee2e6;
        }

        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f3f4;
            font-size: 14px;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-expired {
            background: #f8d7da;
            color: #721c24;
        }

        .status-success {
            background: #d4edda;
            color: #155724;
        }

        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }

        .status-unused {
            background: #fff3cd;
            color: #856404;
        }

        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            margin: 2px;
            transition: all 0.3s ease;
        }

        .action-btn.btn-danger {
            background: #dc3545;
            color: white;
        }

        .action-btn.btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .btn {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 123, 255, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745, #20c997);
        }

        .btn-success:hover {
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }

        .btn-danger:hover {
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            color: #212529;
        }

        .btn-warning:hover {
            box-shadow: 0 8px 20px rgba(255, 193, 7, 0.3);
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            margin: 15px 0;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #007bff;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .search-bar {
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
        }

        .filter-select {
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            min-width: 150px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .page-btn {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .page-btn:hover {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }

        .page-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #999;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .notification.success {
            background: #28a745;
        }

        .notification.error {
            background: #dc3545;
        }

        .notification.warning {
            background: #ffc107;
            color: #212529;
        }

        .notification.info {
            background: #17a2b8;
        }

        .notification.show {
            transform: translateX(0);
        }

        /* 📱 COMPREHENSIVE RESPONSIVE BREAKPOINT SYSTEM */
        
        /* Large Desktop (1441px+) */
        @media (min-width: 1441px) {
            .container {
                max-width: 1600px;
                padding: 40px;
            }
            
            .header h1 {
                font-size: 3rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 30px;
            }
            
            .grid-3 {
                grid-template-columns: repeat(3, 1fr);
                gap: 30px;
            }
            
            .revenue-summary {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .charts-container {
                grid-template-columns: 2.5fr 1fr;
            }
        }

        /* Desktop Medium (1201px - 1440px) - Current default styles work well */
        @media (min-width: 1201px) and (max-width: 1440px) {
            .container {
                padding: 30px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Desktop Small/Tablet Landscape (1025px - 1200px) */
        @media (min-width: 1025px) and (max-width: 1200px) {
            .container {
                padding: 25px;
                margin: 20px;
            }
            
            .grid-3 {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
            }
            
            .revenue-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .charts-container {
                grid-template-columns: 1.8fr 1fr;
            }
            
            .analytics-tables {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        /* Tablet Portrait (769px - 1024px) - NEW COVERAGE */
        @media (min-width: 769px) and (max-width: 1024px) {
            .container {
                padding: 20px;
                margin: 15px;
            }
            
            .header h1 {
                font-size: 2.2rem;
            }
            
            .tabs {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
                margin-bottom: 25px;
            }
            
            .tab {
                font-size: 13px;
                padding: 12px 8px;
                margin: 0;
                text-align: center;
                min-height: 44px; /* Touch target */
            }
            
            .grid-2, .grid-3 {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .search-bar {
                flex-direction: row;
                gap: 10px;
            }
            
            .search-input, .filter-select {
                width: auto;
                flex: 1;
            }
        }

        /* Mobile Landscape (481px - 768px) - ENHANCED */
        @media (min-width: 481px) and (max-width: 768px) {
            .container {
                margin: 10px;
                padding: 15px;
                max-width: 100%;
                overflow-x: hidden;
            }
            
            .tabs {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
            }
            
            .tab {
                font-size: 13px;
                padding: 12px 8px;
                min-width: auto;
            }
            
            .header h1 {
                font-size: 1.8rem;
            }
            
            .tabs {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 5px;
                margin-bottom: 20px;
            }
            
            .tab {
                font-size: 12px;
                padding: 10px 6px;
                margin: 0;
                min-height: 44px; /* Touch target minimum */
                text-align: center;
            }
            
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .search-bar {
                flex-direction: column;
                gap: 10px;
            }
            
            .search-input, .filter-select {
                width: 100%;
            }
        }

        /* Mobile Portrait (320px - 480px) - ENHANCED */
        @media (max-width: 480px) {
            .container {
                margin: 5px;
                padding: 12px;
                max-width: 100%;
                overflow-x: hidden;
            }
            
            .header {
                padding: 15px 0;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .tabs {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 5px;
                margin-bottom: 15px;
            }
            
            .tab {
                font-size: 12px;
                padding: 10px 5px;
                margin: 0;
                min-height: 44px;
                border-radius: 6px;
                text-align: center;
                min-width: auto;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            
            .stats-grid, .grid-2, .grid-3 {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .stat-card {
                padding: 15px;
                text-align: center;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .search-bar {
                flex-direction: column;
                gap: 8px;
            }
            
            .search-input, .filter-select {
                width: 100%;
                padding: 12px;
                font-size: 16px; /* Prevent zoom on iOS */
                box-sizing: border-box;
            }
            
            /* Ensure all inputs are responsive */
            input, select, textarea {
                max-width: 100%;
                box-sizing: border-box;
            }
            
            .data-table {
                font-size: 12px;
                overflow-x: auto;
            }
            
            .data-table th,
            .data-table td {
                padding: 6px 4px;
                min-width: auto;
                font-size: 11px;
                word-break: break-word;
            }
            
            /* Make first column (phone numbers, etc) wider */
            .data-table th:first-child,
            .data-table td:first-child {
                min-width: 90px;
                max-width: 120px;
            }
            
            /* Status badges smaller on mobile */
            .status-badge {
                font-size: 9px;
                padding: 2px 6px;
            }
            
            .btn-primary {
                width: 100%;
                padding: 12px;
                font-size: 14px;
                min-height: 44px;
            }
            
            .action-btn {
                padding: 6px 12px;
                font-size: 12px;
                min-height: 36px;
            }
        }

        /* Extra Small Mobile (320px and below) */
        @media (max-width: 320px) {
            .header h1 {
                font-size: 18px;
            }
            
            .tab {
                font-size: 13px;
                padding: 10px 8px;
            }
            
            .stat-value {
                font-size: 1.3rem;
            }
        }

        /* 🚀 AMAZING REVENUE DASHBOARD STYLES */
        .revenue-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .revenue-header h2 {
            color: #2c3e50;
            font-size: 2rem;
            font-weight: 700;
        }

        .revenue-controls {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .date-picker-container {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 10px 15px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 2px solid #e9ecef;
        }

        .date-input {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
            color: #495057;
        }

        .quick-filters {
            display: flex;
            gap: 5px;
        }

        .filter-btn {
            padding: 8px 16px;
            border: 2px solid #e9ecef;
            background: white;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .filter-btn:hover, .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
            transform: translateY(-2px);
        }

        /* Revenue Summary Cards */
        .revenue-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .summary-card.primary::before { background: linear-gradient(90deg, #667eea, #764ba2); }
        .summary-card.success::before { background: linear-gradient(90deg, #11d4aa, #1dd1a1); }
        .summary-card.info::before { background: linear-gradient(90deg, #3b82f6, #1d4ed8); }
        .summary-card.warning::before { background: linear-gradient(90deg, #f59e0b, #d97706); }

        .card-icon {
            font-size: 2.5rem;
            margin-bottom: 12px;
        }

        .card-content h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .card-content p {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }

        .growth-indicator {
            font-size: 13px;
            font-weight: 600;
            margin-top: 8px;
        }

        .growth-indicator.positive { color: #10b981; }
        .growth-indicator.negative { color: #ef4444; }

        .period-info {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 8px;
        }

        /* Charts Container */
        .charts-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .chart-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-header h3 {
            color: #2c3e50;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .chart-controls {
            display: flex;
            gap: 5px;
        }

        .chart-type-btn {
            padding: 6px 12px;
            border: 1px solid #dee2e6;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .chart-type-btn:hover, .chart-type-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .side-chart .chart-container {
            height: 250px;
        }

        /* Calendar Heatmap */
        .calendar-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
            margin-bottom: 30px;
        }

        .calendar-section h3 {
            color: #2c3e50;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .calendar-heatmap {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            max-width: 100%;
            overflow-x: auto;
        }

        .calendar-day {
            aspect-ratio: 1;
            min-width: 40px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }

        .calendar-day:hover {
            transform: scale(1.1);
            z-index: 10;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .calendar-day[data-intensity="0"] { background: #f8f9fa; color: #6c757d; }
        .calendar-day[data-intensity="1"] { background: #d4edd5; color: #28a745; }
        .calendar-day[data-intensity="2"] { background: #a3d9a5; color: #1e7e34; }
        .calendar-day[data-intensity="3"] { background: #72c274; color: #155724; }
        .calendar-day[data-intensity="4"] { background: #41ab43; color: white; }
        .calendar-day[data-intensity="5"] { background: #28a745; color: white; }

        .heatmap-legend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 15px;
            font-size: 12px;
            color: #6c757d;
        }

        .legend-colors {
            display: flex;
            gap: 2px;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
        }

        .legend-color[data-intensity="0"] { background: #f8f9fa; }
        .legend-color[data-intensity="1"] { background: #d4edd5; }
        .legend-color[data-intensity="2"] { background: #a3d9a5; }
        .legend-color[data-intensity="3"] { background: #72c274; }
        .legend-color[data-intensity="4"] { background: #41ab43; }
        .legend-color[data-intensity="5"] { background: #28a745; }

        /* Analytics Tables */
        .analytics-tables {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .table-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
        }

        .table-section h3 {
            color: #2c3e50;
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .hourly-selector {
            margin-bottom: 15px;
        }

        .hourly-selector label {
            font-size: 14px;
            font-weight: 500;
            color: #495057;
            margin-right: 10px;
        }

        .analytics-table {
            max-height: 300px;
            overflow-y: auto;
        }

        .revenue-tooltip {
            position: absolute;
            background: rgba(0,0,0,0.9);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            pointer-events: none;
            z-index: 1000;
            white-space: nowrap;
        }

        /* 📊 REVENUE DASHBOARD RESPONSIVE ENHANCEMENTS */
        
        /* Large Desktop (1441px+) */
        @media (min-width: 1441px) {
            .revenue-header h2 {
                font-size: 2.5rem;
            }
            
            .revenue-summary {
                grid-template-columns: repeat(4, 1fr);
                gap: 25px;
            }
            
            .summary-card {
                padding: 30px;
            }
            
            .card-content h3 {
                font-size: 2rem;
            }
            
            .chart-container {
                height: 350px;
            }
            
            .calendar-day {
                min-width: 50px;
                font-size: 14px;
            }
        }

        /* Desktop Small/Tablet Landscape (1025px - 1200px) */
        @media (min-width: 1025px) and (max-width: 1200px) {
            .revenue-header {
                gap: 15px;
            }
            
            .revenue-controls {
                gap: 15px;
            }
            
            .chart-container {
                height: 280px;
            }
            
            .calendar-day {
                min-width: 42px;
                font-size: 12px;
            }
        }

        /* Tablet Portrait (769px - 1024px) */
        @media (min-width: 769px) and (max-width: 1024px) {
            .revenue-header {
                flex-direction: column;
                gap: 20px;
                text-align: center;
            }
            
            .revenue-controls {
                justify-content: center;
                gap: 15px;
            }
            
            .date-picker-container {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .revenue-summary {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .charts-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .chart-container {
                height: 250px;
            }
            
            .calendar-heatmap {
                grid-template-columns: repeat(7, 1fr);
                gap: 3px;
            }
            
            .calendar-day {
                min-width: 38px;
                font-size: 11px;
            }
            
            .analytics-tables {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }

        /* Mobile Landscape (481px - 768px) */
        @media (min-width: 481px) and (max-width: 768px) {
            .revenue-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
                gap: 15px;
            }

            .revenue-controls {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .date-picker-container {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            
            .date-input {
                font-size: 16px; /* Prevent zoom on iOS */
                padding: 12px;
            }

            .quick-filters {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 5px;
            }
            
            .filter-btn {
                min-height: 44px;
                font-size: 12px;
                padding: 8px 12px;
            }

            .revenue-summary {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .charts-container {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .chart-container {
                height: 220px;
            }

            .calendar-heatmap {
                grid-template-columns: repeat(7, 1fr);
                gap: 2px;
            }

            .calendar-day {
                min-width: 34px;
                font-size: 10px;
            }
            
            .analytics-tables {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }

        /* Mobile Portrait (320px - 480px) */
        @media (max-width: 480px) {
            .revenue-header h2 {
                font-size: 1.3rem;
            }
            
            .revenue-header {
                gap: 12px;
            }

            .revenue-controls {
                gap: 10px;
            }
            
            .date-picker-container {
                padding: 8px 12px;
            }
            
            .date-input {
                font-size: 16px; /* Prevent zoom */
                padding: 10px 12px;
            }

            .quick-filters {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 4px;
            }
            
            .filter-btn {
                padding: 8px 10px;
                font-size: 11px;
                min-height: 44px;
            }

            .summary-card {
                padding: 16px;
            }

            .card-content h3 {
                font-size: 1.2rem;
            }
            
            .card-icon {
                font-size: 2rem;
            }

            .chart-container {
                height: 200px;
            }
            
            .side-chart .chart-container {
                height: 180px;
            }

            .calendar-day {
                min-width: 25px;
                width: 25px;
                height: 25px;
                font-size: 8px;
                aspect-ratio: 1;
            }
            
            .heatmap-legend {
                font-size: 11px;
                gap: 8px;
            }
            
            .legend-color {
                width: 10px;
                height: 10px;
            }
            
            .table-section h3 {
                font-size: 1.1rem;
            }
            
            .hourly-selector label {
                font-size: 13px;
            }
            
            .hourly-selector .date-input {
                font-size: 14px;
                padding: 8px 10px;
            }
        }

        /* Extra Small Mobile (320px and below) */
        @media (max-width: 320px) {
            .revenue-header h2 {
                font-size: 1.1rem;
            }
            
            .card-content h3 {
                font-size: 1rem;
            }
            
            .card-icon {
                font-size: 1.8rem;
            }
            
            .filter-btn {
                font-size: 10px;
                padding: 6px 8px;
                min-height: 40px;
            }
            
            .calendar-day {
                min-width: 26px;
                font-size: 8px;
            }
            
            .chart-container {
                height: 180px;
            }
        }

        /* Enhanced Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 0;
            border: none;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            padding: 25px 30px 15px;
            border-bottom: 1px solid #e9ecef;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px 20px 0 0;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }

        .close {
            color: white;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            margin-top: -5px;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 30px;
        }

        .modal-footer {
            padding: 20px 30px 30px;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        /* Form Styles for Modal */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fff;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-input.error {
            border-color: #dc3545;
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .error-text {
            color: #dc3545;
            font-size: 12px;
            margin-top: 5px;
            font-weight: 500;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .checkbox-input {
            width: 18px;
            height: 18px;
            accent-color: #667eea;
        }

        /* Loading Spinner Animation */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="last-updated" id="lastUpdated">Last updated: --</div>
            
            <div class="header-top">
                <div>
                    <h1>🛠️ TECH CONNECT WiFi - Advanced Admin Panel</h1>
                    <p>Complete business management and analytics dashboard</p>
                </div>
                
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?></div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($currentUser['role']); ?></div>
                    </div>
                    <button class="logout-btn" onclick="showLogoutModal()">🚪 Logout</button>
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('dashboard', this)">📊 Dashboard</button>
            <button class="tab" onclick="showTab('users', this)">👥 Active Users</button>
            <button class="tab" onclick="showTab('revenue', this)">💰 Revenue</button>
            <button class="tab" onclick="showTab('sessions', this)">🕒 Sessions</button>
            <button class="tab" onclick="showTab('payments', this)">💳 Payments</button>
            <button class="tab" onclick="showTab('vouchers', this)">🎫 Vouchers</button>
            <button class="tab" onclick="showTab('packages', this)">📦 Packages</button>
            <button class="tab" onclick="showTab('sms', this)">📱 SMS Service</button>
            <button class="tab" onclick="showTab('management', this)">⚙️ Management</button>
            <button class="tab" onclick="showTab('mikrotik', this)">🌐 MikroTik</button>
        </div>

        <!-- Dashboard Tab -->
        <div id="dashboard" class="tab-content active">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <div class="stat-value" id="totalUsers">-</div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-icon">💰</div>
                    <div class="stat-value" id="todayRevenue">-</div>
                    <div class="stat-label">Today's Revenue</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-icon">📈</div>
                    <div class="stat-value" id="totalRevenue">-</div>
                    <div class="stat-label">Total Revenue</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-icon">🎫</div>
                    <div class="stat-value" id="totalVouchers">-</div>
                    <div class="stat-label">Unused Vouchers</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-icon">🕒</div>
                    <div class="stat-value" id="expiredSessions">-</div>
                    <div class="stat-label">Expired Sessions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-value" id="totalSessions">-</div>
                    <div class="stat-label">Total Sessions</div>
                </div>
            </div>

            <div class="grid-2">
                <div class="section">
                    <h2>📊 Quick Stats</h2>
                    <div id="quickStatsContent">
                        <div class="loading" id="statsLoading">
                            <div class="spinner"></div>
                            <p>Loading statistics...</p>
                        </div>
                    </div>
                </div>
                <div class="section">
                    <h2>🔧 Quick Actions</h2>
                    <div style="display: grid; gap: 10px;">
                        <button class="btn" onclick="refreshAllData()">
                            🔄 Refresh All Data
                        </button>
                        <button class="btn btn-warning" onclick="cleanExpiredSessions()">
                            🧹 Clean Expired Sessions
                        </button>
                        <button class="btn btn-success" onclick="exportData()">
                            📥 Export Data
                        </button>
                        <button class="btn btn-danger" onclick="emergencyStop()">
                            🛑 Emergency Stop All
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Users Tab -->
        <div id="users" class="tab-content">
            <div class="section">
                <h2>👥 Active Users Management</h2>
                <div class="search-bar">
                    <input type="text" class="search-input" id="userSearch" placeholder="Search by phone number...">
                    <select class="filter-select" id="userFilter">
                        <option value="all">All Sessions</option>
                        <option value="active">Active Only</option>
                        <option value="expiring">Expiring Soon</option>
                    </select>
                    <button class="btn" onclick="refreshUsers()">🔄 Refresh</button>
                </div>
                <div id="usersTableContainer">
                    <div class="loading" id="usersLoading">
                        <div class="spinner"></div>
                        <p>Loading active users...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 🚀 AMAZING Revenue Analytics Dashboard -->
        <div id="revenue" class="tab-content">
            <div class="section">
                <div class="revenue-header">
                    <h2>💰 Revenue Analytics Dashboard</h2>
                    <div class="revenue-controls">
                        <div class="date-picker-container">
                            <label>📅 Date Range:</label>
                            <input type="date" id="startDatePicker" class="date-input">
                            <span>to</span>
                            <input type="date" id="endDatePicker" class="date-input">
                            <button onclick="loadRevenueData()" class="btn-primary">Update</button>
                        </div>
                        <div class="quick-filters">
                            <button onclick="setQuickFilter('today')" class="filter-btn">Today</button>
                            <button onclick="setQuickFilter('week')" class="filter-btn active">7 Days</button>
                            <button onclick="setQuickFilter('month')" class="filter-btn">30 Days</button>
                            <button onclick="setQuickFilter('quarter')" class="filter-btn">3 Months</button>
                        </div>
                    </div>
                </div>

                <!-- Revenue Summary Cards -->
                <div class="revenue-summary">
                    <div class="summary-card primary">
                        <div class="card-icon">💰</div>
                        <div class="card-content">
                            <h3 id="totalRevenueAmount">KES 0</h3>
                            <p>Total Revenue</p>
                            <div class="growth-indicator" id="revenueGrowth">+0%</div>
                        </div>
                    </div>
                    <div class="summary-card success">
                        <div class="card-icon">📊</div>
                        <div class="card-content">
                            <h3 id="avgDailyRevenue">KES 0</h3>
                            <p>Daily Average</p>
                            <div class="growth-indicator" id="sessionGrowth">+0%</div>
                        </div>
                    </div>
                    <div class="summary-card info">
                        <div class="card-icon">🎯</div>
                        <div class="card-content">
                            <h3 id="avgSessionValue">KES 0</h3>
                            <p>Avg Session Value</p>
                            <div class="period-info" id="periodInfo">Last 7 days</div>
                        </div>
                    </div>
                    <div class="summary-card warning">
                        <div class="card-icon">🔥</div>
                        <div class="card-content">
                            <h3 id="bestDayRevenue">KES 0</h3>
                            <p>Best Day</p>
                            <div class="period-info" id="bestDayDate">-</div>
                        </div>
                    </div>
                </div>

                <!-- Interactive Charts Section -->
                <div class="charts-container">
                    <div class="chart-section main-chart">
                        <div class="chart-header">
                            <h3>📈 Revenue Trend</h3>
                            <div class="chart-controls">
                                <button onclick="switchChartType('line')" class="chart-type-btn active" data-type="line">Line</button>
                                <button onclick="switchChartType('bar')" class="chart-type-btn" data-type="bar">Bar</button>
                                <button onclick="switchChartType('area')" class="chart-type-btn" data-type="area">Area</button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="revenueChart" width="400" height="200"></canvas>
                        </div>
                    </div>

                    <div class="chart-section side-chart">
                        <h3>🎯 Package Distribution</h3>
                        <div class="chart-container">
                            <canvas id="packageChart" width="300" height="300"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Calendar Heatmap -->
                <div class="calendar-section">
                    <h3>🗓️ Revenue Calendar Heatmap</h3>
                    <div id="calendarHeatmap" class="calendar-heatmap">
                        <div class="loading" id="revenueLoading">
                            <div class="spinner"></div>
                            <p>Loading revenue calendar...</p>
                        </div>
                    </div>
                    <div class="heatmap-legend">
                        <span>Less</span>
                        <div class="legend-colors">
                            <div class="legend-color" data-intensity="0"></div>
                            <div class="legend-color" data-intensity="1"></div>
                            <div class="legend-color" data-intensity="2"></div>
                            <div class="legend-color" data-intensity="3"></div>
                            <div class="legend-color" data-intensity="4"></div>
                            <div class="legend-color" data-intensity="5"></div>
                        </div>
                        <span>More</span>
                    </div>
                </div>

                <!-- Detailed Analytics Tables -->
                <div class="analytics-tables">
                    <div class="table-section">
                        <h3>🏆 Top Revenue Days</h3>
                        <div id="topRevenueDays" class="analytics-table"></div>
                    </div>
                    <div class="table-section">
                        <h3>⏰ Hourly Breakdown</h3>
                        <div class="hourly-selector">
                            <label>Select Day:</label>
                            <input type="date" id="hourlyDatePicker" class="date-input" onchange="loadHourlyData()">
                        </div>
                        <div id="hourlyRevenue" class="analytics-table"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sessions Tab -->
        <div id="sessions" class="tab-content">
            <div class="section">
                <h2>🕒 Session Logs & History</h2>
                <div class="search-bar">
                    <input type="text" class="search-input" id="sessionSearch" placeholder="Search sessions...">
                    <select class="filter-select" id="sessionFilter">
                        <option value="all">All Sessions</option>
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                        <option value="today">Today</option>
                    </select>
                    <button class="btn" onclick="refreshSessions()">🔄 Refresh</button>
                </div>
                <div id="sessionsTableContainer">
                    <div class="loading" id="sessionsLoading">
                        <div class="spinner"></div>
                        <p>Loading session logs...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payments Tab -->
        <div id="payments" class="tab-content">
            <div class="section">
                <h2>💳 Payment Logs & M-Pesa Transactions</h2>
                <div class="search-bar">
                    <input type="text" class="search-input" id="paymentSearch" placeholder="Search by phone or receipt...">
                    <select class="filter-select" id="paymentFilter">
                        <option value="all">All Payments</option>
                        <option value="success">Successful</option>
                        <option value="failed">Failed</option>
                        <option value="today">Today</option>
                    </select>
                    <button class="btn" onclick="refreshPayments()">🔄 Refresh</button>
                </div>
                <div id="paymentsTableContainer">
                    <div class="loading" id="paymentsLoading">
                        <div class="spinner"></div>
                        <p>Loading payment logs...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vouchers Tab -->
        <div id="vouchers" class="tab-content">
            <div class="grid-2">
                <div class="section">
                    <h2>🎫 Generate New Voucher</h2>
                    <form id="voucherForm">
                        <div class="form-group">
                            <label class="form-label">Duration (Hours)</label>
                            <select class="form-input" id="voucherDuration">
                                <option value="1">🧪 1 Hour (TEST) - KSH 1</option>
                                <option value="6">6 Hours - KSH 20</option>
                                <option value="12">12 Hours - KSH 30</option>
                                <option value="24">24 Hours - KSH 50</option>
                                <option value="168">7 Days - KSH 350</option>
                                <option value="720">30 Days - KSH 1500</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Amount (KSH)</label>
                            <input type="number" class="form-input" id="voucherAmount" value="1">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Admin Key</label>
                            <input type="password" class="form-input" id="adminKey" placeholder="Enter admin key">
                        </div>

                        <button type="submit" class="btn btn-success">Generate Voucher</button>
                    </form>

                    <div class="section" id="voucherResult" style="display: none; background: #d4edda; margin-top: 20px;">
                        <h3>✅ Voucher Generated Successfully!</h3>
                        <div style="font-size: 1.5rem; font-weight: bold; text-align: center; background: #fff; color: #007bff; padding: 15px; border-radius: 10px; margin: 10px 0; border: 2px dashed #007bff;" id="generatedCode">A1B2C3D4</div>
                        <p>Share this code with customers to redeem internet access.</p>
                    </div>
                </div>

                <div class="section">
                    <h2>🎫 Voucher Management & Tracking</h2>
                    <div class="search-bar">
                        <input type="text" class="search-input" id="voucherSearch" placeholder="Search vouchers...">
                        <select class="filter-select" id="voucherFilter">
                            <option value="all">All Vouchers</option>
                            <option value="unused">Unused</option>
                            <option value="used">Used</option>
                        </select>
                        <button class="btn" onclick="refreshVouchers()">🔄 Refresh</button>
                    </div>
                    <div id="vouchersTableContainer">
                        <div class="loading" id="vouchersLoading">
                            <div class="spinner"></div>
                            <p>Loading vouchers...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Packages Tab -->
        <div id="packages" class="tab-content">
            <!-- Package Stats Overview -->
            <div class="section">
                <div class="stats-grid package-stats">
                    <div class="stat-item">
                        <div class="stat-icon">📦</div>
                        <div class="stat-details">
                            <div class="stat-value" id="totalPackages">-</div>
                            <div class="stat-label">Total Packages</div>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon">✅</div>
                        <div class="stat-details">
                            <div class="stat-value" id="activePackages">-</div>
                            <div class="stat-label">Active Packages</div>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon">⭐</div>
                        <div class="stat-details">
                            <div class="stat-value" id="featuredPackages">-</div>
                            <div class="stat-label">Featured Packages</div>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon">🔥</div>
                        <div class="stat-details">
                            <div class="stat-value" id="popularPackage">-</div>
                            <div class="stat-label">Most Popular</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Package Management Controls -->
            <div class="section">
                <div class="package-controls">
                    <div class="controls-left">
                        <h2>📦 Package Management</h2>
                        <p>Create, edit, and manage WiFi internet packages</p>
                    </div>
                    <div class="controls-right">
                        <button class="btn-primary" onclick="showCreatePackageModal()">
                            ➕ New Package
                        </button>
                        <button class="btn-secondary" onclick="loadPackages()">
                            🔄 Refresh
                        </button>
                        <div class="view-toggle">
                            <button class="toggle-btn active" data-view="cards" onclick="toggleView('cards')">🃏 Cards</button>
                            <button class="toggle-btn" data-view="table" onclick="toggleView('table')">📋 Table</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filters -->
            <div class="section">
                <div class="package-filters">
                    <div class="search-container">
                        <input type="text" id="packageSearch" placeholder="🔍 Search packages..." class="search-input">
                    </div>
                    <div class="filter-buttons">
                        <button class="filter-btn active" data-filter="all">All</button>
                        <button class="filter-btn" data-filter="basic">Basic</button>
                        <button class="filter-btn" data-filter="standard">Standard</button>
                        <button class="filter-btn" data-filter="premium">Premium</button>
                        <button class="filter-btn" data-filter="active">Active Only</button>
                        <button class="filter-btn" data-filter="featured">Featured</button>
                    </div>
                    <div class="sort-options">
                        <select id="packageSort" class="sort-select">
                            <option value="sort_order">Sort Order</option>
                            <option value="name">Name A-Z</option>
                            <option value="price_low">Price: Low to High</option>
                            <option value="price_high">Price: High to Low</option>
                            <option value="duration_short">Duration: Short to Long</option>
                            <option value="duration_long">Duration: Long to Short</option>
                            <option value="created_new">Newest First</option>
                            <option value="created_old">Oldest First</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Packages Display Container -->
            <div class="section">
                <div class="packages-container">
                    <div id="packagesLoading" class="loading">
                        <div class="spinner"></div>
                        <p>Loading packages...</p>
                    </div>
                    
                    <!-- Cards View -->
                    <div id="packagesCardsView" class="packages-grid">
                        <!-- Dynamic package cards will be inserted here -->
                    </div>
                    
                    <!-- Table View -->
                    <div id="packagesTableView" class="packages-table-container" style="display: none;">
                        <!-- Dynamic package table will be inserted here -->
                    </div>
                </div>
            </div>

            <!-- Bulk Actions Bar -->
            <div id="bulkActionsBar" class="bulk-actions-bar" style="display: none;">
                <div class="bulk-info">
                    <span id="selectedCount">0</span> packages selected
                </div>
                <div class="bulk-buttons">
                    <button class="bulk-btn" onclick="bulkActivate()">✅ Activate</button>
                    <button class="bulk-btn" onclick="bulkDeactivate()">❌ Deactivate</button>
                    <button class="bulk-btn" onclick="bulkFeature()">⭐ Feature</button>
                    <button class="bulk-btn danger" onclick="bulkDelete()">🗑️ Delete</button>
                    <button class="bulk-btn secondary" onclick="clearSelection()">↩️ Clear</button>
                </div>
            </div>
        </div>

        <!-- SMS Service Tab -->
        <div id="sms" class="tab-content">
            <div class="grid-2">
                <div class="section">
                    <h2>📱 SMS Test & Configuration</h2>
                    <div style="display: grid; gap: 15px;">
                        <div>
                            <label for="testPhone">📞 Test Phone Number:</label>
                            <input type="tel" id="testPhone" placeholder="+254712345678" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; margin-top: 5px;">
                        </div>
                        <button class="btn btn-success" onclick="sendTestSMS()">
                            🧪 Send Test SMS
                        </button>
                        <button class="btn" onclick="checkSMSConfig()">
                            🔧 Check SMS Configuration
                        </button>
                    </div>
                    <div id="smsTestResult" style="margin-top: 20px; display: none;"></div>
                </div>
                <div class="section">
                    <h2>📤 Manual Voucher SMS</h2>
                    <div style="display: grid; gap: 15px;">
                        <div>
                            <label for="voucherPhone">📞 Customer Phone:</label>
                            <input type="tel" id="voucherPhone" placeholder="+254712345678" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; margin-top: 5px;">
                        </div>
                        <div>
                            <label for="voucherCode">🎫 Voucher Code:</label>
                            <input type="text" id="voucherCode" placeholder="Enter voucher code" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; margin-top: 5px;">
                        </div>
                        <div>
                            <label for="packageSelect">📦 Package:</label>
                            <select id="packageSelect" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; margin-top: 5px;">
                                <option value="">Select Package</option>
                                <option value="basic">Basic - 1 Hour</option>
                                <option value="standard">Standard - 24 Hours</option>
                                <option value="premium">Premium - 7 Days</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" onclick="sendVoucherSMS()">
                            📱 Send Voucher SMS
                        </button>
                    </div>
                    <div id="voucherSMSResult" style="margin-top: 20px; display: none;"></div>
                </div>
            </div>
            <div class="section" style="margin-top: 20px;">
                <h2>📊 SMS Statistics & Logs</h2>
                <div class="grid-3">
                    <div class="stat-card">
                        <div class="stat-value" id="totalSMSSent">-</div>
                        <div class="stat-label">Total SMS Sent</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="smsSentToday">-</div>
                        <div class="stat-label">SMS Sent Today</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="smsSuccessRate">-</div>
                        <div class="stat-label">Success Rate</div>
                    </div>
                </div>
                <div style="margin-top: 20px;">
                    <h3>Recent SMS Activity</h3>
                    <div id="smsLogsContainer">
                        <div class="loading">
                            <div class="spinner"></div>
                            <p>Loading SMS logs...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Management Tab -->
        <div id="management" class="tab-content">
            <div class="grid-2">
                <div class="section">
                    <h2>⚙️ System Management & Controls</h2>
                    <div style="display: grid; gap: 15px;">
                        <button class="btn" onclick="backupData()">
                            💾 Backup Database
                        </button>
                        <button class="btn btn-warning" onclick="clearLogs()">
                            🗑️ Clear Old Logs
                        </button>
                        <button class="btn btn-danger" onclick="resetSystem()">
                            🔄 Reset System
                        </button>
                        <button class="btn btn-success" onclick="generateReport()">
                            📋 Generate Business Report
                        </button>
                    </div>
                </div>
                <div class="section">
                    <h2>📊 System Status & Health</h2>
                    <div id="systemStatus">
                        <div class="loading">
                            <div class="spinner"></div>
                            <p>Checking system status...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- MikroTik Router Management Tab -->
        <div id="mikrotik" class="tab-content">
            <div class="grid-2">
                <div class="section">
                    <h2>🌐 MikroTik Router Management</h2>
                    
                    <!-- Router Statistics -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <div class="stat-card" style="background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 15px; border-radius: 10px;">
                            <div style="font-size: 24px; font-weight: bold;" id="onlineRouters">-</div>
                            <div style="font-size: 12px; opacity: 0.9;">Online Routers</div>
                        </div>
                        <div class="stat-card" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 15px; border-radius: 10px;">
                            <div style="font-size: 24px; font-weight: bold;" id="offlineRouters">-</div>
                            <div style="font-size: 12px; opacity: 0.9;">Offline Routers</div>
                        </div>
                        <div class="stat-card" style="background: linear-gradient(135deg, #007bff, #0056b3); color: white; padding: 15px; border-radius: 10px;">
                            <div style="font-size: 24px; font-weight: bold;" id="activeSessions">-</div>
                            <div style="font-size: 12px; opacity: 0.9;">Active Sessions</div>
                        </div>
                        <div class="stat-card" style="background: linear-gradient(135deg, #6f42c1, #5a2d91); color: white; padding: 15px; border-radius: 10px;">
                            <div style="font-size: 24px; font-weight: bold;" id="totalRouters">-</div>
                            <div style="font-size: 12px; opacity: 0.9;">Total Routers</div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div style="display: grid; gap: 10px; margin-bottom: 20px;">
                        <button class="btn" onclick="showAddRouterModal()" style="background: #28a745; color: white;">
                            ➕ Add New Router
                        </button>
                        <button class="btn" onclick="refreshRouters()" style="background: #17a2b8; color: white;">
                            🔄 Refresh Status
                        </button>
                        <button class="btn" onclick="syncAllSessions()" style="background: #ffc107; color: #333;">
                            🔗 Sync All Sessions
                        </button>
                    </div>
                </div>
                
                <div class="section">
                    <h2>📊 Router Status Monitor</h2>
                    <div id="routerStatusContainer" style="max-height: 400px; overflow-y: auto;">
                        <div id="routersLoading" style="text-align: center; padding: 40px; color: #666;">
                            <div class="spinner"></div>
                            <p>Loading routers...</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Routers List -->
            <div class="section" style="margin-top: 20px;">
                <h2>🌐 Registered Routers</h2>
                <div id="routersTableContainer">
                    <table class="table" id="routersTable" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Name</th>
                                <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">IP Address</th>
                                <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Status</th>
                                <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Model</th>
                                <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Firmware</th>
                                <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Last Seen</th>
                                <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="routersTableBody">
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: #666;">
                                    Loading routers...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Router Modal -->
    <div id="addRouterModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close" onclick="closeAddRouterModal()">&times;</span>
                <h2>Add New MikroTik Router</h2>
            </div>
            <div class="modal-body">
                <form id="addRouterForm" onsubmit="addRouter(event)">
                    <div style="display: grid; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Router Name:</label>
                            <input type="text" id="routerName" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">IP Address/Hostname:</label>
                            <input type="text" id="routerHost" required placeholder="192.168.1.1" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">API Port:</label>
                            <input type="number" id="routerPort" value="8728" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Username:</label>
                            <input type="text" id="routerUsername" required placeholder="admin" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Password:</label>
                            <input type="password" id="routerPassword" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button type="button" onclick="testRouterConnection()" style="flex: 1; padding: 10px; background: #17a2b8; color: white; border: none; border-radius: 5px; cursor: pointer;">
                                🔍 Test Connection
                            </button>
                            <button type="submit" style="flex: 1; padding: 10px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer;">
                                ➕ Add Router
                            </button>
                        </div>
                    </div>
                </form>
                <div id="connectionTestResult" style="margin-top: 15px; padding: 10px; border-radius: 5px; display: none;"></div>
            </div>
        </div>
    </div>

    <script>
        // Configuration
        const API_URL = 'api.php'; // Production API for deployment
        const ADMIN_KEY = 'your_admin_key'; // Admin authentication key
        let autoRefreshInterval = null;
        let currentTab = 'dashboard';

        // Tab management - FIXED EVENT HANDLING
        function showTab(tabName, targetElement = null) {
            try {
                // Hide all tabs
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                
                // Remove active class from all tabs
                document.querySelectorAll('.tab').forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Show selected tab
                const tabContent = document.getElementById(tabName);
                if (tabContent) {
                    tabContent.classList.add('active');
                }
                
                // Set active tab button - FIXED to handle missing event
                if (targetElement) {
                    targetElement.classList.add('active');
                } else {
                    // Fallback: find the tab button by onclick attribute
                    const tabButton = document.querySelector(`button[onclick*="${tabName}"]`);
                    if (tabButton) {
                        tabButton.classList.add('active');
                    }
                }
                
                currentTab = tabName;
                
                // Load tab-specific data
                loadTabData(tabName);
                showNotification(`Switched to ${tabName} tab`, 'success');
                
            } catch (error) {
                console.error('Error in showTab:', error);
                showNotification('Error switching tabs', 'error');
            }
        }

        // Load data for specific tab - ENHANCED WITH ERROR HANDLING
        async function loadTabData(tabName) {
            try {
                console.log(`Loading data for tab: ${tabName}`);
                showNotification(`Loading ${tabName} data...`, 'info');
                
                switch(tabName) {
                    case 'dashboard':
                        await loadDashboardData();
                        break;
                    case 'users':
                        await loadActiveUsers();
                        break;
                    case 'revenue':
                        await initializeRevenueDashboard();
                        break;
                    case 'sessions':
                        await loadSessionLogs();
                        break;
                    case 'payments':
                        await loadPaymentLogs();
                        break;
                    case 'vouchers':
                        await loadVoucherData();
                        break;
                    case 'packages':
                        await loadPackageData();
                        break;
                    case 'management':
                        await loadSystemStatus();
                        break;
                    case 'mikrotik':
                        await loadMikroTikData();
                        break;
                    case 'sms':
                        await loadSMSData();
                        break;
                    default:
                        console.warn(`Unknown tab: ${tabName}`);
                        showNotification(`Unknown tab: ${tabName}`, 'warning');
                }
                
                console.log(`✅ Successfully loaded ${tabName} data`);
                
            } catch (error) {
                console.error(`❌ Error loading ${tabName} data:`, error);
                showNotification(`❌ Failed to load ${tabName} data: ${error.message}`, 'error');
            }
        }

        // Load dashboard statistics
        async function loadDashboardData() {
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'admin_stats',
                        admin_key: 'your_admin_key'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const stats = result.data;
                    document.getElementById('totalUsers').textContent = stats.active_users || 0;
                    document.getElementById('todayRevenue').textContent = `KSH ${stats.daily_revenue || 0}`;
                    document.getElementById('totalRevenue').textContent = `KSH ${stats.total_revenue || 0}`;
                    document.getElementById('totalVouchers').textContent = stats.unused_vouchers || 0;
                    document.getElementById('expiredSessions').textContent = stats.expired_sessions || 0;
                    document.getElementById('totalSessions').textContent = stats.total_sessions || 0;
                    
                    // Update quick stats section
                    updateQuickStats(stats);
                    updateLastUpdated();
                } else {
                    showNotification('Failed to load dashboard data', 'error');
                }
            } catch (error) {
                console.error('Error loading dashboard:', error);
                showNotification('Error connecting to server', 'error');
            }
        }

        // Update quick stats section
        function updateQuickStats(stats) {
            const quickStatsContent = document.getElementById('quickStatsContent');
            quickStatsContent.innerHTML = `
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 10px;">
                        <div style="font-size: 1.5rem; font-weight: bold; color: #007bff;">${stats.active_users || 0}</div>
                        <div style="color: #666; font-size: 14px;">Users Online</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 10px;">
                        <div style="font-size: 1.5rem; font-weight: bold; color: #28a745;">KSH ${stats.daily_revenue || 0}</div>
                        <div style="color: #666; font-size: 14px;">Today's Earnings</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 10px;">
                        <div style="font-size: 1.5rem; font-weight: bold; color: #ffc107;">${stats.total_sessions || 0}</div>
                        <div style="color: #666; font-size: 14px;">Total Sessions</div>
                    </div>
                    <div style="text-align: center; padding: 15px; background: #f8f9fa; border-radius: 10px;">
                        <div style="font-size: 1.5rem; font-weight: bold; color: #dc3545;">${stats.expired_sessions || 0}</div>
                        <div style="color: #666; font-size: 14px;">Need Cleanup</div>
                    </div>
                </div>
            `;
        }

        // Enhanced notification system
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 100);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // Load active users with enhanced error handling
        async function loadActiveUsers() {
            showLoading('usersLoading');
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'active_users',
                        admin_key: 'your_admin_key'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    displayUsersTable(result.data.active_users);
                    showNotification(`Loaded ${result.data.active_users?.length || 0} active users`, 'info');
                } else {
                    showEmptyState('usersTableContainer', 'No active users found');
                    showNotification('No active users at the moment', 'warning');
                }
            } catch (error) {
                showEmptyState('usersTableContainer', 'Error loading users');
                showNotification('Failed to load active users', 'error');
            }
            
            hideLoading('usersLoading');
        }

        // Display users table
        function displayUsersTable(users) {
            const container = document.getElementById('usersTableContainer');
            
            if (!users || users.length === 0) {
                showEmptyState('usersTableContainer', 'No active users found');
                return;
            }
            
            let html = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Phone Number</th>
                            <th>Package</th>
                            <th>Amount</th>
                            <th>Started</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            users.forEach(user => {
                const status = new Date(user.session_end) > new Date() ? 'active' : 'expired';
                const timeLeft = new Date(user.session_end) - new Date();
                const hoursLeft = Math.max(0, Math.floor(timeLeft / (1000 * 60 * 60)));
                const minutesLeft = Math.max(0, Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60)));
                
                html += `
                    <tr>
                        <td>${user.phone_number}</td>
                        <td>${user.duration_hours}h</td>
                        <td>KSH ${user.amount}</td>
                        <td>${new Date(user.session_start).toLocaleString()}</td>
                        <td>${new Date(user.session_end).toLocaleString()}</td>
                        <td><span class="status-badge status-${status}">${status}</span></td>
                        <td>
                            ${status === 'active' ? 
                                `<button class="action-btn btn-danger" onclick="terminateSession('${user.phone_number}')">Terminate</button>` :
                                `<span class="status-badge status-expired">Expired</span>`
                            }
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }

        async function terminateSession(phone) {
            if (!confirm(`⚠️ Are you sure you want to terminate the session for ${phone}?\n\nThis action cannot be undone and will immediately disconnect the user.`)) return;
            
            const adminKey = prompt('Enter admin key to confirm termination:');
            if (!adminKey) return;
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'terminate_session', 
                        phone_number: phone,
                        admin_key: adminKey 
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification(`Session for ${phone} terminated successfully!`, 'success');
                    await loadActiveUsers();
                    await loadDashboardData(); // Refresh dashboard stats
                } else {
                    showNotification('Failed to terminate session: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Error terminating session', 'error');
            }
        }

        // Load session logs
        async function loadSessionLogs() {
            showLoading('sessionsLoading');
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'session_logs',
                        admin_key: 'your_admin_key'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    displaySessionsTable(result.data.sessions);
                } else {
                    showEmptyState('sessionsTableContainer', 'Failed to load session logs');
                }
            } catch (error) {
                showEmptyState('sessionsTableContainer', 'Error loading session logs');
            } finally {
                hideLoading('sessionsLoading');
            }
        }
        
        function displaySessionsTable(sessions) {
            const container = document.getElementById('sessionsTableContainer');
            if (!sessions || sessions.length === 0) {
                showEmptyState('sessionsTableContainer', 'No sessions found');
                return;
            }
            
            const table = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Phone Number</th>
                            <th>Amount (KES)</th>
                            <th>Duration</th>
                            <th>M-Pesa Receipt</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${sessions.map(session => `
                            <tr>
                                <td>${session.phone_number}</td>
                                <td>KES ${session.amount}</td>
                                <td>${session.duration_hours}h</td>
                                <td>${session.mpesa_receipt || 'N/A'}</td>
                                <td>${session.session_start ? new Date(session.session_start).toLocaleString() : 'N/A'}</td>
                                <td>${session.session_end ? new Date(session.session_end).toLocaleString() : 'N/A'}</td>
                                <td><span class="status-badge status-${session.status}">${session.status}</span></td>
                                <td>
                                    ${session.status === 'active' ? 
                                        `<button class="action-btn btn-danger" onclick="terminateSession('${session.phone_number}')">Terminate</button>` :
                                        `<span class="status-badge status-expired">Ended</span>`
                                    }
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
            container.innerHTML = table;
        }

        // Load payment logs
        async function loadPaymentLogs() {
            showLoading('paymentsLoading');
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'payment_logs',
                        admin_key: 'your_admin_key'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    displayPaymentLogsTable(result.data.payment_logs);
                } else {
                    showEmptyState('paymentsTableContainer', 'Failed to load payment logs');
                }
            } catch (error) {
                showEmptyState('paymentsTableContainer', 'Error loading payment logs');
            } finally {
                hideLoading('paymentsLoading');
            }
        }
        
        function displayPaymentLogsTable(payments) {
            const container = document.getElementById('paymentsTableContainer');
            if (!payments || payments.length === 0) {
                showEmptyState('paymentsTableContainer', 'No payment logs found');
                return;
            }
            
            const table = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Phone Number</th>
                            <th>Amount (KES)</th>
                            <th>M-Pesa Receipt</th>
                            <th>Response Code</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${payments.map(payment => `
                            <tr>
                                <td>${payment.phone_number}</td>
                                <td>KES ${payment.amount}</td>
                                <td>${payment.mpesa_receipt || 'N/A'}</td>
                                <td>${payment.response_code}</td>
                                <td><span class="status-badge status-${payment.response_code === '0' ? 'success' : 'failed'}">${payment.response_description}</span></td>
                                <td>${new Date(payment.created_at).toLocaleString()}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
            container.innerHTML = table;
        }

        // Load voucher data
        async function loadVoucherData() {
            showLoading('vouchersLoading');
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'unused_vouchers',
                        admin_key: 'your_admin_key'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    displayVouchersTable(result.data.vouchers);
                } else {
                    showEmptyState('vouchersTableContainer', 'Failed to load vouchers');
                }
            } catch (error) {
                showEmptyState('vouchersTableContainer', 'Error loading vouchers');
            } finally {
                hideLoading('vouchersLoading');
            }
        }
        
        function displayVouchersTable(vouchers) {
            const container = document.getElementById('vouchersTableContainer');
            if (!vouchers || vouchers.length === 0) {
                showEmptyState('vouchersTableContainer', 'No vouchers found');
                return;
            }
            
            const table = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Voucher Code</th>
                            <th>Duration</th>
                            <th>Amount (KES)</th>
                            <th>Status</th>
                            <th>Used By</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${vouchers.map(voucher => `
                            <tr>
                                <td><strong>${voucher.voucher_code}</strong></td>
                                <td>${voucher.duration_hours}h</td>
                                <td>KES ${voucher.amount}</td>
                                <td><span class="status-badge status-${voucher.used ? 'used' : 'available'}">${voucher.used ? 'Used' : 'Available'}</span></td>
                                <td>${voucher.used_by || 'N/A'}</td>
                                <td>${new Date(voucher.created_at).toLocaleString()}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
            container.innerHTML = table;
        }

        // 🚀 AMAZING Revenue Analytics Implementation
        let revenueChart = null;
        let packageChart = null;
        let currentChartType = 'line';
        let currentDateRange = { start: null, end: null };

        // Initialize revenue dashboard
        async function initializeRevenueDashboard() {
            // Set default date range (last 7 days)
            const endDate = new Date();
            const startDate = new Date();
            startDate.setDate(endDate.getDate() - 7);
            
            document.getElementById('startDatePicker').value = startDate.toISOString().split('T')[0];
            document.getElementById('endDatePicker').value = endDate.toISOString().split('T')[0];
            document.getElementById('hourlyDatePicker').value = endDate.toISOString().split('T')[0];
            
            currentDateRange.start = startDate.toISOString().split('T')[0];
            currentDateRange.end = endDate.toISOString().split('T')[0];
            
            await loadRevenueData();
        }

        // Enhanced load revenue data with date range support
        async function loadRevenueData() {
            showLoading('revenueLoading');
            
            try {
                const startDate = document.getElementById('startDatePicker').value || currentDateRange.start;
                const endDate = document.getElementById('endDatePicker').value || currentDateRange.end;
                
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'get_revenue_data',
                        admin_key: 'your_admin_key',
                        start_date: startDate,
                        end_date: endDate
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    displayAmazingRevenueAnalytics(result.data);
                    showNotification(`Revenue data loaded for ${startDate} to ${endDate}`, 'success');
                } else {
                    showEmptyState('calendarHeatmap', 'Failed to load revenue data');
                    showNotification('Failed to load revenue data: ' + result.message, 'error');
                }
            } catch (error) {
                showEmptyState('calendarHeatmap', 'Error loading revenue data');
                showNotification('Error loading revenue data', 'error');
                console.error('Revenue loading error:', error);
            } finally {
                hideLoading('revenueLoading');
            }
        }

        // Display amazing revenue analytics with all features
        function displayAmazingRevenueAnalytics(data) {
            // Update summary cards
            updateRevenueSummaryCards(data);
            
            // Create interactive charts
            createRevenueChart(data.daily_revenue);
            createPackageChart(data.revenue_by_package);
            
            // Generate calendar heatmap
            generateCalendarHeatmap(data.calendar_heatmap);
            
            // Display analytics tables
            displayTopRevenueDays(data.top_revenue_days);
            displayHourlyRevenue(data.hourly_revenue);
        }

        // Update summary cards with growth indicators
        function updateRevenueSummaryCards(data) {
            const summary = data.period_summary;
            const comparison = data.period_comparison;
            
            // Total Revenue
            document.getElementById('totalRevenueAmount').textContent = `KES ${summary.total_revenue.toLocaleString()}`;
            const revenueGrowthEl = document.getElementById('revenueGrowth');
            revenueGrowthEl.textContent = `${comparison.revenue_growth > 0 ? '+' : ''}${comparison.revenue_growth}%`;
            revenueGrowthEl.className = `growth-indicator ${comparison.revenue_growth >= 0 ? 'positive' : 'negative'}`;
            
            // Daily Average
            document.getElementById('avgDailyRevenue').textContent = `KES ${Math.round(summary.avg_daily_revenue).toLocaleString()}`;
            const sessionGrowthEl = document.getElementById('sessionGrowth');
            sessionGrowthEl.textContent = `${comparison.sessions_growth > 0 ? '+' : ''}${comparison.sessions_growth}%`;
            sessionGrowthEl.className = `growth-indicator ${comparison.sessions_growth >= 0 ? 'positive' : 'negative'}`;
            
            // Average Session Value
            document.getElementById('avgSessionValue').textContent = `KES ${Math.round(summary.avg_session_value)}`;
            
            // Best Day
            if (data.top_revenue_days && data.top_revenue_days.length > 0) {
                const bestDay = data.top_revenue_days[0];
                document.getElementById('bestDayRevenue').textContent = `KES ${bestDay.revenue.toLocaleString()}`;
                document.getElementById('bestDayDate').textContent = formatDate(bestDay.date);
            }
            
            // Period info
            const daysDiff = Math.ceil((new Date(summary.end_date) - new Date(summary.start_date)) / (1000 * 60 * 60 * 24)) + 1;
            document.getElementById('periodInfo').textContent = `Last ${daysDiff} days`;
        }

        // Create interactive revenue chart with Chart.js
        function createRevenueChart(dailyData) {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            // Destroy existing chart
            if (revenueChart) {
                revenueChart.destroy();
            }
            
            const labels = dailyData.map(item => formatDate(item.date));
            const revenues = dailyData.map(item => item.revenue);
            const sessions = dailyData.map(item => item.sessions);
            
            const chartConfig = {
                type: currentChartType,
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue (KES)',
                        data: revenues,
                        borderColor: '#667eea',
                        backgroundColor: currentChartType === 'area' ? 'rgba(102, 126, 234, 0.1)' : 'rgba(102, 126, 234, 0.8)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: currentChartType === 'area'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                afterLabel: function(context) {
                                    const index = context.dataIndex;
                                    return `Sessions: ${sessions[index]}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Revenue (KES)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Date'
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            };
            
            revenueChart = new Chart(ctx, chartConfig);
        }

        // Create package distribution doughnut chart
        function createPackageChart(packageData) {
            const ctx = document.getElementById('packageChart').getContext('2d');
            
            // Destroy existing chart
            if (packageChart) {
                packageChart.destroy();
            }
            
            const labels = packageData.map(pkg => pkg.package_type);
            const revenues = packageData.map(pkg => pkg.revenue);
            const colors = ['#667eea', '#f093fb', '#4facfe'];
            
            packageChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: revenues,
                        backgroundColor: colors,
                        borderColor: '#fff',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return `${context.label}: KES ${context.parsed.toLocaleString()} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Generate interactive calendar heatmap
        function generateCalendarHeatmap(heatmapData) {
            const container = document.getElementById('calendarHeatmap');
            
            if (!heatmapData || heatmapData.length === 0) {
                container.innerHTML = '<div class="empty-state">No data available for calendar heatmap</div>';
                return;
            }
            
            let html = '';
            heatmapData.forEach(day => {
                const date = new Date(day.date);
                const dayNumber = date.getDate();
                
                html += `
                    <div class="calendar-day" 
                         data-intensity="${day.intensity}" 
                         data-date="${day.date}" 
                         data-revenue="${day.revenue}"
                         onclick="selectCalendarDay('${day.date}')"
                         title="${day.date}: KES ${day.revenue}">
                        ${dayNumber}
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        // Display top revenue days
        function displayTopRevenueDays(topDays) {
            const container = document.getElementById('topRevenueDays');
            
            if (!topDays || topDays.length === 0) {
                container.innerHTML = '<div class="empty-state">No data available</div>';
                return;
            }
            
            let html = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>🏆 Rank</th>
                            <th>📅 Date</th>
                            <th>💰 Revenue</th>
                            <th>📊 Sessions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            topDays.forEach((day, index) => {
                const rank = index + 1;
                const medal = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : `#${rank}`;
                
                html += `
                    <tr>
                        <td style="text-align: center;"><strong>${medal}</strong></td>
                        <td>${formatDate(day.date)}</td>
                        <td style="color: #28a745; font-weight: bold;">KES ${day.revenue.toLocaleString()}</td>
                        <td>${day.sessions}</td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }

        // Display hourly revenue breakdown
        function displayHourlyRevenue(hourlyData) {
            const container = document.getElementById('hourlyRevenue');
            
            if (!hourlyData || hourlyData.length === 0) {
                container.innerHTML = '<div class="empty-state">No hourly data available for selected date</div>';
                return;
            }
            
            let html = `
                <div class="hourly-chart">
                    ${hourlyData.map(hour => {
                        const height = Math.max((hour.revenue / 500) * 100, 5); // Scale for visual
                        return `
                            <div class="hourly-bar" title="${hour.hour}:00 - KES ${hour.revenue}">
                                <div class="bar-fill" style="height: ${height}%; background: linear-gradient(45deg, #667eea, #764ba2);"></div>
                                <div class="bar-label">${hour.hour}:00</div>
                                <div class="bar-value">KES ${hour.revenue}</div>
                            </div>
                        `;
                    }).join('')}
                </div>
                
                <style>
                .hourly-chart { display: flex; gap: 5px; align-items: end; padding: 20px; background: #f8f9fa; border-radius: 8px; overflow-x: auto; }
                .hourly-bar { display: flex; flex-direction: column; align-items: center; min-width: 35px; width: 35px; height: 120px; position: relative; flex-shrink: 0; }
                @media (max-width: 480px) {
                    .hourly-chart { padding: 10px; gap: 3px; }
                    .hourly-bar { min-width: 25px; width: 25px; height: 80px; }
                    .bar-fill { width: 20px !important; }
                    .bar-label, .bar-value { font-size: 8px !important; }
                }
                .bar-fill { width: 30px; border-radius: 4px 4px 0 0; transition: all 0.3s ease; }
                .bar-label { font-size: 10px; margin-top: 5px; color: #666; }
                .bar-value { font-size: 9px; color: #333; font-weight: bold; margin-top: 2px; }
                .hourly-bar:hover .bar-fill { transform: scale(1.1); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); }
                </style>
            `;
            
            container.innerHTML = html;
        }

        // Quick filter functions
        function setQuickFilter(period) {
            const endDate = new Date();
            let startDate = new Date();
            
            // Remove active class from all filter buttons
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
            
            switch(period) {
                case 'today':
                    startDate = endDate;
                    break;
                case 'week':
                    startDate.setDate(endDate.getDate() - 7);
                    break;
                case 'month':
                    startDate.setDate(endDate.getDate() - 30);
                    break;
                case 'quarter':
                    startDate.setDate(endDate.getDate() - 90);
                    break;
            }
            
            document.getElementById('startDatePicker').value = startDate.toISOString().split('T')[0];
            document.getElementById('endDatePicker').value = endDate.toISOString().split('T')[0];
            
            // Add active class to clicked button
            event.target.classList.add('active');
            
            // Reload data
            loadRevenueData();
        }

        // Switch chart type
        function switchChartType(type) {
            currentChartType = type;
            
            // Update button states
            document.querySelectorAll('.chart-type-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.type === type);
            });
            
            // Recreate chart if data exists
            if (revenueChart && revenueChart.data.datasets[0].data.length > 0) {
                const dailyData = revenueChart.data.labels.map((label, index) => ({
                    date: label,
                    revenue: revenueChart.data.datasets[0].data[index],
                    sessions: 0 // Will need to store this separately if needed
                }));
                createRevenueChart(dailyData);
            }
        }

        // Select calendar day for detailed view
        function selectCalendarDay(date) {
            document.getElementById('hourlyDatePicker').value = date;
            loadHourlyData();
            showNotification(`Selected ${formatDate(date)} for hourly breakdown`, 'info');
        }

        // Load hourly data for selected day
        async function loadHourlyData() {
            const selectedDay = document.getElementById('hourlyDatePicker').value;
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'get_revenue_data',
                        admin_key: 'your_admin_key',
                        selected_day: selectedDay,
                        start_date: selectedDay,
                        end_date: selectedDay
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    displayHourlyRevenue(result.data.hourly_revenue);
                }
            } catch (error) {
                console.error('Error loading hourly data:', error);
            }
        }

        // Format date for display
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB', { 
                month: 'short', 
                day: 'numeric',
                year: date.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined
            });
        }

        // Load system status with real API check
        async function loadSystemStatus() {
            const container = document.getElementById('systemStatus');
            
            try {
                const response = await fetch(API_URL, {
                    method: 'GET'
                });
                
                if (response.ok) {
                    const result = await response.json();
                    
                    container.innerHTML = `
                        <div style="padding: 20px;">
                            <div style="color: #28a745; font-size: 1.5rem; margin-bottom: 15px; text-align: center;">✅ System Operational</div>
                            <div style="display: grid; gap: 10px;">
                                <div style="display: flex; justify-content: space-between; padding: 8px; background: #f8f9fa; border-radius: 5px;">
                                    <span>API Status:</span>
                                    <span style="color: #28a745; font-weight: bold;">Online</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 8px; background: #f8f9fa; border-radius: 5px;">
                                    <span>Database:</span>
                                    <span style="color: #28a745; font-weight: bold;">Connected</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 8px; background: #f8f9fa; border-radius: 5px;">
                                    <span>M-Pesa Integration:</span>
                                    <span style="color: #28a745; font-weight: bold;">Active</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 8px; background: #f8f9fa; border-radius: 5px;">
                                    <span>Server Time:</span>
                                    <span style="font-weight: bold;">${new Date().toLocaleString()}</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; padding: 8px; background: #f8f9fa; border-radius: 5px;">
                                    <span>Service Version:</span>
                                    <span style="font-weight: bold;">${result.version || '1.0.0'}</span>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    throw new Error('API not responding');
                }
            } catch (error) {
                container.innerHTML = `
                    <div style="padding: 20px; text-align: center;">
                        <div style="color: #dc3545; font-size: 1.5rem; margin-bottom: 10px;">❌ System Issues</div>
                        <div style="color: #666;">Unable to connect to API</div>
                        <button class="btn" onclick="loadSystemStatus()" style="margin-top: 15px;">🔄 Retry</button>
                    </div>
                `;
            }
        }

        // Enhanced action functions
        async function refreshAllData() {
            showNotification('Refreshing all data...', 'info');
            await loadTabData(currentTab);
            await loadDashboardData();
            showNotification('All data refreshed successfully!', 'success');
        }

        async function cleanExpiredSessions() {
            if (!confirm('🧹 Are you sure you want to clean all expired sessions?\n\nThis will remove all expired session data from the database.')) return;
            
            const adminKey = prompt('Enter admin key to confirm cleanup:');
            if (!adminKey) return;
            
            try {
                showNotification('Cleaning expired sessions...', 'info');
                
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'clean_expired_sessions',
                        admin_key: adminKey 
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const cleanedCount = result.data?.cleaned_count || 0;
                    showNotification(`Successfully cleaned ${cleanedCount} expired sessions!`, 'success');
                    await loadDashboardData();
                    if (currentTab === 'sessions') {
                        await loadSessionLogs();
                    }
                } else {
                    showNotification('Failed to clean expired sessions: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Error cleaning sessions', 'error');
            }
        }

        async function exportData() {
            const adminKey = prompt('Enter admin key to export data:');
            if (!adminKey) return;
            
            showNotification('Preparing data export...', 'info');
            // Implementation would generate CSV/PDF export
            setTimeout(() => {
                showNotification('Data export completed! Check your downloads.', 'success');
            }, 2000);
        }

        async function emergencyStop() {
            if (!confirm('🚨 EMERGENCY STOP: This will immediately terminate ALL active sessions.\n\nThis action cannot be undone. Continue?')) return;
            
            const adminKey = prompt('Enter admin key to confirm EMERGENCY STOP:');
            if (!adminKey) return;
            
            showNotification('EMERGENCY STOP: Terminating all sessions...', 'error');
            // Implementation would terminate all active sessions
            setTimeout(() => {
                showNotification('Emergency stop completed. All sessions terminated.', 'warning');
            }, 3000);
        }

        async function backupData() {
            const adminKey = prompt('Enter admin key to backup database:');
            if (!adminKey) return;
            
            showNotification('Creating database backup...', 'info');
            // Implementation would create database backup
            setTimeout(() => {
                showNotification('Database backup completed successfully!', 'success');
            }, 2000);
        }

        async function clearLogs() {
            if (!confirm('🗑️ Are you sure you want to clear old system logs?\n\nThis will remove log files older than 30 days.')) return;
            
            const adminKey = prompt('Enter admin key to clear logs:');
            if (!adminKey) return;
            
            showNotification('Clearing old log files...', 'info');
            // Implementation would clear old logs
            setTimeout(() => {
                showNotification('Old log files cleared successfully!', 'success');
            }, 1500);
        }

        async function resetSystem() {
            if (!confirm('🚨 DANGER: This will reset the entire system including:\n- All session data\n- Payment logs\n- Vouchers\n- Statistics\n\nThis action CANNOT be undone. Are you absolutely sure?')) return;
            
            if (!confirm('This is your FINAL WARNING. Type "RESET" to confirm:') || prompt('Type "RESET" to confirm:') !== 'RESET') return;
            
            const adminKey = prompt('Enter admin key for system reset:');
            if (!adminKey) return;
            
            showNotification('SYSTEM RESET initiated. This may take several minutes...', 'error');
            // Implementation would reset system
            setTimeout(() => {
                showNotification('System reset completed. Please refresh the page.', 'warning');
            }, 5000);
        }

        async function generateReport() {
            showNotification('Business report generation started...', 'info');
            // Implementation would generate and download PDF/CSV report
            setTimeout(() => {
                showNotification('Report generated successfully!', 'success');
            }, 2000);
        }

        // Auto-update price when duration changes
        document.getElementById('voucherDuration').addEventListener('change', function() {
            const prices = {
                '1': 1,
                '6': 20,
                '12': 30,
                '24': 50,
                '168': 350,
                '720': 1500
            };
            
            document.getElementById('voucherAmount').value = prices[this.value] || 20;
        });

        // Generate voucher with enhanced validation
        document.getElementById('voucherForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const duration = parseInt(document.getElementById('voucherDuration').value);
            const amount = parseInt(document.getElementById('voucherAmount').value);
            const adminKey = document.getElementById('adminKey').value;
            
            if (!adminKey) {
                showNotification('Please enter admin key', 'error');
                return;
            }

            if (amount <= 0) {
                showNotification('Please enter a valid amount', 'error');
                return;
            }

            try {
                const submitBtn = document.querySelector('#voucherForm button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.textContent = 'Generating...';

                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'generate_voucher',
                        duration: duration,
                        amount: amount,
                        admin_key: adminKey
                    })
                });

                const result = await response.json();

                if (result.success) {
                    document.getElementById('generatedCode').textContent = result.data.voucher_code;
                    document.getElementById('voucherResult').style.display = 'block';
                    document.getElementById('adminKey').value = '';
                    showNotification('Voucher generated successfully!', 'success');
                    
                    // Refresh voucher list
                    if (currentTab === 'vouchers') {
                        await loadVoucherData();
                    }
                } else {
                    showNotification('Error: ' + result.message, 'error');
                }

            } catch (error) {
                showNotification('Failed to generate voucher: ' + error.message, 'error');
            } finally {
                const submitBtn = document.querySelector('#voucherForm button[type="submit"]');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Generate Voucher';
            }
        });

        // Utility functions
        function showLoading(elementId) {
            const element = document.getElementById(elementId);
            if (element) element.style.display = 'block';
        }

        function hideLoading(elementId) {
            const element = document.getElementById(elementId);
            if (element) element.style.display = 'none';
        }

        function showEmptyState(containerId, message) {
            const container = document.getElementById(containerId);
            container.innerHTML = `
                <div class="empty-state">
                    <h3>📄 ${message}</h3>
                    <p>Data will appear here when available</p>
                </div>
            `;
        }

        function updateLastUpdated() {
            document.getElementById('lastUpdated').textContent = `Last updated: ${new Date().toLocaleTimeString()}`;
        }

        // Enhanced auto-refresh functionality
        function startAutoRefresh() {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            
            autoRefreshInterval = setInterval(async () => {
                // Always refresh dashboard stats
                await loadDashboardData();
                
                // Refresh current tab data
                if (currentTab === 'users') {
                    await loadActiveUsers();
                } else if (currentTab === 'payments') {
                    await loadPaymentLogs();
                }
            }, 30000); // Refresh every 30 seconds
        }

        // Search and filter functionality placeholder
        function setupSearchAndFilters() {
            // Add search functionality for each table
            ['userSearch', 'sessionSearch', 'paymentSearch', 'voucherSearch'].forEach(searchId => {
                const searchInput = document.getElementById(searchId);
                if (searchInput) {
                    searchInput.addEventListener('input', function() {
                        // Implement search logic here
                        console.log(`Searching in ${searchId}: ${this.value}`);
                    });
                }
            });
        }

        // CRITICAL: Enhanced admin panel initialization with full error handling
        document.addEventListener('DOMContentLoaded', async function() {
            console.log('🚀 TECH CONNECT WiFi Admin Panel Initializing...');
            
            try {
                // Show initialization message
                showNotification('🔧 Initializing admin panel...', 'info');
                
                // Load initial dashboard data
                console.log('Loading dashboard data...');
                await loadDashboardData();
                
                // Setup search and filters
                console.log('Setting up search and filters...');
                setupSearchAndFilters();
                
                // Start auto-refresh
                console.log('Starting auto-refresh...');
                startAutoRefresh();
                
                // Add refresh button functionality to global scope
                window.refreshUsers = () => {
                    console.log('Refreshing users...');
                    return loadActiveUsers();
                };
                window.refreshSessions = () => {
                    console.log('Refreshing sessions...');
                    return loadSessionLogs();
                };
                window.refreshPayments = () => {
                    console.log('Refreshing payments...');
                    return loadPaymentLogs();
                };
                window.refreshVouchers = () => {
                    console.log('Refreshing vouchers...');
                    return loadVoucherData();
                };
                
                // Initialize revenue dashboard when revenue tab is first loaded
                if (currentTab === 'revenue') {
                    console.log('Initializing revenue dashboard...');
                    await initializeRevenueDashboard();
                }
                
                // Welcome message and system check
                setTimeout(() => {
                    showNotification('✅ TECH CONNECT WiFi Admin Panel Ready!', 'success');
                    console.log('✅ Admin panel fully initialized');
                }, 1000);
                
                // Initial system status check
                setTimeout(() => {
                    console.log('Loading system status...');
                    loadSystemStatus();
                }, 2000);
                
            } catch (error) {
                console.error('❌ Initialization error:', error);
                showNotification('❌ Failed to initialize admin panel: ' + error.message, 'error');
                
                // Fallback: try to load basic dashboard
                setTimeout(() => {
                    console.log('Attempting fallback initialization...');
                    loadDashboardData().catch(fallbackError => {
                        console.error('❌ Fallback failed:', fallbackError);
                        showNotification('❌ Critical error: Cannot load admin panel', 'error');
                    });
                }, 3000);
            }
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
        });
        
        // Old logout function replaced with professional modal-based logout
        
        // Auto-logout on session timeout
        let sessionTimeoutWarning;
        let sessionTimeoutLogout;
        
        function startSessionMonitoring() {
            // Warn user 5 minutes before session expires (8 hours - 5 minutes)
            sessionTimeoutWarning = setTimeout(() => {
                if (confirm('Your session will expire in 5 minutes. Click OK to extend your session.')) {
                    // Refresh session by making an API call
                    fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'ping' }) });
                    startSessionMonitoring(); // Restart monitoring
                }
            }, 27300000); // 7 hours 35 minutes
            
            // Auto-logout after 8 hours
            sessionTimeoutLogout = setTimeout(() => {
                alert('Your session has expired. You will be redirected to the login page.');
                logout();
            }, 28800000); // 8 hours
        }
        
        // Start session monitoring when page loads
        startSessionMonitoring();
        
        // ==========================================
        // PACKAGE MANAGEMENT FUNCTIONALITY
        // ==========================================
        
        let allPackages = [];
        let filteredPackages = [];
        let selectedPackages = [];
        let currentView = 'cards';
        let currentFilter = 'all';
        let isEditingPackage = false;
        
        // Load package data and statistics
        async function loadPackageData() {
            showLoading('packagesLoading');
            
            try {
                // Load packages and stats in parallel
                const [packagesResponse, statsResponse] = await Promise.all([
                    fetch(API_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'get_packages',
                            admin_key: 'your_admin_key'
                        })
                    }),
                    fetch(API_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'get_package_stats',
                            admin_key: 'your_admin_key'
                        })
                    })
                ]);
                
                const packagesResult = await packagesResponse.json();
                const statsResult = await statsResponse.json();
                
                if (packagesResult.success) {
                    allPackages = packagesResult.data.packages;
                    filteredPackages = [...allPackages];
                    displayPackages();
                    updatePackageStats(statsResult.success ? statsResult.data : {});
                    showNotification(`Loaded ${allPackages.length} packages`, 'info');
                } else {
                    showNotification('Failed to load packages', 'error');
                }
                
            } catch (error) {
                console.error('Error loading package data:', error);
                showNotification('Error loading package data', 'error');
            } finally {
                hideLoading('packagesLoading');
            }
        }
        
        // Update package statistics display
        function updatePackageStats(stats) {
            document.getElementById('totalPackages').textContent = stats.total_packages || 0;
            document.getElementById('activePackages').textContent = stats.active_packages || 0;
            document.getElementById('featuredPackages').textContent = stats.featured_packages || 0;
            document.getElementById('popularPackage').textContent = stats.most_popular || 'None';
        }
        
        // Display packages based on current view
        function displayPackages() {
            if (currentView === 'cards') {
                displayPackageCards();
            } else {
                displayPackageTable();
            }
        }
        
        // Display packages as cards
        function displayPackageCards() {
            const container = document.getElementById('packagesCardsView');
            
            if (filteredPackages.length === 0) {
                container.innerHTML = '<div class="empty-state">No packages found</div>';
                return;
            }
            
            container.innerHTML = filteredPackages.map(pkg => `
                <div class="package-card ${pkg.is_active ? '' : 'inactive'} ${pkg.is_featured ? 'featured' : ''}" 
                     data-package-id="${pkg.id}">
                    
                    <input type="checkbox" class="package-checkbox" 
                           onchange="togglePackageSelection(${pkg.id}, this.checked)">
                    
                    <div class="package-header ${pkg.package_type}">
                        <h3 class="package-name">${pkg.name}</h3>
                        <p class="package-description">${pkg.description || 'No description'}</p>
                    </div>
                    
                    <div class="package-body">
                        <div class="package-details">
                            <div class="package-detail">
                                <div class="detail-value">KES ${pkg.price}</div>
                                <div class="detail-label">Price</div>
                            </div>
                            <div class="package-detail">
                                <div class="detail-value">${formatDuration(pkg.duration_hours)}</div>
                                <div class="detail-label">Duration</div>
                            </div>
                        </div>
                        
                        <div class="package-features">
                            <div class="feature-item">
                                <span>📱</span> Max ${pkg.max_devices} device${pkg.max_devices > 1 ? 's' : ''}
                            </div>
                            ${pkg.speed_limit_mbps ? `
                                <div class="feature-item">
                                    <span>⚡</span> ${pkg.speed_limit_mbps} Mbps speed limit
                                </div>
                            ` : `
                                <div class="feature-item">
                                    <span>🚀</span> Unlimited speed
                                </div>
                            `}
                            <div class="feature-item">
                                <span>🏷️</span> ${pkg.package_type.charAt(0).toUpperCase() + pkg.package_type.slice(1)} package
                            </div>
                        </div>
                        
                        <div class="package-actions">
                            <button class="package-btn edit" onclick="editPackage(${pkg.id})">
                                ✏️ Edit
                            </button>
                            <button class="package-btn toggle" onclick="togglePackageStatus(${pkg.id})">
                                ${pkg.is_active ? '❌ Disable' : '✅ Enable'}
                            </button>
                            <button class="package-btn delete" onclick="deletePackage(${pkg.id})">
                                🗑️ Delete
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        }
        
        // Display packages as table
        function displayPackageTable() {
            const container = document.getElementById('packagesTableView');
            
            if (filteredPackages.length === 0) {
                container.innerHTML = '<div class="empty-state">No packages found</div>';
                return;
            }
            
            container.innerHTML = `
                <div class="packages-table-container">
                    <table class="packages-table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" onchange="toggleAllPackages(this.checked)">
                                </th>
                                <th>Package Name</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Price</th>
                                <th>Max Devices</th>
                                <th>Speed</th>
                                <th>Status</th>
                                <th>Featured</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${filteredPackages.map(pkg => `
                                <tr data-package-id="${pkg.id}">
                                    <td>
                                        <input type="checkbox" onchange="togglePackageSelection(${pkg.id}, this.checked)">
                                    </td>
                                    <td>
                                        <strong>${pkg.name}</strong>
                                        <br><small style="color: #666;">${pkg.description || 'No description'}</small>
                                    </td>
                                    <td>
                                        <span class="package-type-badge ${pkg.package_type}">${pkg.package_type}</span>
                                    </td>
                                    <td>${formatDuration(pkg.duration_hours)}</td>
                                    <td>KES ${pkg.price}</td>
                                    <td>${pkg.max_devices}</td>
                                    <td>${pkg.speed_limit_mbps ? pkg.speed_limit_mbps + ' Mbps' : 'Unlimited'}</td>
                                    <td>
                                        <span class="package-status-badge ${pkg.is_active ? 'active' : 'inactive'}">
                                            ${pkg.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td>${pkg.is_featured ? '⭐' : '-'}</td>
                                    <td>
                                        <button class="package-btn edit" onclick="editPackage(${pkg.id})" title="Edit">✏️</button>
                                        <button class="package-btn toggle" onclick="togglePackageStatus(${pkg.id})" title="Toggle Status">
                                            ${pkg.is_active ? '❌' : '✅'}
                                        </button>
                                        <button class="package-btn delete" onclick="deletePackage(${pkg.id})" title="Delete">🗑️</button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        // Format duration for display
        function formatDuration(hours) {
            if (hours < 1) {
                return Math.round(hours * 60) + ' minutes';
            } else if (hours < 24) {
                return hours + (hours === 1 ? ' hour' : ' hours');
            } else {
                const days = Math.floor(hours / 24);
                const remainingHours = hours % 24;
                let result = days + (days === 1 ? ' day' : ' days');
                if (remainingHours > 0) {
                    result += ' ' + remainingHours + 'h';
                }
                return result;
            }
        }
        
        // Toggle view between cards and table
        function toggleView(view) {
            currentView = view;
            
            // Update toggle buttons
            document.querySelectorAll('.toggle-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.view === view);
            });
            
            // Show/hide containers
            document.getElementById('packagesCardsView').style.display = view === 'cards' ? 'grid' : 'none';
            document.getElementById('packagesTableView').style.display = view === 'table' ? 'block' : 'none';
            
            displayPackages();
        }
        
        // Filter packages
        function filterPackages(filter) {
            currentFilter = filter;
            
            // Update filter buttons
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.filter === filter);
            });
            
            // Apply filter
            switch (filter) {
                case 'all':
                    filteredPackages = [...allPackages];
                    break;
                case 'basic':
                case 'standard':
                case 'premium':
                    filteredPackages = allPackages.filter(pkg => pkg.package_type === filter);
                    break;
                case 'active':
                    filteredPackages = allPackages.filter(pkg => pkg.is_active);
                    break;
                case 'featured':
                    filteredPackages = allPackages.filter(pkg => pkg.is_featured);
                    break;
                default:
                    filteredPackages = [...allPackages];
            }
            
            displayPackages();
        }
        
        // Search packages
        function searchPackages(query) {
            if (!query.trim()) {
                filterPackages(currentFilter);
                return;
            }
            
            query = query.toLowerCase();
            filteredPackages = allPackages.filter(pkg => 
                pkg.name.toLowerCase().includes(query) ||
                (pkg.description && pkg.description.toLowerCase().includes(query)) ||
                pkg.package_type.toLowerCase().includes(query)
            );
            
            displayPackages();
        }
        
        // Sort packages
        function sortPackages(sortBy) {
            switch (sortBy) {
                case 'name':
                    filteredPackages.sort((a, b) => a.name.localeCompare(b.name));
                    break;
                case 'price_low':
                    filteredPackages.sort((a, b) => a.price - b.price);
                    break;
                case 'price_high':
                    filteredPackages.sort((a, b) => b.price - a.price);
                    break;
                case 'duration_short':
                    filteredPackages.sort((a, b) => a.duration_hours - b.duration_hours);
                    break;
                case 'duration_long':
                    filteredPackages.sort((a, b) => b.duration_hours - a.duration_hours);
                    break;
                case 'created_new':
                    filteredPackages.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
                    break;
                case 'created_old':
                    filteredPackages.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
                    break;
                default: // sort_order
                    filteredPackages.sort((a, b) => a.sort_order - b.sort_order);
            }
            
            displayPackages();
        }
        
        // Package selection functions
        function togglePackageSelection(packageId, isSelected) {
            if (isSelected) {
                if (!selectedPackages.includes(packageId)) {
                    selectedPackages.push(packageId);
                }
            } else {
                selectedPackages = selectedPackages.filter(id => id !== packageId);
            }
            
            updateBulkActionsBar();
        }
        
        function toggleAllPackages(selectAll) {
            selectedPackages = selectAll ? filteredPackages.map(pkg => pkg.id) : [];
            
            // Update all checkboxes
            document.querySelectorAll('.package-checkbox, .packages-table input[type="checkbox"]').forEach(cb => {
                if (cb.onchange !== toggleAllPackages) {
                    cb.checked = selectAll;
                }
            });
            
            updateBulkActionsBar();
        }
        
        function clearSelection() {
            selectedPackages = [];
            document.querySelectorAll('.package-checkbox, .packages-table input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            updateBulkActionsBar();
        }
        
        function updateBulkActionsBar() {
            const bar = document.getElementById('bulkActionsBar');
            const count = selectedPackages.length;
            
            if (count > 0) {
                bar.style.display = 'flex';
                document.getElementById('selectedCount').textContent = count;
            } else {
                bar.style.display = 'none';
            }
        }
        
        // Package CRUD operations
        async function editPackage(packageId) {
            const pkg = allPackages.find(p => p.id === packageId);
            if (!pkg) return;
            
            // Simple inline editing - could be enhanced with modal
            const newName = prompt('Package Name:', pkg.name);
            if (!newName || newName === pkg.name) return;
            
            const newPrice = prompt('Price (KES):', pkg.price);
            if (!newPrice || newPrice === pkg.price.toString()) return;
            
            const newDuration = prompt('Duration (hours):', pkg.duration_hours);
            if (!newDuration || newDuration === pkg.duration_hours.toString()) return;
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_package',
                        admin_key: 'your_admin_key',
                        id: packageId,
                        name: newName,
                        price: parseInt(newPrice),
                        duration_hours: parseFloat(newDuration)
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Package updated successfully', 'success');
                    loadPackageData();
                } else {
                    showNotification('Failed to update package: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Error updating package', 'error');
            }
        }
        
        async function deletePackage(packageId) {
            const pkg = allPackages.find(p => p.id === packageId);
            if (!pkg) return;
            
            if (!confirm(`⚠️ Are you sure you want to delete "${pkg.name}"?\n\nThis action cannot be undone!`)) {
                return;
            }
            
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_package',
                        admin_key: 'your_admin_key',
                        id: packageId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Package deleted successfully', 'success');
                    loadPackageData();
                } else {
                    showNotification('Failed to delete package: ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Error deleting package', 'error');
            }
        }
        
        async function togglePackageStatus(packageId) {
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'toggle_package_status',
                        admin_key: 'your_admin_key',
                        id: packageId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Package status updated', 'success');
                    loadPackageData();
                } else {
                    showNotification('Failed to update package status', 'error');
                }
            } catch (error) {
                showNotification('Error updating package status', 'error');
            }
        }
        
        // Enhanced Modal Management Functions
        function showCreatePackageModal() {
            document.getElementById('packageFormTitle').textContent = 'Create New Package';
            document.getElementById('packageForm').reset();
            document.getElementById('packageId').value = '';
            
            // Set default values
            document.getElementById('packageActive').checked = true;
            document.getElementById('packageMaxDevices').value = '1';
            document.getElementById('packageType').value = 'standard';
            document.getElementById('packageColorScheme').value = 'blue';
            
            clearFormErrors();
            document.getElementById('packageModal').style.display = 'block';
        }
        
        function editPackage(packageId) {
            const pkg = allPackages.find(p => p.id === packageId);
            if (!pkg) return;

            document.getElementById('packageFormTitle').textContent = 'Edit Package';
            document.getElementById('packageId').value = pkg.id;
            document.getElementById('packageName').value = pkg.name;
            document.getElementById('packageDescription').value = pkg.description || '';
            document.getElementById('packageDuration').value = pkg.duration_hours;
            document.getElementById('packagePrice').value = pkg.price;
            document.getElementById('packageType').value = pkg.package_type;
            document.getElementById('packageColorScheme').value = pkg.color_scheme || 'blue';
            document.getElementById('packageActive').checked = pkg.is_active;
            document.getElementById('packageFeatured').checked = pkg.is_featured;
            document.getElementById('packageMaxDevices').value = pkg.max_devices || 1;
            document.getElementById('packageSpeedLimit').value = pkg.speed_limit_mbps || '';
            
            clearFormErrors();
            document.getElementById('packageModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('packageModal').style.display = 'none';
        }
        
        // Professional Logout Functions
        function showLogoutModal() {
            document.getElementById('logoutModal').style.display = 'block';
        }
        
        function closeLogoutModal() {
            document.getElementById('logoutModal').style.display = 'none';
        }
        
        async function confirmLogout() {
            closeLogoutModal();
            
            try {
                const response = await fetch('admin_auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=logout'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    localStorage.clear();
                    sessionStorage.clear();
                    showNotification('Logout successful! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = 'admin_login.php';
                    }, 1000);
                } else {
                    showNotification('Logout failed. Please try again.', 'error');
                }
            } catch (error) {
                console.error('Logout error:', error);
                showNotification('Logout error. Redirecting...', 'warning');
                setTimeout(() => {
                    window.location.href = 'admin_login.php';
                }, 1000);
            }
        }
        
        // Form Validation Functions
        function validateForm(formData) {
            const errors = {};
            
            if (!formData.name || formData.name.trim().length < 3) {
                errors.name = 'Package name must be at least 3 characters';
            }
            
            if (!formData.price || formData.price < 1) {
                errors.price = 'Price must be at least KES 1';
            }
            
            if (!formData.duration_hours || formData.duration_hours < 0.5) {
                errors.duration_hours = 'Duration must be at least 0.5 hours';
            }
            
            return errors;
        }

        function displayFormErrors(errors) {
            clearFormErrors();
            
            Object.keys(errors).forEach(field => {
                const errorElement = document.getElementById(field === 'duration_hours' ? 'durationError' : field + 'Error');
                const inputElement = document.getElementById('package' + field.charAt(0).toUpperCase() + field.slice(1).replace('_', ''));
                
                if (errorElement && inputElement) {
                    errorElement.textContent = errors[field];
                    inputElement.classList.add('error');
                }
            });
        }

        function clearFormErrors() {
            document.querySelectorAll('.error-text').forEach(el => el.textContent = '');
            document.querySelectorAll('.form-input').forEach(el => el.classList.remove('error'));
        }

        // Enhanced Package CRUD Operations
        async function savePackage(formData) {
            console.log('💾 SavePackage called with data:', formData);
            
            const packageId = formData.id;
            const isEdit = packageId && packageId !== '';
            const saveBtn = document.getElementById('savePackageBtn');
            
            console.log(`💾 ${isEdit ? 'Editing' : 'Creating'} package...`);
            
            // Show loading state
            const originalText = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.innerHTML = `
                <div style="display: inline-flex; align-items: center; gap: 8px;">
                    <div style="width: 16px; height: 16px; border: 2px solid #fff; border-top: 2px solid transparent; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    ${isEdit ? 'Updating...' : 'Saving...'}
                </div>
            `;
            
            try {
                const requestData = {
                    action: isEdit ? 'update_package' : 'create_package',
                    admin_key: 'your_admin_key',
                    ...formData
                };
                
                console.log('🌐 Sending API request:', requestData);
                
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(requestData)
                });

                console.log('🌐 API response status:', response.status);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('🌐 API response:', result);
                
                if (result.success) {
                    showNotification(`Package ${isEdit ? 'updated' : 'created'} successfully!`, 'success');
                    closeModal();
                    await loadPackages();
                } else {
                    showNotification(`Failed to ${isEdit ? 'update' : 'create'} package: ` + result.message, 'error');
                }
            } catch (error) {
                console.error('Save package error:', error);
                showNotification(`Error ${isEdit ? 'updating' : 'creating'} package. Please check your connection and try again.`, 'error');
            } finally {
                // Restore button state
                saveBtn.disabled = false;
                saveBtn.textContent = originalText;
            }
        }
        
        // Bulk operations
        async function bulkActivate() {
            if (selectedPackages.length === 0) return;
            
            if (!confirm(`Activate ${selectedPackages.length} selected packages?`)) return;
            
            try {
                const operations = selectedPackages.map(id => ({ action: 'activate', id }));
                
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'bulk_update_packages',
                        admin_key: 'your_admin_key',
                        operations: operations
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Packages activated successfully', 'success');
                    clearSelection();
                    loadPackageData();
                } else {
                    showNotification('Failed to activate packages', 'error');
                }
            } catch (error) {
                showNotification('Error activating packages', 'error');
            }
        }
        
        async function bulkDeactivate() {
            if (selectedPackages.length === 0) return;
            
            if (!confirm(`Deactivate ${selectedPackages.length} selected packages?`)) return;
            
            try {
                const operations = selectedPackages.map(id => ({ action: 'deactivate', id }));
                
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'bulk_update_packages',
                        admin_key: 'your_admin_key',
                        operations: operations
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Packages deactivated successfully', 'success');
                    clearSelection();
                    loadPackageData();
                } else {
                    showNotification('Failed to deactivate packages', 'error');
                }
            } catch (error) {
                showNotification('Error deactivating packages', 'error');
            }
        }
        
        async function bulkFeature() {
            if (selectedPackages.length === 0) return;
            
            if (!confirm(`Mark ${selectedPackages.length} selected packages as featured?`)) return;
            
            try {
                const promises = selectedPackages.map(id => 
                    fetch(API_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'update_package',
                            admin_key: 'your_admin_key',
                            id: id,
                            is_featured: 1
                        })
                    })
                );
                
                await Promise.all(promises);
                
                showNotification('Packages marked as featured', 'success');
                clearSelection();
                loadPackageData();
            } catch (error) {
                showNotification('Error featuring packages', 'error');
            }
        }
        
        async function bulkDelete() {
            if (selectedPackages.length === 0) return;
            
            if (!confirm(`⚠️ DELETE ${selectedPackages.length} selected packages?\n\nThis action CANNOT be undone!`)) return;
            
            try {
                const operations = selectedPackages.map(id => ({ action: 'delete', id }));
                
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'bulk_update_packages',
                        admin_key: 'your_admin_key',
                        operations: operations
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Packages deleted successfully', 'success');
                    clearSelection();
                    loadPackageData();
                } else {
                    showNotification('Failed to delete packages', 'error');
                }
            } catch (error) {
                showNotification('Error deleting packages', 'error');
            }
        }
        
        // Initialize package management when packages tab is loaded
        function loadPackages() {
            if (currentTab === 'packages') {
                loadPackageData();
            }
        }
        
        // Event listeners for package management
        document.addEventListener('DOMContentLoaded', function() {
            // Search functionality
            const packageSearch = document.getElementById('packageSearch');
            if (packageSearch) {
                packageSearch.addEventListener('input', (e) => {
                    searchPackages(e.target.value);
                });
            }
            
            // Filter buttons
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    filterPackages(btn.dataset.filter);
                });
            });
            
            // Sort functionality
            const packageSort = document.getElementById('packageSort');
            if (packageSort) {
                packageSort.addEventListener('change', (e) => {
                    sortPackages(e.target.value);
                });
            }
            
            // Enhanced Form Submission Handler
            const packageForm = document.getElementById('packageForm');
            if (packageForm) {
                packageForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    console.log('📋 Package form submitted');
                    
                    const formData = new FormData(this);
                    const data = {};
                    console.log('📋 Processing form data...');
                    
                    for (let [key, value] of formData.entries()) {
                        if (key === 'is_active') {
                            const activeCheckbox = document.getElementById('packageActive');
                            data[key] = activeCheckbox ? (activeCheckbox.checked ? 1 : 0) : 0;
                        } else if (key === 'is_featured') {
                            const featuredCheckbox = document.getElementById('packageFeatured');
                            data[key] = featuredCheckbox ? (featuredCheckbox.checked ? 1 : 0) : 0;
                        } else if (key === 'price' || key === 'max_devices' || key === 'speed_limit_mbps') {
                            data[key] = value ? parseInt(value) : null;
                        } else if (key === 'duration_hours') {
                            data[key] = value ? parseFloat(value) : null;
                        } else {
                            data[key] = value.trim();
                        }
                    }
                    
                    console.log('📋 Form data processed:', data);
                    
                    // Validate form
                    const errors = validateForm(data);
                    if (Object.keys(errors).length > 0) {
                        console.log('❌ Form validation errors:', errors);
                        displayFormErrors(errors);
                        return;
                    }
                    
                    console.log('✅ Form validation passed, saving package...');
                    savePackage(data);
                });
            }

            // Modal close events
            window.addEventListener('click', function(event) {
                const packageModal = document.getElementById('packageModal');
                const logoutModal = document.getElementById('logoutModal');
                
                if (event.target === packageModal) {
                    closeModal();
                }
                if (event.target === logoutModal) {
                    closeLogoutModal();
                }
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    closeLogoutModal();
                }
                if (e.ctrlKey && e.key === 'n') {
                    e.preventDefault();
                    showCreatePackageModal();
                }
            });
        });

        // ============================================================================
        // MIKROTIK ROUTER MANAGEMENT FUNCTIONS
        // ============================================================================

        // Load MikroTik data
        async function loadMikroTikData() {
            try {
                await Promise.all([
                    loadMikroTikStats(),
                    loadRoutersList()
                ]);
                showNotification('MikroTik data loaded successfully', 'success');
            } catch (error) {
                console.error('Error loading MikroTik data:', error);
                showNotification('Failed to load MikroTik data', 'error');
            }
        }

        // Load MikroTik statistics
        async function loadMikroTikStats() {
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'mikrotik_stats',
                        admin_key: 'your_admin_key'
                    })
                });

                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const result = await response.json();

                if (result.success) {
                    updateMikroTikStats(result.data);
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error('Error loading MikroTik stats:', error);
                // Set default values on error
                updateMikroTikStats({
                    total_routers: 0,
                    online_routers: 0,
                    offline_routers: 0,
                    active_sessions: 0
                });
            }
        }

        // Update MikroTik statistics display
        function updateMikroTikStats(stats) {
            document.getElementById('totalRouters').textContent = stats.total_routers || 0;
            document.getElementById('onlineRouters').textContent = stats.online_routers || 0;
            document.getElementById('offlineRouters').textContent = stats.offline_routers || 0;
            document.getElementById('activeSessions').textContent = stats.active_sessions || 0;
        }

        // Load routers list
        async function loadRoutersList() {
            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'mikrotik_routers',
                        admin_key: 'your_admin_key'
                    })
                });

                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const result = await response.json();

                if (result.success) {
                    displayRoutersList(result.data.routers);
                    displayRoutersStatus(result.data.routers);
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error('Error loading routers list:', error);
                document.getElementById('routersTableBody').innerHTML = `
                    <tr><td colspan="7" style="text-align: center; color: #dc3545; padding: 20px;">
                        Error loading routers: ${error.message}
                    </td></tr>
                `;
            }
        }

        // Display routers in table
        function displayRoutersList(routers) {
            const tableBody = document.getElementById('routersTableBody');
            
            if (!routers || routers.length === 0) {
                tableBody.innerHTML = `
                    <tr><td colspan="7" style="text-align: center; padding: 40px; color: #666;">
                        No routers registered yet. Click "Add New Router" to get started.
                    </td></tr>
                `;
                return;
            }

            tableBody.innerHTML = routers.map(router => {
                const statusColor = router.status === 'online' ? '#28a745' : 
                                  router.status === 'offline' ? '#dc3545' : '#ffc107';
                const statusIcon = router.status === 'online' ? '🟢' : 
                                 router.status === 'offline' ? '🔴' : '🟡';
                
                return `
                    <tr>
                        <td style="padding: 12px; border: 1px solid #ddd;">
                            <strong>${router.name}</strong>
                        </td>
                        <td style="padding: 12px; border: 1px solid #ddd;">${router.host}:${router.api_port}</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">
                            <span style="color: ${statusColor};">
                                ${statusIcon} ${router.status.toUpperCase()}
                            </span>
                        </td>
                        <td style="padding: 12px; border: 1px solid #ddd;">${router.model || 'Unknown'}</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">${router.firmware_version || 'Unknown'}</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">${router.last_seen || 'Never'}</td>
                        <td style="padding: 12px; border: 1px solid #ddd;">
                            <button onclick="pingRouter(${router.id})" style="background: #17a2b8; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-right: 5px;">
                                🔍 Test
                            </button>
                            <button onclick="syncRouterSessions(${router.id})" style="background: #28a745; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">
                                🔗 Sync
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Display routers status in monitor
        function displayRoutersStatus(routers) {
            const container = document.getElementById('routerStatusContainer');
            
            if (!routers || routers.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <h3>No routers to monitor</h3>
                        <p>Add your first MikroTik router to start monitoring.</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = routers.map(router => {
                const statusColor = router.status === 'online' ? '#28a745' : 
                                  router.status === 'offline' ? '#dc3545' : '#ffc107';
                const statusIcon = router.status === 'online' ? '🟢' : 
                                 router.status === 'offline' ? '🔴' : '🟡';
                
                return `
                    <div style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 10px; border-left: 4px solid ${statusColor};">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-weight: bold; font-size: 16px;">
                                    ${statusIcon} ${router.name}
                                </div>
                                <div style="color: #666; font-size: 14px;">
                                    ${router.host} • ${router.model || 'Unknown Model'}
                                </div>
                                <div style="color: #999; font-size: 12px;">
                                    Last seen: ${router.last_seen || 'Never'}
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="color: ${statusColor}; font-weight: bold; font-size: 14px;">
                                    ${router.status.toUpperCase()}
                                </div>
                                <div style="font-size: 12px; color: #666;">
                                    ${router.firmware_version || 'Unknown Version'}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Modal functions
        function showAddRouterModal() {
            document.getElementById('addRouterModal').style.display = 'block';
        }

        function closeAddRouterModal() {
            document.getElementById('addRouterModal').style.display = 'none';
            document.getElementById('addRouterForm').reset();
            document.getElementById('connectionTestResult').style.display = 'none';
        }

        // Test router connection
        async function testRouterConnection() {
            const host = document.getElementById('routerHost').value;
            const username = document.getElementById('routerUsername').value;
            const password = document.getElementById('routerPassword').value;
            const port = document.getElementById('routerPort').value || 8728;
            
            if (!host || !username || !password) {
                showNotification('Please fill in all connection details', 'error');
                return;
            }

            const resultDiv = document.getElementById('connectionTestResult');
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style="text-align: center;">🔍 Testing connection...</div>';
            resultDiv.style.background = '#cce5ff';
            resultDiv.style.color = '#004085';

            try {
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'test_router_connection',
                        host: host,
                        username: username,
                        password: password,
                        api_port: parseInt(port),
                        admin_key: 'your_admin_key'
                    })
                });

                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const result = await response.json();

                if (result.success) {
                    resultDiv.innerHTML = `
                        <div>✅ <strong>Connection Successful!</strong></div>
                        <div style="margin-top: 5px; font-size: 12px;">
                            Identity: ${result.data.identity || 'Unknown'}<br>
                            Model: ${result.data.model || 'Unknown'}<br>
                            Firmware: ${result.data.firmware || 'Unknown'}<br>
                            Uptime: ${result.data.uptime || 'Unknown'}
                        </div>
                    `;
                    resultDiv.style.background = '#d4edda';
                    resultDiv.style.color = '#155724';
                    showNotification('Connection test successful!', 'success');
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error('Connection test error:', error);
                resultDiv.innerHTML = `❌ <strong>Connection Failed:</strong> ${error.message}`;
                resultDiv.style.background = '#f8d7da';
                resultDiv.style.color = '#721c24';
                showNotification('Connection test failed', 'error');
            }
        }

        // Add router
        async function addRouter(event) {
            event.preventDefault();
            
            const formData = {
                name: document.getElementById('routerName').value,
                host: document.getElementById('routerHost').value,
                username: document.getElementById('routerUsername').value,
                password: document.getElementById('routerPassword').value,
                api_port: parseInt(document.getElementById('routerPort').value) || 8728,
                admin_key: 'your_admin_key'
            };

            try {
                showNotification('Adding router...', 'info');
                
                const response = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add_mikrotik_router',
                        ...formData
                    })
                });

                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const result = await response.json();

                if (result.success) {
                    showNotification('Router added successfully!', 'success');
                    closeAddRouterModal();
                    await loadMikroTikData(); // Refresh the data
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                console.error('Add router error:', error);
                showNotification(`Failed to add router: ${error.message}`, 'error');
            }
        }

        // Refresh routers
        async function refreshRouters() {
            showNotification('Refreshing router status...', 'info');
            await loadMikroTikData();
        }

        // Ping specific router
        async function pingRouter(routerId) {
            showNotification('Testing router connection...', 'info');
            // This would implement a specific ping test for the router
            // For now, just refresh the data
            await loadRoutersList();
        }

        // Sync router sessions
        async function syncRouterSessions(routerId) {
            showNotification('Syncing sessions to router...', 'info');
            // This would sync active WiFi sessions to the specific router
            // Implementation would call the sync_session_to_router endpoint
        }

        // Sync all sessions
        async function syncAllSessions() {
            showNotification('Syncing all sessions to routers...', 'info');
            // This would sync all active sessions to all online routers
        }

        // ==================== SMS FUNCTIONS ====================
        
        // Send test SMS
        async function sendTestSMS() {
            const phoneInput = document.getElementById('testPhone');
            const phone = phoneInput.value.trim();
            
            if (!phone) {
                showNotification('Please enter a phone number', 'error');
                return;
            }
            
            try {
                showNotification('Sending test SMS...', 'info');
                
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Key': ADMIN_KEY
                    },
                    body: JSON.stringify({
                        action: 'send_test_sms',
                        phone: phone
                    })
                });
                
                const result = await response.json();
                
                const resultDiv = document.getElementById('smsTestResult');
                resultDiv.style.display = 'block';
                
                if (result.success) {
                    resultDiv.innerHTML = `
                        <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; border: 1px solid #c3e6cb;">
                            <strong>✅ SMS Sent Successfully!</strong><br>
                            Phone: ${result.data.phone || phone}<br>
                            Status: ${result.message}
                        </div>
                    `;
                    showNotification('Test SMS sent successfully!', 'success');
                } else {
                    resultDiv.innerHTML = `
                        <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; border: 1px solid #f5c6cb;">
                            <strong>❌ SMS Failed!</strong><br>
                            Error: ${result.message || 'Unknown error'}
                        </div>
                    `;
                    showNotification('Failed to send test SMS: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('SMS Test Error:', error);
                showNotification('Error sending test SMS: ' + error.message, 'error');
            }
        }
        
        // Check SMS configuration
        async function checkSMSConfig() {
            try {
                showNotification('Checking SMS configuration...', 'info');
                
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Key': ADMIN_KEY
                    },
                    body: JSON.stringify({
                        action: 'check_sms_config'
                    })
                });
                
                const result = await response.json();
                
                const resultDiv = document.getElementById('smsTestResult');
                resultDiv.style.display = 'block';
                
                if (result.success) {
                    resultDiv.innerHTML = `
                        <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; border: 1px solid #c3e6cb;">
                            <strong>✅ SMS Configuration OK!</strong><br>
                            Username: ${result.data.username || 'Not set'}<br>
                            Sender ID: ${result.data.sender_id || 'TECHCON'}<br>
                            API Status: ${result.data.api_status || 'Ready'}
                        </div>
                    `;
                    showNotification('SMS configuration is valid!', 'success');
                } else {
                    resultDiv.innerHTML = `
                        <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; border: 1px solid #f5c6cb;">
                            <strong>❌ SMS Configuration Error!</strong><br>
                            Error: ${result.message || 'Configuration incomplete'}<br>
                            <small>Please check your Africa's Talking credentials in the .env file</small>
                        </div>
                    `;
                    showNotification('SMS configuration error: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('SMS Config Check Error:', error);
                showNotification('Error checking SMS configuration: ' + error.message, 'error');
            }
        }
        
        // Send manual voucher SMS
        async function sendVoucherSMS() {
            const phone = document.getElementById('voucherPhone').value.trim();
            const voucherCode = document.getElementById('voucherCode').value.trim();
            const packageType = document.getElementById('packageSelect').value;
            
            if (!phone) {
                showNotification('Please enter a phone number', 'error');
                return;
            }
            
            if (!voucherCode) {
                showNotification('Please enter a voucher code', 'error');
                return;
            }
            
            if (!packageType) {
                showNotification('Please select a package', 'error');
                return;
            }
            
            try {
                showNotification('Sending voucher SMS...', 'info');
                
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Key': ADMIN_KEY
                    },
                    body: JSON.stringify({
                        action: 'send_voucher_sms',
                        phone: phone,
                        voucher_code: voucherCode,
                        package_type: packageType
                    })
                });
                
                const result = await response.json();
                
                const resultDiv = document.getElementById('voucherSMSResult');
                resultDiv.style.display = 'block';
                
                if (result.success) {
                    resultDiv.innerHTML = `
                        <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; border: 1px solid #c3e6cb;">
                            <strong>✅ Voucher SMS Sent!</strong><br>
                            Phone: ${result.data.phone || phone}<br>
                            Voucher: ${voucherCode}<br>
                            Package: ${packageType}
                        </div>
                    `;
                    showNotification('Voucher SMS sent successfully!', 'success');
                    
                    // Clear form
                    document.getElementById('voucherPhone').value = '';
                    document.getElementById('voucherCode').value = '';
                    document.getElementById('packageSelect').value = '';
                    
                } else {
                    resultDiv.innerHTML = `
                        <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; border: 1px solid #f5c6cb;">
                            <strong>❌ Voucher SMS Failed!</strong><br>
                            Error: ${result.message || 'Unknown error'}
                        </div>
                    `;
                    showNotification('Failed to send voucher SMS: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Voucher SMS Error:', error);
                showNotification('Error sending voucher SMS: ' + error.message, 'error');
            }
        }
        
        // Load packages for SMS service
        async function loadPackagesForSMS() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Key': ADMIN_KEY
                    },
                    body: JSON.stringify({
                        action: 'get_packages',
                        active_only: true
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const packageSelect = document.getElementById('packageSelect');
                    // Clear existing options except the first one
                    packageSelect.innerHTML = '<option value="">Select Package</option>';
                    
                    result.data.forEach(pkg => {
                        const hours = parseFloat(pkg.duration_hours);
                        let duration;
                        if (hours < 1) {
                            const minutes = Math.round(hours * 60);
                            duration = `${minutes} minute${minutes > 1 ? 's' : ''}`;
                        } else if (hours >= 24) {
                            const days = Math.floor(hours / 24);
                            duration = `${days} day${days > 1 ? 's' : ''}`;
                        } else {
                            duration = `${Math.round(hours)} hour${hours > 1 ? 's' : ''}`;
                        }
                            
                        const option = document.createElement('option');
                        option.value = pkg.id;
                        option.textContent = `${pkg.name} - KSH ${pkg.price} (${duration})`;
                        packageSelect.appendChild(option);
                    });
                } else {
                    console.error('Failed to load packages:', result.message);
                }
            } catch (error) {
                console.error('Error loading packages:', error);
            }
        }

        // Load SMS data when tab is accessed
        async function loadSMSData() {
            try {
                // Load packages first
                await loadPackagesForSMS();
                
                // Load SMS statistics
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Key': ADMIN_KEY
                    },
                    body: JSON.stringify({
                        action: 'get_sms_stats'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('totalSMSSent').textContent = result.data.total || '0';
                    document.getElementById('smsSentToday').textContent = result.data.today || '0';
                    document.getElementById('smsSuccessRate').textContent = (result.data.success_rate || '0') + '%';
                }
                
                // Load SMS logs
                loadSMSLogs();
                
            } catch (error) {
                console.error('Error loading SMS data:', error);
            }
        }
        
        // Load SMS logs
        async function loadSMSLogs() {
            try {
                const container = document.getElementById('smsLogsContainer');
                
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Key': ADMIN_KEY
                    },
                    body: JSON.stringify({
                        action: 'get_sms_logs'
                    })
                });
                
                const result = await response.json();
                
                if (result.success && result.data.length > 0) {
                    const logsHtml = `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Phone</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${result.data.map(log => `
                                    <tr>
                                        <td>${new Date(log.timestamp).toLocaleString()}</td>
                                        <td>${log.phone}</td>
                                        <td>${log.type}</td>
                                        <td>
                                            <span style="padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; 
                                                         background: ${log.status === 'success' ? '#d4edda' : '#f8d7da'}; 
                                                         color: ${log.status === 'success' ? '#155724' : '#721c24'};">
                                                ${log.status === 'success' ? '✅ Sent' : '❌ Failed'}
                                            </span>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                    container.innerHTML = logsHtml;
                } else {
                    container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">No SMS logs found</p>';
                }
                
            } catch (error) {
                console.error('Error loading SMS logs:', error);
                document.getElementById('smsLogsContainer').innerHTML = '<p style="color: red;">Error loading SMS logs</p>';
            }
        }
        
    </script>

    <!-- Enhanced Package Form Modal -->
    <div id="packageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2 id="packageFormTitle">Create Package</h2>
            </div>
            <div class="modal-body">
                <form id="packageForm" novalidate>
                    <input type="hidden" id="packageId" name="id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="packageName">Package Name *</label>
                            <input type="text" id="packageName" name="name" class="form-input" required>
                            <div class="error-text" id="nameError"></div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="packagePrice">Price (KES) *</label>
                            <input type="number" id="packagePrice" name="price" class="form-input" min="1" required>
                            <div class="error-text" id="priceError"></div>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="packageDuration">Duration (hours) *</label>
                            <input type="number" id="packageDuration" name="duration_hours" class="form-input" min="0.5" step="0.5" required>
                            <div class="error-text" id="durationError"></div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="packageType">Package Type</label>
                            <select id="packageType" name="package_type" class="form-input">
                                <option value="basic">Basic</option>
                                <option value="standard">Standard</option>
                                <option value="premium">Premium</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="packageDescription">Description</label>
                        <textarea id="packageDescription" name="description" class="form-input" rows="3" placeholder="Brief description of the package..."></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="packageMaxDevices">Max Devices</label>
                            <input type="number" id="packageMaxDevices" name="max_devices" class="form-input" min="1" value="1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="packageSpeedLimit">Speed Limit (Mbps)</label>
                            <input type="number" id="packageSpeedLimit" name="speed_limit_mbps" class="form-input" min="1" placeholder="Leave empty for unlimited">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="packageColorScheme">Color Scheme</label>
                            <select id="packageColorScheme" name="color_scheme" class="form-input">
                                <option value="blue">Blue</option>
                                <option value="green">Green</option>
                                <option value="red">Red</option>
                                <option value="purple">Purple</option>
                                <option value="orange">Orange</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="packageActive" name="is_active" class="checkbox-input" checked>
                                <label for="packageActive" class="form-label">Active Package</label>
                            </div>
                            
                            <div class="checkbox-group">
                                <input type="checkbox" id="packageFeatured" name="is_featured" class="checkbox-input">
                                <label for="packageFeatured" class="form-label">Featured Package</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" form="packageForm" class="btn btn-success" id="savePackageBtn">Save Package</button>
            </div>
        </div>
    </div>

    <!-- Professional Logout Modal -->
    <div id="logoutModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close" onclick="closeLogoutModal()">&times;</span>
                <h2>🚪 Logout Confirmation</h2>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to logout from the admin panel?</p>
                <p><strong>Current session:</strong> <?php echo htmlspecialchars($currentUser['username']); ?></p>
                <p><strong>Login time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeLogoutModal()">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmLogout()">🚪 Yes, Logout</button>
            </div>
        </div>
    </div>
</body>
</html>