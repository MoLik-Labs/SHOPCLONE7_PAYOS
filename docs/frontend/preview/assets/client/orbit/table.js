/*
 * Orbit tables — sorting, filtering and mobile labels as progressive enhancement.
 *
 * The table is complete HTML without this file; this only adds:
 *   1. data-label on every <td>, so the responsive "card" layout has row labels on phones;
 *   2. click-to-sort on headers that contain a <button class="table__sort">  (aria-sort kept in sync);
 *   3. optional live filtering from a search input (data-table-filter="#id");
 *   4. an optional "showing N of M" counter (data-table-count).
 *
 * Markup contract:
 *   <div class="toolbar"><input type="search" id="q"> … </div>
 *   <table class="table table--responsive table--zebra" data-table data-sortable
 *          data-table-filter="#q" data-table-count="#count">
 *     <thead><tr><th><button class="table__sort" type="button">Sản phẩm</button></th>…</tr></thead>
 *     <tbody><tr><td>…</td></tr></tbody>
 *   </table>
 *
 * Numbers are parsed the way this shop prints them (1.234.567đ, $12.50, 1,234, dot or comma decimals).
 * Sorting is stable and never re-orders the underlying data — it only moves rows in the DOM.
 */
(function () {
  'use strict';

  /* --------------------------------------------------------------- helpers */

  function parseNumber(text) {
    var raw = String(text).trim();
    if (!raw) return null;
    /* keep digits, separators and a leading sign; drop currency symbols, units and spaces */
    var cleaned = raw.replace(/[^\d.,\-]/g, '');
    if (!/\d/.test(cleaned)) return null;
    var sign = 1;
    if (cleaned.charAt(0) === '-') {
      sign = -1;
      cleaned = cleaned.slice(1);
    }
    /*
     * A pure thousands group ("1.234.567", "1,234,567", "1.234") has no decimal part. The old code
     * only recognised that for a single separator, so "1.234.567" was read as 1234.567 and could
     * sort a 12,345 order above a 1,234,567 one.
     */
    if (/^\d{1,3}([.,]\d{3})+$/.test(cleaned)) {
      return sign * parseFloat(cleaned.replace(/[.,]/g, ''));
    }
    var lastDot = cleaned.lastIndexOf('.');
    var lastComma = cleaned.lastIndexOf(',');
    var decimalAt = -1;
    if (lastDot > -1 && lastComma > -1) decimalAt = Math.max(lastDot, lastComma);
    else if (lastDot > -1) decimalAt = lastDot;
    else if (lastComma > -1) decimalAt = lastComma;

    var integerPart = decimalAt === -1 ? cleaned : cleaned.slice(0, decimalAt);
    var fraction = decimalAt === -1 ? '' : cleaned.slice(decimalAt + 1);
    integerPart = integerPart.replace(/[.,]/g, '');
    fraction = fraction.replace(/[.,]/g, '');
    var value = parseFloat((integerPart || '0') + (fraction ? '.' + fraction : ''));
    return isNaN(value) ? null : sign * value;
  }

  function isNumericColumn(cell) {
    var value = cell.getAttribute('data-sort-value');
    if (value !== null) return true;
    var text = cell.textContent.trim();
    if (!text) return false;
    return parseNumber(text) !== null && /[\d]/.test(text) &&
      /* a leading date is not a number */
      !/^\d{4}-\d{2}-\d{2}/.test(text);
  }

  function cellSortValue(cell, numeric) {
    var explicit = cell.getAttribute('data-sort-value');
    if (explicit !== null) {
      var parsed = parseFloat(explicit);
      return isNaN(parsed) ? explicit : parsed;
    }
    if (numeric) {
      var number = parseNumber(cell.textContent);
      return number === null ? -Infinity : number;
    }
    return cell.textContent.trim().toLowerCase();
  }

  /* ----------------------------------------------------------------- setup */

  function prepareResponsive(table) {
    if (!table.classList.contains('table--responsive')) return;
    var headers = table.querySelectorAll('thead th');
    if (!headers.length) return;
    var labels = [];
    for (var i = 0; i < headers.length; i++) {
      labels.push(headers[i].textContent.trim());
    }
    var rows = table.tBodies[0] ? table.tBodies[0].rows : [];
    for (var r = 0; r < rows.length; r++) {
      var cells = rows[r].cells;
      for (var c = 0; c < cells.length; c++) {
        if (!cells[c].getAttribute('data-label') && labels[c] && !cells[c].hasAttribute('data-label-skip')) {
          cells[c].setAttribute('data-label', labels[c]);
        }
      }
    }
  }

  function sortTable(table, columnIndex, direction, numeric) {
    var body = table.tBodies[0];
    if (!body) return;
    var rows = [];
    for (var i = 0; i < body.rows.length; i++) rows.push(body.rows[i]);
    rows.sort(function (a, b) {
      var left = cellSortValue(a.cells[columnIndex], numeric);
      var right = cellSortValue(b.cells[columnIndex], numeric);
      if (left < right) return direction === 'ascending' ? -1 : 1;
      if (left > right) return direction === 'ascending' ? 1 : -1;
      return 0;
    });
    var fragment = document.createDocumentFragment();
    rows.forEach(function (row) { fragment.appendChild(row); });
    body.appendChild(fragment);
  }

  function wireSorting(table, onSorted) {
    var headers = table.querySelectorAll('thead th');
    for (var index = 0; index < headers.length; index++) {
      (function (columnIndex) {
        var th = headers[columnIndex];
        var button = th.querySelector('.table__sort');
        if (!button) return;
        var sample = table.tBodies[0] && table.tBodies[0].rows[0] ? table.tBodies[0].rows[0].cells[columnIndex] : null;
        var numeric = sample ? isNumericColumn(sample) : false;
        if (sample && sample.getAttribute('data-sort-type') === 'text') numeric = false;
        button.addEventListener('click', function () {
          var current = th.getAttribute('aria-sort');
          var next = current === 'ascending' ? 'descending' : 'ascending';
          for (var i = 0; i < headers.length; i++) headers[i].removeAttribute('aria-sort');
          th.setAttribute('aria-sort', next);
          sortTable(table, columnIndex, next, numeric);
          if (onSorted) onSorted();
          button.focus();
        });
      })(index);
    }
  }

  function wireFilter(table, input, counter, onFiltered) {
    if (!input) return;
    var body = table.tBodies[0];
    if (!body) return;
    var allRows = [];
    for (var i = 0; i < body.rows.length; i++) allRows.push(body.rows[i]);
    function apply() {
      var needle = input.value.trim().toLowerCase();
      var visible = 0;
      allRows.forEach(function (row) {
        var match = needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1;
        row.hidden = !match;
        if (match) visible++;
      });
      if (counter) {
        counter.textContent = counter.getAttribute('data-template')
          ? counter.getAttribute('data-template').replace('%shown%', visible).replace('%total%', allRows.length)
          : visible + '/' + allRows.length;
      }
      if (onFiltered) onFiltered(visible, allRows.length);
    }
    input.addEventListener('input', apply);
    input.addEventListener('search', apply);
    apply();
  }

  function boot() {
    var tables = document.querySelectorAll('table[data-table]');
    for (var i = 0; i < tables.length; i++) {
      (function (table) {
        try {
          prepareResponsive(table);
          var counter = table.getAttribute('data-table-count')
            ? document.querySelector(table.getAttribute('data-table-count')) : null;
          var filterSelector = table.getAttribute('data-table-filter');
          var input = filterSelector ? document.querySelector(filterSelector) : null;
          if (table.hasAttribute('data-sortable')) {
            wireSorting(table, function () { if (input) input.dispatchEvent(new Event('input')); });
          }
          if (input) wireFilter(table, input, counter);
          else if (counter) {
            var total = table.tBodies[0] ? table.tBodies[0].rows.length : 0;
            counter.textContent = counter.getAttribute('data-template')
              ? counter.getAttribute('data-template').replace('%shown%', total).replace('%total%', total)
              : total + '/' + total;
          }
          table.classList.add('is-enhanced');
        } catch (err) {
          if (window.console && console.warn) console.warn('Orbit table enhancement skipped:', err);
        }
      })(tables[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  /* Exposed so the page (or a test) can re-run enhancement after injecting rows. */
  window.orbitTables = { boot: boot, parseNumber: parseNumber };
})();
