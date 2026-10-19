(function () {
  'use strict';

  var STYLE_ID = 'gvgv-vsl-audio-styles';
  if (!document.getElementById(STYLE_ID)) {
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent =
      '.gvgv-vsl-wrap{position:relative;width:100%}' +
      '.gvgv-vsl-unmute{position:absolute;left:50%;bottom:18%;transform:translateX(-50%);' +
      'background:rgba(0,0,0,.78);color:#fff;border:1px solid rgba(255,255,255,.35);' +
      'border-radius:30px;padding:10px 18px;font-size:14px;font-weight:600;cursor:pointer;z-index:12;' +
      'display:flex;align-items:center;gap:8px;max-width:92%;text-align:center;line-height:1.3}' +
      '.gvgv-vsl-unmute:hover{background:#1351B4;border-color:#1351B4}' +
      '.gvgv-vsl-sound{position:absolute;top:10px;right:10px;width:42px;height:42px;border-radius:50%;' +
      'background:rgba(0,0,0,.55);border:1px solid rgba(255,255,255,.25);color:#fff;cursor:pointer;z-index:12;' +
      'display:flex;align-items:center;justify-content:center;font-size:18px;line-height:1}' +
      '.gvgv-vsl-sound:hover{background:#1351B4;border-color:#1351B4}' +
      '.gvgv-vsl-sound.is-muted::after{content:"🔇"}' +
      '.gvgv-vsl-sound:not(.is-muted)::after{content:"🔊"}';
    document.head.appendChild(style);
  }

  function enableSound(video) {
    if (!video) return;
    video.muted = false;
    video.volume = 1;
    var wrap = video.closest('.gvgv-vsl-wrap');
    if (wrap) {
      var banner = wrap.querySelector('.gvgv-vsl-unmute');
      if (banner) banner.style.display = 'none';
      var btn = wrap.querySelector('.gvgv-vsl-sound');
      if (btn) btn.classList.remove('is-muted');
    }
    var p = video.play();
    if (p && typeof p.catch === 'function') p.catch(function () {});
  }

  function toggleSound(video) {
    if (!video) return;
    if (video.muted || video.volume === 0) {
      enableSound(video);
    } else {
      video.muted = true;
      var wrap = video.closest('.gvgv-vsl-wrap');
      var btn = wrap && wrap.querySelector('.gvgv-vsl-sound');
      if (btn) btn.classList.add('is-muted');
    }
  }

  function setupVideo(video) {
    if (!video || video.dataset.gvgvAudioReady === '1') return;
    video.dataset.gvgvAudioReady = '1';
    video.setAttribute('playsinline', '');
    if (!video.hasAttribute('autoplay')) video.setAttribute('autoplay', '');
    video.muted = true;

    var parent = video.parentNode;
    var wrap = document.createElement('div');
    wrap.className = 'gvgv-vsl-wrap';
    parent.insertBefore(wrap, video);
    wrap.appendChild(video);

    var unmute = document.createElement('button');
    unmute.type = 'button';
    unmute.className = 'gvgv-vsl-unmute';
    unmute.innerHTML = '🔊 Toque para ativar o som';
    unmute.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      enableSound(video);
    });
    wrap.appendChild(unmute);

    var soundBtn = document.createElement('button');
    soundBtn.type = 'button';
    soundBtn.className = 'gvgv-vsl-sound is-muted';
    soundBtn.title = 'Ativar/desativar som';
    soundBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      toggleSound(video);
    });
    wrap.appendChild(soundBtn);

    wrap.addEventListener('click', function (e) {
      if (e.target === video && video.muted) enableSound(video);
    });

    video.addEventListener('volumechange', function () {
      var muted = video.muted || video.volume === 0;
      soundBtn.classList.toggle('is-muted', muted);
      if (!muted) unmute.style.display = 'none';
    });

    var playTry = video.play();
    if (playTry && typeof playTry.catch === 'function') {
      playTry.catch(function () {
        unmute.style.display = 'flex';
      });
    }
  }

  function init() {
    document.querySelectorAll('video[data-gvgv-audio], video.gvgv-vsl-auto').forEach(setupVideo);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
