<?php
declare(strict_types=1);

/**
 * Build an FFmpeg filter graph from the project.
 * Paths come from the media catalogue, never from a client-supplied filesystem path.
 *
 * @return array{args:list<string>,filter:string,duration:float,exportWidth:int,exportHeight:int}
 */
function build_export_plan(array $project, string $jobDir): array
{
    $duration = project_duration($project);
    $fps = (int) $project['fps'];
    $canvasW = (int) $project['width'];
    $canvasH = (int) $project['height'];
    $high = $project['exportMode'] === 'high';
    $exportW = $high ? EXPORT_HQ_WIDTH : EXPORT_FAST_WIDTH;
    $exportH = $high ? EXPORT_HQ_HEIGHT : EXPORT_FAST_HEIGHT;
    $frame = composition_frame($project);
    $originX = $frame['x'];
    $originY = $frame['y'];

    $args = [];
    $chains = [];
    $audioLabels = [];
    $videoLabel = 'base0';

    $chains[] = sprintf(
        'color=c=black:s=%dx%d:r=%d:d=%s,format=yuv420p,setsar=1[%s]',
        $frame['w'],
        $frame['h'],
        $fps,
        ffnum($duration),
        $videoLabel
    );

    foreach ($project['clips'] as $index => $clip) {
        $inputIndex = add_input($args, ['-i', media_absolute_path($clip['media'])]);
        $clipLabel = 'clip' . $index;
        $built = video_clip_filters($clip, $canvasW, $canvasH, $fps);
        $chains[] = sprintf('[%d:v]%s[%s]', $inputIndex, implode(',', $built['filters']), $clipLabel);
        $next = 'v' . $index;
        $chains[] = sprintf(
            '[%s][%s]overlay=x=%d:y=%d:eof_action=pass:repeatlast=0[%s]',
            $videoLabel,
            $clipLabel,
            $built['x'] - $originX,
            $built['y'] - $originY,
            $next
        );
        $videoLabel = $next;

        $useAudio = !$project['muteOriginal'] && !$clip['muted'] && $clip['volume'] > 0 && !empty($clip['media']['hasAudio']);
        if ($useAudio) {
            $audioLabels[] = append_audio_chain(
                $chains,
                $inputIndex,
                $clip['inPoint'],
                $clip['outPoint'],
                $clip['speed'],
                $clip['volume'] / 100,
                $clip['timelineStart'],
                'va' . $index
            );
        }
    }

    $videoLabel = append_color_filters($chains, $videoLabel, $project['filters']);

    foreach ($project['images'] as $index => $image) {
        $path = media_absolute_path($image['media']);
        $inputIndex = add_input($args, [
            '-loop', '1',
            '-framerate', (string) $fps,
            '-t', ffnum($duration),
            '-i', $path,
        ]);
        $layout = image_layout($image);
        $label = 'img' . $index;
        $filters = [
            'scale=' . $layout['width'] . ':' . $layout['height'] . ':flags=lanczos',
            'format=rgba',
            'colorchannelmixer=aa=' . ffnum($image['opacity'] / 100),
        ];
        foreach (rotation_filters((int) $image['rotation']) as $rotation) {
            $filters[] = $rotation;
        }
        $chains[] = sprintf('[%d:v]%s[%s]', $inputIndex, implode(',', $filters), $label);
        $next = 'i' . $index;
        $chains[] = sprintf(
            '[%s][%s]overlay=x=%d:y=%d:enable=%s:eof_action=pass:repeatlast=0[%s]',
            $videoLabel,
            $label,
            $layout['x'] - $originX,
            $layout['y'] - $originY,
            between_enable((float) $image['start'], (float) $image['end']),
            $next
        );
        $videoLabel = $next;
    }

    foreach ($project['texts'] as $index => $text) {
        $videoLabel = append_text($chains, $args, $text, $videoLabel, $index, $duration, $fps, $jobDir, $originX, $originY);
    }

    foreach ($project['audios'] as $index => $audio) {
        $inputIndex = add_input($args, ['-i', media_absolute_path($audio['media'])]);
        $gain = ($audio['volume'] / 100) * ($project['musicVolume'] / 100);
        if ($gain <= 0) {
            continue;
        }
        $audioLabels[] = append_audio_chain(
            $chains,
            $inputIndex,
            $audio['inPoint'],
            $audio['outPoint'],
            1.0,
            $gain,
            $audio['timelineStart'],
            'bg' . $index
        );
    }

    $chains[] = sprintf(
        '[%s]scale=%d:%d:force_original_aspect_ratio=decrease:out_range=tv,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:black,setsar=1,fps=%d,format=yuv420p[vout]',
        $videoLabel,
        $exportW,
        $exportH,
        $exportW,
        $exportH,
        $fps
    );

    $audioLabels = array_values(array_filter($audioLabels));
    if ($audioLabels === []) {
        $inputIndex = add_input($args, [
            '-f', 'lavfi',
            '-t', ffnum($duration),
            '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000',
        ]);
        $chains[] = sprintf('[%d:a]aformat=sample_fmts=fltp:channel_layouts=stereo,atrim=end=%s[aout]', $inputIndex, ffnum($duration));
    } elseif (count($audioLabels) === 1) {
        $chains[] = sprintf(
            '[%s]apad=whole_dur=%s,atrim=end=%s,aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo[aout]',
            $audioLabels[0],
            ffnum($duration),
            ffnum($duration)
        );
    } else {
        $mix = '';
        foreach ($audioLabels as $label) {
            $mix .= '[' . $label . ']';
        }
        $chains[] = sprintf(
            '%samix=inputs=%d:duration=longest:dropout_transition=0:normalize=0,apad=whole_dur=%s,atrim=end=%s,aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo[aout]',
            $mix,
            count($audioLabels),
            ffnum($duration),
            ffnum($duration)
        );
    }

    return [
        'args' => $args,
        'filter' => implode(";\n", $chains),
        'duration' => $duration,
        'exportWidth' => $exportW,
        'exportHeight' => $exportH,
    ];
}

/**
 * @param list<string> $args
 * @param list<string> $tokens
 */
function add_input(array &$args, array $tokens): int
{
    $index = count_inputs($args);
    foreach ($tokens as $token) {
        $args[] = $token;
    }
    return $index;
}

function count_inputs(array $args): int
{
    $count = 0;
    foreach ($args as $arg) {
        if ($arg === '-i') {
            $count++;
        }
    }
    return $count;
}

/**
 * @return array{filters:list<string>,x:int,y:int,w:int,h:int}
 */
function video_clip_filters(array $clip, int $canvasW, int $canvasH, int $fps): array
{
    $media = $clip['media'];
    $srcW = max(2, (int) ($media['width'] ?? 2));
    $srcH = max(2, (int) ($media['height'] ?? 2));
    $crop = $clip['crop'];
    $cropW = even_int($srcW - (int) $crop['left'] - (int) $crop['right']);
    $cropH = even_int($srcH - (int) $crop['top'] - (int) $crop['bottom']);
    $fitW = $cropW;
    $fitH = $cropH;
    if ($clip['rotation'] === 90 || $clip['rotation'] === 270) {
        $fitW = $cropH;
        $fitH = $cropW;
    }
    $fit = min($canvasW / $fitW, $canvasH / $fitH) * (float) $clip['scale'];
    $dispW = even_int((int) round($fitW * $fit));
    $dispH = even_int((int) round($fitH * $fit));
    $x = (int) round(($canvasW - $dispW) / 2 + (float) $clip['x']);
    $y = (int) round(($canvasH - $dispH) / 2 + (float) $clip['y']);

    $filters = [
        'trim=start=' . ffnum((float) $clip['inPoint']) . ':end=' . ffnum((float) $clip['outPoint']),
        'setpts=PTS-STARTPTS',
    ];
    if (abs((float) $clip['speed'] - 1) > 0.001) {
        $filters[] = 'setpts=PTS/' . ffnum((float) $clip['speed']);
    }
    $filters[] = 'fps=' . $fps;
    if ((int) $crop['left'] !== 0 || (int) $crop['top'] !== 0 || (int) $crop['right'] !== 0 || (int) $crop['bottom'] !== 0) {
        $filters[] = sprintf('crop=%d:%d:%d:%d', $cropW, $cropH, (int) $crop['left'], (int) $crop['top']);
    }
    foreach (rotation_filters((int) $clip['rotation']) as $rotation) {
        $filters[] = $rotation;
    }
    $filters[] = 'scale=' . $dispW . ':' . $dispH . ':flags=lanczos';
    $filters[] = 'setsar=1';
    $filters[] = 'format=yuv420p';
    $filters[] = 'setpts=PTS+' . ffnum((float) $clip['timelineStart']) . '/TB';
    return ['filters' => $filters, 'x' => $x, 'y' => $y, 'w' => $dispW, 'h' => $dispH];
}

function image_layout(array $image): array
{
    $media = $image['media'];
    $srcW = max(1, (int) ($media['width'] ?? 1));
    $srcH = max(1, (int) ($media['height'] ?? 1));
    $dispW = even_int((int) round((float) $image['width'] * (float) $image['scale']));
    $dispH = even_int((int) round($dispW * ($srcH / $srcW)));
    $bbW = $dispW;
    $bbH = $dispH;
    if ((int) $image['rotation'] === 90 || (int) $image['rotation'] === 270) {
        $bbW = $dispH;
        $bbH = $dispW;
    }
    $centerX = (float) $image['x'] + ($dispW / 2);
    $centerY = (float) $image['y'] + ($dispH / 2);
    return [
        'width' => $dispW,
        'height' => $dispH,
        'x' => (int) round($centerX - ($bbW / 2)),
        'y' => (int) round($centerY - ($bbH / 2)),
        'boxW' => $bbW,
        'boxH' => $bbH,
    ];
}

/**
 * Frame that includes the project canvas and any layer that sticks out of it.
 * Preview draws those layers; cropping to the canvas alone hides them in the file.
 *
 * @return array{x:int,y:int,w:int,h:int}
 */
function composition_frame(array $project): array
{
    $minX = 0.0;
    $minY = 0.0;
    $maxX = (float) $project['width'];
    $maxY = (float) $project['height'];
    $canvasW = (int) $project['width'];
    $canvasH = (int) $project['height'];
    $fps = (int) $project['fps'];

    foreach ($project['clips'] as $clip) {
        $built = video_clip_filters($clip, $canvasW, $canvasH, $fps);
        $minX = min($minX, $built['x']);
        $minY = min($minY, $built['y']);
        $maxX = max($maxX, $built['x'] + $built['w']);
        $maxY = max($maxY, $built['y'] + $built['h']);
    }
    foreach ($project['images'] as $image) {
        $layout = image_layout($image);
        $minX = min($minX, $layout['x']);
        $minY = min($minY, $layout['y']);
        $maxX = max($maxX, $layout['x'] + $layout['boxW']);
        $maxY = max($maxY, $layout['y'] + $layout['boxH']);
    }
    foreach ($project['texts'] as $text) {
        $rect = text_bounds($text);
        $minX = min($minX, $rect['x']);
        $minY = min($minY, $rect['y']);
        $maxX = max($maxX, $rect['x'] + $rect['w']);
        $maxY = max($maxY, $rect['y'] + $rect['h']);
    }

    $originX = (int) floor($minX);
    $originY = (int) floor($minY);
    $width = (int) ceil($maxX) - $originX;
    $height = (int) ceil($maxY) - $originY;
    if ($width % 2 !== 0) {
        $width++;
    }
    if ($height % 2 !== 0) {
        $height++;
    }

    return ['x' => $originX, 'y' => $originY, 'w' => max(2, $width), 'h' => max(2, $height)];
}

/**
 * @return array{x:float,y:float,w:float,h:float}
 */
function text_bounds(array $text): array
{
    $box = text_box($text);
    $rotation = (int) $text['rotation'];
    if ($rotation === 0) {
        $width = (float) $box['textW'];
        $height = (float) $box['textH'];
        $x = match ($text['align']) {
            'left' => (float) $text['x'],
            'right' => (float) $text['x'] - $width,
            default => (float) $text['x'] - ($width / 2),
        };
        return ['x' => $x, 'y' => (float) $text['y'] - ($height / 2), 'w' => $width, 'h' => $height];
    }
    $boxW = ($rotation === 90 || $rotation === 270) ? $box['boxH'] : $box['boxW'];
    $boxH = ($rotation === 90 || $rotation === 270) ? $box['boxW'] : $box['boxH'];
    $center = text_center($text, $box);
    return [
        'x' => $center['x'] - ($boxW / 2),
        'y' => $center['y'] - ($boxH / 2),
        'w' => $boxW,
        'h' => $boxH,
    ];
}

/**
 * @param list<string> $chains
 */
function append_color_filters(array &$chains, string $label, array $filters): string
{
    $brightness = ffnum(((float) $filters['brightness']) / 100);
    $contrast = ffnum(clamp_float(1 + ((float) $filters['contrast'] / 100), 0, 2));
    $saturation = ((1 + ((float) $filters['saturation'] / 100)) * (1 - ((float) $filters['grayscale'] / 100)));
    $saturation = ffnum(clamp_float($saturation, 0, 3));
    $next = $label . 'eq';
    $chains[] = sprintf('[%s]eq=brightness=%s:contrast=%s:saturation=%s[%s]', $label, $brightness, $contrast, $saturation, $next);
    $label = $next;
    if ((float) $filters['blur'] > 0) {
        $next = $label . 'bl';
        $chains[] = sprintf('[%s]gblur=sigma=%s[%s]', $label, ffnum((float) $filters['blur']), $next);
        $label = $next;
    }
    if ((float) $filters['sepia'] > 0) {
        $amount = ffnum(((float) $filters['sepia']) / 100);
        $chains[] = sprintf('[%s]split[%sA][%sB]', $label, $label, $label);
        $chains[] = sprintf('[%sB]colorchannelmixer=.393:.769:.189:0:.349:.686:.168:0:.272:.534:.131[%sS]', $label, $label);
        $out = $label . 'sp';
        $chains[] = sprintf("[%sA][%sS]blend=all_expr='A*(1-%s)+B*%s'[%s]", $label, $label, $amount, $amount, $out);
        $label = $out;
    }
    return $label;
}

/**
 * @param list<string> $chains
 * @param list<string> $args
 */
function append_text(array &$chains, array &$args, array $text, string $videoLabel, int $index, float $duration, int $fps, string $jobDir, int $originX = 0, int $originY = 0): string
{
    $textPath = $jobDir . DIRECTORY_SEPARATOR . 'text_' . $index . '.txt';
    file_put_contents($textPath, str_replace("\n", ' ', $text['content']));
    $font = ff_escape_path(font_file_for($text['fontFamily'], (bool) $text['bold'], (bool) $text['italic']));
    $opacity = ffnum(((float) $text['opacity']) / 100);
    $hex = strtoupper(ltrim((string) $text['color'], '#'));
    $fontColor = '0x' . $hex . '@' . $opacity;
    $file = ff_escape_path($textPath);
    $enable = between_enable((float) $text['start'], (float) $text['end']);
    $size = (int) $text['fontSize'];

    if ((int) $text['rotation'] === 0) {
        $x = text_x_expression($text, $originX);
        $y = ((int) round((float) $text['y']) - $originY) . '-text_h/2';
        $next = 'tx' . $index;
        $chains[] = sprintf(
            "[%s]drawtext=expansion=none:fontfile='%s':textfile='%s':fontsize=%d:fontcolor=%s:x=%s:y=%s:enable=%s[%s]",
            $videoLabel,
            $font,
            $file,
            $size,
            $fontColor,
            $x,
            $y,
            $enable,
            $next
        );
        return $next;
    }

    $box = text_box($text);
    $background = $box['dark'] ? 'white' : 'black';
    $key = $box['dark'] ? '0xFFFFFF' : '0x000000';
    $inputIndex = add_input($args, [
        '-f', 'lavfi',
        '-t', ffnum($duration),
        '-i', 'color=c=' . $background . ':s=' . $box['boxW'] . 'x' . $box['boxH'] . ':r=' . $fps,
    ]);
    $label = 'txt' . $index;
    $parts = [
        sprintf(
            "drawtext=expansion=none:fontfile='%s':textfile='%s':fontsize=%d:fontcolor=%s:x=(w-text_w)/2:y=(h-text_h)/2",
            $font,
            $file,
            $size,
            $fontColor
        ),
        'format=rgba',
        'colorkey=' . $key . ':0.12:0.06',
    ];
    foreach (rotation_filters((int) $text['rotation']) as $rotation) {
        $parts[] = $rotation;
    }
    $chains[] = sprintf('[%d:v]%s[%s]', $inputIndex, implode(',', $parts), $label);
    $bbW = ((int) $text['rotation'] === 90 || (int) $text['rotation'] === 270) ? $box['boxH'] : $box['boxW'];
    $bbH = ((int) $text['rotation'] === 90 || (int) $text['rotation'] === 270) ? $box['boxW'] : $box['boxH'];
    $center = text_center($text, $box);
    $x = (int) round($center['x'] - ($bbW / 2)) - $originX;
    $y = (int) round($center['y'] - ($bbH / 2)) - $originY;
    $next = 'ty' . $index;
    $chains[] = sprintf(
        '[%s][%s]overlay=x=%d:y=%d:enable=%s:eof_action=pass:repeatlast=0[%s]',
        $videoLabel,
        $label,
        $x,
        $y,
        $enable,
        $next
    );
    return $next;
}

function text_x_expression(array $text, int $originX = 0): string
{
    $x = (int) round((float) $text['x']) - $originX;
    return match ($text['align']) {
        'left' => (string) $x,
        'right' => $x . '-text_w',
        default => $x . '-text_w/2',
    };
}

function text_box(array $text): array
{
    $size = (int) $text['fontSize'];
    $lines = preg_split("/\n/", (string) $text['content']) ?: [''];
    $longest = 1;
    foreach ($lines as $line) {
        $longest = max($longest, mb_strlen($line));
    }
    $estimatedW = (float) $text['boxWidth'] > 8 ? (float) $text['boxWidth'] : ($size * 0.62 * $longest);
    $estimatedH = (float) $text['boxHeight'] > 8 ? (float) $text['boxHeight'] : ($size * 1.25 * count($lines));
    $hex = ltrim((string) $text['color'], '#');
    $red = hexdec(substr($hex, 0, 2));
    $green = hexdec(substr($hex, 2, 2));
    $blue = hexdec(substr($hex, 4, 2));
    $luma = (0.2126 * $red) + (0.7152 * $green) + (0.0722 * $blue);
    return [
        'textW' => $estimatedW,
        'textH' => $estimatedH,
        'boxW' => even_int((int) ceil($estimatedW + ($size * 0.8))),
        'boxH' => even_int((int) ceil($estimatedH + ($size * 0.8))),
        'dark' => $luma < 90,
    ];
}

function text_center(array $text, array $box): array
{
    $x = (float) $text['x'];
    $width = (float) $box['textW'];
    $centerX = match ($text['align']) {
        'left' => $x + ($width / 2),
        'right' => $x - ($width / 2),
        default => $x,
    };
    return ['x' => $centerX, 'y' => (float) $text['y']];
}

/**
 * @param list<string> $chains
 */
function append_audio_chain(
    array &$chains,
    int $inputIndex,
    float $inPoint,
    float $outPoint,
    float $speed,
    float $volume,
    float $timelineStart,
    string $label
): string {
    $parts = [
        'atrim=start=' . ffnum($inPoint) . ':end=' . ffnum($outPoint),
        'asetpts=PTS-STARTPTS',
    ];
    foreach (atempo_filters($speed) as $tempo) {
        $parts[] = $tempo;
    }
    $parts[] = 'volume=' . ffnum(max(0, $volume));
    $delay = max(0, (int) round($timelineStart * 1000));
    if ($delay > 0) {
        $parts[] = 'adelay=' . $delay . '|' . $delay;
    }
    $parts[] = 'aformat=sample_fmts=fltp:channel_layouts=stereo';
    $chains[] = sprintf('[%d:a]%s[%s]', $inputIndex, implode(',', $parts), $label);
    return $label;
}

function atempo_filters(float $speed): array
{
    $filters = [];
    $remaining = $speed;
    $guard = 0;
    while ($remaining > 2.0001 && $guard < 6) {
        $filters[] = 'atempo=2';
        $remaining /= 2;
        $guard++;
    }
    while ($remaining < 0.4999 && $guard < 8) {
        $filters[] = 'atempo=0.5';
        $remaining /= 0.5;
        $guard++;
    }
    if (abs($remaining - 1) > 0.001) {
        $filters[] = 'atempo=' . ffnum($remaining);
    }
    return $filters;
}

function rotation_filters(int $degrees): array
{
    return match ($degrees) {
        90 => ['transpose=1'],
        180 => ['hflip', 'vflip'],
        270 => ['transpose=2'],
        default => [],
    };
}

function between_enable(float $start, float $end): string
{
    return 'between(t\\,' . ffnum($start) . '\\,' . ffnum($end) . ')';
}

function ff_escape_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = str_replace(':', '\\:', $path);
    $path = str_replace("'", "\\'", $path);
    return $path;
}
