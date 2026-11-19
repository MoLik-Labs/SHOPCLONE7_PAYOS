/*
 * A 40-line stand-in for clipboard.js, served from this origin.
 *
 * The admin pages loaded clipboard.js from cdnjs. That put a third-party script on the same page
 * that shows a signed-in administrator their customers' orders, settings and payment keys — and on
 * the admin login page, where a compromised CDN would receive the password as it is typed. This file
 * supports the two things those pages actually use: `new ClipboardJS(".copy")` with
 * `data-clipboard-target="#id"`, and a manual call when the page asks for it.
 *
 * Modern browsers use navigator.clipboard; older ones fall back to a hidden textarea and
 * execCommand("copy"). Nothing here reaches another origin.
 */
(function (global) {
  "use strict";

  function targetOf(element) {
    var selector = element.getAttribute("data-clipboard-target");
    if (!selector) {
      return null;
    }
    return document.querySelector(selector);
  }

  function textOf(element) {
    var target = targetOf(element);
    if (target) {
      return "value" in target ? target.value : target.textContent;
    }
    var text = element.getAttribute("data-clipboard-text");
    return text === null ? "" : text;
  }

  function legacyCopy(text) {
    var area = document.createElement("textarea");
    area.value = text;
    area.setAttribute("readonly", "");
    area.style.position = "fixed";
    area.style.top = "-1000px";
    document.body.appendChild(area);
    area.select();
    var ok = false;
    try {
      ok = document.execCommand("copy");
    } catch (error) {
      ok = false;
    }
    document.body.removeChild(area);
    return ok;
  }

  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    return legacyCopy(text) ? Promise.resolve() : Promise.reject(new Error("copy failed"));
  }

  function ClipboardJS(selector) {
    this.selector = selector || ".copy";
    var self = this;
    this.handler = function (event) {
      var element = event.target.closest ? event.target.closest(self.selector) : null;
      if (!element) {
        return;
      }
      event.preventDefault();
      self.copy(element);
    };
    document.addEventListener("click", this.handler);
  }

  ClipboardJS.prototype.copy = function (element) {
    var text = typeof element === "string" ? element : textOf(element);
    copyText(text).then(function () {
      element.dispatchEvent(new CustomEvent("success"));
    }).catch(function () {
      element.dispatchEvent(new CustomEvent("error"));
    });
    return this;
  };

  ClipboardJS.prototype.destroy = function () {
    document.removeEventListener("click", this.handler);
  };

  global.ClipboardJS = ClipboardJS;
})(window);
