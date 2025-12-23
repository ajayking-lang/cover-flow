&lt;?php
/**
 * Simple PHP backend to fetch data from a Firebase Realtime Database or Firestore REST endpoint.
 *
 * Usage (GET):
 *   firebase_backend.php?path=/some/resource&amp;firebase_url=...&amp;api_key=...
 *
 * Recommended:
 *   - Set FIREBASE_URL and FIREBASE_API_KEY in your hosting environment
 *     instead of passing them on each request.
 */

// Allow CORS for browser-based panels (adjust the origin for production use)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Read Firebase configuration from environment variables or query params
$firebaseUrl = getenv('FIREBASE_URL');
$apiKey = getenv('FIREBASE_API_KEY');

if (!$firebaseUrl) {
    $firebaseUrl = isset($_GET['firebase_url']) ? trim($_GET['firebase_url']) : '';
}
if (!$apiKey) {
    $apiKey = isset($_GET['api_key']) ? trim($_GET['api_key']) : '';
}

$path = isset($_GET['path']) ? trim($_GET['path']) : '/';

// Basic validation
if ($firebaseUrl === '' || $apiKey === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'error' =&gt; 'missing_configuration',
        'message' =&gt; 'FIREBASE_URL and FIREBASE_API_KEY must be provided (env vars or query params).',
    ]);
    exit;
}

// Normalize URL and path
$firebaseUrl = rtrim($firebaseUrl, '/');

if ($path === '') {
    $path = '/';
}

if ($path[0] !== '/') {
    $path = '/' . $path;
}

/**
 * This backend assumes:
 *   - Firebase Realtime Database: use .json at the end of the path
 *   - e.g. https://your-project.firebaseio.com/some/path.json?key=API_KEY
 *
 * If you are using Firestore REST API, pass the full REST path including
 * `documents/...` via the `path` parameter and remove/adjust the `.json`
 * suffix as needed.
 */
$isRealtimeDatabase = true;
$resourceUrl = $firebaseUrl . $path . ($isRealtimeDatabase ? '.json' : '');
$separator = (parse_url($resourceUrl, PHP_URL_QUERY) === null) ? '?' : '&amp;';
$resourceUrl .= $separator . 'key=' . urlencode($apiKey);

// Fetch data via cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL =&gt; $resourceUrl,
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_FOLLOWLOCATION =&gt; true,
    CURLOPT_CONNECTTIMEOUT =&gt; 5,
    CURLOPT_TIMEOUT =&gt; 15,
]);

$responseBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

header('Content-Type: application/json');

if ($responseBody === false) {
    http_response_code(502);
    echo json_encode([
        'error' =&gt; 'upstream_error',
        'message' =&gt; 'Failed to contact Firebase: ' . $curlError,
    ]);
    exit;
}

// Forward Firebase status code if it indicates an error
if ($httpCode &gt;= 400) {
    http_response_code($httpCode);
    echo $responseBody;
    exit;
}

// Success - return the raw JSON body
http_response_code(200);
echo $responseBody;