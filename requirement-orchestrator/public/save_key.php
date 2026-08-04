<?php
/**
 * Store / clear the logged-in user's API key (the "remember my key" checkbox).
 *
 * Called by fetch() from the landing key-gate. When remember=1 we encrypt and save
 * the key to the account (AES-256-GCM, via Auth::storeApiKey); when remember=0 we
 * forget any previously stored key. The key still lives in the browser tab's
 * sessionStorage for the current session either way — this only controls whether it
 * ALSO persists to the account for next login. Returns JSON.
 */
require_once __DIR__ . '/../src/Auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}

$remember = ($_POST['remember'] ?? '') === '1';
$apiKey   = trim($_POST['api_key'] ?? '');
$provider = ($_POST['provider'] ?? '') ?: null;

if (!Auth::keyStorageAvailable()) {
    // No APP_ENC_KEY configured — refuse rather than store in the clear.
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'storage_unavailable']);
    exit;
}

if ($remember && $apiKey !== '') {
    $ok = Auth::storeApiKey($apiKey, $provider);
    echo json_encode(['ok' => $ok, 'stored' => $ok]);
} else {
    Auth::clearApiKey();
    echo json_encode(['ok' => true, 'stored' => false]);
}
