<?php
declare(strict_types=1);

function jobs_directory(): string
{
    return export_directory() . DIRECTORY_SEPARATOR . 'jobs';
}

function job_directory(string $id): string
{
    if (!is_safe_id($id)) {
        throw new RuntimeException('Export job not found.');
    }
    return jobs_directory() . DIRECTORY_SEPARATOR . $id;
}

function job_file(string $id): string
{
    return job_directory($id) . DIRECTORY_SEPARATOR . 'job.json';
}

function create_export_job(array $project): array
{
    cleanup_old_jobs();
    $ffmpeg = tool_path('ffmpeg');
    $ffprobe = tool_path('ffprobe');
    if ($ffmpeg === null) {
        throw new RuntimeException('FFmpeg is unavailable.');
    }
    if ($ffprobe === null) {
        throw new RuntimeException('FFprobe is unavailable.');
    }

    $id = new_id();
    $dir = job_directory($id);
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Export failed.');
    }

    $plan = build_export_plan($project, $dir);
    $filterPath = $dir . DIRECTORY_SEPARATOR . 'filter.txt';
    file_put_contents($filterPath, $plan['filter']);

    $output = export_directory() . DIRECTORY_SEPARATOR . $id . '.mp4';
    $progress = $dir . DIRECTORY_SEPARATOR . 'progress.txt';
    $high = $project['exportMode'] === 'high';
    $args = array_merge(
        ['-y', '-hide_banner', '-loglevel', 'warning', '-progress', $progress, '-nostats'],
        $plan['args'],
        [
            filter_complex_flag($ffmpeg),
            $filterPath,
            '-map', '[vout]',
            '-map', '[aout]',
            '-c:v', 'libx264',
            '-preset', $high ? 'medium' : 'veryfast',
            '-crf', $high ? '18' : '26',
            '-pix_fmt', 'yuv420p',
            '-color_range', 'tv',
            '-movflags', '+faststart',
            '-c:a', 'aac',
            '-b:a', $high ? '192k' : '128k',
            '-ar', '48000',
            '-ac', '2',
            '-t', ffnum($plan['duration']),
            $output,
        ]
    );

    $job = [
        'id' => $id,
        'status' => 'running',
        'progress' => 0,
        'processedTime' => 0,
        'message' => 'Encoding video',
        'error' => '',
        'duration' => $plan['duration'],
        'name' => $project['name'],
        'createdAt' => time(),
        'startedAt' => time(),
        'finishedAt' => null,
    ];
    write_json_file(job_file($id), $job);
    spawn_export($ffmpeg, $args, $dir);
    return public_job($job);
}

function spawn_export(string $ffmpeg, array $args, string $jobDir): void
{
    $log = $jobDir . DIRECTORY_SEPARATOR . 'log.txt';
    $exitFile = $jobDir . DIRECTORY_SEPARATOR . 'exitcode.txt';
    $quoted = array_map(static fn (string $arg): string => escapeshellarg($arg), array_merge([$ffmpeg], $args));
    $command = implode(' ', $quoted);

    if (PHP_OS_FAMILY === 'Windows') {
        $bat = $jobDir . DIRECTORY_SEPARATOR . 'run.bat';
        $script = "@echo off\r\n" . $command . ' 1> ' . escapeshellarg($log) . " 2>&1\r\n"
            . 'echo %ERRORLEVEL%> ' . escapeshellarg($exitFile) . "\r\n";
        file_put_contents($bat, $script);
        $launch = 'cmd.exe /c start "" /b ' . escapeshellarg($bat);
        $handle = @popen($launch, 'r');
        if (is_resource($handle)) {
            pclose($handle);
        }
        return;
    }

    $shell = $jobDir . '/run.sh';
    $script = "#!/bin/sh\n" . $command . ' > ' . escapeshellarg($log) . " 2>&1\n"
        . 'echo $? > ' . escapeshellarg($exitFile) . "\n";
    file_put_contents($shell, $script);
    @chmod($shell, 0755);
    exec(escapeshellarg($shell) . ' > /dev/null 2>&1 &');
}

function read_export_status(string $id): array
{
    if (!is_safe_id($id)) {
        throw new RuntimeException('Export job not found.');
    }
    $job = read_json_file(job_file($id));
    if ($job === null) {
        throw new RuntimeException('Export job not found.');
    }
    if (($job['status'] ?? '') === 'running') {
        $job = refresh_job($job);
        write_json_file(job_file($id), $job);
    }
    return public_job($job);
}

function refresh_job(array $job): array
{
    $id = (string) $job['id'];
    $dir = job_directory($id);
    $progress = parse_progress($dir . DIRECTORY_SEPARATOR . 'progress.txt');
    $duration = max(0.1, (float) ($job['duration'] ?? 0.1));
    $job['processedTime'] = $progress['time'];
    $percent = min(99, ($progress['time'] / $duration) * 100);
    $output = export_directory() . DIRECTORY_SEPARATOR . $id . '.mp4';
    $exitFile = $dir . DIRECTORY_SEPARATOR . 'exitcode.txt';
    $exitCode = null;
    if (is_file($exitFile)) {
        $exitCode = (int) trim((string) file_get_contents($exitFile));
    }
    $elapsed = time() - (int) ($job['startedAt'] ?? time());
    $logPath = $dir . DIRECTORY_SEPARATOR . 'log.txt';
    $progressExists = is_file($dir . DIRECTORY_SEPARATOR . 'progress.txt');
    if ($exitCode === null && $elapsed > 30 && !$progressExists && (!is_file($logPath) || filesize($logPath) === 0)) {
        $job['status'] = 'error';
        $job['progress'] = 0;
        $job['message'] = 'Export failed';
        $job['error'] = 'Export failed.';
        $job['finishedAt'] = time();
        log_error('export did not start ' . $id);
        return $job;
    }
    $fileReady = is_file($output) && filesize($output) > 1024;
    if (($exitCode === 0 && $fileReady) || ($progress['end'] && $fileReady && $exitCode === null)) {
        $job['status'] = 'done';
        $job['progress'] = 100;
        $job['message'] = 'Export complete';
        $job['error'] = '';
        $job['finishedAt'] = time();
        return $job;
    }
    if ($exitCode !== null && $exitCode !== 0) {
        $job['status'] = 'error';
        $job['progress'] = (int) round($percent);
        $job['message'] = 'Export failed';
        $job['error'] = 'Export failed.';
        $job['finishedAt'] = time();
        log_error('export ' . $id . ' exit ' . $exitCode);
        $log = $dir . DIRECTORY_SEPARATOR . 'log.txt';
        if (is_file($log)) {
            log_error('export log: ' . substr((string) file_get_contents($log), -800));
        }
        if (is_file($output)) {
            @unlink($output);
        }
        return $job;
    }
    if ($progress['end'] && !$fileReady && $exitCode === 0) {
        $job['status'] = 'error';
        $job['message'] = 'Export failed';
        $job['error'] = 'Export failed.';
        $job['finishedAt'] = time();
        return $job;
    }
    $job['status'] = 'running';
    $job['progress'] = round($percent, 1);
    $job['message'] = 'Encoding video';
    return $job;
}

function parse_progress(string $path): array
{
    if (!is_file($path)) {
        return ['time' => 0.0, 'end' => false];
    }
    $text = (string) file_get_contents($path);
    $time = 0.0;
    if (preg_match_all('/out_time=(\d+):(\d+):(\d+(?:\.\d+)?)/', $text, $matches, PREG_SET_ORDER)) {
        $last = $matches[count($matches) - 1];
        $time = ((int) $last[1] * 3600) + ((int) $last[2] * 60) + (float) $last[3];
    }
    $end = false;
    if (preg_match_all('/progress=(\w+)/', $text, $flags)) {
        $end = end($flags[1]) === 'end';
    }
    return ['time' => $time, 'end' => $end];
}

function public_job(array $job): array
{
    $id = (string) ($job['id'] ?? '');
    $status = (string) ($job['status'] ?? 'queued');
    $done = $status === 'done';
    return [
        'id' => $id,
        'status' => $status,
        'progress' => (float) ($job['progress'] ?? 0),
        'processedTime' => (float) ($job['processedTime'] ?? 0),
        'elapsed' => max(0, time() - (int) ($job['startedAt'] ?? time())),
        'message' => (string) ($job['message'] ?? ''),
        'error' => (string) ($job['error'] ?? ''),
        'downloadUrl' => $done ? ('api/download.php?id=' . rawurlencode($id)) : '',
    ];
}

function export_output_path(string $id): string
{
    if (!is_safe_id($id)) {
        throw new RuntimeException('Export job not found.');
    }
    $path = export_directory() . DIRECTORY_SEPARATOR . $id . '.mp4';
    if (!is_file($path) || !inside_directory($path, export_directory())) {
        throw new RuntimeException('Export job not found.');
    }
    return $path;
}

function cleanup_old_jobs(): void
{
    $root = jobs_directory();
    $cutoff = time() - (48 * 3600);
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $mtime = filemtime($dir);
        if ($mtime === false || $mtime > $cutoff) {
            continue;
        }
        $id = basename($dir);
        if (!is_safe_id($id)) {
            continue;
        }
        $mp4 = export_directory() . DIRECTORY_SEPARATOR . $id . '.mp4';
        if (is_file($mp4)) {
            @unlink($mp4);
        }
        delete_directory($dir);
    }
}

function delete_directory(string $dir): void
{
    if (!is_dir($dir) || !inside_directory($dir, jobs_directory())) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            delete_directory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
