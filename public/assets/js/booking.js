// Страница записи: календарь, выбор времени, отправка формы. Без библиотек и сборки.
(function () {
  'use strict';

  var dataEl = document.getElementById('booking-data');
  var form = document.getElementById('booking-form');
  if (!dataEl || !form) return;

  var data = JSON.parse(dataEl.textContent);
  var calendar = data.calendar;
  var state = { day: null, startsAt: null };

  var calendarEl = document.getElementById('calendar');
  var timesEl = document.getElementById('times');
  var timesError = document.getElementById('times-error');
  var summaryEl = document.getElementById('summary');
  var formError = document.getElementById('form-error');
  var submitBtn = document.getElementById('submit');
  var doneEl = document.getElementById('done');

  var MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
  var WEEKDAYS = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'вс'];
  var longDate = new Intl.DateTimeFormat('ru-RU', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Даты как 'YYYY-MM-DD' → Date в UTC, чтобы часовой пояс браузера не сдвигал дни.
  function toDate(day) {
    var p = day.split('-');
    return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
  }
  function isoDay(d) {
    return d.toISOString().slice(0, 10);
  }
  function isoWeekday(d) {
    return (d.getUTCDay() + 6) % 7; // 0 — понедельник
  }
  function plural(n, one, few, many) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
    return many;
  }
  function el(tag, attrs, text) {
    var node = document.createElement(tag);
    for (var k in attrs || {}) node.setAttribute(k, attrs[k]);
    if (text != null) node.textContent = text;
    return node;
  }
  function scrollTo(node) {
    if (window.matchMedia('(min-width: 840px)').matches) return; // на десктопе всё рядом
    node.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
  }

  // --- Календарь ---

  function renderCalendar() {
    calendarEl.textContent = '';
    var start = toDate(calendar.today);
    var end = toDate(calendar.last);
    var cursor = new Date(start);
    var month = null, grid = null;

    while (cursor <= end) {
      if (cursor.getUTCMonth() !== month) {
        month = cursor.getUTCMonth();
        var block = el('div', { 'class': 'calendar__block' });
        var title = MONTHS[month] + (cursor.getUTCFullYear() !== start.getUTCFullYear() ? ' ' + cursor.getUTCFullYear() : '');
        block.appendChild(el('div', { 'class': 'calendar__month' }, title));
        grid = el('div', { 'class': 'calendar__grid' });
        WEEKDAYS.forEach(function (w) { grid.appendChild(el('div', { 'class': 'calendar__wd', 'aria-hidden': 'true' }, w)); });
        for (var i = 0; i < isoWeekday(cursor); i++) grid.appendChild(el('div'));
        block.appendChild(grid);
        calendarEl.appendChild(block);
      }

      var day = isoDay(cursor);
      var slots = calendar.days[day] || [];
      var btn = el('button', { type: 'button', 'class': 'calendar__day', 'data-day': day }, String(cursor.getUTCDate()));
      var label = longDate.format(cursor);
      if (slots.length) {
        btn.setAttribute('aria-label', label + ', ' + slots.length + ' ' + plural(slots.length, 'вариант', 'варианта', 'вариантов') + ' времени');
        btn.setAttribute('aria-pressed', day === state.day ? 'true' : 'false');
      } else {
        btn.disabled = true;
        btn.setAttribute('aria-label', label + ', свободного времени нет');
      }
      if (day === calendar.today) btn.classList.add('is-today');
      grid.appendChild(btn);
      cursor.setUTCDate(cursor.getUTCDate() + 1);
    }
  }

  calendarEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.calendar__day');
    if (!btn || btn.disabled) return;
    selectDay(btn.getAttribute('data-day'));
    scrollTo(document.querySelector('[data-step="time"]'));
  });

  function selectDay(day) {
    state.day = day;
    if (state.startsAt && state.startsAt.slice(0, 10) !== day) state.startsAt = null;
    calendarEl.querySelectorAll('.calendar__day:not(:disabled)').forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-day') === day ? 'true' : 'false');
    });
    renderTimes();
    updateSummary();
  }

  // --- Время ---

  function renderTimes() {
    timesEl.textContent = '';
    if (!state.day) {
      timesEl.appendChild(el('p', { 'class': 'placeholder' }, 'Сначала выберите день.'));
      return;
    }
    var slots = calendar.days[state.day] || [];
    timesEl.appendChild(el('p', { 'class': 'times-day' }, capitalize(longDate.format(toDate(state.day)))));
    if (!slots.length) {
      timesEl.appendChild(el('p', { 'class': 'placeholder' }, 'На этот день свободного времени уже нет.'));
      return;
    }
    slots.forEach(function (s) {
      var b = el('button', {
        type: 'button',
        'class': 'time',
        'data-starts': s.starts_at,
        'aria-pressed': s.starts_at === state.startsAt ? 'true' : 'false'
      }, s.time + '–' + s.end);
      timesEl.appendChild(b);
    });
  }

  timesEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.time');
    if (!btn) return;
    state.startsAt = btn.getAttribute('data-starts');
    timesEl.querySelectorAll('.time').forEach(function (b) {
      b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
    });
    timesError.hidden = true;
    updateSummary();
  });

  function capitalize(s) {
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  // --- Итог ---

  function selectedProduct() {
    var input = form.querySelector('input[name="product_id"]:checked');
    if (!input) return null;
    var id = +input.value;
    return data.products.filter(function (p) { return p.id === id; })[0] || null;
  }

  function slotLabel() {
    if (!state.startsAt) return null;
    var slots = calendar.days[state.startsAt.slice(0, 10)] || [];
    var slot = slots.filter(function (s) { return s.starts_at === state.startsAt; })[0];
    if (!slot) return null;
    return capitalize(longDate.format(toDate(state.startsAt.slice(0, 10)))) + ', ' + slot.time + '–' + slot.end;
  }

  function updateSummary() {
    var product = selectedProduct();
    var when = slotLabel();
    if (!product || !when) {
      summaryEl.hidden = true;
      return;
    }
    summaryEl.textContent = '';
    summaryEl.appendChild(el('strong', null, product.title));
    summaryEl.appendChild(el('span', null, when + ' (время московское)'));
    summaryEl.hidden = false;
  }

  form.addEventListener('change', function (e) {
    if (e.target.name === 'product_id') {
      clearError('product_id');
      updateSummary();
    }
  });

  // --- Ошибки ---

  function showError(name, text) {
    var p = form.querySelector('[data-error="' + name + '"]');
    var input = form.querySelector('[name="' + name + '"]');
    if (p) p.textContent = text;
    if (input && input.type !== 'radio' && input.type !== 'hidden') input.setAttribute('aria-invalid', 'true');
  }
  function clearError(name) {
    var p = form.querySelector('[data-error="' + name + '"]');
    var input = form.querySelector('[name="' + name + '"]');
    if (p) p.textContent = '';
    if (input) input.removeAttribute('aria-invalid');
  }
  function clearErrors() {
    form.querySelectorAll('[data-error]').forEach(function (p) { p.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach(function (i) { i.removeAttribute('aria-invalid'); });
    formError.hidden = true;
    timesError.hidden = true;
  }
  form.addEventListener('input', function (e) {
    if (!e.target.name) return;
    clearError(e.target.name);
    if (e.target.name === 'telegram' || e.target.name === 'phone') {
      clearError('telegram');
      clearError('phone');
    }
  });

  function showFormError(text) {
    formError.textContent = text;
    formError.hidden = false;
  }

  // Проверка до отправки. Окончательная — на сервере.
  function validate() {
    var ok = true;
    var first = null;
    function fail(name, text, node) {
      ok = false;
      if (name) showError(name, text);
      first = first || node;
    }
    if (!selectedProduct()) {
      fail(null, '', document.querySelector('[data-step="product"]'));
      showFormError('Выберите формат занятия.');
    }
    if (!state.startsAt) {
      timesError.textContent = 'Выберите день и время.';
      timesError.hidden = false;
      fail(null, '', document.querySelector('[data-step="day"]'));
    }
    var name = form.elements.name.value.trim();
    var email = form.elements.email.value.trim();
    var tg = form.elements.telegram.value.trim();
    var phone = form.elements.phone.value.trim();
    if (!name) fail('name', 'Укажите имя.', form.elements.name);
    if (!email) fail('email', 'Укажите почту: на неё придёт подтверждение.', form.elements.email);
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) fail('email', 'Проверьте адрес почты.', form.elements.email);
    if (!tg && !phone) fail('telegram', 'Укажите ник в Telegram или телефон, чтобы Женя могла с вами связаться.', form.elements.telegram);
    if (!form.elements.consent.checked) fail('consent', 'Нужно согласие на обработку персональных данных.', form.elements.consent);
    if (first) {
      first.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
      if (first.focus && first.tagName === 'INPUT') first.focus({ preventScroll: true });
    }
    return ok;
  }

  // --- Отправка ---

  function reloadSlots() {
    return fetch('/api/slots.php', { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) return;
        calendar = res.calendar;
        renderCalendar();
        renderTimes();
        updateSummary();
      })
      .catch(function () {});
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearErrors();
    if (!validate()) return;

    form.elements.starts_at.value = state.startsAt;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Отправляем…';

    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (res) {
        if (res.ok) {
          form.hidden = true;
          document.getElementById('done-text').textContent = res.email
            ? 'Женя посмотрит заявку и подтвердит время. Письмо придёт на ' + res.email + '.'
            : 'Женя посмотрит заявку и подтвердит время. Письмо придёт на вашу почту.';
          doneEl.hidden = false;
          doneEl.focus();
          doneEl.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
          return;
        }
        if (res.errors) {
          var firstField = null;
          Object.keys(res.errors).forEach(function (name) {
            if (name === 'product_id') showFormError(res.errors[name]);
            else showError(name, res.errors[name]);
            firstField = firstField || form.querySelector('[name="' + name + '"]');
          });
          if (firstField) firstField.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
          return;
        }
        if (res.reason) {
          // Время заняли или запись на него закрылась — обновить расписание.
          state.startsAt = null;
          timesError.textContent = res.error;
          timesError.hidden = false;
          reloadSlots().then(function () {
            timesError.hidden = false;
            document.querySelector('[data-step="time"]').scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
          });
          return;
        }
        showFormError(res.error || 'Не удалось отправить заявку. Попробуйте ещё раз через минуту.');
      })
      .catch(function () {
        showFormError('Нет связи с сайтом. Проверьте интернет и попробуйте ещё раз.');
      })
      .then(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Отправить заявку';
      });
  });

  renderCalendar();
  renderTimes();
})();
