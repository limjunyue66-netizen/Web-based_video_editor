<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
api_init();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('The request could not be completed.', 405);
}

$ffmpeg = tool_path('ffmpeg');
$ffprobe = tool_path('ffprobe');
$ready = $ffmpeg !== null && $ffprobe !== null;
$version = $ready && $ffmpeg !== null ? tool_version_label($ffmpeg) : '';

json_ok($ready ? 'FFmpeg: Ready' : 'FFmpeg is unavailable.', [
    'ffmpeg' => $ffmpeg !== null,
    'ffprobe' => $ffprobe !== null,
    'version' => $version,
    'limits' => [
        'video' => max_bytes_for_kind('video'),
        'image' => max_bytes_for_kind('image'),
        'audio' => max_bytes_for_kind('audio'),
        'phpUpload' => effective_upload_ceiling(),
    ],
    'composition' => [
        'width' => COMPOSITION_WIDTH,
        'height' => COMPOSITION_HEIGHT,
        'fps' => COMPOSITION_FPS,
    ],
]);
