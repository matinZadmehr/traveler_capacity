<?php
/**
 * Webhook Handler for Traveler Capacity Declaration
 * Sends traveler's weight capacity to n8n webhook
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST method allowed']);
    exit();
}

// Get raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

// Log received data (for debugging)
logData('Traveler capacity received:', $data);

// CONFIGURATION - SET YOUR N8N WEBHOOK URL HERE
$n8n_webhook_url = 'https://your-n8n-domain.com/webhook/traveler-capacity'; // ← CHANGE THIS!

// Validate webhook URL
if (empty($n8n_webhook_url) || strpos($n8n_webhook_url, 'your-n8n-domain') !== false) {
    $response = [
        'success' => false,
        'error' => 'N8N webhook URL not configured',
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    echo json_encode($response);
    exit();
}

// Prepare data for n8n
$payload = prepareN8NPayload($data);

// Send to n8n webhook
$result = sendToN8N($n8n_webhook_url, $payload);

// Return response
if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'Traveler capacity sent to n8n successfully',
        'n8n_response' => $result['response'],
        'timestamp' => date('Y-m-d H:i:s'),
        'traveler_id' => uniqid('TRVL_')
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send traveler capacity to n8n',
        'n8n_error' => $result['error'],
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Prepare payload for n8n
 */
function prepareN8NPayload($data) {
    $payload = [
        'event_type' => 'traveler_capacity_declaration',
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => time(),
        'source' => $data['source'] ?? 'telegram_web_app',
        'action' => $data['action'] ?? 'unknown'
    ];
    
    // Add capacity information
    if (isset($data['capacity_info'])) {
        $payload['traveler_capacity'] = [
            'available_weight_kg' => $data['capacity_info']['available_weight']['kg'] ?? 0,
            'available_weight_grams' => $data['capacity_info']['available_weight']['grams'] ?? 0,
            'weight_display' => $data['capacity_info']['available_weight']['display'] ?? '0 kg',
            'is_under_1kg' => $data['capacity_info']['available_weight']['is_under_1kg'] ?? false,
            
            'capacity_level' => $data['capacity_info']['capacity_level']['label'] ?? 'unknown',
            'capacity_level_id' => $data['capacity_info']['capacity_level']['id'] ?? 'unknown',
            'capacity_description' => $data['capacity_info']['capacity_level']['description'] ?? '',
            'capacity_min_kg' => $data['capacity_info']['capacity_level']['min_kg'] ?? 0,
            'capacity_max_kg' => $data['capacity_info']['capacity_level']['max_kg'] ?? 0,
            
            'traveler_type' => $data['capacity_info']['traveler_type'] ?? 'unknown',
            'can_carry_more' => $data['capacity_info']['can_carry_more'] ?? false,
            'is_under_limit' => ($data['capacity_info']['available_weight']['kg'] ?? 0) <= 30
        ];
        
        // Calculate matching opportunities
        $weight = $data['capacity_info']['available_weight']['kg'] ?? 0;
        $payload['traveler_capacity']['matching_possibilities'] = calculateMatchingPossibilities($weight);
        $payload['traveler_capacity']['estimated_earnings'] = estimateEarnings($weight);
    }
    
    // Add Telegram user info
    if (isset($data['telegram_user'])) {
        $payload['traveler'] = $data['telegram_user'];
        $payload['traveler']['status'] = 'active';
        $payload['traveler']['registration_date'] = date('Y-m-d');
    }
    
    // Add metadata
    $payload['metadata'] = [
        'ip_address' => $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $data['metadata']['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timezone' => $data['metadata']['timezone'] ?? 'unknown',
        'language' => $data['metadata']['language'] ?? 'unknown',
        'device_type' => $data['metadata']['device_type'] ?? 'unknown',
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'processed_at' => date('Y-m-d H:i:s'),
        'data_validation' => validateCapacityData($data['capacity_info'] ?? [])
    ];
    
    return $payload;
}

/**
 * Calculate matching possibilities
 */
function calculateMatchingPossibilities($weight) {
    $possibilities = [];
    
    if ($weight >= 20) {
        $possibilities[] = 'large_cargo';
        $possibilities[] = 'multiple_small_cargos';
        $possibilities[] = 'medium_cargo';
    } elseif ($weight >= 10) {
        $possibilities[] = 'medium_cargo';
        $possibilities[] = 'multiple_small_cargos';
    } elseif ($weight >= 5) {
        $possibilities[] = 'small_cargo';
        $possibilities[] = 'documents';
    } elseif ($weight >= 1) {
        $possibilities[] = 'documents';
        $possibilities[] = 'small_items';
    } else {
        $possibilities[] = 'documents';
        $possibilities[] = 'jewelry';
    }
    
    return $possibilities;
}

/**
 * Estimate earnings based on weight
 */
function estimateEarnings($weight) {
    $baseRate = 5000; // Base rate per kg
    
    if ($weight < 1) {
        // For items under 1kg, fixed rate
        $min = 10000;
        $max = 20000;
    } elseif ($weight <= 5) {
        $min = $weight * $baseRate * 0.8;
        $max = $weight * $baseRate * 1.2;
    } elseif ($weight <= 15) {
        $min = $weight * $baseRate * 0.7;
        $max = $weight * $baseRate * 1.3;
    } else {
        $min = $weight * $baseRate * 0.6;
        $max = $weight * $baseRate * 1.4;
    }
    
    return [
        'min' => round($min),
        'max' => round($max),
        'currency' => 'IRR',
        'per_kg_rate' => $baseRate
    ];
}

/**
 * Validate capacity data
 */
function validateCapacityData($capacityInfo) {
    $validation = [
        'weight_valid' => ($capacityInfo['available_weight']['kg'] ?? 0) >= 0.1 && 
                         ($capacityInfo['available_weight']['kg'] ?? 0) <= 50,
        'capacity_level_valid' => !empty($capacityInfo['capacity_level']['id'] ?? ''),
        'traveler_type_valid' => !empty($capacityInfo['traveler_type'] ?? ''),
        'all_valid' => true
    ];
    
    // Check if all validations pass
    foreach ($validation as $key => $value) {
        if ($key !== 'all_valid' && !$value) {
            $validation['all_valid'] = false;
            break;
        }
    }
    
    return $validation;
}

/**
 * Send data to n8n webhook
 */
function sendToN8N($url, $payload) {
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: Telegram-Capacity-Webhook/1.0'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    // Log the request
    logData('Capacity data sent to n8n:', [
        'url' => $url,
        'payload_size' => strlen(json_encode($payload)),
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ]);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    // Consider 2xx and 3xx status codes as success
    if ($httpCode >= 200 && $httpCode < 400) {
        return [
            'success' => true,
            'response' => json_decode($response, true) ?: $response,
            'http_code' => $httpCode
        ];
    }
    
    return [
        'success' => false,
        'error' => "HTTP $httpCode",
        'response' => $response
    ];
}

/**
 * Log data for debugging
 */
function logData($message, $data) {
    $logFile = __DIR__ . '/webhook_capacity_log.txt';
    $logEntry = date('Y-m-d H:i:s') . " - $message\n";
    $logEntry .= print_r($data, true) . "\n";
    $logEntry .= str_repeat('-', 80) . "\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Create a simple HTML test page if accessed via browser
if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ?>
    <!DOCTYPE html>
    <html dir="rtl">
    <head>
        <title>Webhook Test Page - Traveler Capacity</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; direction: rtl; }
            .container { max-width: 800px; margin: 0 auto; }
            .status { padding: 10px; margin: 10px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            .test-form { background: #f8f9fa; padding: 20px; border-radius: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>وب‌هوک ظرفیت مسافر - صفحه تست</h1>
            
            <div class="status success">
                <strong>وضعیت:</strong> وب‌هوک در حال اجراست
            </div>
            
            <div class="test-form">
                <h2>تست وب‌هوک</h2>
                <p>از این فرم برای تست دستی وب‌هوک استفاده کنید:</p>
                
                <form id="testForm">
                    <div>
                        <label>وزن قابل حمل (کیلوگرم):</label>
                        <input type="number" id="capacity" value="10.5" step="0.1" min="0.1" max="50">
                    </div>
                    
                    <div>
                        <label>سطح ظرفیت:</label>
                        <select id="capacityLevel">
                            <option value="low">کم</option>
                            <option value="medium" selected>متوسط</option>
                            <option value="high">زیاد</option>
                        </select>
                    </div>
                    
                    <button type="button" onclick="testWebhook()">تست وب‌هوک</button>
                </form>
                
                <div id="testResult"></div>
            </div>
        </div>
        
        <script>
            async function testWebhook() {
                const capacity = parseFloat(document.getElementById('capacity').value);
                const capacityLevel = document.getElementById('capacityLevel').value;
                
                const data = {
                    action: 'traveler_capacity_declared',
                    timestamp: new Date().toISOString(),
                    capacity_info: {
                        available_weight: {
                            kg: capacity,
                            grams: Math.round(capacity * 1000),
                            display: capacity < 1 ? `${Math.round(capacity * 1000)} گرم` : `${capacity} کیلوگرم`,
                            is_under_1kg: capacity < 1
                        },
                        capacity_level: {
                            id: capacityLevel,
                            label: capacityLevel === 'low' ? 'کم' : capacityLevel === 'medium' ? 'متوسط' : 'زیاد',
                            description: capacityLevel === 'low' ? 'کیف دستی' : 
                                         capacityLevel === 'medium' ? 'چمدان کوچک' : 'چمدان بزرگ',
                            min_kg: capacityLevel === 'low' ? 0.1 : capacityLevel === 'medium' ? 5 : 15,
                            max_kg: capacityLevel === 'low' ? 5 : capacityLevel === 'medium' ? 15 : 50
                        },
                        traveler_type: capacity <= 2 ? 'light_traveler' : 
                                      capacity <= 10 ? 'standard_traveler' : 
                                      capacity <= 20 ? 'heavy_traveler' : 'extra_capacity_traveler',
                        can_carry_more: capacity < 30
                    },
                    telegram_user: {
                        telegram_id: 123456789,
                        telegram_username: 'testtraveler'
                    },
                    source: 'web_test',
                    ip_address: '127.0.0.1'
                };
                
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    document.getElementById('testResult').innerHTML = 
                        '<pre>' + JSON.stringify(result, null, 2) + '</pre>';
                } catch (error) {
                    document.getElementById('testResult').innerHTML = 
                        'خطا: ' + error.message;
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit();
}
?>