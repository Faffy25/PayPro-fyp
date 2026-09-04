/* PayPro front-end behaviour. Progressive: every page still works without it. */
(function () {
  'use strict';

  // --- Off-canvas navigation on small screens ---------------------
  var toggle = document.getElementById('menuToggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      document.body.classList.toggle('nav-open');
    });
    document.addEventListener('click', function (ev) {
      if (!document.body.classList.contains('nav-open')) return;
      var side = document.getElementById('sidebar');
      if (side && !side.contains(ev.target) && ev.target !== toggle) {
        document.body.classList.remove('nav-open');
      }
    });
  }

  // --- Filter any table from a search box ------------------------
  // <input data-filter="#tableId">
  document.querySelectorAll('[data-filter]').forEach(function (box) {
    var table = document.querySelector(box.getAttribute('data-filter'));
    if (!table) return;
    box.addEventListener('input', function () {
      var q = box.value.toLowerCase().trim();
      var shown = 0;
      table.querySelectorAll('tbody tr').forEach(function (row) {
        var hit = row.textContent.toLowerCase().indexOf(q) !== -1;
        row.style.display = hit ? '' : 'none';
        if (hit) shown++;
      });
      var note = document.getElementById('filterCount');
      if (note) note.textContent = shown + ' of ' + table.querySelectorAll('tbody tr').length + ' rows';
    });
  });

  // --- Confirm destructive actions --------------------------------
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (ev) {
      if (!window.confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  // --- Leave: count working days between the two dates ------------
  var start = document.getElementById('start_date');
  var end   = document.getElementById('end_date');
  var days  = document.getElementById('days_requested');
  function countDays() {
    if (!start.value || !end.value) return;
    var a = new Date(start.value), b = new Date(end.value), n = 0;
    if (b < a) { days.value = 0; return; }
    for (var d = new Date(a); d <= b; d.setDate(d.getDate() + 1)) {
      var wd = d.getDay();
      if (wd !== 0 && wd !== 6) n++;
    }
    days.value = n;
  }
  if (start && end && days) {
    start.addEventListener('change', countDays);
    end.addEventListener('change', countDays);
  }

  // --- Appraisal: weighted score as you rate ----------------------
  var scoreInputs = document.querySelectorAll('.kpi-score');
  if (scoreInputs.length) {
    var totalOut  = document.getElementById('liveScore');
    var ratingOut = document.getElementById('liveRating');
    var bonusOut  = document.getElementById('liveBonus');
    var rules     = window.PAYPRO_BONUS_RULES || [];

    var recalc = function () {
      var total = 0;
      scoreInputs.forEach(function (inp) {
        var max = parseFloat(inp.dataset.max) || 5;
        var w   = parseFloat(inp.dataset.weight) || 0;
        var v   = parseFloat(inp.value) || 0;
        if (v > max) { inp.value = max; v = max; }
        if (v < 0)   { inp.value = 0;   v = 0; }
        total += (v / max) * w;
      });
      total = Math.round(total * 100) / 100;
      if (totalOut) totalOut.textContent = total.toFixed(2) + ' / 100';
      var hit = rules.find(function (r) { return total >= +r.min_score && total <= +r.max_score; });
      if (ratingOut) ratingOut.textContent = hit ? hit.rating : '—';
      if (bonusOut)  bonusOut.textContent  = hit ? (+hit.bonus_percentage).toFixed(2) + '% of basic salary' : '—';
    };
    scoreInputs.forEach(function (i) { i.addEventListener('input', recalc); });
    recalc();
  }

  // --- Salary form: show the gross as it is typed -----------------
  var salaryFields = document.querySelectorAll('.salary-part');
  var grossOut = document.getElementById('grossPreview');
  if (salaryFields.length && grossOut) {
    var sum = function () {
      var t = 0;
      salaryFields.forEach(function (f) { t += parseFloat(f.value) || 0; });
      grossOut.textContent = t.toFixed(2);
    };
    salaryFields.forEach(function (f) { f.addEventListener('input', sum); });
    sum();
  }

  // --- Auto-submit filter forms on change -------------------------
  document.querySelectorAll('form[data-autosubmit] select').forEach(function (sel) {
    sel.addEventListener('change', function () { sel.form.submit(); });
  });
})();
