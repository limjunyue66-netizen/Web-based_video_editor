<?php
declare(strict_types=1);

function tool_path(string $tool): ?string
{
    if (!in_array($tool, ['ffmpeg', 'ffprobe'], true)) {
        return null;
    }
    $constant = $tool === 'ffmpeg' ? 'FFMPEG_PATH' : 'FFPROBE_PATH';
    if (defined($constant)) {
        $custom = (string) constant($constant);
        if ($custom !== '' && is_file($custom)) {
            return $custom;
        }
    }
    $names = PHP_OS_FAMILY === 'Windows' ? [$tool . '.exe', $tool] : [$tool];
    foreach ($names as $name) {
        $candidate = app_root() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    $lookup = PHP_OS_FAMILY === 'Windows' ? 'where.exe ' . $tool : 'command -v ' . escapeshellarg($tool);
    $lines = [];
    $code = 1;
    @exec($lookup, $lines, $code);
    if ($code === 0) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && is_file($line)) {
                return $line;
            }
        }
    }
    return null;
}

function ffmpeg_ready(): bool
{
    return tool_path('ffmpeg') !== null && tool_path('ffprobe') !== null;
}

/**
 * @param list<string> $args
 * @return array{code:int,stdout:string,stderr:string}
 */
function run_process(array $args, int $timeout = 120): array
{
    if ($args === []) {
        throw new RuntimeException('Export failed.');
    }
    $command = '';
    foreach ($args as $arg) {
        $command .= escapeshellarg((string) $arg) . ' ';
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Export failed.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = time();
    $timedOut = false;
    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if ((time() - $started) > $timeout) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(80000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($timedOut) {
        throw new RuntimeException('Processing timed out.');
    }
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
}

function tool_version_label(string $path): string
{
    try {
        $result = run_process([$path, '-version'], 20);
    } catch (Throwable) {
        return '';
    }
    $text = $result['stdout'] . "\n" . $result['stderr'];
    if (preg_match('/(?:ffmpeg|ffprobe) version\s+(\S+)/i', $text, $match)) {
        return $match[1];
    }
    return '';
}

function filter_complex_flag(string $ffmpeg): string
{
    $cache = app_root() . '/logs/filter_flag.txt';
    if (is_file($cache)) {
        $saved = trim((string) file_get_contents($cache));
        if (in_array($saved, ['-filter_complex_script', '-/filter_complex'], true)) {
            return $saved;
        }
    }
    $result = run_process([$ffmpeg, '-hide_banner', '-h', 'long'], 20);
    $help = $result['stdout'] . $result['stderr'];
    $flag = str_contains($help, 'filter_complex_script') ? '-filter_complex_script' : '-/filter_complex';
    @file_put_contents($cache, $flag);
    return $flag;
}

function probe_media_file(string $path): array
{
    $ffprobe = tool_path('ffprobe');
    if ($ffprobe === null) {
        throw new RuntimeException('FFprobe is unavailable.');
    }
    $result = run_process([
        $ffprobe,
        '-v', 'error',
        '-print_format', 'json',
        '-show_format',
        '-show_streams',
        $path,
    ], 60);
    $data = json_decode($result['stdout'], true);
    if (!is_array($data)) {
        log_error('ffprobe failed: ' . substr($result['stderr'], 0, 500));
        throw new RuntimeException('Media could not be analysed.');
    }
    $width = 0;
    $height = 0;
    $hasAudio = false;
    $hasVideo = false;
    $codec = '';
    $duration = (float) ($data['format']['duration'] ?? 0);
    foreach ($data['streams'] ?? [] as $stream) {
        if (!is_array($stream)) {
            continue;
        }
        $type = (string) ($stream['codec_type'] ?? '');
        $attached = (int) ($stream['disposition']['attached_pic'] ?? 0) === 1;
        if ($type === 'video' && !$attached) {
            $hasVideo = true;
            $width = (int) ($stream['width'] ?? $width);
            $height = (int) ($stream['height'] ?? $height);
            $codec = (string) ($stream['codec_name'] ?? $codec);
            if ($duration <= 0 && isset($stream['duration'])) {
                $duration = (float) $stream['duration'];
            }
        }
        if ($type === 'audio') {
            $hasAudio = true;
            if ($duration <= 0 && isset($stream['duration'])) {
                $duration = (float) $stream['duration'];
            }
        }
    }
    return [
        'duration' => $duration > 0 ? $duration : 0.0,
        'width' => $width,
        'height' => $height,
        'hasAudio' => $hasAudio,
        'hasVideo' => $hasVideo,
        'codec' => $codec,
    ];
}

function make_thumbnail(string $source, string $destination, float $duration): void
{
    $ffmpeg = tool_path('ffmpeg');
    if ($ffmpeg === null) {
        return;
    }
    $seek = $duration > 0.6 ? 0.4 : 0.0;
    $result = run_process([
        $ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
        '-ss', ffnum($seek),
        '-i', $source,
        '-frames:v', '1',
        '-vf', 'scale=320:-2',
        $destination,
    ], 60);
    if ($result['code'] !== 0 || !is_file($destination)) {
        log_error('thumbnail failed');
        if (is_file($destination)) {
            @unlink($destination);
        }
    }
}

function make_preview_proxy(string $source, string $destination): bool
{
    $ffmpeg = tool_path('ffmpeg');
    if ($ffmpeg === null) {
        return false;
    }
    $result = run_process([
        $ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
        '-i', $source,
        '-vf', 'scale=min(960\,iw):-2',
        '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28',
        '-pix_fmt', 'yuv420p',
        '-c:a', 'aac', '-b:a', '128k',
        '-movflags', '+faststart',
        $destination,
    ], 240);
    if ($result['code'] !== 0 || !is_file($destination) || filesize($destination) < 256) {
        log_error('preview proxy failed: ' . substr($result['stderr'], 0, 400));
        if (is_file($destination)) {
            @unlink($destination);
        }
        return false;
    }
    return true;
}
