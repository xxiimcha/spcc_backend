<?php
function handleCORS() {
    $allowed_origins = [
        'http://localhost:5174',
        'http://127.0.0.1:5500',
        'http://127.0.0.1:5501',
        'http://localhost:3000',
        'http://localhost:5173',
        'http://localhost:8080',
        'http://127.0.0.1:3000',
        'http://localhost:4173',
        'http://localhost:5000',
        'https://spcc-web.vercel.app',
        'https://spcc-smartsched.vercel.app',
        'https://spcc-scheduler.site'
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed_origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        header('Access-Control-Allow-Origin: *');
    }

    // IMPORTANT: allow the custom headers you actually send
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Role, X-Role');
    header('Access-Control-Max-Age: 86400'); // cache preflight for a day
    header('Vary: Origin');                  // for caches/CDNs
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}
