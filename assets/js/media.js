window.VE = window.VE || {};

VE.Media = (function () {
    let items = [];
    let filter = 'all';
    const listeners = new Set();

    function onChange(listener) {
        listeners.add(listener);
    }

    function notify() {
        listeners.forEach((listener) => listener(items));
    }

    async function load() {
        const response = await fetch('api/media.php');
        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.message || 'The media library could not be loaded.');
        }
        items = data.media || [];
        notify();
        return items;
    }

    function all() {
        return items;
    }

    function get(id) {
        return items.find((item) => item.id === id) || null;
    }

    function setFilter(next) {
        filter = next;
        render();
    }

    function formatTime(seconds) {
        if (!Number.isFinite(seconds) || seconds <= 0) return '';
        const total = Math.floor(seconds);
        const minutes = Math.floor(total / 60);
        const remain = total % 60;
        return minutes + ':' + String(remain).padStart(2, '0');
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[char]));
    }

    function render() {
        const list = document.getElementById('mediaList');
        if (!list) return;
        const visible = items.filter((item) => filter === 'all' || item.type === filter);
        if (!visible.length) {
            list.innerHTML = '<p class="empty-copy px-2">No media in this category yet.</p>';
            return;
        }
        list.innerHTML = visible.map((item) => {
            const icon = '<i class="fa-solid ' + (item.type === 'audio' ? 'fa-music' : 'fa-video') + '"></i>';
            const thumbSrc = item.thumbUrl || (item.type === 'image' ? item.url : '');
            const thumb = thumbSrc ? '<img src="' + escapeHtml(thumbSrc) + '" alt="">' : icon;
            const meta = [item.type, item.duration ? formatTime(item.duration) : ''].filter(Boolean).join(' · ');
            return '<article class="media-card" draggable="true" data-id="' + item.id + '">'
                + '<div class="thumb">' + thumb + '</div>'
                + '<div><div class="media-name" title="' + escapeHtml(item.name) + '">' + escapeHtml(item.name) + '</div>'
                + '<div class="media-meta">' + escapeHtml(meta) + '</div></div>'
                + '<div class="media-actions">'
                + '<button type="button" class="text-btn" data-add="' + item.id + '" title="Add to timeline">Add</button>'
                + '<button type="button" class="text-btn danger" data-delete="' + item.id + '" title="Delete media">Delete</button>'
                + '</div></article>';
        }).join('');
    }

    async function remove(id) {
        const response = await fetch('api/media.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id }),
        });
        const data = await response.json();
        if (!data.ok) throw new Error(data.message || 'Media not found.');
        VE.Project.removeMedia(id);
        await load();
    }

    return { load, all, get, setFilter, render, remove, onChange };
})();
