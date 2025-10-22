<?php
// cors_helper.php

function handleCORS(): void {
  // Accept any origin during development; tighten to your domain(s) in prod.
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

  // If you want to allow only specific origins, do it like:
  // $allowed = ['http://localhost:5173', 'https://your-frontend.com'];
  // $origin = in_array($origin, $allowed, true) ? $origin : 'https://your-frontend.com';

  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin'); // so caches don't mix origins
  header('Access-Control-Allow-Credentials: false');

  // Methods your API supports
  header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

  // All headers you read in PHP or set from the client
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Role, X-Role');

  // Keep preflight results for a while
  header('Access-Control-Max-Age: 86400');

  // Respond to preflight cleanly
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    // Send an explicit length and type (some proxies are picky)
    header('Content-Length: 0');
    header('Content-Type: text/plain; charset=utf-8');
    exit();
  }
}
