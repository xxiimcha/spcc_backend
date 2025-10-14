<?php
function _get_client_ip(): string {
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $v = trim((string)$_SERVER[$k]);
            if (strpos($v, ',') !== false) $v = explode(',', $v)[0];
            return $v;
        }
    }
    return '';
}

function log_activity(mysqli $conn, string $module, string $action, string $description, ?int $referenceId = null, ?string $user = null): bool {
    $user = $user ?: 'System';
    $ip   = _get_client_ip();
    $ref  = isset($referenceId) ? (int)$referenceId : 'NULL';
    $module = $conn->real_escape_string($module);
    $action = $conn->real_escape_string($action);
    $description = $conn->real_escape_string($description);
    $user = $conn->real_escape_string($user);
    $ip = $conn->real_escape_string($ip);

    $sql = "INSERT INTO activity_logs (module, action, description, reference_id, user, ip)
            VALUES ('$module', '$action', '$description', $ref, '$user', '$ip')";
    if ($conn->query($sql)) return true;

    $sqlFallback = "INSERT INTO activity_logs (module, action, description, reference_id, user)
                    VALUES ('$module', '$action', '$description', $ref, '$user')";
    return $conn->query($sqlFallback);
}
