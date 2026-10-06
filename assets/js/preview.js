window.VE = window.VE || {};

VE.Preview = (function () {
    const canvas = () => document.getElementById('previewCanvas');
    const pool = () => document.getElementById('mediaPool');
    const videos = new Map();
    const audios = new Map();
    const images = new Map();
    let view = { scale: 1, ox: 0, oy: 0 };
    let playing = false;
    let previewMuted = false;
    let playhead = 0;
    let anchorTime = 0;
    let anchorHead = 0;
    let raf = 0;
    let drag = null;
    const hits = [];

    function getPlayhead() { return playhead; }
    function isPlaying() { return playing; }
    function isMuted() { return previewMuted; }

    function setPlayhead(time, options = {}) {
        const duration = Math.max(0, VE.Project.duration());
        playhead = Math.max(0, duration ? Math.min(time, duration) : time);
        if (!options.silent) {
            draw();
            if (VE.Timeline) VE.Timeline.syncPlayhead();
            if (VE.App) VE.App.syncClock();
        }
        if (options.seekMedia !== false) syncMedia(playing);
    }

    function layout() {
        const stage = document.getElementById('stage');
        const surface = canvas();
        const rect = stage.getBoundingClientRect();
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        surface.width = Math.max(2, Math.floor(rect.width * dpr));
        surface.height = Math.max(2, Math.floor(rect.height * dpr));
        const project = VE.Project.get();
        const bounds = contentBounds(project);
        const scale = Math.min(rect.width / bounds.w, rect.height / bounds.h);
        const drawScale = scale * dpr;
        view = {
            scale: drawScale,
            ox: (surface.width - bounds.w * drawScale) / 2 - bounds.x * drawScale,
            oy: (surface.height - bounds.h * drawScale) / 2 - bounds.y * drawScale,
            bounds: bounds,
        };
        draw();
    }

    function contentBounds(project) {
        let minX = 0;
        let minY = 0;
        let maxX = project.width;
        let maxY = project.height;
        project.clips.forEach((clip) => {
            const media = VE.Media.get(clip.mediaId);
            const rect = videoRect(clip, media || { width: project.width, height: project.height }, project);
            minX = Math.min(minX, rect.dx);
            minY = Math.min(minY, rect.dy);
            maxX = Math.max(maxX, rect.dx + rect.dw);
            maxY = Math.max(maxY, rect.dy + rect.dh);
        });
        project.images.forEach((image) => {
            const media = VE.Media.get(image.mediaId);
            const rect = imageRect(image, media);
            minX = Math.min(minX, rect.x);
            minY = Math.min(minY, rect.y);
            maxX = Math.max(maxX, rect.x + rect.w);
            maxY = Math.max(maxY, rect.y + rect.h);
        });
        project.texts.forEach((text) => {
            const rect = textRect(text);
            minX = Math.min(minX, rect.x);
            minY = Math.min(minY, rect.y);
            maxX = Math.max(maxX, rect.x + rect.w);
            maxY = Math.max(maxY, rect.y + rect.h);
        });
        return { x: minX, y: minY, w: Math.max(2, maxX - minX), h: Math.max(2, maxY - minY) };
    }

    function textRect(text) {
        const size = text.fontSize || 48;
        const width = text.boxWidth > 8 ? text.boxWidth : size * 0.62 * Math.max(1, String(text.content || '').length);
        const height = text.boxHeight > 8 ? text.boxHeight : size * 1.25;
        const x = text.align === 'left' ? text.x : (text.align === 'right' ? text.x - width : text.x - width / 2);
        return { x: x, y: text.y - height / 2, w: width, h: height };
    }

    function cssFilter(filters) {
        const brightness = 1 + filters.brightness / 100;
        const contrast = 1 + filters.contrast / 100;
        const saturation = (1 + filters.saturation / 100) * (1 - filters.grayscale / 100);
        return 'brightness(' + brightness + ') contrast(' + contrast + ') saturate(' + saturation + ') blur(' + filters.blur + 'px) sepia(' + filters.sepia + '%)';
    }

    function activeClips(project, time) {
        return project.clips.filter((clip) => {
            const end = clip.timelineStart + (clip.outPoint - clip.inPoint) / clip.speed;
            return time >= clip.timelineStart && time < end - 0.0001;
        });
    }

    function sourceTime(clip, time) {
        return clip.inPoint + (time - clip.timelineStart) * clip.speed;
    }

    function ensureVideo(clip) {
        const media = VE.Media.get(clip.mediaId);
        if (!media) return null;
        let element = videos.get(clip.id);
        const src = media.previewUrl || media.url;
        if (!element) {
            element = document.createElement('video');
            element.playsInline = true;
            element.preload = 'auto';
            element.src = src;
            pool().appendChild(element);
            element.addEventListener('seeked', () => { if (!playing) draw(); });
            videos.set(clip.id, element);
        } else if (element.getAttribute('src') !== src && element.src.indexOf(src) === -1) {
            element.src = src;
        }
        return element;
    }

    function ensureAudio(item) {
        const media = VE.Media.get(item.mediaId);
        if (!media) return null;
        let element = audios.get(item.id);
        if (!element) {
            element = document.createElement('audio');
            element.preload = 'auto';
            element.src = media.url;
            pool().appendChild(element);
            audios.set(item.id, element);
        }
        return element;
    }

    function ensureImage(mediaId) {
        const media = VE.Media.get(mediaId);
        if (!media) return null;
        let element = images.get(mediaId);
        if (!element) {
            element = new Image();
            element.src = media.url;
            element.onload = () => draw();
            images.set(mediaId, element);
        }
        return element;
    }

    function syncPool() {
        const project = VE.Project.get();
        const clipIds = new Set(project.clips.map((clip) => clip.id));
        const audioIds = new Set(project.audios.map((audio) => audio.id));
        videos.forEach((element, id) => {
            if (!clipIds.has(id)) {
                element.pause();
                element.remove();
                videos.delete(id);
            }
        });
        audios.forEach((element, id) => {
            if (!audioIds.has(id)) {
                element.pause();
                element.remove();
                audios.delete(id);
            }
        });
        project.clips.forEach(ensureVideo);
        project.audios.forEach(ensureAudio);
        project.images.forEach((image) => ensureImage(image.mediaId));
    }

    function syncMedia(shouldPlay) {
        const project = VE.Project.get();
        const time = playhead;
        videos.forEach((element, id) => {
            const clip = project.clips.find((item) => item.id === id);
            if (!clip) return;
            const end = clip.timelineStart + (clip.outPoint - clip.inPoint) / clip.speed;
            const active = time >= clip.timelineStart && time < end;
            const local = clamp(sourceTime(clip, active ? time : clip.timelineStart), clip.inPoint, clip.outPoint);
            element.playbackRate = clip.speed;
            const audible = !previewMuted && !project.muteOriginal && !clip.muted;
            element.volume = audible ? clip.volume / 100 : 0;
            if (Math.abs(element.currentTime - local) > (shouldPlay ? 0.35 : 0.08)) {
                try { element.currentTime = local; } catch (error) { /* seek before metadata */ }
            }
            if (shouldPlay && active) {
                if (element.paused) element.play().catch(() => {});
            } else if (!element.paused) {
                element.pause();
            }
        });
        audios.forEach((element, id) => {
            const item = project.audios.find((audio) => audio.id === id);
            if (!item) return;
            const end = item.timelineStart + (item.outPoint - item.inPoint);
            const active = time >= item.timelineStart && time < end;
            const local = clamp(item.inPoint + (time - item.timelineStart), item.inPoint, item.outPoint);
            const master = project.musicVolume / 100;
            element.volume = previewMuted ? 0 : Math.max(0, Math.min(1, (item.volume / 100) * master));
            if (Math.abs(element.currentTime - local) > (shouldPlay ? 0.35 : 0.08)) {
                try { element.currentTime = local; } catch (error) { /* not ready */ }
            }
            if (shouldPlay && active) {
                if (element.paused) element.play().catch(() => {});
            } else if (!element.paused) {
                element.pause();
            }
        });
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function videoRect(clip, media, project) {
        const sourceW = media.width || project.width;
        const sourceH = media.height || project.height;
        const crop = clip.crop || { left: 0, top: 0, right: 0, bottom: 0 };
        const cropW = Math.max(2, sourceW - crop.left - crop.right);
        const cropH = Math.max(2, sourceH - crop.top - crop.bottom);
        const rotated = clip.rotation === 90 || clip.rotation === 270;
        const fitW = rotated ? cropH : cropW;
        const fitH = rotated ? cropW : cropH;
        const fitted = Math.min(project.width / fitW, project.height / fitH) * clip.scale;
        const width = fitW * fitted;
        const height = fitH * fitted;
        return {
            sx: crop.left,
            sy: crop.top,
            sw: cropW,
            sh: cropH,
            dx: (project.width - width) / 2 + clip.x,
            dy: (project.height - height) / 2 + clip.y,
            dw: width,
            dh: height,
            preW: rotated ? height : width,
            preH: rotated ? width : height,
        };
    }

    function imageRect(image, media) {
        const width = image.width * image.scale;
        const height = media && media.width ? width * (media.height / media.width) : width;
        return { x: image.x, y: image.y, w: width, h: height };
    }

    function draw() {
        const surface = canvas();
        const ctx = surface.getContext('2d');
        const project = VE.Project.get();
        hits.length = 0;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.fillStyle = '#07090d';
        ctx.fillRect(0, 0, surface.width, surface.height);
        ctx.setTransform(view.scale, 0, 0, view.scale, view.ox, view.oy);
        ctx.fillStyle = '#000';
        const frame = view.bounds || { x: 0, y: 0, w: project.width, h: project.height };
        ctx.fillRect(frame.x, frame.y, frame.w, frame.h);

        const hasContent = project.clips.length || project.images.length || project.texts.length;
        document.getElementById('stageEmpty').hidden = !!hasContent;

        ctx.save();
        ctx.filter = cssFilter(project.filters);
        activeClips(project, playhead).forEach((clip) => {
            const media = VE.Media.get(clip.mediaId);
            const element = ensureVideo(clip);
            const rect = videoRect(clip, media || { width: project.width, height: project.height }, project);
            ctx.save();
            ctx.translate(rect.dx + rect.dw / 2, rect.dy + rect.dh / 2);
            ctx.rotate((clip.rotation * Math.PI) / 180);
            if (element && element.readyState >= 2) {
                ctx.drawImage(element, rect.sx, rect.sy, rect.sw, rect.sh, -rect.preW / 2, -rect.preH / 2, rect.preW, rect.preH);
            } else {
                ctx.fillStyle = '#1b2433';
                ctx.fillRect(-rect.preW / 2, -rect.preH / 2, rect.preW, rect.preH);
                ctx.fillStyle = '#9aabc4';
                ctx.font = '28px Segoe UI, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(media ? clip.name : 'Missing media', 0, 0);
            }
            ctx.restore();
            hits.push({ type: 'clip', id: clip.id, cx: rect.dx + rect.dw / 2, cy: rect.dy + rect.dh / 2, w: rect.dw, h: rect.dh, rot: 0 });
        });
        ctx.restore();

        project.images.forEach((image) => {
            if (playhead < image.start || playhead >= image.end) return;
            const media = VE.Media.get(image.mediaId);
            const element = ensureImage(image.mediaId);
            const rect = imageRect(image, media);
            ctx.save();
            ctx.globalAlpha = image.opacity / 100;
            ctx.translate(rect.x + rect.w / 2, rect.y + rect.h / 2);
            ctx.rotate((image.rotation * Math.PI) / 180);
            if (element && element.complete && element.naturalWidth) {
                ctx.drawImage(element, -rect.w / 2, -rect.h / 2, rect.w, rect.h);
            } else {
                ctx.fillStyle = '#12897d';
                ctx.fillRect(-rect.w / 2, -rect.h / 2, rect.w, rect.h);
            }
            ctx.restore();
            hits.push({ type: 'image', id: image.id, cx: rect.x + rect.w / 2, cy: rect.y + rect.h / 2, w: rect.w, h: rect.h, rot: image.rotation * Math.PI / 180 });
        });

        project.texts.forEach((text) => {
            if (playhead < text.start || playhead >= text.end) return;
            ctx.save();
            ctx.globalAlpha = text.opacity / 100;
            ctx.translate(text.x, text.y);
            ctx.rotate((text.rotation * Math.PI) / 180);
            ctx.font = (text.italic ? 'italic ' : '') + (text.bold ? 'bold ' : '') + text.fontSize + 'px ' + text.fontFamily;
            ctx.fillStyle = text.color;
            ctx.textAlign = text.align;
            ctx.textBaseline = 'middle';
            ctx.fillText(text.content, 0, 0);
            const metrics = ctx.measureText(text.content);
            const width = Math.max(8, metrics.width);
            const height = text.fontSize * 1.2;
            text.boxWidth = width;
            text.boxHeight = height;
            ctx.restore();
            const centerX = text.align === 'left' ? text.x + width / 2 : (text.align === 'right' ? text.x - width / 2 : text.x);
            hits.push({ type: 'text', id: text.id, cx: centerX, cy: text.y, w: width, h: height, rot: text.rotation * Math.PI / 180 });
        });

        const selected = VE.App && VE.App.selection();
        const hit = selected && hits.find((item) => item.type === selected.type && item.id === selected.id);
        if (hit) drawHandles(ctx, hit);
    }

    function drawHandles(ctx, hit) {
        ctx.save();
        ctx.translate(hit.cx, hit.cy);
        ctx.rotate(hit.rot);
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2 / view.scale;
        ctx.strokeRect(-hit.w / 2, -hit.h / 2, hit.w, hit.h);
        const handle = Math.max(10, 14 / view.scale);
        ctx.fillStyle = '#4c8dff';
        ctx.fillRect(hit.w / 2 - handle / 2, hit.h / 2 - handle / 2, handle, handle);
        ctx.restore();
    }

    function clientToProject(clientX, clientY) {
        const surface = canvas();
        const rect = surface.getBoundingClientRect();
        const x = (clientX - rect.left) * (surface.width / rect.width);
        const y = (clientY - rect.top) * (surface.height / rect.height);
        return { x: (x - view.ox) / view.scale, y: (y - view.oy) / view.scale };
    }

    function hitTest(point) {
        for (let index = hits.length - 1; index >= 0; index -= 1) {
            const hit = hits[index];
            const dx = point.x - hit.cx;
            const dy = point.y - hit.cy;
            const angle = -hit.rot;
            const localX = dx * Math.cos(angle) - dy * Math.sin(angle);
            const localY = dx * Math.sin(angle) + dy * Math.cos(angle);
            const pad = 10 / view.scale;
            if (Math.abs(localX) <= hit.w / 2 + pad && Math.abs(localY) <= hit.h / 2 + pad) {
                const onHandle = localX > hit.w / 2 - 16 / view.scale && localY > hit.h / 2 - 16 / view.scale;
                return { hit, handle: onHandle };
            }
        }
        return null;
    }

    function measure(text) {
        const surface = canvas();
        const ctx = surface.getContext('2d');
        ctx.save();
        ctx.font = (text.italic ? 'italic ' : '') + (text.bold ? 'bold ' : '') + text.fontSize + 'px ' + text.fontFamily;
        const width = ctx.measureText(text.content).width;
        ctx.restore();
        return { boxWidth: Math.max(8, width), boxHeight: text.fontSize * 1.2 };
    }

    function play() {
        const duration = VE.Project.duration();
        if (duration <= 0) {
            VE.App.toast('Add media to the timeline first.', 'error');
            return;
        }
        if (playhead >= duration - 0.01) playhead = 0;
        playing = true;
        anchorTime = performance.now();
        anchorHead = playhead;
        syncMedia(true);
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(tick);
        VE.App.syncTransport();
    }

    function pause() {
        playing = false;
        cancelAnimationFrame(raf);
        syncMedia(false);
        VE.App.syncTransport();
    }

    function stop() {
        pause();
        setPlayhead(0);
    }

    function toggleMute() {
        previewMuted = !previewMuted;
        syncMedia(playing);
        const icon = document.querySelector('#btnMutePreview i');
        icon.className = previewMuted ? 'fa-solid fa-volume-xmark' : 'fa-solid fa-volume-high';
    }

    function tick() {
        if (!playing) return;
        const duration = VE.Project.duration();
        const next = anchorHead + (performance.now() - anchorTime) / 1000;
        if (next >= duration) {
            setPlayhead(duration);
            pause();
            return;
        }
        playhead = next;
        draw();
        VE.Timeline.syncPlayhead();
        VE.App.syncClock();
        syncMedia(true);
        raf = requestAnimationFrame(tick);
    }

    function bindPointer() {
        const surface = canvas();
        surface.addEventListener('pointerdown', (event) => {
            const point = clientToProject(event.clientX, event.clientY);
            const found = hitTest(point);
            if (!found) return;
            VE.App.select(found.hit.type, found.hit.id);
            const item = VE.Project.find(found.hit.type, found.hit.id);
            drag = {
                type: found.hit.type,
                id: found.hit.id,
                mode: found.handle ? 'resize' : 'move',
                x: point.x,
                y: point.y,
                originX: item.x,
                originY: item.y,
                originScale: item.scale || 1,
                originSize: item.fontSize || 64,
                cx: found.hit.cx,
                cy: found.hit.cy,
            };
            surface.setPointerCapture(event.pointerId);
        });
        surface.addEventListener('pointermove', (event) => {
            if (!drag) return;
            const point = clientToProject(event.clientX, event.clientY);
            VE.Project.change((draft) => {
                const list = draft[drag.type === 'clip' ? 'clips' : drag.type + 's'];
                const itemRef = list.find((entry) => entry.id === drag.id);
                if (!itemRef) return;
                if (drag.mode === 'move') {
                    itemRef.x = drag.originX + (point.x - drag.x);
                    itemRef.y = drag.originY + (point.y - drag.y);
                } else if (drag.type === 'text') {
                    const distance = Math.hypot(point.x - drag.cx, point.y - drag.cy);
                    const start = Math.hypot(drag.x - drag.cx, drag.y - drag.cy) || 1;
                    itemRef.fontSize = Math.max(8, Math.round(drag.originSize * (distance / start)));
                } else {
                    const distance = Math.hypot(point.x - drag.cx, point.y - drag.cy);
                    const start = Math.hypot(drag.x - drag.cx, drag.y - drag.cy) || 1;
                    itemRef.scale = Math.max(0.05, drag.originScale * (distance / start));
                }
            }, false);
        });
        const endDrag = () => {
            if (!drag) return;
            drag = null;
            VE.Project.commit();
        };
        surface.addEventListener('pointerup', endDrag);
        surface.addEventListener('pointercancel', endDrag);
    }

    function bind() {
        layout();
        bindPointer();
        window.addEventListener('resize', layout);
        document.getElementById('btnPlay').addEventListener('click', () => playing ? pause() : play());
        document.getElementById('btnStop').addEventListener('click', stop);
        document.getElementById('btnStart').addEventListener('click', () => { pause(); setPlayhead(0); });
        document.getElementById('btnEnd').addEventListener('click', () => { pause(); setPlayhead(VE.Project.duration()); });
        document.getElementById('btnMutePreview').addEventListener('click', toggleMute);
        const seek = document.getElementById('seekBar');
        seek.addEventListener('input', () => {
            const duration = VE.Project.duration();
            setPlayhead((Number(seek.value) / 1000) * duration);
            anchorTime = performance.now();
            anchorHead = playhead;
        });
    }

    return { bind, layout, draw, syncPool, setPlayhead, getPlayhead, isPlaying, play, pause, stop, measure };
})();
