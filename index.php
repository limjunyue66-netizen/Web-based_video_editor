<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
ensure_storage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Web-Based Video Editor</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= app_asset('assets/css/editor.css') ?>">
</head>
<body>
<div class="editor-app">
    <header class="topbar">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true"><i class="fa-solid fa-clapperboard"></i></span>
            <div>
                <strong>Video Editor</strong>
                <small>Web App</small>
            </div>
        </div>
        <label class="project-name">
            <span class="visually-hidden">Project name</span>
            <input id="projectName" type="text" maxlength="80" value="Untitled Project" title="Project name">
        </label>
        <div class="top-actions">
            <button type="button" id="btnUndo" class="icon-btn" title="Undo (Ctrl+Z)" disabled><i class="fa-solid fa-rotate-left"></i></button>
            <button type="button" id="btnRedo" class="icon-btn" title="Redo (Ctrl+Y)" disabled><i class="fa-solid fa-rotate-right"></i></button>
            <span class="top-divider"></span>
            <button type="button" id="btnNew" class="text-btn" title="New project"><i class="fa-regular fa-file"></i> New</button>
            <button type="button" id="btnSave" class="text-btn" title="Save in this browser and download JSON"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            <button type="button" id="btnLoad" class="text-btn" title="Load a project JSON file"><i class="fa-regular fa-folder-open"></i> Load</button>
            <button type="button" id="btnReset" class="text-btn" title="Reset project"><i class="fa-solid fa-arrow-rotate-left"></i> Reset</button>
            <input id="projectFile" type="file" accept="application/json,.json" hidden>
        </div>
        <div class="export-cluster">
            <label class="quality-select">
                <span class="visually-hidden">Export quality</span>
                <select id="exportMode" title="Export quality">
                    <option value="fast">Fast 720p</option>
                    <option value="high">High Quality 1080p</option>
                </select>
            </label>
            <button type="button" id="btnExport" class="export-btn" title="Export MP4">
                <i class="fa-solid fa-file-export"></i> Export MP4
            </button>
            <div id="ffmpegBadge" class="status-badge waiting" title="Checking FFmpeg">
                <span class="status-dot"></span>
                <span id="ffmpegLabel">FFmpeg: Checking</span>
            </div>
        </div>
    </header>

    <div class="workspace">
        <aside class="panel library-panel">
            <div class="panel-head">
                <h2>Media Library</h2>
                <button type="button" id="btnAddText" class="icon-btn" title="Add text at the playhead"><i class="fa-solid fa-font"></i></button>
            </div>
            <div id="dropzone" class="dropzone">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <p>Drop video, image, or audio here</p>
                <button type="button" id="btnBrowse" class="text-btn">Browse files</button>
                <input id="fileInput" type="file" hidden multiple accept=".mp4,.webm,.mov,.wmv,.jpg,.jpeg,.png,.webp,.mp3,.wav,.aac,.m4a,video/*,image/*,audio/*">
                <small id="uploadHint">MP4, WebM, MOV, WMV, JPG, PNG, WEBP, MP3, WAV, AAC, M4A</small>
            </div>
            <div id="uploadList" class="upload-list"></div>
            <div class="library-tabs" role="tablist">
                <button type="button" class="tab active" data-filter="all">All</button>
                <button type="button" class="tab" data-filter="video">Videos</button>
                <button type="button" class="tab" data-filter="image">Images</button>
                <button type="button" class="tab" data-filter="audio">Audio</button>
            </div>
            <div id="mediaList" class="media-list"></div>
        </aside>

        <main class="preview-panel">
            <div id="stage" class="stage">
                <canvas id="previewCanvas"></canvas>
                <div id="stageEmpty" class="stage-empty">
                    <i class="fa-solid fa-film"></i>
                    <p>Add a clip to the timeline to see the preview.</p>
                </div>
            </div>
            <div class="transport">
                <div class="transport-buttons">
                    <button type="button" id="btnStart" class="icon-btn" title="Go to beginning"><i class="fa-solid fa-backward-step"></i></button>
                    <button type="button" id="btnPlay" class="icon-btn play-btn" title="Play / Pause (Space)"><i class="fa-solid fa-play"></i></button>
                    <button type="button" id="btnStop" class="icon-btn" title="Stop"><i class="fa-solid fa-stop"></i></button>
                    <button type="button" id="btnEnd" class="icon-btn" title="Go to end"><i class="fa-solid fa-forward-step"></i></button>
                </div>
                <span id="timeReadout" class="time-readout">0:00.0 / 0:00.0</span>
                <input id="seekBar" class="seek" type="range" min="0" max="1000" value="0" step="1" title="Seek">
                <button type="button" id="btnMutePreview" class="icon-btn" title="Mute preview"><i class="fa-solid fa-volume-high"></i></button>
            </div>
        </main>

        <aside class="panel inspector-panel" id="inspector">
            <div class="panel-head"><h2>Inspector</h2></div>
            <div class="inspector-scroll">
                <section id="selectionPanel" class="inspector-section">
                    <p class="empty-copy">Select a clip on the timeline to edit it. Project settings and filters stay available below.</p>
                </section>
                <section class="inspector-section">
                    <h3>Project</h3>
                    <label class="field">Frame rate
                        <select id="projectFps">
                            <option value="24">24 fps</option>
                            <option value="25">25 fps</option>
                            <option value="30" selected>30 fps</option>
                            <option value="60">60 fps</option>
                        </select>
                    </label>
                    <label class="field">Canvas preset
                        <select id="projectPreset">
                            <option value="1920x1080">1920 × 1080</option>
                            <option value="1280x720">1280 × 720</option>
                            <option value="1080x1920">1080 × 1920</option>
                        </select>
                    </label>
                    <div class="field-row">
                        <label class="field">Width<input id="projectWidth" type="number" min="320" max="1920" step="2" value="1920"></label>
                        <label class="field">Height<input id="projectHeight" type="number" min="320" max="1920" step="2" value="1080"></label>
                    </div>
                    <label class="check"><input id="muteOriginal" type="checkbox"> Mute original video audio</label>
                    <label class="field">Background music volume
                        <input id="musicVolume" type="range" min="0" max="100" value="100">
                        <span id="musicVolumeValue" class="range-value">100</span>
                    </label>
                </section>
                <section class="inspector-section">
                    <h3>Filters</h3>
                    <label class="field">Brightness <span data-for="brightness">0</span>
                        <input class="filter-range" data-filter="brightness" type="range" min="-100" max="100" value="0">
                    </label>
                    <label class="field">Contrast <span data-for="contrast">0</span>
                        <input class="filter-range" data-filter="contrast" type="range" min="-100" max="100" value="0">
                    </label>
                    <label class="field">Saturation <span data-for="saturation">0</span>
                        <input class="filter-range" data-filter="saturation" type="range" min="-100" max="100" value="0">
                    </label>
                    <label class="field">Grayscale <span data-for="grayscale">0</span>
                        <input class="filter-range" data-filter="grayscale" type="range" min="0" max="100" value="0">
                    </label>
                    <label class="field">Blur <span data-for="blur">0</span>
                        <input class="filter-range" data-filter="blur" type="range" min="0" max="20" step="0.5" value="0">
                    </label>
                    <label class="field">Sepia <span data-for="sepia">0</span>
                        <input class="filter-range" data-filter="sepia" type="range" min="0" max="100" value="0">
                    </label>
                </section>
            </div>
        </aside>
    </div>

    <section class="timeline-panel">
        <div class="tl-toolbar">
            <strong>Timeline</strong>
            <button type="button" id="btnSplit" class="text-btn" title="Split the selected clip at the playhead" disabled><i class="fa-solid fa-scissors"></i> Split</button>
            <button type="button" id="btnDeleteClip" class="text-btn" title="Delete selection (Delete)" disabled><i class="fa-regular fa-trash-can"></i> Delete</button>
            <span class="top-divider"></span>
            <button type="button" id="btnZoomOut" class="icon-btn" title="Zoom out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <input id="zoomRange" type="range" min="24" max="220" value="70" title="Timeline zoom">
            <button type="button" id="btnZoomIn" class="icon-btn" title="Zoom in"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            <button type="button" id="btnZoomFit" class="text-btn" title="Fit timeline">Fit</button>
        </div>
        <div class="tl-body">
            <div class="tl-labels">
                <div class="tl-label ruler-label">Time</div>
                <div class="tl-label video-label"><i class="fa-solid fa-video"></i> Video</div>
                <div class="tl-label image-label"><i class="fa-solid fa-image"></i> Image</div>
                <div class="tl-label text-label"><i class="fa-solid fa-font"></i> Text</div>
                <div class="tl-label audio-label"><i class="fa-solid fa-music"></i> Audio</div>
            </div>
            <div id="tlScroll" class="tl-scroll">
                <div id="tlCanvas" class="tl-canvas">
                    <div id="tlRuler" class="tl-ruler"></div>
                    <div id="trackVideo" class="tl-track" data-track="video"></div>
                    <div id="trackImage" class="tl-track" data-track="image"></div>
                    <div id="trackText" class="tl-track" data-track="text"></div>
                    <div id="trackAudio" class="tl-track" data-track="audio"></div>
                    <div id="playhead" class="playhead"><div class="playhead-head"></div></div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content export-modal">
            <div class="modal-header">
                <h2 class="modal-title h5">Export MP4</h2>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="exportMessage" class="mb-2">Preparing export…</p>
                <div class="progress export-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                    <div id="exportBar" class="progress-bar" style="width:0%">0%</div>
                </div>
                <ul class="export-stats">
                    <li>Status <strong id="exportStatus">queued</strong></li>
                    <li>Progress <strong id="exportPercent">0%</strong></li>
                    <li>Processed <strong id="exportProcessed">0:00</strong></li>
                    <li>Elapsed <strong id="exportElapsed">0:00</strong></li>
                </ul>
                <p id="exportError" class="export-error" hidden></p>
                <a id="exportDownload" class="export-btn download-link" hidden href="#">
                    <i class="fa-solid fa-download"></i> Download MP4
                </a>
            </div>
        </div>
    </div>
</div>

<div id="mediaPool" class="media-pool" aria-hidden="true"></div>
<div id="toast" class="toast-note" hidden></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= app_asset('assets/js/project.js') ?>"></script>
<script src="<?= app_asset('assets/js/media.js') ?>"></script>
<script src="<?= app_asset('assets/js/upload.js') ?>"></script>
<script src="<?= app_asset('assets/js/preview.js') ?>"></script>
<script src="<?= app_asset('assets/js/timeline.js') ?>"></script>
<script src="<?= app_asset('assets/js/inspector.js') ?>"></script>
<script src="<?= app_asset('assets/js/export.js') ?>"></script>
<script src="<?= app_asset('assets/js/app.js') ?>"></script>
</body>
</html>
