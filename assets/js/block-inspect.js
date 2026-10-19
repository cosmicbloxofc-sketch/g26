(function () {
  'use strict';

  function block(e) {
    e.preventDefault();
    e.stopPropagation();
    return false;
  }

  document.addEventListener('contextmenu', block, true);

  document.addEventListener('keydown', function (e) {
    var key = e.key || e.code || '';
    var k = String(key).toUpperCase();
    if (k === 'F12') return block(e);
    if (e.ctrlKey && e.shiftKey && (k === 'I' || k === 'J' || k === 'C' || k === 'K')) return block(e);
    if (e.ctrlKey && !e.shiftKey && (k === 'U' || k === 'S')) return block(e);
    if (e.metaKey && e.altKey && (k === 'I' || k === 'J' || k === 'C')) return block(e);
  }, true);
})();
