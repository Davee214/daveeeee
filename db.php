<?php
declare(strict_types=1);

function db(): mysqli {
    static $connection = null;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = @new mysqli(
        getenv('LIBFLOW_DB_HOST') ?: '127.0.0.1',
        getenv('LIBFLOW_DB_USER') ?: 'root',
        getenv('LIBFLOW_DB_PASS') ?: '',
        getenv('LIBFLOW_DB_NAME') ?: 'libflow',
        (int)(getenv('LIBFLOW_DB_PORT') ?: 3306)
    );

    if ($connection->connect_errno) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database unavailable: ' . $connection->connect_error]);
        exit;
    }
    $connection->set_charset('utf8mb4');
    return $connection;
}
