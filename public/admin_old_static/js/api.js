(function (global) {
  var TOKEN_KEY = 'zulu_token';
  var USER_KEY = 'zulu_user';
  var BASE = '/api';

  function getToken() {
    return localStorage.getItem(TOKEN_KEY);
  }

  function clearAuthAndRedirect() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    location.replace('/admin/login/');
  }

  async function parseJson(res) {
    try {
      return await res.json();
    } catch (e) {
      return null;
    }
  }

  var feedbackTimers = {};

  function clearFeedbackTimer(elementId) {
    if (feedbackTimers[elementId]) {
      global.clearTimeout(feedbackTimers[elementId]);
      delete feedbackTimers[elementId];
    }
  }

  global.ZuluApi = {
    TOKEN_KEY: TOKEN_KEY,
    USER_KEY: USER_KEY,
    getToken: getToken,
    setToken: function (t) {
      localStorage.setItem(TOKEN_KEY, t);
    },
    clearToken: function () {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
    },
    clearAuthAndRedirect: clearAuthAndRedirect,

    login: async function (email, password) {
      var res = await fetch(BASE + '/login', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email: email, password: password }),
      });
      var data = await parseJson(res);
      if (res.status === 401) {
        return { ok: false, message: (data && data.message) || 'Invalid credentials' };
      }
      if (!res.ok) {
        return { ok: false, message: (data && data.message) || 'Login failed' };
      }
      if (data && data.success && data.data && data.data.token) {
        localStorage.setItem(TOKEN_KEY, data.data.token);
        if (data.data.user && typeof data.data.user === 'object') {
          localStorage.setItem(USER_KEY, JSON.stringify(data.data.user));
        } else {
          localStorage.removeItem(USER_KEY);
        }
        return { ok: true };
      }
      return { ok: false, message: 'Unexpected response' };
    },

    logout: async function () {
      var token = getToken();
      if (!token) {
        clearAuthAndRedirect();
        return;
      }
      try {
        var res = await fetch(BASE + '/logout', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            Authorization: 'Bearer ' + token,
          },
        });
        if (res.status === 403) {
          var errData = await parseJson(res);
          global.alert((errData && errData.message) || 'Forbidden');
        }
      } catch (e) {}
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
      location.replace('/admin/login/');
    },

    request: async function (path, options) {
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
      var res = await fetch(url, Object.assign({}, options, { headers: headers, body: body }));

      if (res.status === 401) {
        clearAuthAndRedirect();
        return null;
      }
      if (res.status === 403) {
        var errData = await parseJson(res);
        var msg = (errData && errData.message) ? errData.message : 'Forbidden';
        global.alert(msg);
        var err = new Error(msg);
        err.status = 403;
        throw err;
      }
      return res;
    },

    /**
     * Authenticated JSON request; returns { ok: true, data } or { ok: false, message }.
     * On 401 returns null (redirect handled).
     * On 403 returns { ok: false, status: 403 } (alert already shown by request()).
     * On validation failure (e.g. 422), may include `errors` from JSON body when present.
     */
    requestJson: async function (path, options) {
      var res;
      try {
        res = await this.request(path, options);
      } catch (e) {
        if (e && e.status === 403) {
          return { ok: false, message: e.message || 'Forbidden', status: 403 };
        }
        throw e;
      }
      if (res === null) {
        return null;
      }
      var data = await parseJson(res);
      if (!res.ok) {
        var msg = (data && data.message) ? data.message : 'Request failed (' + res.status + ')';
        var err = { ok: false, message: msg, status: res.status };
        if (data && data.errors && typeof data.errors === 'object') {
          err.errors = data.errors;
        }
        return err;
      }
      if (!data || data.success !== true) {
        return { ok: false, message: (data && data.message) || 'Invalid response' };
      }
      var out = { ok: true, data: data.data };
      if (data.meta != null && typeof data.meta === 'object') {
        out.meta = data.meta;
      }
      return out;
    },

    /**
     * Prev/Next row for admin list pages (roadmap meta: current_page, last_page, total, per_page).
     */
    renderListPagination: function (containerId, meta, loadPageFn) {
      var el = document.getElementById(containerId);
      if (!el) return;
      if (!meta || meta.last_page <= 1) {
        el.classList.add('d-none');
        el.innerHTML = '';
        return;
      }
      el.classList.remove('d-none');
      var cur = meta.current_page;
      var last = meta.last_page;
      var total = meta.total != null ? meta.total : '—';
      el.innerHTML =
        '<div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">' +
        '<button type="button" class="btn btn-sm btn-outline-dark mb-0" data-zulu-page="prev"' +
        (cur <= 1 ? ' disabled' : '') +
        '>Previous</button>' +
        '<span class="text-muted text-xs">Page ' +
        cur +
        ' of ' +
        last +
        ' · ' +
        total +
        ' total</span>' +
        '<button type="button" class="btn btn-sm btn-outline-dark mb-0" data-zulu-page="next"' +
        (cur >= last ? ' disabled' : '') +
        '>Next</button>' +
        '</div>';
      var prevBtn = el.querySelector('[data-zulu-page="prev"]');
      var nextBtn = el.querySelector('[data-zulu-page="next"]');
      if (prevBtn)
        prevBtn.addEventListener('click', function () {
          if (cur > 1) loadPageFn(cur - 1);
        });
      if (nextBtn)
        nextBtn.addEventListener('click', function () {
          if (cur < last) loadPageFn(cur + 1);
        });
    },

    getHealth: async function () {
      var res = await fetch(BASE + '/v1/health', {
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) {
        return { error: 'Health request failed', status: res.status };
      }
      return await parseJson(res);
    },

    /**
     * POST with no body (lifecycle actions). 401 → redirect. 403 → alert + { ok: false }.
     */
    postLifecycleJson: async function (path) {
      var headers = new Headers();
      headers.set('Accept', 'application/json');
      var token = getToken();
      if (token) {
        headers.set('Authorization', 'Bearer ' + token);
      }
      var url = path.indexOf('http') === 0 ? path : BASE + path;
      var res = await fetch(url, { method: 'POST', headers: headers });
      if (res.status === 401) {
        clearAuthAndRedirect();
        return null;
      }
      var data = await parseJson(res);
      if (res.status === 403) {
        var msg403 = (data && data.message) ? data.message : 'Forbidden';
        global.alert(msg403);
        return { ok: false, message: msg403, status: 403 };
      }
      if (!res.ok) {
        var msg = (data && data.message) ? data.message : 'Request failed (' + res.status + ')';
        return { ok: false, message: msg, status: res.status };
      }
      if (!data || data.success !== true) {
        return { ok: false, message: (data && data.message) || 'Invalid response' };
      }
      return { ok: true, data: data.data };
    },

    /**
     * List/detail inline alerts (list-feedback, detail-feedback) or persistent form error (form-error).
     * kind: 'success' | 'error'. ttlMs: 0 = no auto-hide (form-error). Default 4000 list, 5000 detail.
     */
    showInlineFeedback: function (elementId, kind, message, ttlMs) {
      var el = document.getElementById(elementId);
      if (!el) return;
      clearFeedbackTimer(elementId);
      var success = kind === 'success';
      var cls = success ? 'alert-success' : 'alert-danger';
      if (elementId === 'form-error') {
        el.className = 'alert alert-danger py-2';
        el.textContent = message;
        el.classList.remove('d-none');
        return;
      }
      if (elementId === 'list-feedback') {
        el.className = 'alert mx-3 ' + cls;
      } else if (elementId === 'detail-feedback') {
        el.className = 'alert mb-3 ' + cls;
      } else {
        el.className = 'alert ' + cls;
      }
      el.textContent = message;
      el.classList.remove('d-none');
      if (ttlMs === undefined) {
        ttlMs = elementId === 'detail-feedback' ? 5000 : 4000;
      }
      if (ttlMs > 0) {
        feedbackTimers[elementId] = global.setTimeout(function () {
          el.classList.add('d-none');
          delete feedbackTimers[elementId];
        }, ttlMs);
      }
    },

    messageFromRequestError: function (result, fallback) {
      if (result && result.errors && typeof result.errors === 'object') {
        var keys = Object.keys(result.errors);
        if (keys.length && result.errors[keys[0]] && result.errors[keys[0]][0]) {
          return result.errors[keys[0]][0];
        }
      }
      return (result && result.message) || fallback;
    },

    confirm: function (message) {
      return global.confirm(message);
    },

    /** Disable one or more buttons (or a NodeList) during async work; sets aria-busy. */
    setBusyState: function (target, busy) {
      if (!target) return;
      var nodes;
      if (Array.isArray(target)) {
        nodes = target;
      } else if (target.nodeType === 1) {
        nodes = [target];
      } else if (typeof target.length === 'number') {
        nodes = Array.prototype.slice.call(target);
      } else {
        nodes = [target];
      }
      nodes.forEach(function (btn) {
        if (!btn) return;
        if (busy) {
          btn.disabled = true;
          btn.setAttribute('aria-busy', 'true');
        } else {
          btn.disabled = false;
          btn.removeAttribute('aria-busy');
        }
      });
    },
  };
})(window);
