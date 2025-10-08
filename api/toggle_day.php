<?php
// api/toggle_day.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Start session (required for auth + CSRF)
session_start();

// --- include DB connection (adjust path if your db.php location differs) ---
$included = false;
$tryPaths = [
    __DIR__ . '/../db.php',   // common: api/ is sibling to root where db.php lives
    __DIR__ . '/db.php',      // alternative: db.php inside api/
    __DIR__ . '/../config/db.php', // alternate layouts
];

foreach ($tryPaths as $p) {
    if (file_exists($p)) {
        require_once $p;
        $included = true;
        break;
    }
}

if (!$included) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server misconfiguration: db.php not found']);
    exit;
}

// $conn should be provided by db.php (mysqli)
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB connection not available']);
    exit;
}

// --- Auth: ensure logged in ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// --- Role check: allow flexible matching for admin / super (case-insensitive, trims) ---
$role = strtolower(trim((string)($_SESSION['user_role'] ?? '')));
if (!in_array($role, ['admin','super','super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden: admin/super only', 'debug_role' => $role]);
    exit;
}

// --- Read JSON body ---
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// --- Validate inputs ---
$day_id = $payload['day_id'] ?? null;
$is_enabled = $payload['is_enabled'] ?? null;

if ($day_id === null || $is_enabled === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters: day_id and is_enabled required']);
    exit;
}

$day_id = filter_var($day_id, FILTER_VALIDATE_INT);
$is_enabled = filter_var($is_enabled, FILTER_VALIDATE_INT);

if ($day_id === false || $day_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid day_id']);
    exit;
}

if (!in_array($is_enabled, [0, 1], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid is_enabled (must be 0 or 1)']);
    exit;
}

// --- Optional CSRF enforcement ---
$sessionCsrf = $_SESSION['csrf_token'] ?? null;
if ($sessionCsrf !== null) {
    // prefer header, fallback to JSON body token
    $headers = getallheaders();
    $sent = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $payload['csrf_token'] ?? null;
    if (!$sent || !hash_equals((string)$sessionCsrf, (string)$sent)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
}

// --- Ensure day exists ---
$selectSql = "SELECT id, label, day_date, is_enabled FROM function_room_days WHERE id = ? LIMIT 1";
if (!($selectStmt = $conn->prepare($selectSql))) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error (prepare select): ' . $conn->error]);
    exit;
}
$selectStmt->bind_param('i', $day_id);
$selectStmt->execute();
$result = $selectStmt->get_result();
if ($result->num_rows === 0) {
    $selectStmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Day not found']);
    exit;
}
$existing = $result->fetch_assoc();
$selectStmt->close();

// If value is already same, short-circuit (optional) include diagnostic meta
if ((int)$existing['is_enabled'] === (int)$is_enabled) {
    echo json_encode([
        'success' => true,
        'message' => 'No change',
        'day' => [
            'id' => (int)$existing['id'],
            'is_enabled' => (int)$existing['is_enabled']
        ],
        'changed' => false,
        'previous_is_enabled' => (int)$existing['is_enabled']
    ]);
    exit;
}

// --- Update record ---
$updateSql = "UPDATE function_room_days SET is_enabled = ? WHERE id = ?";
if (!($updateStmt = $conn->prepare($updateSql))) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error (prepare update): ' . $conn->error]);
    exit;
}

$updateStmt->bind_param('ii', $is_enabled, $day_id);

if (!$updateStmt->execute()) {
    $err = $updateStmt->error ?: $conn->error;
    $updateStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error (execute update): ' . $err]);
    exit;
}

$updateStmt->close();

// Optionally: log who changed it (you can add a simple audit log table later)

// --- Return success with current state ---
http_response_code(200);
echo json_encode([
    'success' => true,
    'day' => [
        'id' => (int)$day_id,
        'is_enabled' => (int)$is_enabled
    ],
    'changed' => true,
    'previous_is_enabled' => (int)$existing['is_enabled']
]);
exit;
