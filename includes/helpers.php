<?php
declare(strict_types=1);

function json_response(array $data, int $code = 200): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(string $message, array $extra = []): never
{
    json_response(array_merge(['ok' => true, 'message' => $message], $extra));
}

function json_error(string $message, int $code = 400, array $extra = []): never
{
    json_response(array_merge(['ok' => false, 'message' => $message], $extra), $code);
}

function log_error(string $message): void
{
    $dir = app_root() . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = date('c') . ' ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'error.log', $line, FILE_APPEND | LOCK_EX);
}

function user_safe_message(string $message): string
{
    $known = [
        'Unsupported file type.',
        'File is too large.',
        'Upload failed.',
        'FFmpeg is unavailable.',
        'FFprobe is unavailable.',
        'Media could not be analysed.',
        'Export failed.',
        'Export job not found.',
        'Invalid project data.',
        'Invalid project JSON.',
        'Invalid media path.',
        'Missing media.',
        'Empty project.',
        'Media not found.',
        'Project has too many elements.',
        'A required font file is unavailable on the server.',
        'Processing timed out.',
    ];
    return in_array($message, $known, true) ? $message : 'The request could not be completed.';
}

function api_init(): void
{
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    error_reporting(E_ALL);
    ensure_storage();

    set_exception_handler(static function (Throwable $e): void {
        log_error($e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        if (!headers_sent()) {
            json_error(user_safe_message($e->getMessage()), 500);
        }
    });

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

function ensure_storage(): void
{
    $dirs = [
        upload_directory() . '/videos',
        upload_directory() . '/images',
        upload_directory() . '/audio',
        upload_directory() . '/thumbs',
        upload_directory() . '/previews',
        upload_directory() . '/meta',
        export_directory(),
        export_directory() . '/jobs',
        app_root() . '/logs',
        app_root() . '/bin',
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Upload failed.');
        }
    }
}

function new_id(): string
{
    return bin2hex(random_bytes(8));
}

function is_safe_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
}

function read_json_body(int $maxBytes = 8388608): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        json_error('Invalid project data.');
    }
    if (strlen($raw) > $maxBytes) {
        json_error('Invalid project data.');
    }
    try {
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        json_error('Invalid project JSON.');
    }
    if (!is_array($data)) {
        json_error('Invalid project data.');
    }
    return $data;
}

function write_json_file(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Invalid project data.');
    }
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        throw new RuntimeException('Export failed.');
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Export failed.');
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $json);
        fflush($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
}

function read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    try {
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function clean_display_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'file';
    $name = trim($name);
    if (strlen($name) > 140) {
        $name = substr($name, 0, 140);
    }
    return $name !== '' ? $name : 'file';
}

function safe_download_name(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'video';
    $name = trim($name, '._');
    if ($name === '') {
        $name = 'video';
    }
    if (!str_ends_with(strtolower($name), '.mp4')) {
        $name .= '.mp4';
    }
    return substr($name, 0, 80);
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    return match ($unit) {
        'g' => (int) round($number * 1024 * 1024 * 1024),
        'm' => (int) round($number * 1024 * 1024),
        'k' => (int) round($number * 1024),
        default => (int) round($number),
    };
}

function effective_upload_ceiling(): int
{
    $upload = ini_bytes((string) ini_get('upload_max_filesize'));
    $post = ini_bytes((string) ini_get('post_max_size'));
    $limits = array_filter([$upload, $post], static fn (int $n): bool => $n > 0);
    return $limits === [] ? MAX_VIDEO_SIZE : min($limits);
}

function clamp_float(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function clamp_int(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

function num_value(mixed $value, float $fallback = 0.0): float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    if (is_string($value) && is_numeric($value)) {
        return (float) $value;
    }
    return $fallback;
}

function ffnum(float $value): string
{
    if (!is_finite($value)) {
        return '0';
    }
    $text = sprintf('%.4F', $value);
    $text = rtrim(rtrim($text, '0'), '.');
    return $text === '' || $text === '-' ? '0' : $text;
}

function even_int(int $value, int $min = 2): int
{
    if ($value < $min) {
        $value = $min;
    }
    if ($value % 2 !== 0) {
        $value--;
    }
    if ($value < $min) {
        $value = ($min % 2 === 0) ? $min : $min + 1;
    }
    return $value;
}

function inside_directory(string $path, string $directory): bool
{
    $real = realpath($path);
    $root = realpath($directory);
    if ($real === false || $root === false) {
        return false;
    }
    $real = rtrim(strtolower(str_replace('\\', '/', $real)), '/');
    $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');
    return $real === $root || str_starts_with($real, $root . '/');
}

function app_asset(string $relative): string
{
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    $full = app_root() . '/' . $relative;
    $version = is_file($full) ? (string) filemtime($full) : '1';
    return htmlspecialchars($relative . '?v=' . $version, ENT_QUOTES);
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / (1024 * 1024), 1) . ' MB';
}
