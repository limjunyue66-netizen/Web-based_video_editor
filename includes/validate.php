<?php
declare(strict_types=1);

const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'wmv'];
const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
const AUDIO_EXTENSIONS = ['mp3', 'wav', 'aac', 'm4a'];

const VIDEO_MIMES = [
    'video/mp4',
    'video/webm',
    'video/quicktime',
    'video/x-ms-wmv',
    'video/x-ms-asf',
    'video/x-msvideo',
    'application/octet-stream',
];

const IMAGE_MIMES = [
    'image/jpeg',
    'image/png',
    'image/webp',
];

const AUDIO_MIMES = [
    'audio/mpeg',
    'audio/mp3',
    'audio/wav',
    'audio/x-wav',
    'audio/wave',
    'audio/aac',
    'audio/x-aac',
    'audio/mp4',
    'audio/x-m4a',
    'audio/m4a',
    'application/octet-stream',
];

function kind_from_extension(string $extension): ?string
{
    $extension = strtolower($extension);
    if (in_array($extension, VIDEO_EXTENSIONS, true)) {
        return 'video';
    }
    if (in_array($extension, IMAGE_EXTENSIONS, true)) {
        return 'image';
    }
    if (in_array($extension, AUDIO_EXTENSIONS, true)) {
        return 'audio';
    }
    return null;
}

function mime_allowed(string $kind, string $mime): bool
{
    $mime = strtolower(trim($mime));
    $list = match ($kind) {
        'video' => VIDEO_MIMES,
        'image' => IMAGE_MIMES,
        'audio' => AUDIO_MIMES,
        default => [],
    };
    return in_array($mime, $list, true);
}

function max_bytes_for_kind(string $kind): int
{
    $configured = match ($kind) {
        'video' => MAX_VIDEO_SIZE,
        'image' => MAX_IMAGE_SIZE,
        'audio' => MAX_AUDIO_SIZE,
        default => MAX_IMAGE_SIZE,
    };
    $ceiling = effective_upload_ceiling();
    return $ceiling > 0 ? min($configured, $ceiling) : $configured;
}

function detect_mime(string $path): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
}

function meta_file(string $id): string
{
    if (!is_safe_id($id)) {
        throw new RuntimeException('Invalid media path.');
    }
    return upload_directory() . '/meta/' . $id . '.json';
}

function save_media_meta(array $media): void
{
    $id = (string) ($media['id'] ?? '');
    write_json_file(meta_file($id), $media);
}

function load_media(string $id): ?array
{
    if (!is_safe_id($id)) {
        return null;
    }
    $path = upload_directory() . '/meta/' . $id . '.json';
    $data = read_json_file($path);
    return is_array($data) ? $data : null;
}

function list_media(): array
{
    $dir = upload_directory() . '/meta';
    $items = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $data = read_json_file($file);
        if (!is_array($data) || !isset($data['id'])) {
            continue;
        }
        $items[] = public_media($data);
    }
    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });
    return $items;
}

function public_media(array $media): array
{
    return [
        'id' => $media['id'],
        'name' => $media['name'],
        'type' => $media['type'],
        'ext' => $media['ext'],
        'size' => (int) ($media['size'] ?? 0),
        'duration' => isset($media['duration']) ? (float) $media['duration'] : null,
        'width' => isset($media['width']) ? (int) $media['width'] : null,
        'height' => isset($media['height']) ? (int) $media['height'] : null,
        'hasAudio' => (bool) ($media['hasAudio'] ?? false),
        'hasVideo' => (bool) ($media['hasVideo'] ?? false),
        'url' => (string) ($media['url'] ?? ''),
        'previewUrl' => (string) ($media['previewUrl'] ?? ($media['url'] ?? '')),
        'thumbUrl' => (string) ($media['thumbUrl'] ?? ''),
        'createdAt' => (string) ($media['createdAt'] ?? ''),
    ];
}

function media_absolute_path(array $media, string $field = 'file'): string
{
    $relative = str_replace('\\', '/', (string) ($media[$field] ?? ''));
    if (!preg_match('#^(videos|images|audio|previews|thumbs)/[a-f0-9]{16}\.[a-z0-9]+$#', $relative)) {
        throw new RuntimeException('Invalid media path.');
    }
    $full = upload_directory() . '/' . $relative;
    if (!is_file($full) || !inside_directory($full, upload_directory())) {
        throw new RuntimeException('Missing media.');
    }
    $real = realpath($full);
    if ($real === false) {
        throw new RuntimeException('Missing media.');
    }
    return $real;
}

function delete_media_files(array $media): void
{
    foreach (['file', 'thumb', 'preview'] as $field) {
        $relative = (string) ($media[$field] ?? '');
        if ($relative === '') {
            continue;
        }
        try {
            $path = media_absolute_path($media, $field === 'file' ? 'file' : ($field === 'thumb' ? 'thumb' : 'preview'));
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable) {
            continue;
        }
    }
    $id = (string) ($media['id'] ?? '');
    if (is_safe_id($id)) {
        $meta = upload_directory() . '/meta/' . $id . '.json';
        if (is_file($meta)) {
            @unlink($meta);
        }
    }
}

function font_file_for(string $family, bool $bold, bool $italic): string
{
    $families = [
        'Arial' => ['arial.ttf', 'arialbd.ttf', 'ariali.ttf', 'arialbi.ttf'],
        'Verdana' => ['verdana.ttf', 'verdanab.ttf', 'verdanai.ttf', 'verdanaz.ttf'],
        'Georgia' => ['georgia.ttf', 'georgiab.ttf', 'georgiai.ttf', 'georgiaz.ttf'],
        'Times New Roman' => ['times.ttf', 'timesbd.ttf', 'timesi.ttf', 'timesbi.ttf'],
        'Courier New' => ['cour.ttf', 'courbd.ttf', 'couri.ttf', 'courbi.ttf'],
    ];
    if (!isset($families[$family])) {
        $family = 'Arial';
    }
    $index = ($bold ? 1 : 0) + ($italic ? 2 : 0);
    $candidates = [$families[$family][$index], $families[$family][0], 'arial.ttf'];
    $windows = getenv('WINDIR') ?: 'C:\\Windows';
    foreach ($candidates as $file) {
        $winPath = $windows . DIRECTORY_SEPARATOR . 'Fonts' . DIRECTORY_SEPARATOR . $file;
        if (is_file($winPath)) {
            return $winPath;
        }
    }
    $linux = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
    ];
    foreach ($linux as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    throw new RuntimeException('A required font file is unavailable on the server.');
}

function sanitize_project(array $input): array
{
    $name = trim((string) ($input['name'] ?? 'Untitled Project'));
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'Untitled Project';
    if ($name === '' || strlen($name) > 80) {
        $name = 'Untitled Project';
    }

    $fps = (int) ($input['fps'] ?? COMPOSITION_FPS);
    if (!in_array($fps, [24, 25, 30, 60], true)) {
        $fps = COMPOSITION_FPS;
    }

    $width = even_int(clamp_int((int) ($input['width'] ?? COMPOSITION_WIDTH), 320, 1920), 320);
    $height = even_int(clamp_int((int) ($input['height'] ?? COMPOSITION_HEIGHT), 320, 1920), 320);

    $filtersIn = is_array($input['filters'] ?? null) ? $input['filters'] : [];
    $filters = [
        'brightness' => clamp_float(num_value($filtersIn['brightness'] ?? 0), -100, 100),
        'contrast' => clamp_float(num_value($filtersIn['contrast'] ?? 0), -100, 100),
        'saturation' => clamp_float(num_value($filtersIn['saturation'] ?? 0), -100, 100),
        'grayscale' => clamp_float(num_value($filtersIn['grayscale'] ?? 0), 0, 100),
        'blur' => clamp_float(num_value($filtersIn['blur'] ?? 0), 0, 20),
        'sepia' => clamp_float(num_value($filtersIn['sepia'] ?? 0), 0, 100),
    ];

    $clips = sanitize_clips($input['clips'] ?? []);
    $images = sanitize_images($input['images'] ?? []);
    $texts = sanitize_texts($input['texts'] ?? []);
    $audios = sanitize_audios($input['audios'] ?? []);

    if (count($clips) + count($images) + count($texts) + count($audios) > 80) {
        throw new RuntimeException('Project has too many elements.');
    }

    $mode = (($input['exportMode'] ?? 'fast') === 'high') ? 'high' : 'fast';

    return [
        'name' => $name,
        'fps' => $fps,
        'width' => $width,
        'height' => $height,
        'clips' => $clips,
        'images' => $images,
        'texts' => $texts,
        'audios' => $audios,
        'filters' => $filters,
        'muteOriginal' => (bool) ($input['muteOriginal'] ?? false),
        'musicVolume' => clamp_float(num_value($input['musicVolume'] ?? 100), 0, 100),
        'exportMode' => $mode,
    ];
}

function require_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

function sanitize_clips(mixed $value): array
{
    $clips = [];
    foreach (require_list($value) as $clip) {
        if (!is_array($clip)) {
            continue;
        }
        $mediaId = (string) ($clip['mediaId'] ?? '');
        if (!is_safe_id($mediaId)) {
            throw new RuntimeException('Missing media.');
        }
        $media = load_media($mediaId);
        if ($media === null || ($media['type'] ?? '') !== 'video') {
            throw new RuntimeException('Missing media.');
        }
        $source = (float) ($media['duration'] ?? 0);
        if ($source < MIN_CLIP_SECONDS) {
            throw new RuntimeException('Media could not be analysed.');
        }
        $speed = clamp_float(num_value($clip['speed'] ?? 1, 1), 0.25, 4);
        $minSource = MIN_CLIP_SECONDS * $speed;
        $in = clamp_float(num_value($clip['inPoint'] ?? 0), 0, max(0, $source - $minSource));
        $out = clamp_float(num_value($clip['outPoint'] ?? $source), $in + $minSource, $source);
        $rotation = (int) ($clip['rotation'] ?? 0);
        if (!in_array($rotation, [0, 90, 180, 270], true)) {
            $rotation = 0;
        }
        $cropIn = is_array($clip['crop'] ?? null) ? $clip['crop'] : [];
        $width = max(2, (int) ($media['width'] ?? 2));
        $height = max(2, (int) ($media['height'] ?? 2));
        $left = clamp_int((int) num_value($cropIn['left'] ?? 0), 0, $width - 2);
        $top = clamp_int((int) num_value($cropIn['top'] ?? 0), 0, $height - 2);
        $right = clamp_int((int) num_value($cropIn['right'] ?? 0), 0, $width - $left - 2);
        $bottom = clamp_int((int) num_value($cropIn['bottom'] ?? 0), 0, $height - $top - 2);

        $clips[] = [
            'id' => clean_element_id($clip['id'] ?? ''),
            'mediaId' => $mediaId,
            'name' => clean_display_name((string) ($clip['name'] ?? $media['name'] ?? 'Clip')),
            'timelineStart' => max(0, num_value($clip['timelineStart'] ?? 0)),
            'inPoint' => $in,
            'outPoint' => $out,
            'sourceDuration' => $source,
            'speed' => $speed,
            'volume' => clamp_float(num_value($clip['volume'] ?? 100), 0, 100),
            'muted' => (bool) ($clip['muted'] ?? false),
            'x' => clamp_float(num_value($clip['x'] ?? 0), -4000, 4000),
            'y' => clamp_float(num_value($clip['y'] ?? 0), -4000, 4000),
            'scale' => clamp_float(num_value($clip['scale'] ?? 1, 1), 0.05, 4),
            'rotation' => $rotation,
            'crop' => ['left' => $left, 'top' => $top, 'right' => $right, 'bottom' => $bottom],
            'media' => $media,
        ];
    }
    return $clips;
}

function sanitize_images(mixed $value): array
{
    $images = [];
    foreach (require_list($value) as $image) {
        if (!is_array($image)) {
            continue;
        }
        $mediaId = (string) ($image['mediaId'] ?? '');
        if (!is_safe_id($mediaId)) {
            throw new RuntimeException('Missing media.');
        }
        $media = load_media($mediaId);
        if ($media === null || ($media['type'] ?? '') !== 'image') {
            throw new RuntimeException('Missing media.');
        }
        $start = max(0, num_value($image['start'] ?? 0));
        $end = num_value($image['end'] ?? ($start + 5));
        if ($end < $start + MIN_CLIP_SECONDS) {
            $end = $start + MIN_CLIP_SECONDS;
        }
        $rotation = (int) ($image['rotation'] ?? 0);
        if (!in_array($rotation, [0, 90, 180, 270], true)) {
            $rotation = 0;
        }
        $images[] = [
            'id' => clean_element_id($image['id'] ?? ''),
            'mediaId' => $mediaId,
            'name' => clean_display_name((string) ($image['name'] ?? $media['name'] ?? 'Image')),
            'x' => clamp_float(num_value($image['x'] ?? 40), -4000, 4000),
            'y' => clamp_float(num_value($image['y'] ?? 40), -4000, 4000),
            'width' => clamp_float(num_value($image['width'] ?? 480, 480), 8, 4000),
            'scale' => clamp_float(num_value($image['scale'] ?? 1, 1), 0.05, 5),
            'rotation' => $rotation,
            'opacity' => clamp_float(num_value($image['opacity'] ?? 100), 0, 100),
            'start' => $start,
            'end' => $end,
            'media' => $media,
        ];
    }
    return $images;
}

function sanitize_texts(mixed $value): array
{
    $fonts = ['Arial', 'Verdana', 'Georgia', 'Times New Roman', 'Courier New'];
    $texts = [];
    foreach (require_list($value) as $text) {
        if (!is_array($text)) {
            continue;
        }
        $content = (string) ($text['content'] ?? '');
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content) ?? '';
        $content = trim($content);
        if ($content === '') {
            continue;
        }
        if (strlen($content) > 240) {
            $content = substr($content, 0, 240);
        }
        $start = max(0, num_value($text['start'] ?? 0));
        $end = num_value($text['end'] ?? ($start + 5));
        if ($end < $start + MIN_CLIP_SECONDS) {
            $end = $start + MIN_CLIP_SECONDS;
        }
        $family = (string) ($text['fontFamily'] ?? 'Arial');
        if (!in_array($family, $fonts, true)) {
            $family = 'Arial';
        }
        $align = (string) ($text['align'] ?? 'center');
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'center';
        }
        $rotation = (int) ($text['rotation'] ?? 0);
        if (!in_array($rotation, [0, 90, 180, 270], true)) {
            $rotation = 0;
        }
        $color = strtolower((string) ($text['color'] ?? '#ffffff'));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $color = '#ffffff';
        }
        $texts[] = [
            'id' => clean_element_id($text['id'] ?? ''),
            'content' => $content,
            'fontFamily' => $family,
            'fontSize' => clamp_int((int) num_value($text['fontSize'] ?? 64), 8, 320),
            'color' => $color,
            'bold' => (bool) ($text['bold'] ?? false),
            'italic' => (bool) ($text['italic'] ?? false),
            'align' => $align,
            'x' => clamp_float(num_value($text['x'] ?? (COMPOSITION_WIDTH / 2)), -4000, 4000),
            'y' => clamp_float(num_value($text['y'] ?? (COMPOSITION_HEIGHT / 2)), -4000, 4000),
            'start' => $start,
            'end' => $end,
            'opacity' => clamp_float(num_value($text['opacity'] ?? 100), 0, 100),
            'rotation' => $rotation,
            'boxWidth' => clamp_float(num_value($text['boxWidth'] ?? 0), 0, 4000),
            'boxHeight' => clamp_float(num_value($text['boxHeight'] ?? 0), 0, 2000),
        ];
    }
    return $texts;
}

function sanitize_audios(mixed $value): array
{
    $audios = [];
    foreach (require_list($value) as $audio) {
        if (!is_array($audio)) {
            continue;
        }
        $mediaId = (string) ($audio['mediaId'] ?? '');
        if (!is_safe_id($mediaId)) {
            throw new RuntimeException('Missing media.');
        }
        $media = load_media($mediaId);
        if ($media === null || ($media['type'] ?? '') !== 'audio') {
            throw new RuntimeException('Missing media.');
        }
        $source = (float) ($media['duration'] ?? 0);
        if ($source < MIN_CLIP_SECONDS) {
            throw new RuntimeException('Media could not be analysed.');
        }
        $in = clamp_float(num_value($audio['inPoint'] ?? 0), 0, max(0, $source - MIN_CLIP_SECONDS));
        $out = clamp_float(num_value($audio['outPoint'] ?? $source), $in + MIN_CLIP_SECONDS, $source);
        $audios[] = [
            'id' => clean_element_id($audio['id'] ?? ''),
            'mediaId' => $mediaId,
            'name' => clean_display_name((string) ($audio['name'] ?? $media['name'] ?? 'Audio')),
            'timelineStart' => max(0, num_value($audio['timelineStart'] ?? 0)),
            'inPoint' => $in,
            'outPoint' => $out,
            'sourceDuration' => $source,
            'volume' => clamp_float(num_value($audio['volume'] ?? 100), 0, 100),
            'track' => clamp_int((int) ($audio['track'] ?? 1), 1, 8),
            'media' => $media,
        ];
    }
    return $audios;
}

function clean_element_id(mixed $id): string
{
    $id = (string) $id;
    if (preg_match('/^[a-f0-9]{8,32}$/', $id)) {
        return $id;
    }
    return new_id();
}

function project_duration(array $project): float
{
    $end = 0.0;
    foreach ($project['clips'] as $clip) {
        $end = max($end, $clip['timelineStart'] + (($clip['outPoint'] - $clip['inPoint']) / $clip['speed']));
    }
    foreach ($project['images'] as $image) {
        $end = max($end, (float) $image['end']);
    }
    foreach ($project['texts'] as $text) {
        $end = max($end, (float) $text['end']);
    }
    foreach ($project['audios'] as $audio) {
        $end = max($end, $audio['timelineStart'] + ($audio['outPoint'] - $audio['inPoint']));
    }
    if ($end < MIN_CLIP_SECONDS) {
        throw new RuntimeException('Empty project.');
    }
    return max(0.2, ceil($end * 1000) / 1000);
}
