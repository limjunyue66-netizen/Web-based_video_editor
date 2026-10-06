window.VE = window.VE || {};

VE.Inspector = (function () {
    const speeds = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2, 4];
    let gesture = false;

    function field(label, html) {
        return '<label class="field">' + label + html + '</label>';
    }

    function numberInput(key, value, step, min, max) {
        return '<input data-key="' + key + '" type="number" step="' + step + '" min="' + min + '" max="' + max + '" value="' + value + '">';
    }

    function render() {
        const panel = document.getElementById('selectionPanel');
        const selected = VE.App.selection();
        if (!selected) {
            panel.innerHTML = '<p class="empty-copy">Select a clip on the timeline to edit trim, speed, volume, text, or position.</p>';
            return;
        }
        const item = VE.Project.find(selected.type, selected.id);
        if (!item) {
            panel.innerHTML = '<p class="empty-copy">This item is no longer on the timeline.</p>';
            return;
        }
        if (selected.type === 'clip') panel.innerHTML = videoForm(item);
        else if (selected.type === 'image') panel.innerHTML = imageForm(item);
        else if (selected.type === 'text') panel.innerHTML = textForm(item);
        else panel.innerHTML = audioForm(item);
        bind(panel, selected);
    }

    function videoForm(clip) {
        const knownSpeed = speeds.some((speed) => Math.abs(speed - clip.speed) < 0.001);
        const speedOptions = (knownSpeed ? '' : '<option value="' + clip.speed + '" selected>' + clip.speed + 'x</option>')
            + speeds.map((speed) => '<option value="' + speed + '"' + (Math.abs(clip.speed - speed) < 0.001 ? ' selected' : '') + '>' + speed + 'x</option>').join('');
        return '<h3>Video clip</h3>'
            + field('Clip name', '<input data-key="name" type="text" maxlength="140" value="' + escapeAttr(clip.name) + '">')
            + field('Start position', numberInput('timelineStart', round(clip.timelineStart), '0.1', '0', '99999'))
            + field('In point', numberInput('inPoint', round(clip.inPoint), '0.1', '0', clip.sourceDuration))
            + field('Out point', numberInput('outPoint', round(clip.outPoint), '0.1', '0', clip.sourceDuration))
            + field('Source duration', '<input type="text" readonly value="' + round(clip.sourceDuration) + ' s">')
            + field('Playback speed', '<select data-key="speed">' + speedOptions + '</select>')
            + field('Custom speed', numberInput('speed', clip.speed, '0.05', '0.25', '4'))
            + field('Volume', '<input data-key="volume" type="range" min="0" max="100" value="' + clip.volume + '">')
            + '<label class="check"><input data-key="muted" type="checkbox"' + (clip.muted ? ' checked' : '') + '> Mute clip audio</label>'
            + '<div class="field-row">' + field('X offset', numberInput('x', round(clip.x), '1', '-4000', '4000')) + field('Y offset', numberInput('y', round(clip.y), '1', '-4000', '4000')) + '</div>'
            + field('Scale', numberInput('scale', clip.scale, '0.05', '0.05', '4'))
            + field('Rotation', rotationSelect(clip.rotation))
            + '<div class="field-row">'
            + field('Crop left', numberInput('crop.left', clip.crop.left, '1', '0', '4000'))
            + field('Crop top', numberInput('crop.top', clip.crop.top, '1', '0', '4000'))
            + field('Crop right', numberInput('crop.right', clip.crop.right, '1', '0', '4000'))
            + field('Crop bottom', numberInput('crop.bottom', clip.crop.bottom, '1', '0', '4000'))
            + '</div>'
            + actions();
    }

    function imageForm(image) {
        return '<h3>Image overlay</h3>'
            + field('Name', '<input type="text" readonly value="' + escapeAttr(image.name) + '">')
            + '<div class="field-row">' + field('X position', numberInput('x', round(image.x), '1', '-4000', '4000')) + field('Y position', numberInput('y', round(image.y), '1', '-4000', '4000')) + '</div>'
            + field('Width', numberInput('width', round(image.width), '1', '8', '4000'))
            + field('Scale', numberInput('scale', image.scale, '0.05', '0.05', '5'))
            + field('Start time', numberInput('start', round(image.start), '0.1', '0', '99999'))
            + field('End time', numberInput('end', round(image.end), '0.1', '0', '99999'))
            + field('Opacity', '<input data-key="opacity" type="range" min="0" max="100" value="' + image.opacity + '">')
            + field('Rotation', rotationSelect(image.rotation))
            + actions();
    }

    function textForm(text) {
        const fonts = VE.Project.FONTS.map((font) => '<option' + (font === text.fontFamily ? ' selected' : '') + '>' + font + '</option>').join('');
        return '<h3>Text</h3>'
            + field('Text content', '<input data-key="content" type="text" maxlength="240" value="' + escapeAttr(text.content) + '">')
            + field('Font family', '<select data-key="fontFamily">' + fonts + '</select>')
            + field('Font size', numberInput('fontSize', text.fontSize, '1', '8', '320'))
            + field('Font color', '<input data-key="color" type="color" value="' + text.color + '">')
            + '<label class="check"><input data-key="bold" type="checkbox"' + (text.bold ? ' checked' : '') + '> Bold</label>'
            + '<label class="check"><input data-key="italic" type="checkbox"' + (text.italic ? ' checked' : '') + '> Italic</label>'
            + field('Alignment', '<select data-key="align"><option' + (text.align === 'left' ? ' selected' : '') + '>left</option><option' + (text.align === 'center' ? ' selected' : '') + '>center</option><option' + (text.align === 'right' ? ' selected' : '') + '>right</option></select>')
            + '<div class="field-row">' + field('X position', numberInput('x', round(text.x), '1', '-4000', '4000')) + field('Y position', numberInput('y', round(text.y), '1', '-4000', '4000')) + '</div>'
            + field('Start time', numberInput('start', round(text.start), '0.1', '0', '99999'))
            + field('End time', numberInput('end', round(text.end), '0.1', '0', '99999'))
            + field('Opacity', '<input data-key="opacity" type="range" min="0" max="100" value="' + text.opacity + '">')
            + field('Rotation', rotationSelect(text.rotation))
            + actions();
    }

    function audioForm(audio) {
        return '<h3>Audio</h3>'
            + field('Name', '<input data-key="name" type="text" maxlength="140" value="' + escapeAttr(audio.name) + '">')
            + field('Start time', numberInput('timelineStart', round(audio.timelineStart), '0.1', '0', '99999'))
            + field('In point', numberInput('inPoint', round(audio.inPoint), '0.1', '0', audio.sourceDuration))
            + field('Out point', numberInput('outPoint', round(audio.outPoint), '0.1', '0', audio.sourceDuration))
            + field('Source duration', '<input type="text" readonly value="' + round(audio.sourceDuration) + ' s">')
            + field('Volume', '<input data-key="volume" type="range" min="0" max="100" value="' + audio.volume + '">')
            + field('Track number', numberInput('track', audio.track, '1', '1', '8'))
            + actions();
    }

    function rotationSelect(value) {
        return '<select data-key="rotation">' + [0, 90, 180, 270].map((angle) => {
            return '<option value="' + angle + '"' + (Number(value) === angle ? ' selected' : '') + '>' + angle + '°</option>';
        }).join('') + '</select>';
    }

    function actions() {
        return '<div class="prop-actions"><button type="button" class="text-btn" data-action="split"><i class="fa-solid fa-scissors"></i> Split</button>'
            + '<button type="button" class="text-btn danger" data-action="delete">Delete</button></div>';
    }

    function bind(panel, selected) {
        panel.querySelectorAll('[data-key]').forEach((input) => {
            const eventName = input.type === 'range' || input.type === 'color' ? 'input' : 'input';
            input.addEventListener('focus', () => { gesture = true; });
            input.addEventListener(eventName, () => {
                write(selected, input.dataset.key, readControl(input));
            });
            input.addEventListener('change', () => {
                gesture = false;
                VE.Project.commit();
                if (input.dataset.key === 'speed' || input.type !== 'range') render();
            });
        });
        const split = panel.querySelector('[data-action="split"]');
        if (split) {
            split.hidden = selected.type !== 'clip';
            split.addEventListener('click', () => VE.App.splitSelection());
        }
        panel.querySelector('[data-action="delete"]').addEventListener('click', () => VE.App.deleteSelection());
    }

    function write(selected, key, value) {
        VE.Project.change((draft) => {
            const keyName = selected.type === 'clip' ? 'clips' : selected.type + 's';
            const item = draft[keyName].find((entry) => entry.id === selected.id);
            if (!item) return;
            if (key.startsWith('crop.')) {
                item.crop[key.split('.')[1]] = value;
            } else {
                item[key] = value;
            }
            if (selected.type === 'text') {
                const measured = VE.Preview.measure(item);
                item.boxWidth = measured.boxWidth;
                item.boxHeight = measured.boxHeight;
            }
        }, false);
    }

    function readControl(input) {
        if (input.type === 'checkbox') return input.checked;
        if (input.type === 'number' || input.type === 'range') return Number(input.value);
        if (input.dataset.key === 'speed' || input.dataset.key === 'rotation' || input.dataset.key === 'track' || input.dataset.key === 'fontSize') {
            return Number(input.value);
        }
        return input.value;
    }

    function syncStatic() {
        if (gesture) return;
        const project = VE.Project.get();
        setValue('projectFps', project.fps);
        setValue('projectWidth', project.width);
        setValue('projectHeight', project.height);
        const preset = project.width + 'x' + project.height;
        const presetBox = document.getElementById('projectPreset');
        presetBox.value = ['1920x1080', '1280x720', '1080x1920'].includes(preset) ? preset : '1920x1080';
        document.getElementById('muteOriginal').checked = project.muteOriginal;
        document.getElementById('musicVolume').value = project.musicVolume;
        document.getElementById('musicVolumeValue').textContent = String(Math.round(project.musicVolume));
        document.querySelectorAll('.filter-range').forEach((input) => {
            if (document.activeElement === input) return;
            input.value = project.filters[input.dataset.filter];
            const label = document.querySelector('[data-for="' + input.dataset.filter + '"]');
            if (label) label.textContent = String(input.value);
        });
        const name = document.getElementById('projectName');
        if (document.activeElement !== name) name.value = project.name;
        const mode = document.getElementById('exportMode');
        if (document.activeElement !== mode) mode.value = project.exportMode;
    }

    function setValue(id, value) {
        const element = document.getElementById(id);
        if (document.activeElement !== element) element.value = value;
    }

    function bindStatic() {
        const bindChange = (id, apply) => {
            const element = document.getElementById(id);
            element.addEventListener('input', () => {
                gesture = true;
                VE.Project.change(apply, false);
            });
            element.addEventListener('change', () => {
                gesture = false;
                VE.Project.commit();
            });
        };
        document.getElementById('projectName').addEventListener('input', (event) => {
            VE.Project.change((draft) => { draft.name = event.target.value; }, false);
        });
        document.getElementById('projectName').addEventListener('change', () => VE.Project.commit());
        bindChange('projectFps', (draft) => { draft.fps = Number(document.getElementById('projectFps').value); });
        bindChange('projectWidth', (draft) => { draft.width = Number(document.getElementById('projectWidth').value); });
        bindChange('projectHeight', (draft) => { draft.height = Number(document.getElementById('projectHeight').value); });
        document.getElementById('projectPreset').addEventListener('change', (event) => {
            const [width, height] = event.target.value.split('x').map(Number);
            VE.Project.change((draft) => { draft.width = width; draft.height = height; });
        });
        document.getElementById('muteOriginal').addEventListener('change', (event) => {
            VE.Project.change((draft) => { draft.muteOriginal = event.target.checked; });
        });
        bindChange('musicVolume', (draft) => { draft.musicVolume = Number(document.getElementById('musicVolume').value); });
        document.getElementById('exportMode').addEventListener('change', (event) => {
            VE.Project.change((draft) => { draft.exportMode = event.target.value; });
        });
        document.querySelectorAll('.filter-range').forEach((input) => {
            input.addEventListener('input', () => {
                gesture = true;
                const label = document.querySelector('[data-for="' + input.dataset.filter + '"]');
                label.textContent = input.value;
                VE.Project.change((draft) => { draft.filters[input.dataset.filter] = Number(input.value); }, false);
            });
            input.addEventListener('change', () => {
                gesture = false;
                VE.Project.commit();
            });
        });
    }

    function round(value) {
        return Math.round(Number(value) * 100) / 100;
    }

    function escapeAttr(value) {
        return String(value).replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[char]));
    }

    return { render, syncStatic, bindStatic };
})();
