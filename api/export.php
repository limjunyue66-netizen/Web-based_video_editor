<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
api_init();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Invalid project data.', 405);
}

try {
    $project = sanitize_project(read_json_body());
    $job = create_export_job($project);
} catch (RuntimeException $e) {
    json_error(user_safe_message($e->getMessage()));
}
json_ok('Export started', ['job' => $job]);
