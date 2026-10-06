window.VE = window.VE || {};

VE.Upload = (function () {
    let limits = { video: 64 * 1024 * 1024, image: 25 * 1024 * 1024, audio: 80 * 1024 * 1024 };
    const queue = [];
    let active = false;

    const extensions = {
        video: ['mp4', 'webm', 'mov', 'wmv'],
        image: ['jpg', 'jpeg', 'png', 'webp'],
        audio: ['mp3', 'wav', 'aac', 'm4a'],
    };

    function setLimits(next) {
        limits = next || limits;
        const hint = document.getElementById('uploadHint');
        if (hint) {
            hint.textContent = 'Limits: video ' + mb(limits.video) + ', image ' + mb(limits.image) + ', audio ' + mb(limits.audio);
        }
    }

    function mb(bytes) {
        return Math.max(1, Math.round(bytes / (1024 * 1024))) + ' MB';
    }

    function kindOf(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (extensions.video.includes(ext)) return 'video';
        if (extensions.image.includes(ext)) return 'image';
        if (extensions.audio.includes(ext)) return 'audio';
        return null;
    }

    function enqueue(fileList) {
        Array.from(fileList || []).forEach((file) => queue.push(file));
        pump();
    }

    async function pump() {
        if (active) return;
        active = true;
        while (queue.length) {
            await send(queue.shift());
        }
        active = false;
    }

    function send(file) {
        return new Promise((resolve) => {
            const kind = kindOf(file);
            const row = addRow(file.name);
            if (!kind) {
                finishRow(row, 'Unsupported file type.', true);
                resolve();
                return;
            }
            if (file.size > (limits[kind] || limits.video)) {
                finishRow(row, 'File is too large.', true);
                resolve();
                return;
            }
            const body = new FormData();
            body.append('file', file);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'api/upload.php');
            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) return;
                setBar(row, Math.round((event.loaded / event.total) * 100));
            };
            xhr.upload.onload = () => {
                row.querySelector('small').textContent = 'Processing media…';
            };
            xhr.onreadystatechange = async () => {
                if (xhr.readyState !== 4) return;
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (error) { data = null; }
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    finishRow(row, 'Uploaded', false);
                    await VE.Media.load();
                } else {
                    finishRow(row, (data && data.message) || 'Upload failed.', true);
                }
                resolve();
            };
            xhr.onerror = () => {
                finishRow(row, 'Upload failed.', true);
                resolve();
            };
            xhr.send(body);
        });
    }

    function addRow(name) {
        const list = document.getElementById('uploadList');
        const row = document.createElement('div');
        row.className = 'upload-item';
        row.innerHTML = '<div class="d-flex justify-content-between"><span></span><small>0%</small></div>'
            + '<div class="progress mt-1"><div class="progress-bar" style="width:0%"></div></div>';
        row.querySelector('span').textContent = name;
        list.prepend(row);
        return row;
    }

    function setBar(row, percent) {
        row.querySelector('.progress-bar').style.width = percent + '%';
        row.querySelector('small').textContent = percent + '%';
    }

    function finishRow(row, message, failed) {
        row.querySelector('small').textContent = message;
        row.querySelector('.progress-bar').style.width = '100%';
        if (failed) row.querySelector('.progress-bar').classList.add('bg-danger');
        setTimeout(() => row.remove(), failed ? 5000 : 1800);
    }

    function bind() {
        const input = document.getElementById('fileInput');
        const zone = document.getElementById('dropzone');
        document.getElementById('btnBrowse').addEventListener('click', () => input.click());
        input.addEventListener('change', () => {
            enqueue(input.files);
            input.value = '';
        });
        ['dragenter', 'dragover'].forEach((name) => zone.addEventListener(name, (event) => {
            event.preventDefault();
            zone.classList.add('dragover');
        }));
        ['dragleave', 'drop'].forEach((name) => zone.addEventListener(name, (event) => {
            event.preventDefault();
            zone.classList.remove('dragover');
        }));
        zone.addEventListener('drop', (event) => enqueue(event.dataTransfer.files));
    }

    return { bind, setLimits, enqueue };
})();
