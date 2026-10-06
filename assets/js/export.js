window.VE = window.VE || {};

VE.Export = (function () {
    let timer = 0;
    let modal = null;
    let started = 0;

    function bind() {
        modal = new bootstrap.Modal(document.getElementById('exportModal'));
        document.getElementById('btnExport').addEventListener('click', start);
    }

    async function start() {
        const project = JSON.parse(JSON.stringify(VE.Project.get()));
        if (VE.Project.duration() <= 0) {
            VE.App.toast('Empty project. Add media before exporting.', 'error');
            return;
        }
        project.texts.forEach((text) => {
            const measured = VE.Preview.measure(text);
            text.boxWidth = measured.boxWidth;
            text.boxHeight = measured.boxHeight;
        });
        resetView();
        modal.show();
        document.getElementById('exportMessage').textContent = 'Sending the project to the server…';
        try {
            const response = await fetch('api/export.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(project),
            });
            const data = await readReply(response);
            if (!data.ok) throw new Error(data.message || 'Export failed.');
            started = Date.now();
            poll(data.job.id);
        } catch (error) {
            showError(error.message || 'Export failed.');
        }
    }

    function poll(id) {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            try {
                const response = await fetch('api/export_status.php?id=' + encodeURIComponent(id));
                const data = await readReply(response);
                if (!data.ok) throw new Error(data.message || 'Export job not found.');
                paint(data.job);
                if (data.job.status === 'running' || data.job.status === 'queued') poll(id);
            } catch (error) {
                showError(error.message || 'Export failed.');
            }
        }, 700);
    }

    function paint(job) {
        const progress = Math.max(0, Math.min(100, job.progress || 0));
        document.getElementById('exportMessage').textContent = job.message || 'Encoding video';
        document.getElementById('exportStatus').textContent = job.status;
        document.getElementById('exportPercent').textContent = Math.round(progress) + '%';
        document.getElementById('exportProcessed').textContent = clock(job.processedTime || 0);
        document.getElementById('exportElapsed').textContent = clock(job.elapsed || (Date.now() - started) / 1000);
        const bar = document.getElementById('exportBar');
        bar.style.width = progress + '%';
        bar.textContent = Math.round(progress) + '%';
        const error = document.getElementById('exportError');
        if (job.status === 'error') {
            error.hidden = false;
            error.textContent = job.error || 'Export failed.';
            bar.classList.add('bg-danger');
        }
        if (job.status === 'done' && job.downloadUrl) {
            const link = document.getElementById('exportDownload');
            link.hidden = false;
            link.href = job.downloadUrl;
        }
    }

    async function readReply(response) {
        const raw = await response.text();
        if (!raw) throw new Error('Export could not start. Please try again.');
        try {
            return JSON.parse(raw);
        } catch (error) {
            throw new Error('Export could not start. Please try again.');
        }
    }

    function showError(message) {
        document.getElementById('exportMessage').textContent = 'Export failed';
        document.getElementById('exportStatus').textContent = 'error';
        const error = document.getElementById('exportError');
        error.hidden = false;
        error.textContent = message;
    }

    function resetView() {
        clearTimeout(timer);
        document.getElementById('exportMessage').textContent = 'Preparing export…';
        document.getElementById('exportStatus').textContent = 'queued';
        document.getElementById('exportPercent').textContent = '0%';
        document.getElementById('exportProcessed').textContent = '0:00';
        document.getElementById('exportElapsed').textContent = '0:00';
        const bar = document.getElementById('exportBar');
        bar.style.width = '0%';
        bar.textContent = '0%';
        bar.classList.remove('bg-danger');
        document.getElementById('exportError').hidden = true;
        document.getElementById('exportDownload').hidden = true;
    }

    function clock(seconds) {
        const total = Math.max(0, Math.floor(seconds));
        return Math.floor(total / 60) + ':' + String(total % 60).padStart(2, '0');
    }

    return { bind };
})();
