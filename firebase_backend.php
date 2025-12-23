&lt;?php
/**
 * PHP backend for a web-based control panel using Firebase Realtime Database.
 *
 * It exposes a few simple actions:
 *
 *   GET  firebase_backend.php?action=list_devices
 *   GET  firebase_backend.php?action=get_device&amp;device_id=DEVICE_ID
 *   POST firebase_backend.php?action=update_device
 *   POST firebase_backend.php?action=add_sms_credits
 *
 * You can still use it as a generic proxy:
 *   GET firebase_backend.php?path=/some/resource
 */

require_once __DIR__ . '/firebase_config.php';

// Allow CORS for browser-based panels (adjust the origin for production use)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Read Firebase configuration from environment variables or config file
$firebaseUrl = getenv('FIREBASE_URL');
$apiKey = getenv('FIREBASE_API_KEY');

if (!$firebaseUrl &amp;&amp; defined('FIREBASE_DATABASE_URL')) {
    $firebaseUrl = FIREBASE_DATABASE_URL;
}
if (!$apiKey &amp;&amp; defined('FIREBASE_WEB_API_KEY')) {
    $apiKey = FIREBASE_WEB_API_KEY;
}

if (!$firebaseUrl) {
    $firebaseUrl = isset($_GET['firebase_url']) ? trim($_GET['firebase_url']) : '';
}
if (!$apiKey) {
    $apiKey = isset($_GET['api_key']) ? trim($_GET['api_key']) : '';
}

$action = isset($_GET['action']) ? $_GET['action'] : null;
$path   = isset($_GET['path']) ? trim($_GET['path']) : '/';

// Basic validation
if ($firebaseUrl === '' || $apiKey === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'error' =&gt; 'missing_configuration',
        'message' =&gt; 'FIREBASE_URL and FIREBASE_API_KEY must be provided (env vars, config file or query params).',
    ]);
    exit;
}

// Normalize URL
$firebaseUrl = rtrim($firebaseUrl, '/');

function build_firebase_url($firebaseUrl, $apiKey, $path, $isRealtimeDatabase = true)
{
    $path = ltrim($path, '/');
    $resourceUrl = $firebaseUrl . '/' . $path . ($isRealtimeDatabase ? '.json' : '');
    $separator = (parse_url($resourceUrl, PHP_URL_QUERY) === null) ? '?' : '&amp;';
    return $resourceUrl . $separator . 'key=' . urlencode($apiKey);
}

function firebase_get($firebaseUrl, $apiKey, $path)
{
    $url = build_firebase_url($firebaseUrl, $apiKey, $path);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL =&gt; $url,
        CURLOPT_RETURNTRANSFER =&gt; true,
        CURLOPT_FOLLOWLOCATION =&gt; true,
        CURLOPT_CONNECTTIMEOUT =&gt; 5,
        CURLOPT_TIMEOUT =&gt; 15,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [$code, $body, $err];
}

function firebase_put($firebaseUrl, $apiKey, $path, $data)
{
    $url = build_firebase_url($firebaseUrl, $apiKey, $path);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL =&gt; $url,
        CURLOPT_CUSTOMREQUEST =&gt; 'PUT',
        CURLOPT_POSTFIELDS =&gt; json_encode($data),
        CURLOPT_HTTPHEADER =&gt; ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER =&gt; true,
        CURLOPT_FOLLOWLOCATION =&gt; true,
        CURLOPT_CONNECTTIMEOUT =&gt; 5,
        CURLOPT_TIMEOUT =&gt; 15,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [$code, $body, $err];
}

header('Content-Type: application/json');

// Route by action
if ($action === 'list_devices') {
    // GET /devices
    list($code, $body, $err) = firebase_get($firebaseUrl, $apiKey, 'devices');

    if ($body === false) {
        http_response_code(502);
        echo json_encode(['error' =&gt; 'upstream_error', 'message' =&gt; $err]);
        exit;
    }

    if ($code &gt;= 400) {
        http_response_code($code);
        echo $body;
        exit;
    }

    http_response_code(200);
    echo $body;
    exit;
}

if ($action === 'get_device') {
    $deviceId = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';
    if ($deviceId === '') {
        http_response_code(400);
        echo json_encode(['error' =&gt; 'missing_device_id']);
        exit;
    }

    list($code, $body, $err) = firebase_get($firebaseUrl, $apiKey, 'devices/' . $deviceId);

    if ($body === false) {
        http_response_code(502);
        echo json_encode(['error' =&gt; 'upstream_error', 'message' =&gt; $err]);
        exit;
    }

    if ($code &gt;= 400) {
        http_response_code($code);
        echo $body;
        exit;
    }

    http_response_code(200);
    echo $body;
    exit;
}

if ($action === 'update_device' &amp;&amp; $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['device_id']) || !is_array($data['fields'])) {
        http_response_code(400);
        echo json_encode(['error' =&gt; 'invalid_payload']);
        exit;
    }

    $deviceId = $data['device_id'];
    $fields   = $data['fields'];

    // Fetch current device
    list($code, $body, $err) = firebase_get($firebaseUrl, $apiKey, 'devices/' . $deviceId);
    if ($body === false) {
        http_response_code(502);
        echo json_encode(['error' =&gt; 'upstream_error', 'message' =&gt; $err]);
        exit;
    }
    if ($code &gt;= 400) {
        http_response_code($code);
        echo $body;
        exit;
    }

    $current = json_decode($body, true);
    if (!is_array($current)) {
        $current = [];
    }

    $updated = array_merge($current, $fields);

    list($code2, $body2, $err2) = firebase_put($firebaseUrl, $apiKey, 'devices/' . $deviceId, $updated);
    if ($body2 === false) {
        http_response_code(502);
        echo json_encode(['error' =&gt; 'upstream_error', 'message' =&gt; $err2]);
        exit;
    }
    if ($code2 &gt;= 400) {
        http_response_code($code2);
        echo $body2;
        exit;
    }

    http_response_code(200);
    echo $body2;
    exit;
}

if ($action === 'add_sms_credits' &amp;&amp; $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['device_id']) || !isset($data['amount'])) {
        http_response_code(400);
        echo json_encode(['error' =&gt; 'invalid_payload']);
        exit;
    }

    $deviceId = $data['device_id'];
    $amount   = (int)$data['amount'];

    list($code, $body, $err) = firebase_get($firebaseUrl, $apiKey, 'devices/' . $deviceId);
    if ($body === false) {
        http_response_code(502);
        echo json_encode(['error' =&gt; 'upstream_error', 'message' =&gt; $err]);
        exit;
    }
    if ($code &gt;= 400) {
        http_response_code($code);
        echo $body;
        exit;
    }

    $device = json_decode($body, true);
    if (!is_array($device)) {
        $device = [];
    }

    $currentCredits = isset($device['credits']) ? (int)$device['credits'] : 0;
    $device['credits'] = $currentCredits + $amount;

    list($code2, $body2, $err2) = firebase_put($firebaseUrl, $apiKey, 'devices/' . $deviceId, $device);
    if ($body2 === false) {
        http_response_code(502);
        echo json_encode(['error' =&gt; 'upstream_error', 'message' =&gt; $err2]);
        exit;
    }
    if ($code2 &gt;= 400) {
        http_response_code($code2);
        echo $body2;
        exit;
    }

    http_response_code(200);
    echo $body2;
    exit;
}

// Default: generic proxy, same as before
if ($path === '') {
    $path = '/';
}

if ($path[0] === '/') {
    $path = substr($path, 1);
}

list($code, $body, $err) = firebase_get($firebaseUrl, $apiKey, $path);

if ($body === false) {
    http_response_code(502);
    echo json_encode([
        'error' =&gt; 'upstream_error',
        'message' =&gt; 'Failed to contact Firebase: ' . $err,
    ]);
    exit;
}

if ($code &gt;= 400) {
    http_response_code($code);
    echo $body;
    exit;
}

http_response_code(200);
echo $body;