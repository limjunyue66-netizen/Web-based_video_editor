<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
api_init();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Upload failed.', 405);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 0 && empty($_FILES)) {
    json_error('File is too large.', 413);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_error('Upload failed.');
}

$file = $_FILES['file'];
$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
    json_error('File is too large.', 413);
}
if ($error !== UPLOAD_ERR_OK) {
    json_error('Upload failed.');
}

$tmp = (string) ($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    json_error('Upload failed.');
}

$original = clean_display_name((string) ($file['name'] ?? 'file'));
$extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
$kind = kind_from_extension($extension);
if ($kind === null) {
    json_error('Unsupported file type.');
}

$size = (int) ($file['size'] ?? 0);
if ($size <= 0) {
    json_error('Upload failed.');
}
if ($size > max_bytes_for_kind($kind)) {
    json_error('File is too large.', 413);
}

$mime = detect_mime($tmp);
if ($mime === 'application/x-php' || $mime === 'text/x-php' || $mime === 'application/x-httpd-php' || str_contains($mime, 'executable')) {
    json_error('Unsupported file type.');
}
if (!mime_allowed($kind, $mime)) {
    json_error('Unsupported file type.');
}
if ($mime === 'application/octet-stream' && $kind === 'image') {
    json_error('Unsupported file type.');
}

$id = new_id();
$storedExt = $extension === 'jpeg' ? 'jpg' : $extension;
$relative = match ($kind) {
    'video' => 'videos/' . $id . '.' . $storedExt,
    'image' => 'images/' . $id . '.' . $storedExt,
    default => 'audio/' . $id . '.' . $storedExt,
};
$destination = upload_directory() . '/' . $relative;
if (!move_uploaded_file($tmp, $destination)) {
    json_error('Upload failed.');
}
@chmod($destination, 0644);

try {
    $duration = null;
    $width = null;
    $height = null;
    $hasAudio = false;
    $hasVideo = false;
    $thumbRelative = '';
    $previewRelative = '';

    if ($kind === 'image') {
        $info = @getimagesize($destination);
        if (!is_array($info)) {
            if (ffmpeg_ready()) {
                $probe = probe_media_file($destination);
                $width = $probe['width'] ?: null;
                $height = $probe['height'] ?: null;
            }
        } else {
            $width = (int) $info[0];
            $height = (int) $info[1];
        }
        if (!$width || !$height) {
            throw new RuntimeException('Media could not be analysed.');
        }
        $hasVideo = false;
        $hasAudio = false;
    } else {
        if (!ffmpeg_ready()) {
            throw new RuntimeException('FFmpeg is unavailable.');
        }
        $probe = probe_media_file($destination);
        if ($kind === 'video' && !$probe['hasVideo']) {
            throw new RuntimeException('Media could not be analysed.');
        }
        if ($kind === 'audio' && !$probe['hasAudio']) {
            throw new RuntimeException('Media could not be analysed.');
        }
        if ($probe['duration'] < MIN_CLIP_SECONDS) {
            throw new RuntimeException('Media could not be analysed.');
        }
        $duration = $probe['duration'];
        $width = $probe['width'] ?: null;
        $height = $probe['height'] ?: null;
        $hasAudio = $probe['hasAudio'];
        $hasVideo = $probe['hasVideo'];

        if ($kind === 'video') {
            $thumbRelative = 'thumbs/' . $id . '.jpg';
            make_thumbnail($destination, upload_directory() . '/' . $thumbRelative, $probe['duration']);
            if (!is_file(upload_directory() . '/' . $thumbRelative)) {
                $thumbRelative = '';
            }
            $browserSafe = in_array($storedExt, ['mp4', 'webm'], true)
                && in_array(strtolower((string) $probe['codec']), ['h264', 'vp8', 'vp9', 'av1'], true);
            if (!$browserSafe) {
                $previewRelative = 'previews/' . $id . '.mp4';
                $ok = make_preview_proxy($destination, upload_directory() . '/' . $previewRelative);
                if (!$ok) {
                    $previewRelative = '';
                }
            }
        }
    }

    $url = 'uploads/' . $relative;
    $media = [
        'id' => $id,
        'name' => $original,
        'type' => $kind,
        'ext' => $storedExt,
        'mime' => $mime,
        'size' => $size,
        'duration' => $duration,
        'width' => $width,
        'height' => $height,
        'hasAudio' => $hasAudio,
        'hasVideo' => $hasVideo,
        'file' => $relative,
        'thumb' => $thumbRelative,
        'preview' => $previewRelative,
        'url' => $url,
        'previewUrl' => $previewRelative !== '' ? ('uploads/' . $previewRelative) : $url,
        'thumbUrl' => $thumbRelative !== '' ? ('uploads/' . $thumbRelative) : '',
        'createdAt' => date('c'),
    ];
    save_media_meta($media);
    json_ok('Upload successful', ['media' => public_media($media)]);
} catch (Throwable $e) {
    if (is_file($destination)) {
        @unlink($destination);
    }
    $message = user_safe_message($e->getMessage());
    if ($message === 'The request could not be completed.') {
        log_error($e->getMessage());
        $message = 'Media could not be analysed.';
    }
    json_error($message);
}
