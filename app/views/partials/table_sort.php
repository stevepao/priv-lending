<script>
(function () {
  function tbodyIsEmptyState(tbody) {
    var rows = tbody.querySelectorAll(':scope > tr');
    if (rows.length !== 1) {
      return false;
    }
    return rows[0].querySelector('td[colspan]') !== null;
  }

  function cellSortMeta(cell) {
    if (!cell) {
      return { kind: 'str', v: '' };
    }
    var cb = cell.querySelector('input[type="checkbox"]');
    if (cb) {
      return { kind: 'num', v: cb.checked ? 1 : 0 };
    }
    var t = (cell.textContent || '').replace(/\u00a0/g, ' ').trim().replace(/\s+/g, ' ');
    if (t === '' || t === '—') {
      return { kind: 'empty', v: '' };
    }
    var clean = t.replace(/,/g, '');
    if (/^-?\d+(?:\.\d+)?$/.test(clean)) {
      return { kind: 'num', v: parseFloat(clean) };
    }
    return { kind: 'str', v: t.toLowerCase() };
  }

  function compareMeta(a, b) {
    if (a.kind === 'empty' && b.kind === 'empty') {
      return 0;
    }
    if (a.kind === 'empty') {
      return 1;
    }
    if (b.kind === 'empty') {
      return -1;
    }
    if (a.kind === 'num' && b.kind === 'num') {
      if (a.v < b.v) {
        return -1;
      }
      if (a.v > b.v) {
        return 1;
      }
      return 0;
    }
    if (a.kind === 'num') {
      return -1;
    }
    if (b.kind === 'num') {
      return 1;
    }
    if (a.v < b.v) {
      return -1;
    }
    if (a.v > b.v) {
      return 1;
    }
    return 0;
  }

  function setAriaSort(ths, activeCol, dir) {
    for (var i = 0; i < ths.length; i++) {
      var th = ths[i];
      if (i === activeCol) {
        th.setAttribute('aria-sort', dir > 0 ? 'ascending' : 'descending');
      } else {
        th.setAttribute('aria-sort', 'none');
      }
    }
  }

  function updateIndicators(buttons, activeCol, dir) {
    for (var i = 0; i < buttons.length; i++) {
      if (buttons[i] === null) {
        continue;
      }
      var ind = buttons[i].querySelector('.js-table-sort-ind');
      if (!ind) {
        continue;
      }
      if (i === activeCol) {
        ind.textContent = dir > 0 ? '\u25b2' : '\u25bc';
      } else {
        ind.textContent = '';
      }
    }
  }

  function enhanceHeaderRow(table, headerRow) {
    var ths = headerRow.cells;
    var buttons = [];
    for (var j = 0; j < ths.length; j++) {
      (function (colIndex) {
        var th = ths[colIndex];
        if (th.tagName !== 'TH' || th.hasAttribute('data-no-sort')) {
          buttons.push(null);
          return;
        }
        var cs = th.getAttribute('colspan');
        if (cs && parseInt(cs, 10) > 1) {
          buttons.push(null);
          return;
        }
        th.setAttribute('scope', 'col');
        th.setAttribute('aria-sort', 'none');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'js-table-sort-btn group inline-flex w-full max-w-full items-center gap-1 rounded px-0 py-0 text-left font-medium text-inherit hover:bg-slate-200/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400';
        if (th.classList.contains('text-right')) {
          btn.classList.remove('text-left');
          btn.classList.add('justify-end', 'text-right');
        } else {
          btn.classList.add('text-left');
        }
        while (th.firstChild) {
          btn.appendChild(th.firstChild);
        }
        var ind = document.createElement('span');
        ind.className = 'js-table-sort-ind shrink-0 text-xs text-slate-400';
        ind.setAttribute('aria-hidden', 'true');
        btn.appendChild(ind);
        th.appendChild(btn);
        buttons.push(btn);
      })(j);
    }
    return buttons;
  }

  function initTable(table) {
    var thead = table.tHead || table.querySelector('thead');
    var tbody = table.querySelector('tbody');
    if (!thead || !tbody || thead.rows.length < 1) {
      return;
    }
    if (tbodyIsEmptyState(tbody)) {
      return;
    }
    var headerRow = thead.rows[0];
    var buttons = enhanceHeaderRow(table, headerRow);
    var ths = headerRow.cells;
    var state = { col: -1, dir: 1 };

    function sortBy(colIndex) {
      if (buttons[colIndex] === null) {
        return;
      }
      if (state.col === colIndex) {
        state.dir = -state.dir;
      } else {
        state.col = colIndex;
        state.dir = 1;
      }
      setAriaSort(ths, colIndex, state.dir);
      updateIndicators(buttons, colIndex, state.dir);
      var rows = Array.prototype.slice.call(tbody.querySelectorAll(':scope > tr'));
      var withIdx = rows.map(function (r, i) {
        return { r: r, i: i };
      });
      withIdx.sort(function (a, b) {
        var ca = a.r.cells[colIndex];
        var cb = b.r.cells[colIndex];
        var cmp = compareMeta(cellSortMeta(ca), cellSortMeta(cb));
        if (cmp !== 0) {
          return state.dir * cmp;
        }
        return a.i - b.i;
      });
      withIdx.forEach(function (x) {
        tbody.appendChild(x.r);
      });
    }

    for (var k = 0; k < buttons.length; k++) {
      if (buttons[k] === null) {
        continue;
      }
      (function (ci) {
        buttons[ci].addEventListener('click', function () {
          sortBy(ci);
        });
      })(k);
    }
  }

  function run() {
    var tables = document.querySelectorAll('table');
    for (var t = 0; t < tables.length; t++) {
      initTable(tables[t]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
</script>
