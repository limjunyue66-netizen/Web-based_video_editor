<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
api_init();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Export job not found.', 405);
}

$id = (string) ($_GET['id'] ?? '');
try {
    $job = read_export_status($id);
} catch (RuntimeException $e) {
    json_error(user_safe_message($e->getMessage()), 404);
}
json_ok('Export status', ['job' => $job]);
