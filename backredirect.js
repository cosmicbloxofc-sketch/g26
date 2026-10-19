/**
 * BackRedirect
 * Captura a tentativa do usuário de voltar (botão "voltar" do navegador,
 * gesto de swipe no mobile, tecla Backspace/Alt+Seta) e redireciona
 * para uma URL configurável.
 *
 * COMO USAR:
 * <script src="back-redirect.js"></script>
 * (coloque antes do </body> de qualquer página)
 */

(function () {
  // ============ CONFIGURAÇÃO ============
  // A URL pode ser definida por página, ANTES de carregar este script:
  //
  //   <script>
  //     window.BACK_REDIRECT_URL = "https://exemplo.com/pagina-x";
  //   </script>
  //   <script src="back-redirect.js"></script>
  //
  // Se window.BACK_REDIRECT_URL não for definida, usa o valor padrão abaixo.
  const CONFIG = {
    redirectUrl: window.BACK_REDIRECT_URL || "back/index.html",

    // Se true, só redireciona 1 vez por sessão (evita loop caso o
    // usuário fique clicando em voltar várias vezes)
    onlyOnce: window.BACK_REDIRECT_ONLY_ONCE !== undefined ? window.BACK_REDIRECT_ONLY_ONCE : true,

    // Delay opcional antes de redirecionar (em ms). 0 = imediato
    delay: window.BACK_REDIRECT_DELAY || 0,

    // Se true, mostra logs no console (útil pra debugar)
    debug: window.BACK_REDIRECT_DEBUG || false,
  };
  // =======================================

  const STORAGE_KEY = "backRedirect_alreadyTriggered";

  function log(...args) {
    if (CONFIG.debug) console.log("[BackRedirect]", ...args);
  }

  function jaRedirecionou() {
    return sessionStorage.getItem(STORAGE_KEY) === "true";
  }

  function marcarRedirecionado() {
    sessionStorage.setItem(STORAGE_KEY, "true");
  }

  function redirecionar() {
    if (CONFIG.onlyOnce && jaRedirecionou()) {
      log("Já redirecionou antes nessa sessão, ignorando.");
      return;
    }

    log("Redirecionando para:", CONFIG.redirectUrl);
    marcarRedirecionado();

    if (CONFIG.delay > 0) {
      setTimeout(() => {
        window.location.href = CONFIG.redirectUrl;
      }, CONFIG.delay);
    } else {
      window.location.href = CONFIG.redirectUrl;
    }
  }

  function init() {
    history.pushState({ backRedirectGuard: true }, document.title, window.location.href);

    window.addEventListener("popstate", function (event) {
      log("popstate disparado", event.state);
      redirecionar();

      if (!CONFIG.onlyOnce) {
        history.pushState({ backRedirectGuard: true }, document.title, window.location.href);
      }
    });

    log("BackRedirect iniciado. redirectUrl =", CONFIG.redirectUrl);
  }

  if (document.readyState === "complete") {
    init();
  } else {
    window.addEventListener("load", init);
  }
})();