(function (global) {
  function getUser() {
    if (!global.ZuluApi || !ZuluApi.USER_KEY) return null;
    var raw = localStorage.getItem(ZuluApi.USER_KEY);
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (e) {
      return null;
    }
  }

  function permissionsDefined(u) {
    u = u || getUser();
    if (!u) return false;
    return Object.prototype.hasOwnProperty.call(u, 'permissions');
  }

  function hasPermission(name) {
    var u = getUser();
    if (!u || !name) return true;
    if (!permissionsDefined(u)) return true;
    var perms = u.permissions;
    if (!Array.isArray(perms)) return true;
    for (var i = 0; i < perms.length; i++) {
      var p = perms[i];
      if (p === name) return true;
      if (p && typeof p === 'object' && p.name === name) return true;
    }
    return false;
  }

  function roleList(u) {
    u = u || getUser();
    if (!u || !Array.isArray(u.roles)) return [];
    return u.roles;
  }

  function isSuperAdminUser(u) {
    u = u || getUser();
    if (!u) return false;
    if (u.is_super_admin === true) return true;
    var roles = roleList(u);
    return roles.indexOf('super_admin') !== -1;
  }

  function isCompanyAdminOnly(u) {
    u = u || getUser();
    if (!u) return false;
    if (u.is_super_admin === true) return false;
    var roles = roleList(u);
    if (roles.length === 0) return false;
    var hasSuper = roles.indexOf('super_admin') !== -1;
    var hasCompany = roles.indexOf('company_admin') !== -1;
    return hasCompany && !hasSuper;
  }

  function setVisible(el, show) {
    if (!el) return;
    el.style.display = show ? '' : 'none';
  }

  function refreshNotificationBadge() {
    if (!global.ZuluApi || !ZuluApi.requestJson) return;
    var el = document.getElementById('zulu-notifications-badge');
    if (!el) return;
    ZuluApi.requestJson('/notifications', { method: 'GET' }).then(function (result) {
      if (result === null) return;
      if (!result.ok || !Array.isArray(result.data)) {
        el.textContent = '';
        el.classList.add('d-none');
        return;
      }
      var n = 0;
      for (var i = 0; i < result.data.length; i++) {
        if (result.data[i].status === 'unread') n++;
      }
      if (n > 0) {
        el.textContent = n > 99 ? '99+' : String(n);
        el.classList.remove('d-none');
      } else {
        el.textContent = '';
        el.classList.add('d-none');
      }
    });
  }

  function applyPermissionVisibility() {
    var u = getUser();
    document.querySelectorAll('[data-zulu-perm]').forEach(function (el) {
      var name = el.getAttribute('data-zulu-perm');
      if (!name) return;
      setVisible(el, hasPermission(name));
    });

    if (u && !permissionsDefined(u) && isCompanyAdminOnly(u)) {
      document.querySelectorAll('aside a[href="/admin/companies/"]').forEach(function (a) {
        var li = a.closest('li');
        if (li) setVisible(li, false);
      });
    }

    if (u && Object.prototype.hasOwnProperty.call(u, 'is_super_admin') && u.is_super_admin === false) {
      document.querySelectorAll('[data-zulu-super-only]').forEach(function (el) {
        setVisible(el, false);
      });
    }
  }

  function requireAuth() {
    if (!global.ZuluApi || !ZuluApi.getToken()) {
      global.location.replace('/admin/login/');
      return;
    }
    function boot() {
      applyPermissionVisibility();
      refreshNotificationBadge();
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
    } else {
      boot();
    }
  }

  global.ZuluAuth = {
    getUser: getUser,
    hasPermission: hasPermission,
    permissionsDefined: function () {
      return permissionsDefined();
    },
    isSuperAdminUser: isSuperAdminUser,
    isCompanyAdminOnly: isCompanyAdminOnly,
    applyPermissionVisibility: applyPermissionVisibility,
    refreshNotificationBadge: refreshNotificationBadge,
    requireAuth: requireAuth,
  };
})(window);
