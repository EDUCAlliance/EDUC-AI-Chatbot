<?php
/**
 * API Status Endpoint
 */

session_start();

// Check authentication
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';
    
    // Test API connection
    $apiTest = testAPIConnection();
    
    if ($apiTest['status'] === 'success') {
        echo json_encode([
            'status' => 'success',
            'message' => 'API connection successful',
            'details' => $apiTest
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'error' => $apiTest['error'] ?? 'Unknown error',
            'details' => $apiTest
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage()
    ]);
}
?> 