(function () {
    'use strict';

    var modal = document.getElementById('nmMusicModal');
    var dataNode = document.getElementById('nmMusicData');
    if (!modal || !dataNode) return;

    var songs;
    try {
        songs = JSON.parse(dataNode.textContent || '[]');
    } catch (e) {
        songs = [];
    }
    if (!Array.isArray(songs) || !songs.length) return;

    var audio = document.getElementById('nmMusicAudio');
    var cover = modal.querySelector('[data-music-cover-img]');
    var coverFallback = modal.querySelector('.nm-music-cover-fallback');
    var titleNode = modal.querySelector('[data-music-title]');
    var artistNode = modal.querySelector('[data-music-artist]');
    var seek = document.getElementById('musicSeek');
    var elapsedNode = modal.querySelector('[data-music-elapsed]');
    var totalNode = modal.querySelector('[data-music-total]');
    var toggleBtn = modal.querySelector('[data-music-toggle]');
    var prevBtn = modal.querySelector('[data-music-prev]');
    var nextBtn = modal.querySelector('[data-music-next]');
    var muteBtn = modal.querySelector('[data-music-mute]');
    var volumeRange = document.getElementById('musicVolume');
    var lyricsBox = modal.querySelector('[data-music-lyrics]');
    var listNode = modal.querySelector('[data-music-list]');
    var countNode = modal.querySelector('[data-music-count]');
    var closeBtn = modal.querySelector('.nm-music-close');

    var current = 0;
    var lastFocus = null;
    var STORAGE_KEY = 'northmu-music-volume';

    function formatTime(seconds) {
        seconds = Math.max(0, Math.floor(Number(seconds) || 0));
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function percent(value, total) {
        if (!total || !isFinite(total)) return 0;
        return Math.min(100, Math.max(0, (value / total) * 100));
    }

    function paintRange(input, value) {
        if (!input) return;
        input.style.setProperty('--nm-range-progress', value.toFixed(2) + '%');
    }

    function setPlaying(state) {
        toggleBtn.classList.toggle('is-playing', state);
        toggleBtn.setAttribute('aria-label', state ? '暂停' : '播放');
        var row = listNode.querySelector('.is-current');
        if (row) row.classList.toggle('is-paused', !state);
    }

    function renderLyrics(text) {
        lyricsBox.innerHTML = '';
        var lines = String(text || '').split(/\r?\n/).map(function (line) {
            return line.trim();
        }).filter(function (line) {
            return line !== '';
        });
        if (!lines.length) {
            var hint = document.createElement('p');
            hint.className = 'nm-music-lyrics-hint';
            hint.textContent = '暂无歌词，静静聆听。';
            lyricsBox.appendChild(hint);
            return;
        }
        lines.forEach(function (line) {
            var p = document.createElement('p');
            p.textContent = line;
            lyricsBox.appendChild(p);
        });
        lyricsBox.scrollTop = 0;
    }

    function renderCover(url) {
        if (!cover) return;
        var hasCover = !!(url && String(url).trim());
        cover.hidden = !hasCover;
        if (hasCover) {
            cover.onerror = function () {
                cover.hidden = true;
                coverFallback.hidden = false;
            };
            cover.src = String(url).trim();
            coverFallback.hidden = false;
        } else {
            cover.removeAttribute('src');
            coverFallback.hidden = false;
        }
        if (hasCover) coverFallback.hidden = true;
    }

    function paintList() {
        listNode.innerHTML = '';
        songs.forEach(function (song, index) {
            var li = document.createElement('li');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'nm-music-item' + (index === current ? ' is-current' : '');
            btn.setAttribute('data-music-index', String(index));

            var idx = document.createElement('span');
            idx.className = 'nm-music-item-index';
            idx.textContent = String(index + 1);

            var body = document.createElement('span');
            body.className = 'nm-music-item-body';
            var name = document.createElement('span');
            name.className = 'nm-music-item-title';
            name.textContent = song.title || '未命名歌曲';
            var artist = document.createElement('span');
            artist.className = 'nm-music-item-artist';
            artist.textContent = song.artist || '未知歌手';
            body.appendChild(name);
            body.appendChild(artist);

            var bars = document.createElement('span');
            bars.className = 'nm-music-item-bars';
            bars.setAttribute('aria-hidden', 'true');
            bars.innerHTML = '<span></span><span></span><span></span>';

            btn.appendChild(idx);
            btn.appendChild(body);
            btn.appendChild(bars);
            btn.addEventListener('click', function () {
                loadSong(index, true);
            });
            li.appendChild(btn);
            listNode.appendChild(li);
        });
        countNode.textContent = songs.length + ' 首歌曲';
    }

    function highlightCurrent() {
        Array.prototype.forEach.call(listNode.querySelectorAll('.nm-music-item'), function (row) {
            row.classList.toggle('is-current', Number(row.getAttribute('data-music-index')) === current);
        });
        var active = listNode.querySelector('.is-current');
        if (active && listNode.scrollHeight > listNode.clientHeight) {
            var top = active.offsetTop - listNode.clientHeight / 2 + active.offsetHeight / 2;
            listNode.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }
    }

    function loadSong(index, autoplay) {
        current = (index + songs.length) % songs.length;
        var song = songs[current];
        titleNode.textContent = song.title || '未命名歌曲';
        artistNode.textContent = song.artist || '';
        renderCover(song.cover);
        renderLyrics(song.lyrics);
        audio.src = song.audio;
        seek.value = '0';
        paintRange(seek, 0);
        elapsedNode.textContent = '00:00';
        totalNode.textContent = '00:00';
        highlightCurrent();
        setPlaying(false);
        if (autoplay) {
            var promise = audio.play();
            if (promise && promise.catch) promise.catch(function () { setPlaying(false); });
        }
    }

    function open() {
        if (modal.classList.contains('is-open')) return;
        lastFocus = document.activeElement;
        if (!audio.getAttribute('src')) {
            loadSong(0, false);
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('nm-music-open');
        if (closeBtn) closeBtn.focus({ preventScroll: true });
    }

    function close() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('nm-music-open');
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    toggleBtn.addEventListener('click', function () {
        if (audio.paused) {
            var promise = audio.play();
            if (promise && promise.catch) {
                promise.catch(function () {
                    setPlaying(false);
                    if (window.NorthmuUI && window.NorthmuUI.toast) {
                        window.NorthmuUI.toast('音频无法播放，请检查歌曲链接', 'error');
                    }
                });
            }
        } else {
            audio.pause();
        }
    });

    prevBtn.addEventListener('click', function () {
        loadSong(current - 1, true);
    });

    nextBtn.addEventListener('click', function () {
        loadSong(current + 1, true);
    });

    audio.addEventListener('play', function () { setPlaying(true); });
    audio.addEventListener('pause', function () { setPlaying(false); });

    audio.addEventListener('loadedmetadata', function () {
        totalNode.textContent = formatTime(audio.duration);
        seek.max = String(Math.max(1, Math.round(audio.duration * 10)));
    });

    audio.addEventListener('timeupdate', function () {
        var duration = audio.duration || 0;
        var value = duration ? percent(audio.currentTime, duration) : 0;
        if (!seek.matches(':active')) {
            seek.value = String(Math.round(value / 10));
        }
        paintRange(seek, value);
        elapsedNode.textContent = formatTime(audio.currentTime);
        if (duration) totalNode.textContent = formatTime(duration);
    });

    audio.addEventListener('ended', function () {
        loadSong(current + 1, true);
    });

    audio.addEventListener('error', function () {
        setPlaying(false);
        if (window.NorthmuUI && window.NorthmuUI.toast) {
            window.NorthmuUI.toast('歌曲加载失败，请检查音频链接', 'error');
        }
    });

    seek.addEventListener('input', function () {
        var ratio = Number(seek.value) / Number(seek.max || 1000);
        paintRange(seek, ratio * 100);
        if (audio.duration) {
            elapsedNode.textContent = formatTime(ratio * audio.duration);
        }
    });

    seek.addEventListener('change', function () {
        if (!audio.duration) return;
        audio.currentTime = (Number(seek.value) / Number(seek.max || 1000)) * audio.duration;
    });

    function applyVolume(value) {
        var v = Math.min(1, Math.max(0, Number(value) / 100));
        audio.volume = v;
        audio.muted = v === 0;
        volumeRange.value = String(Math.round(v * 100));
        paintRange(volumeRange, v * 100);
        muteBtn.classList.toggle('is-muted', audio.muted);
        try { localStorage.setItem(STORAGE_KEY, String(audio.volume)); } catch (e) { }
    }

    volumeRange.addEventListener('input', function () {
        applyVolume(volumeRange.value);
    });

    muteBtn.addEventListener('click', function () {
        if (audio.muted) {
            applyVolume(Number(volumeRange.value) || 50);
        } else {
            audio.muted = true;
            muteBtn.classList.add('is-muted');
        }
    });

    var savedVolume = 80;
    try {
        var stored = localStorage.getItem(STORAGE_KEY);
        if (stored !== null && stored !== '' && !isNaN(Number(stored))) savedVolume = Number(stored) * 100;
    } catch (e) { }

    modal.querySelectorAll('[data-music-close]').forEach(function (node) {
        node.addEventListener('click', close);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            close();
        }
    });

    document.querySelectorAll('[data-music-open]').forEach(function (button) {
        button.addEventListener('click', open);
    });

    paintList();
    loadSong(0, false);
    applyVolume(savedVolume);
})();
