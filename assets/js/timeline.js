window.VE = window.VE || {};

VE.Timeline = (function () {
    let zoom = 70;
    let drag = null;

    function pps() { return zoom; }

    function span() {
        return Math.max(VE.Project.duration() + 8, VE.Preview.getPlayhead() + 4, 20);
    }

    function timeToX(time) { return time * zoom; }

    function render() {
        const width = Math.max(span() * zoom, document.getElementById('tlScroll').clientWidth);
        document.getElementById('tlCanvas').style.width = width + 'px';
        renderRuler(width);
        renderTrack('video', 'trackVideo', VE.Project.get().clips, clipWindow);
        renderTrack('image', 'trackImage', VE.Project.get().images, (item) => ({ start: item.start, end: item.end }));
        renderTrack('text', 'trackText', VE.Project.get().texts, (item) => ({ start: item.start, end: item.end }));
        const audios = VE.Project.get().audios;
        const lanes = Math.max(1, audios.reduce((max, item) => Math.max(max, item.track || 1), 1));
        const audioHeight = 46 * Math.min(lanes, 4);
        document.getElementById('trackAudio').style.height = audioHeight + 'px';
        document.querySelector('.audio-label').style.height = audioHeight + 'px';
        renderTrack('audio', 'trackAudio', audios, (item) => ({
            start: item.timelineStart,
            end: item.timelineStart + (item.outPoint - item.inPoint),
        }));
        syncPlayhead();
        updateButtons();
    }

    function clipWindow(clip) {
        return {
            start: clip.timelineStart,
            end: clip.timelineStart + (clip.outPoint - clip.inPoint) / clip.speed,
        };
    }

    function renderRuler(width) {
        const ruler = document.getElementById('tlRuler');
        ruler.style.width = width + 'px';
        const step = zoom < 40 ? 5 : (zoom < 90 ? 2 : 1);
        let html = '';
        for (let second = 0; second < width / zoom; second += step) {
            const label = step >= 1 && second % (step * 2 === 2 ? 2 : step) === 0 ? formatTick(second) : '';
            html += '<div class="tick" style="left:' + (second * zoom) + 'px">' + (label ? '<span>' + label + '</span>' : '') + '</div>';
        }
        ruler.innerHTML = html;
    }

    function formatTick(seconds) {
        const whole = Math.floor(seconds);
        return Math.floor(whole / 60) + ':' + String(whole % 60).padStart(2, '0');
    }

    function renderTrack(type, elementId, items, windowOf) {
        const track = document.getElementById(elementId);
        const selected = VE.App.selection();
        track.innerHTML = '';
        items.forEach((item) => {
            const range = windowOf(item);
            const block = document.createElement('div');
            const kind = type === 'video' ? 'clip' : type;
            block.className = 'tl-clip ' + type + ((selected && selected.type === kind && selected.id === item.id) ? ' selected' : '');
            block.dataset.type = kind;
            block.dataset.id = item.id;
            block.style.left = timeToX(range.start) + 'px';
            block.style.width = Math.max(10, timeToX(range.end - range.start)) + 'px';
            if (type === 'audio') block.style.top = (6 + ((Math.min(item.track || 1, 4) - 1) * 46)) + 'px';
            const left = document.createElement('div');
            left.className = 'tl-handle left';
            left.dataset.edge = 'left';
            const label = document.createElement('span');
            label.textContent = type === 'text' ? item.content : item.name;
            const right = document.createElement('div');
            right.className = 'tl-handle right';
            right.dataset.edge = 'right';
            block.append(left, label, right);
            track.appendChild(block);
        });
    }

    function syncPlayhead() {
        const head = document.getElementById('playhead');
        head.style.left = timeToX(VE.Preview.getPlayhead()) + 'px';
        if (VE.Preview.isPlaying()) {
            const scroller = document.getElementById('tlScroll');
            const x = timeToX(VE.Preview.getPlayhead());
            if (x > scroller.scrollLeft + scroller.clientWidth - 40 || x < scroller.scrollLeft + 20) {
                scroller.scrollLeft = Math.max(0, x - scroller.clientWidth * 0.3);
            }
        }
    }

    function seekFromEvent(event) {
        const scroller = document.getElementById('tlScroll');
        const rect = scroller.getBoundingClientRect();
        const x = event.clientX - rect.left + scroller.scrollLeft;
        VE.Preview.setPlayhead(Math.max(0, x / zoom));
    }

    function updateButtons() {
        const selected = VE.App.selection();
        document.getElementById('btnDeleteClip').disabled = !selected;
        const canSplit = selected && selected.type === 'clip' && playheadInside(selected.id);
        document.getElementById('btnSplit').disabled = !canSplit;
    }

    function playheadInside(id) {
        const clip = VE.Project.find('clip', id);
        if (!clip) return false;
        const time = VE.Preview.getPlayhead();
        const end = clip.timelineStart + (clip.outPoint - clip.inPoint) / clip.speed;
        return time > clip.timelineStart + 0.05 && time < end - 0.05;
    }

    function applyDrag(item, mode, dt, origin, type) {
        const min = VE.Project.MIN;
        if (type === 'clip' || type === 'audio') {
            const speed = type === 'clip' ? origin.speed : 1;
            if (mode === 'move') {
                item.timelineStart = Math.max(0, origin.timelineStart + dt);
            } else if (mode === 'left') {
                const maxIn = origin.outPoint - min * speed;
                const nextIn = clamp(origin.inPoint + dt * speed, 0, maxIn);
                item.inPoint = nextIn;
                item.timelineStart = Math.max(0, origin.timelineStart + (nextIn - origin.inPoint) / speed);
            } else {
                item.outPoint = clamp(origin.outPoint + dt * speed, origin.inPoint + min * speed, origin.sourceDuration);
            }
        } else if (mode === 'move') {
            const length = origin.end - origin.start;
            item.start = Math.max(0, origin.start + dt);
            item.end = item.start + length;
        } else if (mode === 'left') {
            item.start = clamp(origin.start + dt, 0, origin.end - min);
        } else {
            item.end = Math.max(origin.start + min, origin.end + dt);
        }
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function bind() {
        const scroller = document.getElementById('tlScroll');
        scroller.addEventListener('pointerdown', (event) => {
            const block = event.target.closest('.tl-clip');
            if (!block) {
                if (event.target.closest('.playhead-head') || event.target.closest('.tl-ruler') || event.target.closest('.tl-track')) {
                    seekFromEvent(event);
                    drag = { mode: 'playhead' };
                }
                return;
            }
            event.preventDefault();
            const type = block.dataset.type;
            const id = block.dataset.id;
            VE.App.select(type, id);
            const item = VE.Project.find(type, id);
            const edge = event.target.dataset.edge || 'move';
            drag = {
                mode: edge,
                type,
                id,
                x: event.clientX,
                timelineStart: item.timelineStart || 0,
                inPoint: item.inPoint || 0,
                outPoint: item.outPoint || 0,
                sourceDuration: item.sourceDuration || item.outPoint || 1,
                speed: item.speed || 1,
                start: item.start || 0,
                end: item.end || 0,
            };
        });
        window.addEventListener('pointermove', (event) => {
            if (!drag) return;
            if (drag.mode === 'playhead') {
                seekFromEvent(event);
                return;
            }
            const dt = (event.clientX - drag.x) / zoom;
            VE.Project.change((draft) => {
                const key = drag.type === 'clip' ? 'clips' : drag.type + 's';
                const item = draft[key].find((entry) => entry.id === drag.id);
                if (item) applyDrag(item, drag.mode, dt, drag, drag.type);
            }, false);
        });
        window.addEventListener('pointerup', () => {
            if (!drag) return;
            const commit = drag.mode !== 'playhead';
            drag = null;
            if (commit) VE.Project.commit();
        });

        ['trackVideo', 'trackImage', 'trackText', 'trackAudio'].forEach((id) => {
            const track = document.getElementById(id);
            track.addEventListener('dragover', (event) => {
                event.preventDefault();
                track.classList.add('drop-target');
            });
            track.addEventListener('dragleave', () => track.classList.remove('drop-target'));
            track.addEventListener('drop', (event) => {
                event.preventDefault();
                track.classList.remove('drop-target');
                const mediaId = event.dataTransfer.getData('application/x-ve-media');
                if (!mediaId) return;
                const rect = document.getElementById('tlScroll').getBoundingClientRect();
                const time = Math.max(0, (event.clientX - rect.left + document.getElementById('tlScroll').scrollLeft) / zoom);
                VE.App.addMediaToTimeline(mediaId, time);
            });
        });

        document.getElementById('zoomRange').addEventListener('input', (event) => {
            zoom = Number(event.target.value);
            render();
        });
        document.getElementById('btnZoomIn').addEventListener('click', () => setZoom(zoom + 16));
        document.getElementById('btnZoomOut').addEventListener('click', () => setZoom(zoom - 16));
        document.getElementById('btnZoomFit').addEventListener('click', fit);
        scroller.addEventListener('wheel', (event) => {
            if (!event.ctrlKey) return;
            event.preventDefault();
            setZoom(zoom + (event.deltaY < 0 ? 12 : -12));
        }, { passive: false });
    }

    function setZoom(value) {
        zoom = Math.max(24, Math.min(220, value));
        document.getElementById('zoomRange').value = String(zoom);
        render();
    }

    function fit() {
        const available = document.getElementById('tlScroll').clientWidth - 24;
        const seconds = Math.max(VE.Project.duration(), 10);
        setZoom(available / seconds);
    }

    return { bind, render, syncPlayhead, updateButtons, pps };
})();
