<?php
function handleCORS(array $allowedOrigins = [])
{
    if (!$allowedOrigins) {
        $allowedOrigins = [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'https://spcc-smartsched.vercel.app',
            'https://spcc-scheduler.site',
            'https://www.spcc-scheduler.site',
        ];
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $isAllowed = in_array($origin, $allowedOrigins, true);

    if ($isAllowed) {
        header("Access-Control-Allow-Origin: {$origin}");
        header("Vary: Origin");
        header("Access-Control-Allow-Credentials: true"); // only if you need cookies
    } else {
        header("Access-Control-Allow-Origin: *");
        header_remove("Access-Control-Allow-Credentials");
    }

    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Expose-Headers: Content-Type");
    header("Content-Type: application/json; charset=utf-8");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}
