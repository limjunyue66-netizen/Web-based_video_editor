window.VE = window.VE || {};

VE.App = (function () {
    let selection = null;
    let toastTimer = 0;

    function selectionState() { return selection; }

    function toast(message, kind) {
        const note = document.getElementById('toast');
        note.hidden = false;
        note.className = 'toast-note ' + (kind || '');
        note.textContent = message;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { note.hidden = true; }, 3400);
    }

    function select(type, id) {
        selection = type && id ? { type, id } : null;
        VE.Inspector.render();
        VE.Timeline.render();
        VE.Preview.draw();
    }

    function syncClock() {
        const current = VE.Preview.getPlayhead();
        const total = VE.Project.duration();
        document.getElementById('timeReadout').textContent = format(current) + ' / ' + format(total);
        const bar = document.getElementById('seekBar');
        if (document.activeElement !== bar) {
            bar.value = total > 0 ? String(Math.round((current / total) * 1000)) : '0';
        }
        VE.Timeline.updateButtons();
    }

    function syncTransport() {
        const icon = document.querySelector('#btnPlay i');
        icon.className = VE.Preview.isPlaying() ? 'fa-solid fa-pause' : 'fa-solid fa-play';
    }

    function syncHistory() {
        document.getElementById('btnUndo').disabled = !VE.Project.canUndo();
        document.getElementById('btnRedo').disabled = !VE.Project.canRedo();
    }

    function format(seconds) {
        const safe = Math.max(0, seconds || 0);
        const whole = Math.floor(safe);
        const tenth = Math.floor((safe - whole) * 10);
        return Math.floor(whole / 60) + ':' + String(whole % 60).padStart(2, '0') + '.' + tenth;
    }

    function addMediaToTimeline(mediaId, at) {
        const media = VE.Media.get(mediaId);
        if (!media) {
            toast('Media not found.', 'error');
            return;
        }
        if ((media.type === 'video' || media.type === 'audio') && !(media.duration > 0)) {
            toast('Media could not be analysed.', 'error');
            return;
        }
        const time = Number.isFinite(at) ? at : VE.Preview.getPlayhead();
        let id = null;
        let type = media.type === 'video' ? 'clip' : media.type;
        if (media.type === 'video') id = VE.Project.addVideo(media, time);
        else if (media.type === 'image') id = VE.Project.addImage(media, time);
        else id = VE.Project.addAudio(media, time);
        select(type, id);
        toast('Added to the timeline.', 'ok');
    }

    function deleteSelection() {
        if (!selection) return;
        const current = selection;
        VE.Project.remove(current.type, current.id);
        selection = null;
    }

    function splitSelection() {
        if (!selection || selection.type !== 'clip') {
            toast('Select a video clip before splitting.', 'error');
            return;
        }
        const result = VE.Project.splitAt(selection.id, VE.Preview.getPlayhead());
        if (!result.ok) {
            toast(result.message, 'error');
            return;
        }
        select('clip', result.id);
        toast('Clip split.', 'ok');
    }

    function onProjectChange(project, meta) {
        if (selection && !VE.Project.find(selection.type, selection.id)) selection = null;
        VE.Preview.syncPool();
        if (meta.live) VE.Preview.draw();
        else VE.Preview.layout();
        VE.Timeline.render();
        if (!meta.live) VE.Inspector.render();
        VE.Inspector.syncStatic();
        syncClock();
        syncHistory();
        syncTransport();
    }

    async function checkHealth() {
        const badge = document.getElementById('ffmpegBadge');
        const label = document.getElementById('ffmpegLabel');
        try {
            const response = await fetch('api/health.php');
            const data = await response.json();
            VE.Upload.setLimits(data.limits);
            const ready = data.ffmpeg && data.ffprobe;
            badge.classList.remove('waiting', 'ready', 'down');
            badge.classList.add(ready ? 'ready' : 'down');
            label.textContent = ready ? 'FFmpeg: Ready' : 'FFmpeg: Not Available';
            if (!ready) toast(data.message || 'FFmpeg is unavailable.', 'error');
        } catch (error) {
            badge.classList.add('down');
            label.textContent = 'FFmpeg: Not Available';
        }
    }

    function bindLibrary() {
        document.querySelectorAll('.library-tabs .tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.library-tabs .tab').forEach((button) => button.classList.remove('active'));
                tab.classList.add('active');
                VE.Media.setFilter(tab.dataset.filter);
            });
        });
        document.getElementById('mediaList').addEventListener('click', async (event) => {
            const add = event.target.closest('[data-add]');
            const remove = event.target.closest('[data-delete]');
            if (add) addMediaToTimeline(add.dataset.add);
            if (remove) {
                const media = VE.Media.get(remove.dataset.delete);
                if (!media || !confirm('Delete "' + media.name + '" from the library?')) return;
                try {
                    await VE.Media.remove(remove.dataset.delete);
                    toast('Media deleted.', 'ok');
                } catch (error) {
                    toast(error.message, 'error');
                }
            }
        });
        document.getElementById('mediaList').addEventListener('dragstart', (event) => {
            const card = event.target.closest('.media-card');
            if (!card) return;
            event.dataTransfer.setData('application/x-ve-media', card.dataset.id);
            event.dataTransfer.effectAllowed = 'copy';
        });
        VE.Media.onChange(() => {
            VE.Media.render();
            VE.Preview.syncPool();
            VE.Preview.draw();
        });
    }

    function bindProjectButtons() {
        document.getElementById('btnNew').addEventListener('click', () => {
            if (!confirm('Start a new empty project? Unsaved timeline changes in this browser stay in the previous local save until you save again.')) return;
            VE.Preview.pause();
            VE.Project.newProject();
            selection = null;
            VE.Preview.setPlayhead(0, { seekMedia: false });
            toast('New project created.', 'ok');
        });
        document.getElementById('btnReset').addEventListener('click', () => {
            if (!confirm('Reset this project to a blank 1920×1080 timeline?')) return;
            VE.Preview.pause();
            VE.Project.newProject();
            selection = null;
            VE.Preview.setPlayhead(0, { seekMedia: false });
            toast('Project reset.', 'ok');
        });
        document.getElementById('btnSave').addEventListener('click', () => {
            VE.Project.snapshotDownload();
            toast('Project saved in this browser and downloaded as JSON.', 'ok');
        });
        document.getElementById('btnLoad').addEventListener('click', () => document.getElementById('projectFile').click());
        document.getElementById('projectFile').addEventListener('change', async (event) => {
            const file = event.target.files[0];
            event.target.value = '';
            if (!file) return;
            try {
                const data = JSON.parse(await file.text());
                VE.Preview.pause();
                VE.Project.loadData(data);
                selection = null;
                VE.Preview.setPlayhead(0, { seekMedia: false });
                toast('Project loaded.', 'ok');
            } catch (error) {
                toast(error.message || 'Invalid project JSON.', 'error');
            }
        });
        document.getElementById('btnUndo').addEventListener('click', () => VE.Project.undo());
        document.getElementById('btnRedo').addEventListener('click', () => VE.Project.redo());
        document.getElementById('btnAddText').addEventListener('click', () => {
            const id = VE.Project.addText(VE.Preview.getPlayhead());
            select('text', id);
        });
        document.getElementById('btnSplit').addEventListener('click', splitSelection);
        document.getElementById('btnDeleteClip').addEventListener('click', deleteSelection);
    }

    function bindKeys() {
        document.addEventListener('keydown', (event) => {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName);
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
                event.preventDefault();
                if (event.shiftKey) VE.Project.redo();
                else VE.Project.undo();
                return;
            }
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'y') {
                event.preventDefault();
                VE.Project.redo();
                return;
            }
            if (typing) return;
            if (event.key === ' ' || event.code === 'Space') {
                event.preventDefault();
                if (VE.Preview.isPlaying()) VE.Preview.pause();
                else VE.Preview.play();
            } else if (event.key === 'Delete' || event.key === 'Backspace') {
                deleteSelection();
            } else if (event.key.toLowerCase() === 's' && !event.ctrlKey) {
                splitSelection();
            }
        });
    }

    async function init() {
        VE.Upload.bind();
        VE.Preview.bind();
        VE.Timeline.bind();
        VE.Inspector.bindStatic();
        VE.Export.bind();
        bindLibrary();
        bindProjectButtons();
        bindKeys();
        VE.Project.onChange(onProjectChange);
        VE.Project.loadLocal();
        try {
            await VE.Media.load();
        } catch (error) {
            toast(error.message, 'error');
        }
        await checkHealth();
        VE.Preview.syncPool();
        VE.Preview.layout();
        syncClock();
    }

    return {
        init, toast, select, selection: selectionState, syncClock, syncTransport,
        addMediaToTimeline, deleteSelection, splitSelection,
    };
})();

document.addEventListener('DOMContentLoaded', () => {
    VE.App.init();
});
