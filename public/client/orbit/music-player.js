/*
 * Orbit music player — vanilla, no dependencies, no autoplay, never breaks the page.
 *
 * Contract:
 *  - Nothing plays until the visitor clicks. (Browsers block unmuted autoplay anyway, and a shop that
 *    plays music at visitors is exactly the "flashy" thing we are avoiding.)
 *  - The player remembers what the visitor chose (track, position, volume, on/off) in localStorage.
 *    After a page navigation a plain PHP site stops the audio, so we offer a one-click
 *    "Continue listening" pill instead of trying to trick the browser.
 *  - A failing track (expired URL, blocked hotlink, offline) shows a message and moves on. It never
 *    throws, never blocks rendering, and never touches anything else on the page.
 *  - One <audio> element, created lazily, preload="none": zero requests until the first play.
 */
(function () {
  'use strict';

  var STORE_KEY = 'orbit.music.v1';
  var root = document.getElementById('orbit-music');
  var configNode = document.getElementById('om-config');
  if (!root || !configNode) return;

  var config;
  try {
    config = JSON.parse(configNode.textContent || '{}');
  } catch (err) {
    return; /* a broken config must not take the page down */
  }
  var tracks = Array.isArray(config.tracks) ? config.tracks : [];
  if (!tracks.length) return;

  var state = readState();
  var index = clampIndex(state.index || 0);
  var audio = null;

  /* ---------------------------------------------------------------- state */

  function readState() {
    try {
      return JSON.parse(window.localStorage.getItem(STORE_KEY) || '{}') || {};
    } catch (err) {
      return {};
    }
  }

  function saveState(patch) {
    var next = Object.assign({}, readState(), patch);
    try {
      window.localStorage.setItem(STORE_KEY, JSON.stringify(next));
    } catch (err) {
      /* private mode / storage full: the player still works for this page view */
    }
  }

  function clampIndex(i) {
    var n = Number(i) || 0;
    if (n < 0) return tracks.length - 1;
    if (n >= tracks.length) return 0;
    return n;
  }

  function trackSrc(track) {
    if (track.url) return track.url;
    return String(config.base || '') + track.file;
  }

  /* ------------------------------------------------------------------ DOM */

  function svg(path, extra) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + (extra || '') + '</svg>';
  }
  var ICON = {
    note: svg('<circle cx="12" cy="12" r="9"/><path d="M10 16V9l5-1v7"/><circle cx="8.5" cy="16" r="1.5"/><circle cx="13.5" cy="15" r="1.5"/>'),
    play: svg('<path d="M8 5.5v13l11-6.5z" fill="currentColor" stroke="none"/>'),
    pause: svg('<path d="M9 5.5v13M15 5.5v13"/>'),
    prev: svg('<path d="M18 6v12L9 12z" fill="currentColor" stroke="none"/><path d="M7 6v12"/>'),
    next: svg('<path d="M6 6v12l9-6z" fill="currentColor" stroke="none"/><path d="M17 6v12"/>'),
    close: svg('<path d="M6 6l12 12M18 6L6 18"/>')
  };

  function el(tag, className, html) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (html !== undefined) node.innerHTML = html;
    return node;
  }

  // The collapsed control is always present when a playlist exists.
  var toggle = el('button', 'om__toggle', ICON.note);
  toggle.type = 'button';
  toggle.setAttribute('aria-label', 'Music player');
  toggle.setAttribute('aria-expanded', 'false');
  root.appendChild(toggle);

  var panel = el('div', 'om__panel');
  panel.setAttribute('role', 'group');
  panel.setAttribute('aria-label', 'Music player controls');
  panel.innerHTML =
    '<div class="om__head">' +
      '<div><p class="om__title" data-om-title></p><p class="om__meta" data-om-meta></p></div>' +
      '<button type="button" class="om__close" data-om-close aria-label="Close player">' + ICON.close + '</button>' +
    '</div>' +
    '<div class="om__controls">' +
      '<button type="button" class="om__btn" data-om-prev aria-label="Previous track">' + ICON.prev + '</button>' +
      '<button type="button" class="om__btn om__btn--main" data-om-play aria-label="Play">' + ICON.play + '</button>' +
      '<button type="button" class="om__btn" data-om-next aria-label="Next track">' + ICON.next + '</button>' +
    '</div>' +
    '<div class="om__row"><span aria-hidden="true">Vol</span>' +
      '<input type="range" min="0" max="1" step="0.05" data-om-volume aria-label="Volume">' +
      '<button type="button" class="btn--link" data-om-mute aria-label="Mute">Mute</button>' +
    '</div>' +
    '<p class="om__status" data-om-status aria-live="polite"></p>';
  root.appendChild(panel);

  var titleNode = panel.querySelector('[data-om-title]');
  var metaNode = panel.querySelector('[data-om-meta]');
  var statusNode = panel.querySelector('[data-om-status]');
  var playBtn = panel.querySelector('[data-om-play]');
  var volume = panel.querySelector('[data-om-volume]');
  var muteBtn = panel.querySelector('[data-om-mute]');

  /* --------------------------------------------------------------- audio */

  function ensureAudio() {
    if (audio) return audio;
    audio = document.createElement('audio');
    audio.preload = 'none';
    audio.setAttribute('playsinline', '');
    audio.setAttribute('aria-hidden', 'true'); /* the labelled buttons below are the interface */
    audio.addEventListener('timeupdate', function () {
      if (audio.currentTime > 3) saveState({ position: Math.floor(audio.currentTime), enabled: true });
    });
    audio.addEventListener('ended', function () { next(); });
    audio.addEventListener('play', function () { render(); });
    audio.addEventListener('pause', function () { render(); });
    audio.addEventListener('error', onError);
    root.appendChild(audio);
    return audio;
  }

  function onError() {
    statusNode.textContent = 'This track is unavailable (the link may have expired).';
    statusNode.className = 'om__status om__status--error';
    playBtn.innerHTML = ICON.play;
    playBtn.setAttribute('aria-label', 'Play');
    // Move on politely, but only if the visitor was actually listening.
    // (Read fresh state: the snapshot taken at load time can be stale.)
    if (readState().enabled) {
      window.setTimeout(function () {
        if (statusNode.className.indexOf('--error') !== -1) next();
      }, 1200);
    }
  }

  function load(i, autoplayFromUser) {
    index = clampIndex(i);
    var track = tracks[index] || {};
    var a = ensureAudio();
    statusNode.textContent = '';
    statusNode.className = 'om__status';
    a.src = trackSrc(track);
    a.volume = typeof state.volume === 'number' ? Math.min(1, Math.max(0, state.volume)) : 0.6;
    a.muted = Boolean(state.muted);
    volume.value = String(a.volume);
    render();
    saveState({ index: index, enabled: Boolean(autoplayFromUser) });
    if (autoplayFromUser) {
      var started = a.play();
      if (started && typeof started.catch === 'function') started.catch(function () { /* blocked: stay paused */ });
    }
    if ('mediaSession' in navigator && window.MediaMetadata) {
      try {
        navigator.mediaSession.metadata = new window.MediaMetadata({
          title: track.title || 'Track ' + (index + 1),
          artist: track.artist || config.site || '',
          album: track.license ? 'Licence: ' + track.license : ''
        });
        navigator.mediaSession.setActionHandler('play', function () { play(); });
        navigator.mediaSession.setActionHandler('pause', function () { pause(); });
        navigator.mediaSession.setActionHandler('previoustrack', function () { prev(); });
        navigator.mediaSession.setActionHandler('nexttrack', function () { next(); });
      } catch (err) { /* unsupported metadata is not an error */ }
    }
  }

  function play() { load(index, true); }
  function pause() { if (audio) audio.pause(); render(); }
  function next() { load(index + 1, true); }
  function prev() { load(index - 1, true); }

  /* -------------------------------------------------------------- render */

  function render() {
    var track = tracks[index] || {};
    titleNode.textContent = track.title || ('Track ' + (index + 1));
    var bits = [];
    if (track.artist) bits.push(track.artist);
    if (track.license) bits.push(track.license);
    if (tracks.length > 1) bits.push((index + 1) + '/' + tracks.length);
    metaNode.textContent = bits.join(' · ');
    var playing = audio && !audio.paused && !audio.ended;
    playBtn.innerHTML = playing ? ICON.pause : ICON.play;
    playBtn.setAttribute('aria-label', playing ? 'Pause' : 'Play');
    muteBtn.textContent = audio && audio.muted ? 'Unmute' : 'Mute';
  }

  function open() {
    root.classList.add('om--open');
    toggle.setAttribute('aria-expanded', 'true');
    playBtn.focus(); /* focus follows the disclosure; Escape comes back to the toggle */
  }
  function close() {
    root.classList.remove('om--open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.focus();
  }

  /* ------------------------------------------------------------- events */

  toggle.addEventListener('click', function () {
    if (root.classList.contains('om--open')) {
      close();
    } else {
      open();
      render();
    }
  });
  panel.querySelector('[data-om-close]').addEventListener('click', function () {
    pause();
    close();
    saveState({ enabled: false });
  });
  playBtn.addEventListener('click', function () {
    if (!audio || !audio.src || audio.paused || audio.ended) play(); else pause();
  });
  panel.querySelector('[data-om-next]').addEventListener('click', function () { next(); });
  panel.querySelector('[data-om-prev]').addEventListener('click', function () { prev(); });
  volume.addEventListener('input', function () {
    var v = Number(volume.value);
    if (audio) audio.volume = v;
    saveState({ volume: v });
    render();
  });
  muteBtn.addEventListener('click', function () {
    if (!audio) return;
    audio.muted = !audio.muted;
    saveState({ muted: audio.muted });
    render();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && root.classList.contains('om--open')) {
      close();
      return;
    }
    /* Space works while the panel itself has focus; on the buttons the browser default applies. */
    if ((event.key === ' ' || event.key === 'Spacebar') && document.activeElement === panel) {
      event.preventDefault();
      if (audio && !audio.paused) pause(); else play();
    }
  });

  render();

  /* --------------------------------------- continue after a page change */

  if (state.enabled && state.position > 3) {
    var resume = el('button', 'om__resume', ICON.play + '<span>Continue listening</span>');
    resume.type = 'button';
    resume.addEventListener('click', function () {
      resume.remove();
      open();  /* open() focuses the play button */
      load(index, false);
      var target = Number(state.position) || 0;
      var seek = function () { if (target > 0 && audio.duration && target < audio.duration - 1) audio.currentTime = target; };
      audio.addEventListener('loadedmetadata', seek, { once: true });
      var started = audio.play();
      if (started && typeof started.catch === 'function') started.catch(function () {});
    });
    root.insertBefore(resume, toggle);
  }
})();
