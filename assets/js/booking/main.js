/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Booking page entry point (ES module).
 * Bootstraps all booking modules and initialises state from server-side vars.
 */

import * as state from 'apexianlab-booking/state';
import * as api from 'apexianlab-booking/api';
import { initCalendar } from 'apexianlab-booking/calendar';
import { initSlots, loadSlots } from 'apexianlab-booking/slots';
import { initFrames, goToFrame } from 'apexianlab-booking/frames';
import { initForm, applySlotSelection } from 'apexianlab-booking/form';
import { TZ_ALIASES, EXCLUDED_TIMEZONES } from 'apexianlab-shared/timezones';

const RESUME_STORAGE_KEY = `apexianlab-booking-resume:${location.pathname}`;

function saveResumeStateBeforeReload() {
  const frame = state.get('frame');
  if (frame !== 'frame-2') return;
  const time = state.get('selectedTime');
  const year = state.get('selectedYear');
  const month = state.get('selectedMonth');
  const day = state.get('selectedDay');
  const duration = state.get('duration');
  if (!time || !year || !month || !day) return;
  try {
    sessionStorage.setItem(RESUME_STORAGE_KEY, JSON.stringify({ year, month, day, time, duration }));
  } catch {
    // ignore quota / disabled storage
  }
}

// Returns true when a pre-login slot selection was restored (page lands on
// frame-2). The caller then skips the initial loadSlots() — frame-2 only needs
// the already-picked slot, not the availability grid. Slots load lazily if the
// user later navigates back to the calendar (see apexianlab-frame-changed below).
function maybeRestoreAfterLogin() {
  let raw;
  try {
    raw = sessionStorage.getItem(RESUME_STORAGE_KEY);
  } catch {
    return false;
  }
  if (!raw) return false;
  try { sessionStorage.removeItem(RESUME_STORAGE_KEY); } catch { /* ignore */ }

  let saved;
  try { saved = JSON.parse(raw); } catch { return false; }
  if (!saved || !saved.time || !saved.year || !saved.month || !saved.day) return false;

  if (saved.duration) {
    state.set('duration', parseInt(saved.duration, 10) || state.get('duration'));
  }

  applySlotSelection(
    { year: saved.year, month: saved.month - 1, day: saved.day },
    saved.time,
  );
  goToFrame(2);
  return true;
}

function detectTimezone() {
  const vars = window.calendar_vars || {};
  let tz = '';

  try {
    const params = new URLSearchParams(window.location.search);
    tz = params.get('tz') || params.get('timezone') || '';
  } catch {
    // ignore
  }

  if (!tz) {
    try {
      tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch {
      // ignore
    }
  }

  if (!tz && typeof jstz !== 'undefined' && jstz.determine) {
    const result = jstz.determine();
    tz = result?.name?.() || '';
  }

  const saved = vars.saved_booking_timezone ? String(vars.saved_booking_timezone).trim() : '';
  if (saved) tz = saved;

  const out = (tz || 'UTC').trim();
  return TZ_ALIASES[out] || out;
}

const vars = window.calendar_vars || {};
const durationEl = document.querySelector('.duration__value');

state.init({
  timezone: detectTimezone(),
  scheduleBookingShortId: vars.schedule_booking_short_id || '',
  duration: parseInt(durationEl?.getAttribute('data-duration'), 10) || 30,
});

// `save_booking_timezone` is only fired when the user actively picks a TZ
// from the timezone picker (see initTimezoneSelect → selectTz). Re-saving
// the detected default on every page load is a no-op AJAX — the saved
// value would always equal what detectTimezone() already returned.

const now = new Date();
state.set('selectedYear', now.getFullYear());
state.set('selectedMonth', now.getMonth() + 1);
state.set('selectedDay', now.getDate());

initCalendar('#frame-1');
initFrames();
initForm();

// Restore the pre-login slot selection BEFORE initSlots() wires up the
// change:duration / change:selectedDay listeners. maybeRestoreAfterLogin()
// mutates both via state.set(); if the listeners are already registered each
// set() fires its own loadSlots(), so one post-login page load kicks off three
// concurrent get_available_ranges requests. Those serialize on the PHP session
// lock and stall unrelated AJAX (logout, calendar switch) for seconds. Running
// the restore first leaves a single bootstrap loadSlots() below.
//
// initDurationController() also runs before initSlots(): when the restored
// duration is not one of the schedule's allowed values it corrects state via
// state.set('duration', ...), which would otherwise trip the slot listener too.
document.addEventListener('apexianlab-login-before-reload', saveResumeStateBeforeReload);
const restoredAfterLogin = maybeRestoreAfterLogin();

initDurationController();
initSlots();
initTimezoneSelect();

document.body.classList.add('apexianlab-ready');

// Skip the slots fetch when the page boots on a non-calendar frame
// (confirm/confirmed/cancel/cancelled/slot-not-available/etc.) — the slot
// data is irrelevant there. loadSlots() is invoked from the Reschedule
// click handler when the user actually needs the calendar.
const SLOT_LESS_INITIAL_FRAMES = new Set([
  'confirm', 'confirmed', '5', '6', '7',
  'cancelled', 'slot-not-available', 'creation-event-error',
]);
const initialFrameParam = new URLSearchParams(window.location.search).get('frame') || '';
// A post-login restore lands the user on frame-2 with the slot already picked,
// so the availability grid is not needed yet — skip the fetch entirely. It
// loads lazily via the apexianlab-frame-changed handler if the user goes back to the
// calendar.
const skipInitialSlots = restoredAfterLogin || SLOT_LESS_INITIAL_FRAMES.has(initialFrameParam);
if (!skipInitialSlots) {
  loadSlots();
}

// When the page boots on a slot-less frame (e.g. cancel) and the user later
// navigates to the calendar (logo click, back-button, etc.), the calendar
// would otherwise render with no availability data — every day appears free
// and the slots panel stays empty until the user changes the day. Trigger a
// one-shot fetch the first time the calendar becomes visible.
let calendarSlotsBootstrapped = !skipInitialSlots;
document.addEventListener('apexianlab-frame-changed', (e) => {
  if (e.detail?.frame === 1 && !calendarSlotsBootstrapped) {
    calendarSlotsBootstrapped = true;
    loadSlots();
  }
});

// --- Duration controller ---

function initDurationController() {
  const controller = document.querySelector('.duration__controller');
  if (!controller) return;

  const lessBtn = controller.querySelector('.duration__btn--less');
  const moreBtn = controller.querySelector('.duration__btn--more');
  const valueEl = controller.querySelector('.duration__value');

  let DURATIONS = [15, 30, 45, 60, 90];
  try {
    const parsed = JSON.parse(valueEl?.dataset.durations || '');
    if (Array.isArray(parsed) && parsed.length) {
      DURATIONS = parsed.map(n => parseInt(n, 10)).filter(n => Number.isFinite(n) && n > 0);
    }
  } catch { /* keep default */ }

  let current = state.get('duration');
  if (!DURATIONS.includes(current)) {
    current = DURATIONS[0];
    state.set('duration', current);
  }

  if (DURATIONS.length <= 1) {
    const toolbar = controller.closest('.booking__toolbar-duration');
    if (toolbar) toolbar.style.display = 'none';
    return;
  }

  function render() {
    if (valueEl) {
      valueEl.textContent = `${current} min`;
      valueEl.setAttribute('data-duration', current);
    }
    const idx = DURATIONS.indexOf(current);
    if (lessBtn) {
      lessBtn.disabled = idx <= 0;
      lessBtn.classList.toggle('duration__btn--disabled', idx <= 0);
    }
    if (moreBtn) {
      moreBtn.disabled = idx >= DURATIONS.length - 1;
      moreBtn.classList.toggle('duration__btn--disabled', idx >= DURATIONS.length - 1);
    }
  }
  render();

  lessBtn?.addEventListener('click', () => {
    const idx = DURATIONS.indexOf(current);
    if (idx > 0) {
      current = DURATIONS[idx - 1];
      state.set('duration', current);
      render();
    }
  });

  moreBtn?.addEventListener('click', () => {
    const idx = DURATIONS.indexOf(current);
    if (idx < DURATIONS.length - 1) {
      current = DURATIONS[idx + 1];
      state.set('duration', current);
      render();
    }
  });

  state.on('change:duration', () => {
    const next = state.get('duration');
    if (next === current || !DURATIONS.includes(next)) return;
    current = next;
    render();
  });
}

// --- Timezone selector ---

function initTimezoneSelect() {
  const select = document.querySelector('.slots__timezone');
  const input = document.querySelector('.slots__timezone-input');
  const dropdown = document.querySelector('.slots__timezone-dropdown');
  const ant = document.querySelector('.slots__timezone-ant');
  const picker = document.querySelector('#frame-1 .slots__timezone-picker');
  if (!input) return;

  const userLocale = navigator.language;
  const allTimezones = (typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [])
    .filter(tz => !EXCLUDED_TIMEZONES.has(tz));

  function getOffset(tz) {
    try {
      const parts = new Intl.DateTimeFormat(userLocale, { timeZone: tz, timeZoneName: 'longOffset' })
        .formatToParts(new Date());
      const tzPart = (parts.find(p => p.type === 'timeZoneName') || {}).value || 'GMT+00:00';
      const m = String(tzPart).match(/GMT([+-]\d{2}:\d{2})/);
      return m ? m[1] : '+00:00';
    } catch {
      return '+00:00';
    }
  }

  function parseOffsetMinutes(offset) {
    const m = String(offset).match(/^([+-])(\d{2}):(\d{2})$/);
    if (!m) return 0;
    return (m[1] === '-' ? -1 : 1) * (parseInt(m[2], 10) * 60 + parseInt(m[3], 10));
  }

  function fmtOffset(o) {
    const m = String(o).match(/^([+-])(\d{2}):(\d{2})$/);
    if (!m) return '+0:00';
    return m[1] + parseInt(m[2], 10) + ':' + String(parseInt(m[3], 10)).padStart(2, '0');
  }

  function label(name, offset) { return `${name} (${fmtOffset(offset)})`; }

  const canonicalTz = (t) => TZ_ALIASES[t] || t;
  const seenTz = new Set();
  const options = allTimezones.map(tz => {
    const name = canonicalTz(tz);
    if (seenTz.has(name)) return null;
    seenTz.add(name);
    const offset = getOffset(name);
    return { name, offset, offsetMinutes: parseOffsetMinutes(offset), label: label(name, offset) };
  }).filter(Boolean).sort((a, b) => a.offsetMinutes !== b.offsetMinutes ? a.offsetMinutes - b.offsetMinutes : a.name.localeCompare(b.name));

  const detected = state.get('timezone');

  if (select) {
    options.forEach(tz => {
      const opt = document.createElement('option');
      opt.value = tz.name;
      opt.textContent = tz.label;
      if (tz.name === detected) opt.selected = true;
      select.appendChild(opt);
    });
    select.value = detected;
  }

  let highlightIdx = -1;

  function currentTimezoneName() {
    return String(select?.value || state.get('timezone') || '').trim();
  }

  function filter(query) {
    let q = (query || '').trim().toLowerCase();
    const currentName = currentTimezoneName();
    if (q && currentName) {
      const selected = options.find(tz => tz.name === currentName);
      if (selected && q === selected.label.trim().toLowerCase()) {
        q = '';
      }
    }
    const matches = options.filter(tz => !q || tz.label.toLowerCase().includes(q) || tz.name.toLowerCase().includes(q));
    return q ? matches.slice(0, 200) : matches;
  }

  function renderDropdown(list, currentVal) {
    if (!dropdown) return;
    if (list.length === 0) {
      dropdown.innerHTML = '<div class="ant-select-item ant-select-item-empty">No matches</div>';
      return;
    }
    const cur = String(currentVal || '').trim();
    dropdown.innerHTML = list.map((tz, i) => {
      const isSelected = tz.name === cur;
      const sel = isSelected ? ' ant-select-item-option-selected' : '';
      const hl = i === highlightIdx ? ' ant-select-item-option-active' : '';
      const ariaSel = isSelected ? ' aria-selected="true"' : ' aria-selected="false"';
      return `<div class="ant-select-item ant-select-item-option${sel}${hl}" role="option"${ariaSel} data-tz="${tz.name}"><div class="ant-select-item-option-content">${tz.label}</div></div>`;
    }).join('');
    requestAnimationFrame(() => {
      const selectedEl = cur
        ? [...dropdown.querySelectorAll('.ant-select-item-option[data-tz]')].find(el => el.dataset.tz === cur)
        : null;
      (selectedEl || dropdown.querySelector('.ant-select-item-option-active'))?.scrollIntoView({ block: 'nearest' });
    });
  }

  function updateInputValue(tzName) {
    const match = options.find(tz => tz.name === tzName);
    if (match) input.value = match.label;
    syncPickerWidth();
  }

  function open() {
    if (dropdown) dropdown.classList.remove('ant-select-dropdown-hidden');
    if (ant) ant.classList.add('ant-select-open');
  }

  function close() {
    if (dropdown) dropdown.classList.add('ant-select-dropdown-hidden');
    if (ant) ant.classList.remove('ant-select-open');
  }

  function selectTz(tzName) {
    if (!tzName) return;
    if (select) select.value = tzName;
    state.set('timezone', tzName);
    api.saveBookingTimezone(tzName);
    updateInputValue(tzName);
    close();
    highlightIdx = -1;
    input.blur();
  }

  function syncPickerWidth() {
    if (!picker || !input) return;
    let measure = picker.querySelector('.slots__timezone-width-measure');
    if (!measure) {
      measure = document.createElement('span');
      measure.className = 'slots__timezone-width-measure';
      measure.setAttribute('aria-hidden', 'true');
      picker.appendChild(measure);
    }
    const cs = getComputedStyle(input);
    Object.assign(measure.style, {
      position: 'absolute', left: '-99999px', top: '0', visibility: 'hidden',
      pointerEvents: 'none', whiteSpace: 'nowrap', overflow: 'visible',
      fontFamily: cs.fontFamily, fontSize: cs.fontSize, fontWeight: cs.fontWeight,
      fontStyle: cs.fontStyle, letterSpacing: cs.letterSpacing, lineHeight: cs.lineHeight,
      textTransform: cs.textTransform,
    });
    const text = input.value || '\u00a0';
    measure.textContent = text;
    const textW = measure.getBoundingClientRect().width;
    const selectorEl = ant?.querySelector('.ant-select-selector');
    const isTablet = window.matchMedia('(min-width: 768px) and (max-width: 991px)').matches;
    let extra = 48;
    let padL = 0;
    let padR = 0;
    if (selectorEl) {
      padL = parseFloat(getComputedStyle(selectorEl).paddingLeft) || 0;
      padR = parseFloat(getComputedStyle(selectorEl).paddingRight) || 0;
      const arrowEl = ant.querySelector('.ant-select-arrow');
      const arrowW = arrowEl ? arrowEl.getBoundingClientRect().width + 10 : 32;
      const flexGap = 4;
      extra = padL + padR + arrowW + flexGap + 8;
    }
    const parentW = picker.parentElement?.getBoundingClientRect().width || 0;
    const MAX = 720;
    const TABLET_OUTER_MAX = 320;
    let cap = parentW > 0 ? Math.min(MAX, Math.floor(parentW)) : MAX;
    const raw = Math.ceil(textW + extra);
    let upper = Math.min(MAX, Math.max(cap, raw));
    if (isTablet) {
      upper = Math.min(upper, TABLET_OUTER_MAX);
    }
    let w = Math.min(Math.max(raw, 96), upper);
    picker.style.width = `${w}px`;
    picker.style.minWidth = `${w}px`;
    picker.style.maxWidth = '100%';

    if (selectorEl) {
      const flexGap = 4;
      if (isTablet) {
        const arrowEl = ant?.querySelector('.ant-select-arrow');
        const aw = arrowEl ? arrowEl.getBoundingClientRect().width : 12;
        const desiredInner = Math.ceil(textW + padL + padR + 12);
        const desiredOuter = Math.min(TABLET_OUTER_MAX, desiredInner + aw + flexGap);
        if (desiredOuter > w && desiredOuter <= upper) {
          w = desiredOuter;
          picker.style.width = `${w}px`;
          picker.style.minWidth = `${w}px`;
        }
        const maxInner = Math.max(48, Math.floor(w - aw - flexGap + 8));
        selectorEl.style.width = `${Math.min(desiredInner, maxInner)}px`;
        selectorEl.style.flex = '0 0 auto';
      } else {
        selectorEl.style.removeProperty('width');
        selectorEl.style.removeProperty('flex');
      }
    }
  }

  updateInputValue(detected);
  const initList = filter('');
  const initIdx = initList.findIndex(tz => tz.name === detected);
  highlightIdx = initIdx >= 0 ? initIdx : 0;
  renderDropdown(initList, detected);

  let resizeTimer;
  window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(syncPickerWidth, 150); });

  function schedulePickerWidthReflow() {
    syncPickerWidth();
    requestAnimationFrame(() => {
      syncPickerWidth();
      requestAnimationFrame(() => {
        syncPickerWidth();
      });
    });
    try {
      const fontsReady = document.fonts?.ready;
      if (fontsReady && typeof fontsReady.then === 'function') {
        fontsReady.then(() => { syncPickerWidth(); });
      }
    } catch {
      // ignore
    }
    window.addEventListener('load', () => { syncPickerWidth(); }, { once: true });
  }
  schedulePickerWidthReflow();

  document.addEventListener('apexianlab-frame-changed', (e) => {
    if (e.detail?.frame === 1) {
      requestAnimationFrame(() => {
        syncPickerWidth();
        requestAnimationFrame(syncPickerWidth);
      });
    }
  });

  if (ant) {
    ant.addEventListener('mousedown', e => {
      if (e.target.closest('.slots__timezone-dropdown')) return;
      const isInput = !!e.target.closest('.slots__timezone-input');
      const isToggle = !!e.target.closest('.ant-select-selector, .ant-select-arrow');
      if (isToggle && !isInput) {
        e.preventDefault();
        const isOpen = dropdown && !dropdown.classList.contains('ant-select-dropdown-hidden');
        if (isOpen) { close(); ant.classList.remove('ant-select-focused'); }
        else { open(); input.focus(); }
      } else if (!isInput) {
        e.preventDefault();
        input.focus();
      }
    });
  }

  input.addEventListener('focus', () => {
    if (ant) ant.classList.add('ant-select-focused');
    input.value = '';
    const list = filter('');
    const cur = currentTimezoneName();
    highlightIdx = list.findIndex(tz => tz.name === cur);
    if (highlightIdx < 0) highlightIdx = 0;
    renderDropdown(list, cur);
    open();
  });

  input.addEventListener('input', () => {
    syncPickerWidth();
    const list = filter(input.value);
    highlightIdx = 0;
    renderDropdown(list, currentTimezoneName());
    open();
  });

  input.addEventListener('blur', () => {
    if (ant) ant.classList.remove('ant-select-focused');
    setTimeout(() => {
      close();
      const raw = (input.value || '').trim();
      const match = options.find(tz => tz.label.toLowerCase() === raw.toLowerCase() || tz.name.toLowerCase() === raw.toLowerCase());
      const currentName = currentTimezoneName();
      if (match && match.name !== currentName) selectTz(match.name);
      else updateInputValue(currentName);
    }, 150);
  });

  input.addEventListener('keydown', e => {
    const key = e.key;
    if (!['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(key)) return;
    const list = filter(input.value);
    const n = list.length;

    if (key === 'Escape') {
      if (dropdown && !dropdown.classList.contains('ant-select-dropdown-hidden')) {
        e.preventDefault();
        close();
        updateInputValue(select?.value || state.get('timezone'));
      }
      return;
    }
    if (key === 'Enter') {
      if (!dropdown || dropdown.classList.contains('ant-select-dropdown-hidden') || !n) return;
      e.preventDefault();
      const pick = list[Math.min(Math.max(highlightIdx, 0), n - 1)];
      if (pick) selectTz(pick.name);
      return;
    }
    e.preventDefault();
    if (!n) { open(); return; }
    if (dropdown?.classList.contains('ant-select-dropdown-hidden')) open();
    highlightIdx = key === 'ArrowDown'
      ? (highlightIdx < 0 ? 0 : (highlightIdx + 1) % n)
      : (highlightIdx <= 0 ? n - 1 : highlightIdx - 1);
    renderDropdown(list, currentTimezoneName());
    const active = dropdown?.querySelector('.ant-select-item-option-active');
    active?.scrollIntoView({ block: 'nearest' });
  });

  if (dropdown) {
    dropdown.addEventListener('mousedown', e => {
      if (e.target.closest('.ant-select-item-option[data-tz]')) e.preventDefault();
    });
    dropdown.addEventListener('click', e => {
      const item = e.target.closest('.ant-select-item-option[data-tz]');
      if (item) selectTz(item.dataset.tz);
    });
  }

  if (select) {
    select.addEventListener('change', () => updateInputValue(select.value));
  }
}
