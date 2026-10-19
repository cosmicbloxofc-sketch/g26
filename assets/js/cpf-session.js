/**
 * CPF – single source of truth via sessionStorage
 * Chaves: rr_cpf_digits (somente números), rr_cpf_display (formatado ###.###.###-##)
 */
(function (global) {
  'use strict';

  var STORAGE_KEYS = { digits: 'rr_cpf_digits', display: 'rr_cpf_display', telefone: 'rr_telefone' };
  var DEBUG = typeof global.DEBUG_CPF !== 'undefined' && global.DEBUG_CPF;

  function log(msg, data) {
    if (DEBUG && console && console.log) {
      console.log('[CPF]', msg, data !== undefined ? data : '');
    }
  }

  /**
   * Remove tudo que não for dígito; garante até 11 dígitos.
   * @param {string} value - CPF em qualquer formato
   * @returns {string} - apenas dígitos (máx. 11)
   */
  function normalizeCPF(value) {
    if (value == null) return '';
    var digits = String(value).replace(/\D/g, '');
    return digits.length > 11 ? digits.slice(0, 11) : digits;
  }

  /**
   * Formata 11 dígitos em ###.###.###-##
   * @param {string} digits - 11 dígitos
   * @returns {string} - formatado ou vazio se inválido
   */
  function formatCPF(digits) {
    digits = normalizeCPF(digits);
    if (digits.length !== 11) return '';
    return digits.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
  }

  /**
   * Salva CPF no sessionStorage (digits + display).
   * @param {string} value - CPF em qualquer formato
   * @returns {{ digits: string, display: string }} - normalizado e formatado
   */
  function setCPF(value) {
    var digits = normalizeCPF(value);
    var display = digits.length === 11 ? formatCPF(digits) : '';
    try {
      if (sessionStorage) {
        sessionStorage.setItem(STORAGE_KEYS.digits, digits);
        sessionStorage.setItem(STORAGE_KEYS.display, display);
      }
    } catch (e) {
      log('setCPF sessionStorage error', e);
    }
    log('setCPF', { digits: digits, display: display, source: 'sessionStorage' });
    return { digits: digits, display: display };
  }

  /**
   * Lê CPF do sessionStorage. Opcionalmente tenta migrar de localStorage/query.
   * @returns {{ digits: string, display: string } | null}
   */
  function getCPF() {
    try {
      var digits = sessionStorage && sessionStorage.getItem(STORAGE_KEYS.digits);
      var display = sessionStorage && sessionStorage.getItem(STORAGE_KEYS.display);
      if (digits && display) {
        return { digits: digits, display: display };
      }
      if (digits && digits.length === 11) {
        display = formatCPF(digits);
        if (sessionStorage) {
          sessionStorage.setItem(STORAGE_KEYS.display, display);
        }
        return { digits: digits, display: display };
      }
      return null;
    } catch (e) {
      log('getCPF error', e);
      return null;
    }
  }

  /**
   * Lê CPF do sessionStorage; se não existir, redireciona para a página de login.
   * @param {string} loginRelativePath - caminho relativo para login (ex: '../../login.html')
   * @returns {{ digits: string, display: string }}
   */
  function getCPFOrRedirect(loginRelativePath) {
    var cpf = getCPF();
    if (cpf && cpf.digits && cpf.digits.length === 11) {
      log('getCPFOrRedirect', { page: location.pathname || location.href, digits: cpf.digits, display: cpf.display, source: 'sessionStorage' });
      return cpf;
    }
    log('getCPFOrRedirect: no CPF, redirecting to', loginRelativePath);
    var base = location.href.replace(/[^/]+$/, '');
    location.replace(base + loginRelativePath.replace(/^\//, ''));
    return { digits: '', display: '' };
  }

  function normalizeTelefone(value) {
    if (value == null) return '';
    var d = String(value).replace(/\D/g, '');
    if (d.indexOf('55') === 0 && d.length >= 12) d = d.slice(2);
    if (d.length > 11) d = d.slice(0, 11);
    return d;
  }

  function formatTelefone(value) {
    var d = normalizeTelefone(value);
    if (d.length === 11) return d.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
    if (d.length === 10) return d.replace(/^(\d{2})(\d{4})(\d{4})$/, '($1) $2-$3');
    return d;
  }

  function isValidTelefone(value) {
    var d = normalizeTelefone(value);
    return d.length === 10 || d.length === 11;
  }

  function setTelefone(value) {
    var digits = normalizeTelefone(value);
    try {
      if (sessionStorage && digits) sessionStorage.setItem(STORAGE_KEYS.telefone, digits);
    } catch (e) {}
    return digits;
  }

  function getTelefone() {
    try {
      var d = sessionStorage && sessionStorage.getItem(STORAGE_KEYS.telefone);
      return d ? normalizeTelefone(d) : '';
    } catch (e) {
      return '';
    }
  }

  global.RR_CPF = {
    normalizeCPF: normalizeCPF,
    formatCPF: formatCPF,
    setCPF: setCPF,
    getCPF: getCPF,
    getCPFOrRedirect: getCPFOrRedirect,
    normalizeTelefone: normalizeTelefone,
    formatTelefone: formatTelefone,
    isValidTelefone: isValidTelefone,
    setTelefone: setTelefone,
    getTelefone: getTelefone,
    STORAGE_KEYS: STORAGE_KEYS
  };
})(typeof window !== 'undefined' ? window : this);
