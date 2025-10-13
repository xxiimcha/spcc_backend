<?php
declare(strict_types=1);

function ss_get_setting(mysqli $conn, string $key): ?string {
    if ($key === '') return null;

    $safeKey = mysqli_real_escape_string($conn, $key);
    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = '$safeKey' LIMIT 1";
    $result = $conn->query($sql);

    if (!$result || $result->num_rows === 0) return null;

    $row = $result->fetch_assoc();
    return isset($row['setting_value']) ? (string)$row['setting_value'] : null;
}

function ss_get_settings(mysqli $conn, array $keys): array {
    $cleanKeys = array_values(array_unique(array_filter(array_map('trim', $keys), fn($k) => $k !== '')));
    if (empty($cleanKeys)) return [];

    $escaped = array_map(fn($k) => "'" . mysqli_real_escape_string($conn, $k) . "'", $cleanKeys);
    $inList = implode(',', $escaped);

    $sql = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($inList)";
    $result = $conn->query($sql);

    $out = array_fill_keys($cleanKeys, null);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = (string)$row['setting_key'];
            $out[$key] = isset($row['setting_value']) ? (string)$row['setting_value'] : null;
        }
    }

    return $out;
}

function ss_get_current_school_year(mysqli $conn): ?string {
    return ss_get_setting($conn, 'current_school_year');
}

function ss_get_current_semester(mysqli $conn): ?string {
    return ss_get_setting($conn, 'current_semester');
}

function ss_get_current_school_info(mysqli $conn): array {
    $settings = ss_get_settings($conn, ['current_school_year', 'current_semester']);
    return [
        'school_year' => $settings['current_school_year'] ?? null,
        'semester' => $settings['current_semester'] ?? null,
    ];
}
