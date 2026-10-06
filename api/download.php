<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
api_init();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Export job not found.', 405);
}

$id = (string) ($_GET['id'] ?? '');
try {
    $job = read_json_file(job_file($id));
    if ($job === null || ($job['status'] ?? '') !== 'done') {
        $job = $job === null ? null : refresh_job($job);
    }
    if ($job === null || ($job['status'] ?? '') !== 'done') {
        throw new RuntimeException('Export job not found.');
    }
    $path = export_output_path($id);
} catch (RuntimeException $e) {
    json_error(user_safe_message($e->getMessage()), 404);
}

$name = safe_download_name((string) ($job['name'] ?? 'video'));
$size = filesize($path);
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: video/mp4');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . (string) $size);
header('Cache-Control: no-store');
readfile($path);
exit;
