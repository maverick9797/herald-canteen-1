<?php
// khalti_initiate.php — initiates Khalti payment via server-side API call
require_once '../includes/auth.php';
start_session();
session_security_check();
require '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['pending_payment'])) {
    echo json_encode(['error' => 'Session expired. Please try again.']);
    exit;
}
// RBAC: Only customers may initiate Khalti payment
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'customer') {
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$pending = $_SESSION['pending_payment'];

// Read JSON body
$body = json_decode(file_get_contents('php://input'), true);
$received_uuid = $body['transaction_uuid'] ?? '';

if ($received_uuid !== $pending['transaction_uuid']) {
    echo json_encode(['error' => 'Invalid transaction.']);
    exit;
}

// ── Khalti sandbox config ─────────────────────────────────────────────────────
$khalti_secret_key = "test_secret_key_f59e8b7d18b4499ca40f68195a846e9b";
$khalti_api_url    = "https://a.khalti.com/api/v2/epayment/initiate/";

$return_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/khalti_verify.php";
$website_url = "http://" . $_SERVER['HTTP_HOST'];

$payload = [
    "return_url"       => $return_url,
    "website_url"      => $website_url,
    "amount"           => (int)($pending['total'] * 100), // paisa
    "purchase_order_id"=> $pending['transaction_uuid'],
    "purchase_order_name" => "Herald Canteen Order",
    "customer_info"    => [
        "name"  => $_SESSION['full_name'],
    ],
];

// ── cURL to Khalti ────────────────────────────────────────────────────────────
$ch = curl_init($khalti_api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        "Authorization: Key $khalti_secret_key",
        "Content-Type: application/json",
    ],
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($http_code === 200 && isset($result['payment_url'])) {
    // Save pidx in session for verification later
    $_SESSION['pending_payment']['khalti_pidx'] = $result['pidx'];
    echo json_encode(['payment_url' => $result['payment_url']]);
} else {
    error_log("Khalti initiation failed: " . $response);
    echo json_encode(['error' => $result['detail'] ?? 'Khalti initiation failed.']);
}
