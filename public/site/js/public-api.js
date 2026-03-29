(function (global) {
  var TOKEN_KEY = 'zulu_public_token';
  var USER_KEY = 'zulu_public_user';
  var LANG_KEY = 'zulu_lang';
  var BASE = '/api';
  var DEFAULT_LANG = 'en';
  var currentLang = DEFAULT_LANG;
  var supportedLangs = [DEFAULT_LANG];
  var translationMap = {};
  var localizationReady = false;

  async function parseJson(res) {
    try {
      return await res.json();
    } catch (e) {
      return null;
    }
  }

  function getToken() {
    try {
      return localStorage.getItem(TOKEN_KEY);
    } catch (e) {
      return null;
    }
  }

  function clearAuthStorage() {
    try {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
    } catch (e) {}
  }

  function invalidIdResponse() {
    return { ok: false, status: 422, message: 'Invalid identifier.' };
  }

  function normalizeLang(value) {
    var raw = value == null ? '' : String(value).trim().toLowerCase();
    return raw || DEFAULT_LANG;
  }

  function getLanguage() {
    try {
      var stored = localStorage.getItem(LANG_KEY);
      return normalizeLang(stored);
    } catch (e) {
      return DEFAULT_LANG;
    }
  }

  function setLanguage(lang) {
    var normalized = normalizeLang(lang);
    currentLang = normalized;
    try {
      localStorage.setItem(LANG_KEY, normalized);
    } catch (e) {}
    return normalized;
  }

  function getStoredUser() {
    var raw;
    try {
      raw = localStorage.getItem(USER_KEY);
    } catch (e) {
      return null;
    }
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function setAuth(token, user) {
    try {
      if (token && typeof token === 'string') {
        localStorage.setItem(TOKEN_KEY, token);
      }
      if (user && typeof user === 'object') {
        localStorage.setItem(USER_KEY, JSON.stringify(user));
      } else {
        localStorage.removeItem(USER_KEY);
      }
    } catch (e) {}
  }

  function isAuthenticated() {
    return !!getToken();
  }

  function sanitizeNextSitePath(next, fallback) {
    var safeFallback = (typeof fallback === 'string' && fallback.indexOf('/site/') === 0) ? fallback : '/site/';
    if (!next || typeof next !== 'string') return safeFallback;
    if (next.indexOf('/site/') !== 0) return safeFallback;
    if (next.indexOf('//', 1) !== -1) return safeFallback;
    return next;
  }

  function normalizeFinanceStatus(status) {
    var raw = status == null ? '' : String(status);
    var normalized = raw.toLowerCase();
    if (normalized === 'paid' || normalized === 'success') return 'Paid';
    if (normalized === 'pending') return 'Pending';
    if (normalized === 'failed') return 'Failed';
    if (normalized === 'refunded') return 'Refunded';
    return raw || '—';
  }

  function normalizePayoutStatus(status) {
    var raw = status == null ? '' : String(status);
    var normalized = raw.toLowerCase();
    if (normalized === 'payable') return 'Payable';
    if (normalized === 'pending') return 'Pending';
    if (normalized === 'paid') return 'Paid';
    if (normalized === 'processing') return 'Processing';
    if (normalized === 'failed') return 'Failed';
    if (normalized === 'cancelled' || normalized === 'canceled') return 'Cancelled';
    return raw || '—';
  }

  function t(key) {
    var lookup = key == null ? '' : String(key);
    if (!lookup) return '';
    if (Object.prototype.hasOwnProperty.call(translationMap, lookup)) {
      var value = translationMap[lookup];
      if (value == null) return lookup;
      var normalized = String(value);
      return normalized || lookup;
    }
    return lookup;
  }

  function toLanguageCode(item) {
    if (item == null) return '';
    if (typeof item === 'string') return normalizeLang(item);
    if (typeof item !== 'object') return '';
    var code = item.code || item.locale || item.lang || item.language || item.key || '';
    return normalizeLang(code);
  }

  function normalizeLanguagesPayload(payload) {
    var rows = [];
    if (Array.isArray(payload)) rows = payload;
    else if (payload && Array.isArray(payload.items)) rows = payload.items;
    else if (payload && Array.isArray(payload.data)) rows = payload.data;
    var out = rows
      .map(toLanguageCode)
      .filter(function (v) { return !!v; });
    if (!out.length) out = [DEFAULT_LANG];
    if (out.indexOf(DEFAULT_LANG) === -1) out.unshift(DEFAULT_LANG);
    return out;
  }

  function normalizeFlatMap(source, target) {
    if (!source || typeof source !== 'object') return;
    Object.keys(source).forEach(function (k) {
      var v = source[k];
      if (v == null) return;
      if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
        target[String(k)] = String(v);
      }
    });
  }

  function normalizeTranslationsPayload(payload, lang) {
    var out = {};
    if (!payload) return out;
    var wanted = normalizeLang(lang || currentLang);

    if (Array.isArray(payload)) {
      payload.forEach(function (row) {
        if (!row || typeof row !== 'object') return;
        var key = row.key || row.translation_key || row.name;
        if (!key) return;
        var rowLang = normalizeLang(row.language || row.lang || row.locale || '');
        if (rowLang && rowLang !== wanted) return;
        var value = row.value || row.translation || row.text || row.content;
        if (value == null) return;
        out[String(key)] = String(value);
      });
      return out;
    }

    if (typeof payload === 'object') {
      if (payload[wanted] && typeof payload[wanted] === 'object') {
        normalizeFlatMap(payload[wanted], out);
        return out;
      }
      normalizeFlatMap(payload, out);
      return out;
    }
    return out;
  }

  function applyLocalizedText(root) {
    var targetRoot = root && root.querySelectorAll ? root : document;
    if (!targetRoot || !targetRoot.querySelectorAll) return;
    function isValidLocalizedValue(value, key) {
      if (value == null) return false;
      var normalized = String(value).trim();
      if (!normalized) return false;
      return normalized !== String(key).trim();
    }
    targetRoot.querySelectorAll('[data-i18n]').forEach(function (node) {
      var key = node.getAttribute('data-i18n');
      if (!key || !String(key).trim()) return;
      if (!node.hasAttribute('data-i18n-default')) {
        node.setAttribute('data-i18n-default', node.textContent || '');
      }
      var translated = t(key);
      var fallback = node.getAttribute('data-i18n-default') || '';
      if (!isValidLocalizedValue(translated, key)) {
        translated = fallback;
      }
      if (isValidLocalizedValue(translated, key)) {
        node.textContent = translated;
      } else {
        node.textContent = fallback;
      }
    });
    targetRoot.querySelectorAll('[data-i18n-placeholder]').forEach(function (node) {
      var key = node.getAttribute('data-i18n-placeholder');
      if (!key || !String(key).trim()) return;
      if (!node.hasAttribute('data-i18n-placeholder-default')) {
        node.setAttribute('data-i18n-placeholder-default', node.getAttribute('placeholder') || '');
      }
      var translated = t(key);
      var fallback = node.getAttribute('data-i18n-placeholder-default') || '';
      if (!isValidLocalizedValue(translated, key)) {
        translated = fallback;
      }
      node.setAttribute('placeholder', translated || fallback || '');
    });
    targetRoot.querySelectorAll('[data-i18n-title]').forEach(function (node) {
      var key = node.getAttribute('data-i18n-title');
      if (!key || !String(key).trim()) return;
      if (!node.hasAttribute('data-i18n-title-default')) {
        node.setAttribute('data-i18n-title-default', node.getAttribute('title') || '');
      }
      var translated = t(key);
      var fallback = node.getAttribute('data-i18n-title-default') || '';
      if (!isValidLocalizedValue(translated, key)) {
        translated = fallback;
      }
      node.setAttribute('title', translated || fallback || '');
    });
  }

  async function fetchLanguages() {
    var result = await requestJson('/localization/languages', { method: 'GET' });
    if (!result || !result.ok) return [DEFAULT_LANG];
    return normalizeLanguagesPayload(result.data);
  }

  async function fetchTranslations(lang) {
    var result = await requestJson('/localization/translations', { method: 'GET' });
    if (!result || !result.ok) return {};
    return normalizeTranslationsPayload(result.data, lang);
  }

  async function initLocalization() {
    if (localizationReady) return;
    currentLang = getLanguage();
    try {
      supportedLangs = await fetchLanguages();
    } catch (e) {
      supportedLangs = [DEFAULT_LANG];
    }
    if (supportedLangs.indexOf(currentLang) === -1) {
      currentLang = setLanguage(DEFAULT_LANG);
    }
    try {
      translationMap = await fetchTranslations(currentLang);
    } catch (e) {
      translationMap = {};
    }
    localizationReady = true;
    applyLocalizedText(document);
  }

  async function requestJson(path, options) {
    options = options || {};
    var headers = new Headers(options.headers || {});
    if (!headers.has('Accept')) {
      headers.set('Accept', 'application/json');
    }
    var token = getToken();
    if (token) {
      headers.set('Authorization', 'Bearer ' + token);
    }
    var body = options.body;
    if (body && typeof body === 'object' && !(body instanceof FormData) && !headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json');
      body = JSON.stringify(body);
    }
    var url = path.indexOf('http') === 0 ? path : BASE + path;
    var res;
    var data;
    try {
      res = await fetch(url, Object.assign({}, options, { headers: headers, body: body }));
      data = await parseJson(res);
    } catch (e) {
      return { ok: false, message: 'Network error — server unreachable', status: 0 };
    }
    if (data === null && !res.ok) {
      return { ok: false, message: 'Invalid response', status: res.status };
    }
    if (res.status === 401) {
      clearAuthStorage();
    }
    if (!res.ok) {
      var msg = (data && data.message) ? data.message : 'Request failed (' + res.status + ')';
      var err = { ok: false, message: msg, status: res.status };
      if (data && data.errors && typeof data.errors === 'object') {
        err.errors = data.errors;
      }
      return err;
    }
    if (!data || data.success !== true) {
      return {
        ok: false,
        message: (data && data.message) || 'Invalid response',
        status: res.status,
      };
    }
    return { ok: true, data: data.data };
  }

  global.ZuluPublicApi = {
    TOKEN_KEY: TOKEN_KEY,
    USER_KEY: USER_KEY,
    getToken: getToken,
    getStoredUser: getStoredUser,
    isAuthenticated: isAuthenticated,
    sanitizeNextSitePath: sanitizeNextSitePath,
    normalizeFinanceStatus: normalizeFinanceStatus,
    normalizePayoutStatus: normalizePayoutStatus,
    LANG_KEY: LANG_KEY,
    getLanguage: getLanguage,
    setLanguage: setLanguage,
    getLanguages: function () {
      return supportedLangs.slice();
    },
    getTranslations: function () {
      return Object.assign({}, translationMap);
    },
    t: t,
    applyLocalizedText: applyLocalizedText,
    initLocalization: initLocalization,
    clearAuth: function () {
      clearAuthStorage();
    },
    login: async function (email, password) {
      var result = await requestJson('/login', {
        method: 'POST',
        body: { email: email, password: password },
      });
      if (result && result.ok && result.data && result.data.token) {
        setAuth(result.data.token, result.data.user);
        return { ok: true };
      }
      return result || { ok: false, message: 'Login failed' };
    },
    register: async function (name, email, password, passwordConfirmation) {
      var result = await requestJson('/register', {
        method: 'POST',
        body: {
          name: name,
          email: email,
          password: password,
          password_confirmation: passwordConfirmation,
        },
      });
      if (result && result.ok && result.data && result.data.token) {
        setAuth(result.data.token, result.data.user);
        return { ok: true };
      }
      return result || { ok: false, message: 'Registration failed' };
    },
    forgotPassword: async function (email) {
      return requestJson('/forgot-password', {
        method: 'POST',
        body: { email: email },
      });
    },
    resetPassword: async function (token, email, password, passwordConfirmation) {
      return requestJson('/reset-password', {
        method: 'POST',
        body: {
          token: token,
          email: email,
          password: password,
          password_confirmation: passwordConfirmation,
        },
      });
    },
    /** Authenticated profile — GET /api/account/me (Bearer zulu_public_token). */
    accountMe: async function () {
      return requestJson('/account/me', { method: 'GET' });
    },
    /** Authenticated trip continuity — GET /api/account/trips. */
    accountTrips: async function (perPage, page) {
      var p = (typeof perPage === 'number' && perPage > 0) ? perPage : 20;
      var pg = (typeof page === 'number' && page > 0) ? page : 1;
      return requestJson('/account/trips?per_page=' + encodeURIComponent(String(p)) + '&page=' + encodeURIComponent(String(pg)), {
        method: 'GET',
      });
    },
    /** Authenticated package-order continuity — GET /api/package-orders. */
    packageOrders: async function (perPage) {
      var p = (typeof perPage === 'number' && perPage > 0) ? perPage : 20;
      return requestJson('/package-orders?per_page=' + encodeURIComponent(String(p)), {
        method: 'GET',
      });
    },
    /** Authenticated payments list — GET /api/payments. */
    payments: async function (perPage, page) {
      var p = (typeof perPage === 'number' && perPage > 0) ? perPage : 20;
      var pg = (typeof page === 'number' && page > 0) ? page : 1;
      return requestJson('/payments?per_page=' + encodeURIComponent(String(p)) + '&page=' + encodeURIComponent(String(pg)), {
        method: 'GET',
      });
    },
    /** Authenticated payment detail — GET /api/payments/{id}. */
    paymentShow: async function (id) {
      if (!id && id !== 0) return invalidIdResponse();
      return requestJson('/payments/' + encodeURIComponent(id), { method: 'GET' });
    },
    /** Authenticated invoices list — GET /api/invoices. */
    invoices: async function (perPage, page) {
      var p = (typeof perPage === 'number' && perPage > 0) ? perPage : 20;
      var pg = (typeof page === 'number' && page > 0) ? page : 1;
      return requestJson('/invoices?per_page=' + encodeURIComponent(String(p)) + '&page=' + encodeURIComponent(String(pg)), {
        method: 'GET',
      });
    },
    /** Authenticated invoice detail — GET /api/invoices/{id}. */
    invoiceShow: async function (id) {
      if (!id && id !== 0) return invalidIdResponse();
      return requestJson('/invoices/' + encodeURIComponent(id), { method: 'GET' });
    },
    /** Authenticated finance summary — GET /api/finance/summary. */
    financeSummary: async function () {
      return requestJson('/finance/summary', { method: 'GET' });
    },
    /** Authenticated finance entitlements — GET /api/finance/entitlements. */
    financeEntitlements: async function (perPage, page) {
      var p = (typeof perPage === 'number' && perPage > 0) ? perPage : 20;
      var pg = (typeof page === 'number' && page > 0) ? page : 1;
      return requestJson('/finance/entitlements?per_page=' + encodeURIComponent(String(p)) + '&page=' + encodeURIComponent(String(pg)), {
        method: 'GET',
      });
    },
    /** Authenticated finance settlements — GET /api/finance/settlements. */
    financeSettlements: async function (perPage, page) {
      var p = (typeof perPage === 'number' && perPage > 0) ? perPage : 20;
      var pg = (typeof page === 'number' && page > 0) ? page : 1;
      return requestJson('/finance/settlements?per_page=' + encodeURIComponent(String(p)) + '&page=' + encodeURIComponent(String(pg)), {
        method: 'GET',
      });
    },
    /** Authenticated notifications list — GET /api/notifications/paginated. */
    notifications: async function (perPage, page) {
      try {
        var p = (typeof perPage === 'number' && perPage > 0) ? perPage : 20;
        var pg = (typeof page === 'number' && page > 0) ? page : 1;
        var result = await requestJson('/notifications/paginated?per_page=' + encodeURIComponent(String(p)) + '&page=' + encodeURIComponent(String(pg)), {
          method: 'GET',
        });
        var rows = [];
        if (result && result.ok && result.data) {
          if (Array.isArray(result.data)) rows = result.data;
          else if (Array.isArray(result.data.items)) rows = result.data.items;
          else if (Array.isArray(result.data.data)) rows = result.data.data;
        }
        return { ok: true, data: rows };
      } catch (e) {
        return { ok: true, data: [] };
      }
    },
    /** Authenticated notification unread count — GET /api/notifications/unread-count. */
    notificationUnreadCount: async function () {
      try {
        var result = await requestJson('/notifications/unread-count', { method: 'GET' });
        var count = 0;
        if (result && result.ok && result.data && typeof result.data.unread_count !== 'undefined') {
          var parsed = Number(result.data.unread_count);
          if (Number.isFinite(parsed) && parsed >= 0) count = parsed;
        }
        return { ok: true, data: { unread_count: count } };
      } catch (e) {
        return { ok: true, data: { unread_count: 0 } };
      }
    },
    marketplaceBook: async function (offerId) {
      if (!Number.isInteger(Number(offerId)) || Number(offerId) <= 0) return invalidIdResponse();
      return requestJson('/marketplace/bookings', {
        method: 'POST',
        body: { offer_id: Number(offerId) },
      });
    },
    marketplaceBooking: async function (bookingId) {
      if (!Number.isInteger(Number(bookingId)) || Number(bookingId) <= 0) return invalidIdResponse();
      return requestJson('/marketplace/bookings/' + encodeURIComponent(bookingId), { method: 'GET' });
    },
    marketplaceCheckout: async function (bookingId) {
      if (!Number.isInteger(Number(bookingId)) || Number(bookingId) <= 0) return invalidIdResponse();
      return requestJson('/marketplace/bookings/' + encodeURIComponent(bookingId) + '/checkout', {
        method: 'POST',
        body: {},
      });
    },
    packageOrderCreate: async function (packageId, payload) {
      if (!Number.isInteger(Number(packageId)) || Number(packageId) <= 0) return invalidIdResponse();
      var body = Object.assign({}, payload || {}, { package_id: packageId });
      return requestJson('/package-orders', {
        method: 'POST',
        body: body,
      });
    },
    packageOrderShow: async function (orderId) {
      if (!Number.isInteger(Number(orderId)) || Number(orderId) <= 0) return invalidIdResponse();
      return requestJson('/package-orders/' + encodeURIComponent(orderId), { method: 'GET' });
    },
    packageOrderPay: async function (orderId) {
      if (!Number.isInteger(Number(orderId)) || Number(orderId) <= 0) return invalidIdResponse();
      return requestJson('/package-orders/' + encodeURIComponent(orderId) + '/pay', {
        method: 'POST',
        body: {},
      });
    },
    /**
     * Unauthenticated catalog list — does not send Authorization (public read).
     */
    catalogOffers: async function (queryString) {
      var path = '/catalog/offers';
      if (queryString && String(queryString).length) {
        path += '?' + queryString;
      }
      var res;
      var data;
      try {
        res = await fetch(BASE + path, {
          method: 'GET',
          headers: { Accept: 'application/json' },
        });
        data = await parseJson(res);
      } catch (e) {
        return { ok: false, message: 'Network error — server unreachable', status: 0 };
      }
      if (!res.ok) {
        return {
          ok: false,
          message: (data && data.message) ? data.message : 'Request failed (' + res.status + ')',
          status: res.status,
        };
      }
      if (!data || data.success !== true) {
        return { ok: false, message: (data && data.message) || 'Invalid response' };
      }
      return { ok: true, data: data.data };
    },
    /**
     * Package public listing contract.
     * GET /api/catalog/offers?type=package
     */
    packageOffers: async function () {
      return this.catalogOffers('type=package');
    },
    /**
     * Public catalog offer detail (Prompt 3–4 verticals only). No Authorization header.
     */
    catalogOffer: async function (id) {
      try {
        var res = await fetch(BASE + '/catalog/offers/' + encodeURIComponent(id), {
          method: 'GET',
          headers: { Accept: 'application/json' },
        });
        var data = await parseJson(res);
        if (res.status === 404) {
          return {
            ok: false,
            message: (data && data.message) ? data.message : 'Not found',
            status: 404,
          };
        }
        if (!res.ok) {
          return {
            ok: false,
            message: (data && data.message) ? data.message : 'Request failed (' + res.status + ')',
            status: res.status,
          };
        }
        if (!data || data.success !== true) {
          return { ok: false, message: (data && data.message) || 'Invalid response', status: res.status };
        }
        return { ok: true, data: data.data, status: res.status };
      } catch (e) {
        return {
          ok: false,
          status: 0,
          message: 'Network error — server unreachable',
        };
      }
    },
    logout: async function () {
      var token = getToken();
      if (!token) {
        clearAuthStorage();
        return;
      }
      try {
        await fetch(BASE + '/logout', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            Authorization: 'Bearer ' + token,
          },
        });
      } catch (e) {}
      clearAuthStorage();
    },
  };

  // Non-blocking localization initialization for static pages.
  Promise.resolve().then(initLocalization);
  if (document && typeof document.addEventListener === 'function') {
    document.addEventListener('DOMContentLoaded', function () {
      applyLocalizedText(document);
    });
  }
})(window);
