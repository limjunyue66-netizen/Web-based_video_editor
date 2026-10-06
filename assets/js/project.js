window.VE = window.VE || {};

VE.Project = (function () {
    const STORAGE_KEY = 'videoEditor.project.v1';
    const LIMIT = 50;
    const MIN = 0.1;
    const FONTS = ['Arial', 'Verdana', 'Georgia', 'Times New Roman', 'Courier New'];

    let project = defaultProject();
    let past = [];
    let future = [];
    const listeners = new Set();
    let saveTimer = 0;

    function uid() {
        const bytes = new Uint8Array(8);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
    }

    function defaultFilters() {
        return { brightness: 0, contrast: 0, saturation: 0, grayscale: 0, blur: 0, sepia: 0 };
    }

    function defaultProject() {
        return {
            name: 'Untitled Project',
            fps: 30,
            width: 1920,
            height: 1080,
            clips: [],
            images: [],
            texts: [],
            audios: [],
            filters: defaultFilters(),
            muteOriginal: false,
            musicVolume: 100,
            exportMode: 'fast',
        };
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function even(value) {
        const number = Math.max(2, Math.round(value));
        return number % 2 === 0 ? number : number - 1;
    }

    function get() {
        return project;
    }

    function onChange(listener) {
        listeners.add(listener);
        return () => listeners.delete(listener);
    }

    function emit(live) {
        listeners.forEach((listener) => listener(project, { live: !!live }));
        scheduleSave();
    }

    function sanitizeClip(clip) {
        clip.speed = clamp(Number(clip.speed) || 1, 0.25, 4);
        const source = Math.max(MIN, Number(clip.sourceDuration) || Number(clip.outPoint) || MIN);
        clip.sourceDuration = source;
        const minSource = MIN * clip.speed;
        clip.inPoint = clamp(Number(clip.inPoint) || 0, 0, Math.max(0, source - minSource));
        clip.outPoint = clamp(Number(clip.outPoint) || source, clip.inPoint + minSource, source);
        clip.timelineStart = Math.max(0, Number(clip.timelineStart) || 0);
        clip.volume = clamp(Number(clip.volume ?? 100), 0, 100);
        clip.muted = !!clip.muted;
        clip.x = Number(clip.x) || 0;
        clip.y = Number(clip.y) || 0;
        clip.scale = clamp(Number(clip.scale) || 1, 0.05, 4);
        clip.rotation = [0, 90, 180, 270].includes(Number(clip.rotation)) ? Number(clip.rotation) : 0;
        const crop = clip.crop || {};
        clip.crop = {
            left: Math.max(0, Number(crop.left) || 0),
            top: Math.max(0, Number(crop.top) || 0),
            right: Math.max(0, Number(crop.right) || 0),
            bottom: Math.max(0, Number(crop.bottom) || 0),
        };
        clip.name = String(clip.name || 'Clip').slice(0, 140);
    }

    function sanitizeImage(image) {
        image.x = Number(image.x) || 0;
        image.y = Number(image.y) || 0;
        image.width = clamp(Number(image.width) || 480, 8, 4000);
        image.scale = clamp(Number(image.scale) || 1, 0.05, 5);
        image.opacity = clamp(Number(image.opacity ?? 100), 0, 100);
        image.rotation = [0, 90, 180, 270].includes(Number(image.rotation)) ? Number(image.rotation) : 0;
        image.start = Math.max(0, Number(image.start) || 0);
        image.end = Math.max(image.start + MIN, Number(image.end) || image.start + 5);
        image.name = String(image.name || 'Image').slice(0, 140);
    }

    function sanitizeText(text) {
        text.content = String(text.content || 'Your text').replace(/\s+/g, ' ').trim().slice(0, 240) || 'Your text';
        text.fontFamily = FONTS.includes(text.fontFamily) ? text.fontFamily : 'Arial';
        text.fontSize = clamp(Math.round(Number(text.fontSize) || 64), 8, 320);
        text.color = /^#[0-9a-fA-F]{6}$/.test(text.color || '') ? text.color : '#ffffff';
        text.bold = !!text.bold;
        text.italic = !!text.italic;
        text.align = ['left', 'center', 'right'].includes(text.align) ? text.align : 'center';
        text.x = Number(text.x) || 0;
        text.y = Number(text.y) || 0;
        text.opacity = clamp(Number(text.opacity ?? 100), 0, 100);
        text.rotation = [0, 90, 180, 270].includes(Number(text.rotation)) ? Number(text.rotation) : 0;
        text.start = Math.max(0, Number(text.start) || 0);
        text.end = Math.max(text.start + MIN, Number(text.end) || text.start + 5);
        text.boxWidth = Math.max(0, Number(text.boxWidth) || 0);
        text.boxHeight = Math.max(0, Number(text.boxHeight) || 0);
    }

    function sanitizeAudio(audio) {
        const source = Math.max(MIN, Number(audio.sourceDuration) || Number(audio.outPoint) || MIN);
        audio.sourceDuration = source;
        audio.inPoint = clamp(Number(audio.inPoint) || 0, 0, Math.max(0, source - MIN));
        audio.outPoint = clamp(Number(audio.outPoint) || source, audio.inPoint + MIN, source);
        audio.timelineStart = Math.max(0, Number(audio.timelineStart) || 0);
        audio.volume = clamp(Number(audio.volume ?? 100), 0, 100);
        audio.track = clamp(Math.round(Number(audio.track) || 1), 1, 8);
        audio.name = String(audio.name || 'Audio').slice(0, 140);
    }

    function normalize(target) {
        target.name = String(target.name || 'Untitled Project').trim().slice(0, 80) || 'Untitled Project';
        target.fps = [24, 25, 30, 60].includes(Number(target.fps)) ? Number(target.fps) : 30;
        target.width = even(clamp(Number(target.width) || 1920, 320, 1920));
        target.height = even(clamp(Number(target.height) || 1080, 320, 1920));
        target.clips = Array.isArray(target.clips) ? target.clips : [];
        target.images = Array.isArray(target.images) ? target.images : [];
        target.texts = Array.isArray(target.texts) ? target.texts : [];
        target.audios = Array.isArray(target.audios) ? target.audios : [];
        target.filters = Object.assign(defaultFilters(), target.filters || {});
        ['brightness', 'contrast', 'saturation'].forEach((key) => {
            target.filters[key] = clamp(Number(target.filters[key]) || 0, -100, 100);
        });
        target.filters.grayscale = clamp(Number(target.filters.grayscale) || 0, 0, 100);
        target.filters.blur = clamp(Number(target.filters.blur) || 0, 0, 20);
        target.filters.sepia = clamp(Number(target.filters.sepia) || 0, 0, 100);
        target.muteOriginal = !!target.muteOriginal;
        target.musicVolume = clamp(Number(target.musicVolume ?? 100), 0, 100);
        target.exportMode = target.exportMode === 'high' ? 'high' : 'fast';
        target.clips.forEach(sanitizeClip);
        target.images.forEach(sanitizeImage);
        target.texts.forEach(sanitizeText);
        target.audios.forEach(sanitizeAudio);
    }

    function adopt(next, recordHistory) {
        project = next;
        normalize(project);
        if (recordHistory) {
            past = [JSON.stringify(project)];
            future = [];
        }
        emit(false);
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveLocal, 300);
    }

    function saveLocal() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ project, savedAt: new Date().toISOString() }));
        } catch (error) {
            console.warn('Could not save the project locally.', error);
        }
    }

    function loadLocal() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) {
                adopt(defaultProject(), true);
                return false;
            }
            const data = JSON.parse(raw);
            if (!data || typeof data !== 'object' || !data.project) {
                adopt(defaultProject(), true);
                return false;
            }
            adopt(Object.assign(defaultProject(), data.project), true);
            return true;
        } catch (error) {
            adopt(defaultProject(), true);
            return false;
        }
    }

    function snapshotDownload() {
        const blob = new Blob([JSON.stringify(project, null, 2)], { type: 'application/json' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        const safe = project.name.replace(/[^A-Za-z0-9._-]+/g, '_') || 'project';
        link.download = safe + '.json';
        link.click();
        URL.revokeObjectURL(link.href);
        saveLocal();
    }

    function loadData(data) {
        if (!data || typeof data !== 'object') {
            throw new Error('Invalid project JSON.');
        }
        const source = data.project && typeof data.project === 'object' ? data.project : data;
        if (!Array.isArray(source.clips) && !Array.isArray(source.images) && !Array.isArray(source.texts) && !Array.isArray(source.audios)) {
            throw new Error('Invalid project data.');
        }
        adopt(Object.assign(defaultProject(), source), true);
    }

    function change(mutator, record = true) {
        mutator(project);
        normalize(project);
        if (record) {
            const snap = JSON.stringify(project);
            if (past[past.length - 1] !== snap) {
                past.push(snap);
                if (past.length > LIMIT) past.shift();
                future = [];
            }
            emit(false);
        } else {
            emit(true);
        }
    }

    function commit() {
        const snap = JSON.stringify(project);
        if (past[past.length - 1] !== snap) {
            past.push(snap);
            if (past.length > LIMIT) past.shift();
            future = [];
        }
        emit(false);
    }

    function undo() {
        if (past.length < 2) return false;
        future.push(past.pop());
        project = JSON.parse(past[past.length - 1]);
        emit(false);
        return true;
    }

    function redo() {
        if (!future.length) return false;
        const snap = future.pop();
        past.push(snap);
        project = JSON.parse(snap);
        emit(false);
        return true;
    }

    function list(type) {
        if (type === 'clip' || type === 'video') return project.clips;
        if (type === 'image') return project.images;
        if (type === 'text') return project.texts;
        if (type === 'audio') return project.audios;
        return [];
    }

    function find(type, id) {
        return list(type).find((item) => item.id === id) || null;
    }

    function duration(source = project) {
        let end = 0;
        source.clips.forEach((clip) => {
            end = Math.max(end, clip.timelineStart + (clip.outPoint - clip.inPoint) / clip.speed);
        });
        source.images.forEach((item) => { end = Math.max(end, Number(item.end) || 0); });
        source.texts.forEach((item) => { end = Math.max(end, Number(item.end) || 0); });
        source.audios.forEach((item) => {
            end = Math.max(end, item.timelineStart + (item.outPoint - item.inPoint));
        });
        return end;
    }

    function addVideo(media, at) {
        const clip = {
            id: uid(),
            mediaId: media.id,
            name: media.name,
            timelineStart: Math.max(0, at || 0),
            inPoint: 0,
            outPoint: media.duration,
            sourceDuration: media.duration,
            speed: 1,
            volume: 100,
            muted: false,
            x: 0,
            y: 0,
            scale: 1,
            rotation: 0,
            crop: { left: 0, top: 0, right: 0, bottom: 0 },
        };
        change((draft) => draft.clips.push(clip));
        return clip.id;
    }

    function addImage(media, at) {
        const start = Math.max(0, at || 0);
        const image = {
            id: uid(),
            mediaId: media.id,
            name: media.name,
            x: 80,
            y: 80,
            width: Math.min(480, media.width || 480),
            scale: 1,
            rotation: 0,
            opacity: 100,
            start,
            end: start + 5,
        };
        change((draft) => draft.images.push(image));
        return image.id;
    }

    function addText(at) {
        const start = Math.max(0, at || 0);
        const text = {
            id: uid(),
            content: 'Your text',
            fontFamily: 'Arial',
            fontSize: 64,
            color: '#ffffff',
            bold: false,
            italic: false,
            align: 'center',
            x: project.width / 2,
            y: project.height / 2,
            start,
            end: start + 5,
            opacity: 100,
            rotation: 0,
            boxWidth: 0,
            boxHeight: 0,
        };
        change((draft) => draft.texts.push(text));
        return text.id;
    }

    function addAudio(media, at) {
        const occupied = project.audios.some((audio) => {
            const end = audio.timelineStart + (audio.outPoint - audio.inPoint);
            const start = Math.max(0, at || 0);
            const span = media.duration || 1;
            return audio.track === 1 && start < end && start + span > audio.timelineStart;
        });
        const audio = {
            id: uid(),
            mediaId: media.id,
            name: media.name,
            timelineStart: Math.max(0, at || 0),
            inPoint: 0,
            outPoint: media.duration,
            sourceDuration: media.duration,
            volume: 80,
            track: occupied ? 2 : 1,
        };
        change((draft) => draft.audios.push(audio));
        return audio.id;
    }

    function remove(type, id) {
        change((draft) => {
            const key = type === 'clip' || type === 'video' ? 'clips' : type + 's';
            draft[key] = draft[key].filter((item) => item.id !== id);
        });
    }

    function removeMedia(mediaId) {
        change((draft) => {
            draft.clips = draft.clips.filter((item) => item.mediaId !== mediaId);
            draft.images = draft.images.filter((item) => item.mediaId !== mediaId);
            draft.audios = draft.audios.filter((item) => item.mediaId !== mediaId);
        });
    }

    function splitAt(id, time) {
        const clip = project.clips.find((item) => item.id === id);
        if (!clip) {
            return { ok: false, message: 'Select a video clip before splitting.' };
        }
        const end = clip.timelineStart + (clip.outPoint - clip.inPoint) / clip.speed;
        if (time <= clip.timelineStart + 0.05 || time >= end - 0.05) {
            return { ok: false, message: 'Place the playhead inside the clip, away from the edges.' };
        }
        const source = clip.inPoint + (time - clip.timelineStart) * clip.speed;
        const secondId = uid();
        change((draft) => {
            const index = draft.clips.findIndex((item) => item.id === id);
            const current = draft.clips[index];
            const first = Object.assign({}, current, { id: uid(), outPoint: source, crop: Object.assign({}, current.crop) });
            const second = Object.assign({}, current, {
                id: secondId,
                inPoint: source,
                timelineStart: time,
                crop: Object.assign({}, current.crop),
            });
            draft.clips.splice(index, 1, first, second);
        });
        return { ok: true, id: secondId };
    }

    function resetHistoryFromCurrent() {
        past = [JSON.stringify(project)];
        future = [];
    }

    return {
        MIN, FONTS, uid, get, onChange, change, commit, undo, redo,
        canUndo: () => past.length > 1,
        canRedo: () => future.length > 0,
        duration, list, find, addVideo, addImage, addText, addAudio, remove, removeMedia, splitAt,
        loadLocal, saveLocal, snapshotDownload, loadData,
        newProject() { adopt(defaultProject(), true); },
        resetHistoryFromCurrent,
    };
})();
