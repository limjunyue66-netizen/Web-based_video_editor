<?php
declare(strict_types=1);

/**
 * Central configuration.
 * Override any constant in includes/config.local.php (see config.local.php.example).
 */

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Web-Based Video Editor');
}

if (!defined('MAX_VIDEO_SIZE')) {
    define('MAX_VIDEO_SIZE', 512 * 1024 * 1024);
}
if (!defined('MAX_IMAGE_SIZE')) {
    define('MAX_IMAGE_SIZE', 25 * 1024 * 1024);
}
if (!defined('MAX_AUDIO_SIZE')) {
    define('MAX_AUDIO_SIZE', 80 * 1024 * 1024);
}

if (!defined('COMPOSITION_WIDTH')) {
    define('COMPOSITION_WIDTH', 1920);
}
if (!defined('COMPOSITION_HEIGHT')) {
    define('COMPOSITION_HEIGHT', 1080);
}
if (!defined('COMPOSITION_FPS')) {
    define('COMPOSITION_FPS', 30);
}

if (!defined('EXPORT_FAST_WIDTH')) {
    define('EXPORT_FAST_WIDTH', 1280);
}
if (!defined('EXPORT_FAST_HEIGHT')) {
    define('EXPORT_FAST_HEIGHT', 720);
}
if (!defined('EXPORT_HQ_WIDTH')) {
    define('EXPORT_HQ_WIDTH', 1920);
}
if (!defined('EXPORT_HQ_HEIGHT')) {
    define('EXPORT_HQ_HEIGHT', 1080);
}

if (!defined('MIN_CLIP_SECONDS')) {
    define('MIN_CLIP_SECONDS', 0.1);
}

if (!defined('HISTORY_LIMIT')) {
    define('HISTORY_LIMIT', 50);
}

function app_root(): string
{
    return dirname(__DIR__);
}

function upload_directory(): string
{
    return app_root() . DIRECTORY_SEPARATOR . 'uploads';
}

function export_directory(): string
{
    return app_root() . DIRECTORY_SEPARATOR . 'exports';
}
