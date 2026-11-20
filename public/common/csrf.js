/*
 * Attach the per-session CSRF token to same-origin state-changing requests.
 *
 * The server publishes the token as a readable `orbit_csrf` cookie (it is not the session id),
 * so no template needs a meta tag and no call site needs to change: this file wraps
 * XMLHttpRequest.prototype.open/send — which covers jQuery, axios and hand-written XHR — and
 * window.fetch. A request that already carries the header is left alone.
 *
 * Loaded by views/admin/footer.php, views/client/footer.php and store_shell_close().
 */
(function () {
  "use strict";
  var COOKIE = "orbit_csrf=";

  function token() {
    var jar = document.cookie ? document.cookie.split("; ") : [];
    for (var i = 0; i < jar.length; i++) {
      if (jar[i].indexOf(COOKIE) === 0) {
        try {
          return decodeURIComponent(jar[i].slice(COOKIE.length));
        } catch (error) {
          return jar[i].slice(COOKIE.length);
        }
      }
    }
    return "";
  }

  function sameOrigin(url) {
    try {
      return new URL(url || "", window.location.href).origin === window.location.origin;
    } catch (error) {
      return false;
    }
  }

  var WRITE = /^(POST|PUT|PATCH|DELETE)$/;

  var open = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__orbitMethod = String(method || "GET").toUpperCase();
    this.__orbitUrl = url;
    return open.apply(this, arguments);
  };

  var send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function (body) {
    try {
      if (WRITE.test(this.__orbitMethod || "GET") && sameOrigin(this.__orbitUrl)) {
        var value = token();
        if (value && !this.__orbitCsrf) {
          this.setRequestHeader("X-CSRF-Token", value);
          this.__orbitCsrf = true;
        }
      }
    } catch (error) {
      /* A header we cannot set must not stop the request: the server still checks the origin. */
    }
    return send.apply(this, arguments);
  };

  if (typeof window.fetch === "function") {
    var nativeFetch = window.fetch;
    window.fetch = function (input, init) {
      var options = init || {};
      var url = typeof input === "string" ? input : (input && input.url) || "";
      var method = String(options.method || (input && input.method) || "GET").toUpperCase();
      if (WRITE.test(method) && sameOrigin(url)) {
        var value = token();
        if (value) {
          try {
            var headers = new Headers(options.headers || (input && input.headers) || {});
            if (!headers.has("X-CSRF-Token")) {
              headers.set("X-CSRF-Token", value);
              options = Object.assign({}, options, { headers: headers });
            }
          } catch (error) {
            /* Fall through to the plain request. */
          }
        }
      }
      return nativeFetch.call(this, input, options);
    };
  }
})();
