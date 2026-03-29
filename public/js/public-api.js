(function (global) {
  var TOKEN_KEY = 'zulu_public_token';
  var USER_KEY = 'zulu_public_user';
  var BASE = '/api';

  async function parseJson(res) {
    try {
      return await res.json();
    } catch (e) {
      return null;
    }
  }

  function getToken() {
    return localStorage.getItem(TOKEN_KEY);
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
    var res = await fetch(url, Object.assign({}, options, { headers: headers, body: body }));
    var data = await parseJson(res);
    if (data === null && !res.ok) {
      return { ok: false, message: 'Invalid response', status: res.status };
    }
    if (res.status === 401) {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
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
      return { ok: false, message: (data && data.message) || 'Invalid response' };
    }
    return { ok: true, data: data.data };
  }

  global.ZuluPublicApi = {
    TOKEN_KEY: TOKEN_KEY,
    USER_KEY: USER_KEY,
    getToken: getToken,
    clearAuth: function () {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
    },
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
        localStorage.setItem(TOKEN_KEY, result.data.token);
        if (result.data.user) {
          localStorage.setItem(USER_KEY, JSON.stringify(result.data.user));
        }
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
    marketplaceBook: async function (offerId) {
      return requestJson('/marketplace/bookings', {
        method: 'POST',
        body: { offer_id: offerId },
      });
    },
    marketplaceBooking: async function (bookingId) {
      return requestJson('/marketplace/bookings/' + encodeURIComponent(bookingId), { method: 'GET' });
    },
    marketplaceCheckout: async function (bookingId) {
      return requestJson('/marketplace/bookings/' + encodeURIComponent(bookingId) + '/checkout', {
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
      var res = await fetch(BASE + path, {
        method: 'GET',
        headers: { Accept: 'application/json' },
      });
      var data = await parseJson(res);
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
     * Public catalog offer detail (Prompt 3–4 verticals only). No Authorization header.
     */
    catalogOffer: async function (id) {
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
        return { ok: false, message: (data && data.message) || 'Invalid response' };
      }
      return { ok: true, data: data.data };
    },
    catalogCars: async function (params) {
      var queryString = new URLSearchParams(params).toString();
      return requestJson('/cars?' + queryString, { method: 'GET' });
    },
    catalogExcursions: async function (params) {
      var queryString = new URLSearchParams(params).toString();
      return requestJson('/excursions?' + queryString, { method: 'GET' });
    },
    checkoutBooking: async function (bookingId, passengerData) {
      return requestJson('/marketplace/bookings/' + encodeURIComponent(bookingId) + '/checkout', {
        method: 'POST',
        body: { passengers: passengerData },
      });
    },
    getBookingDetails: async function (bookingId) {
      return requestJson('/marketplace/bookings/' + encodeURIComponent(bookingId), { method: 'GET' });
    },
    logout: async function () {
      var token = getToken();
      if (!token) {
        localStorage.removeItem(USER_KEY);
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
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem(USER_KEY);
    },
  };
})(window);
