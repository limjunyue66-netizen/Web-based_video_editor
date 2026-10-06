<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
api_init();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    json_ok('Media library', ['media' => list_media()]);
}

if ($method !== 'POST') {
    json_error('The request could not be completed.', 405);
}

$body = read_json_body();
$action = (string) ($body['action'] ?? '');
if ($action !== 'delete') {
    json_error('Invalid project data.');
}
$id = (string) ($body['id'] ?? '');
$media = load_media($id);
if ($media === null) {
    json_error('Media not found.', 404);
}
delete_media_files($media);
json_ok('Media deleted', ['id' => $id]);
