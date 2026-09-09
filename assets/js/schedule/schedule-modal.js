/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Schedule create / edit modal.
 */

import * as state from 'apexianlab-schedule/state';
import * as api from 'apexianlab-schedule/api';
import { refetchEvents } from 'apexianlab-schedule/fullcalendar-adapter';
import { EXCLUDED_TIMEZONES, canonicalTimezone } from 'apexianlab-shared/timezones';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => root.querySelectorAll(sel);

let availabilitySelectProgrammatic = false;
let customAvailabilityPending = false;
let committedAvailabilitySelect = 'none';

function modal()   { return $('#create-schedule-modal'); }
function form()    { return $('#create-schedule-form'); }
function customModal() { return $('#custom-availability-modal'); }

function getAvailabilityMode() {
  const sel = $('#schedule-availability-select');
  if (sel) {
    const v = String(sel.value || '');
    if (v === 'none' || v === 'weekly') return v;
    if (v === 'custom' || v === 'custom-label') return 'custom';
  }
  const root = $('#schedule-availability');
  return root ? String(root.dataset.mode || 'none') : 'none';
}

/** Apply hidden repeat + panels from the availability select (select is source of truth). */
function applySelectValueToAvailabilityState() {
  const sel = $('#schedule-availability-select');
  if (!sel) return;
  const v = String(sel.value || 'none');
  if (v === 'custom' || v === 'custom-label') {
    const interval = Math.max(1, Math.min(52, parseInt(String($('#schedule-repeat-interval-hidden')?.value), 10) || 1));
    const never = $('#schedule-repeat-end-never-hidden')?.value === '1';
    setAvailabilityMode('custom', 'custom', interval, never);
    return;
  }
  if (v === 'weekly') {
    setAvailabilityMode('weekly', 'weekly', 1, true);
    return;
  }
  setAvailabilityMode('none', 'does_not_repeat', 1, false);
}

/** Mirror the (hidden) native select's current label onto the custom picker trigger. */
function syncAvailabilityTrigger() {
  const sel = $('#schedule-availability-select');
  const value = $('#schedule-availability-value');
  if (!sel || !value) return;
  const opt = sel.options[sel.selectedIndex];
  value.textContent = opt ? opt.textContent : '';
}

/**
 * Custom dropdown over the native availability select (tz-picker style): keeps the
 * native <select> as the source of truth so all existing value/change logic works,
 * while giving full control over option padding (native <option> can't be styled in Chrome).
 */
function initAvailabilityPicker() {
  const sel = $('#schedule-availability-select');
  const picker = $('#schedule-availability-picker');
  const trigger = $('#schedule-availability-trigger');
  const dropdown = $('#schedule-availability-dropdown');
  if (!sel || !picker || !trigger || !dropdown) return;

  function close() {
    dropdown.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
  }

  function renderDropdown() {
    const currentMode = getAvailabilityMode();
    dropdown.innerHTML = '';
    Array.from(sel.options).forEach(o => {
      if (o.value === 'custom-label') return; // display-only state, never user-pickable
      const item = document.createElement('div');
      item.className = 'avail-picker__option';
      item.setAttribute('role', 'option');
      item.setAttribute('data-value', o.value);
      item.textContent = o.textContent;
      const itemMode = o.value === 'custom' ? 'custom' : o.value;
      if (itemMode === currentMode) item.classList.add('is-selected');
      item.addEventListener('mousedown', e => {
        e.preventDefault();
        close();
        sel.value = o.value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        syncAvailabilityTrigger();
      });
      dropdown.appendChild(item);
    });
  }

  trigger.addEventListener('click', e => {
    e.preventDefault();
    if (dropdown.hidden) {
      renderDropdown();
      dropdown.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
    } else {
      close();
    }
  });

  trigger.addEventListener('keydown', e => {
    if (e.key === 'Escape') close();
  });

  document.addEventListener('mousedown', e => {
    if (!picker.contains(e.target)) close();
  });

  syncAvailabilityTrigger();
}

// Syncers for the generic select pickers, replayed after the form is populated
// programmatically (edit / create) so each custom trigger shows the right label.
const selectPickerSyncers = [];

function syncSelectPickers() {
  selectPickerSyncers.forEach(fn => fn());
}

/**
 * Wrap a native <select> with a custom dropdown (tz-picker / avail-picker style).
 * The native <select> stays in the DOM (hidden) as the source of truth, so all
 * existing value reads, change listeners and form submission keep working — this
 * only takes over rendering so option styling/padding is fully controllable.
 */
function initSelectPicker(sel) {
  if (!sel || sel.dataset.pickerInit === '1') return;
  sel.dataset.pickerInit = '1';

  sel.setAttribute('aria-hidden', 'true');
  sel.setAttribute('tabindex', '-1');
  sel.style.display = 'none';

  const picker = document.createElement('div');
  picker.className = 'avail-picker';

  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = 'avail-picker__trigger';
  trigger.setAttribute('aria-haspopup', 'listbox');
  trigger.setAttribute('aria-expanded', 'false');

  const value = document.createElement('span');
  value.className = 'avail-picker__value';

  const arrow = document.createElement('span');
  arrow.className = 'avail-picker__arrow';
  arrow.setAttribute('aria-hidden', 'true');

  trigger.appendChild(value);
  trigger.appendChild(arrow);

  const dropdown = document.createElement('div');
  dropdown.className = 'avail-picker__dropdown';
  dropdown.setAttribute('role', 'listbox');
  dropdown.hidden = true;

  picker.appendChild(trigger);
  picker.appendChild(dropdown);
  sel.parentNode.insertBefore(picker, sel);

  function syncLabel() {
    const opt = sel.options[sel.selectedIndex];
    value.textContent = opt ? opt.textContent : '';
  }

  function close() {
    dropdown.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
  }

  function renderDropdown() {
    dropdown.innerHTML = '';
    Array.from(sel.options).forEach(o => {
      if (o.hidden) return;
      const item = document.createElement('div');
      item.className = 'avail-picker__option';
      item.setAttribute('role', 'option');
      item.setAttribute('data-value', o.value);
      item.textContent = o.textContent;
      if (o.value === sel.value) item.classList.add('is-selected');
      item.addEventListener('mousedown', e => {
        e.preventDefault();
        close();
        if (sel.value !== o.value) {
          sel.value = o.value;
          sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
        syncLabel();
      });
      dropdown.appendChild(item);
    });
  }

  trigger.addEventListener('click', e => {
    e.preventDefault();
    if (dropdown.hidden) {
      renderDropdown();
      dropdown.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
    } else {
      close();
    }
  });
  trigger.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  document.addEventListener('mousedown', e => { if (!picker.contains(e.target)) close(); });

  // Reflect programmatic value changes (e.g. browser-driven change) onto the trigger.
  sel.addEventListener('change', syncLabel);

  selectPickerSyncers.push(syncLabel);
  syncLabel();
}

function addMinutesToTime(hhmm, minutes) {
  if (!hhmm || hhmm === '24:00') return '24:00';
  const [h, m] = hhmm.split(':').map(Number);
  const total = h * 60 + m + minutes;
  if (total >= 24 * 60) return '24:00';
  const nh = Math.floor(total / 60);
  const nm = total % 60;
  return (nh < 10 ? '0' : '') + nh + ':' + (nm < 10 ? '0' : '') + nm;
}

function formatTime12(hhmm) {
  if (!hhmm) return '--:--';
  // 24:00 is the end-of-day marker (midnight closing the day) — only valid as
  // an end time. Labelled distinctly so it never collides with 00:00 ("12:00 AM").
  if (hhmm === '24:00') return 'End of day';
  const parts = hhmm.split(':');
  const h = parseInt(parts[0], 10);
  const m = parts.length > 1 ? parseInt(parts[1], 10) : 0;
  const period = h >= 12 ? 'PM' : 'AM';
  let h12 = h % 12;
  if (h12 === 0) h12 = 12;
  return h12 + ':' + (m < 10 ? '0' : '') + m + ' ' + period;
}

function buildTimeOptions(includeEndOfDay) {
  const opts = [];
  for (let h = 0; h < 24; h++) {
    for (let m = 0; m < 60; m += 30) {
      const hh = (h < 10 ? '0' : '') + h;
      const mm = (m < 10 ? '0' : '') + m;
      const v = hh + ':' + mm;
      opts.push({ value: v, label: formatTime12(v) });
    }
  }
  // End-of-day marker — lets availability cover the full 24h (e.g. a meeting
  // ending at 23:00–24:00). Only offered on end-time pickers.
  if (includeEndOfDay) {
    opts.push({ value: '24:00', label: formatTime12('24:00') });
  }
  return opts;
}

const timeOptions = buildTimeOptions(false);
const timeOptionsEnd = buildTimeOptions(true);

function parseTypedTime(raw) {
  let s = String(raw || '').trim().toLowerCase();
  if (!s) return null;
  const ampm = /^(\d{1,2})(?::?(\d{2}))?\s*(am|pm|a|p)$/.exec(s.replace(/\s+/g, ''));
  if (ampm) {
    let h = parseInt(ampm[1], 10);
    const min = ampm[2] ? parseInt(ampm[2], 10) : 0;
    if (h < 1 || h > 12 || min < 0 || min > 59) return null;
    const isPm = ampm[3].startsWith('p');
    if (h === 12) h = 0;
    if (isPm) h += 12;
    return (h < 10 ? '0' : '') + h + ':' + (min < 10 ? '0' : '') + min;
  }
  s = s.replace(/[.\s]/g, ':');
  if (/^\d{3,4}$/.test(s)) {
    if (s.length === 3) s = '0' + s;
    s = s.slice(0, 2) + ':' + s.slice(2);
  }
  const m = /^(\d{1,2}):(\d{1,2})$/.exec(s);
  if (!m) return null;
  const h = parseInt(m[1], 10);
  const min = parseInt(m[2], 10);
  // 24:00 allowed as the end-of-day marker; any other hour past 23 is invalid.
  if (h < 0 || h > 24 || min < 0 || min > 59 || (h === 24 && min !== 0)) return null;
  return (h < 10 ? '0' : '') + h + ':' + (min < 10 ? '0' : '') + min;
}

function scrollOptionIntoView(dropdown, opt) {
  if (!opt) return;
  var typeEl = dropdown.querySelector('.time-picker-gc__type');
  var stickyH = typeEl ? typeEl.offsetHeight : 0;
  var optTop = opt.offsetTop;
  var optBot = optTop + opt.offsetHeight;
  var st = dropdown.scrollTop;
  if (optTop - stickyH < st) {
    dropdown.scrollTop = optTop - stickyH;
  } else if (optBot > st + dropdown.clientHeight) {
    dropdown.scrollTop = optBot - dropdown.clientHeight;
  }
}

function initTimePickerGc(picker) {
  const input = picker.querySelector('.slot-start, .slot-end');
  const trigger = picker.querySelector('.time-picker-gc__trigger');
  const dropdown = picker.querySelector('.time-picker-gc__dropdown');
  if (!input || !trigger || !dropdown) return;

  trigger.textContent = formatTime12(input.value);

  let typeInput = dropdown.querySelector('.time-picker-gc__type');
  if (!typeInput) {
    typeInput = document.createElement('input');
    typeInput.type = 'text';
    typeInput.className = 'time-picker-gc__type';
    typeInput.placeholder = '9:30 AM';
    typeInput.inputMode = 'text';
    typeInput.maxLength = 8;
    typeInput.autocomplete = 'off';
    dropdown.appendChild(typeInput);
  }

  if (!dropdown.querySelector('.time-picker-gc__option')) {
    // End pickers also get the 24:00 end-of-day option.
    const opts = input.classList.contains('slot-end') ? timeOptionsEnd : timeOptions;
    opts.forEach(o => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'time-picker-gc__option';
      btn.dataset.value = o.value;
      btn.textContent = o.label;
      dropdown.appendChild(btn);
    });
  }

  function commitTyped() {
    const v = parseTypedTime(typeInput.value);
    if (v) {
      input.value = v;
      trigger.textContent = formatTime12(v);
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    typeInput.value = '';
    dropdown.classList.remove('is-open');
  }

  typeInput.addEventListener('click', e => e.stopPropagation());
  typeInput.addEventListener('mousedown', e => e.stopPropagation());
  typeInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      commitTyped();
    } else if (e.key === 'Escape') {
      typeInput.value = '';
      dropdown.classList.remove('is-open');
    }
  });
  typeInput.addEventListener('input', () => {
    const v = parseTypedTime(typeInput.value);
    if (v) {
      const opt = dropdown.querySelector(`.time-picker-gc__option[data-value="${v}"]`);
      scrollOptionIntoView(dropdown, opt);
    }
  });

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    document.querySelectorAll('.time-picker-gc__dropdown').forEach(d => {
      if (d !== dropdown) d.classList.remove('is-open');
    });
    const wasOpen = dropdown.classList.contains('is-open');
    dropdown.classList.toggle('is-open');
    if (!wasOpen) {
      typeInput.value = '';
      const v = input.value;
      if (v) {
        const opt = dropdown.querySelector(`.time-picker-gc__option[data-value="${v}"]`);
        scrollOptionIntoView(dropdown, opt);
      }
      typeInput.focus();
    }
  });

  dropdown.addEventListener('click', (e) => {
    const opt = e.target.closest('.time-picker-gc__option');
    if (!opt) return;
    e.stopPropagation();
    input.value = opt.dataset.value;
    trigger.textContent = opt.textContent;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    typeInput.value = '';
    dropdown.classList.remove('is-open');
  });
}

function closeAllTimeDropdowns() {
  document.querySelectorAll('.time-picker-gc__dropdown').forEach(d => d.classList.remove('is-open'));
}

function ymdFromLocalDate(d) {
  const y = d.getFullYear();
  const m = d.getMonth() + 1;
  const day = d.getDate();
  const pad = n => (n < 10 ? '0' : '') + n;
  return y + '-' + pad(m) + '-' + pad(day);
}

function addYearsToYmd(ymd, years) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);
  if (!m) return ymd;
  const y = parseInt(m[1], 10) + years;
  return y + '-' + m[2] + '-' + m[3];
}

function addDaysToYmd(ymd, days) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);
  if (!m) return ymd;
  const d = new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
  d.setDate(d.getDate() + days);
  return ymdFromLocalDate(d);
}

const SHORT_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// "2026-05-29" -> "May 29, 2026" (Google's date display format).
function formatYmdDisplay(ymd) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);
  if (!m) return '';
  return SHORT_MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
}

// Mirror a native date input's value into its formatted overlay span.
function syncDateDisplay(input) {
  const disp = input?.closest('.custom-date')?.querySelector('.custom-date__display');
  if (disp) disp.textContent = formatYmdDisplay(input.value);
}

// Block an end date before the start, matching Google: any date can be picked,
// but end < start turns the field red and disables Done (no alert, no picker min).
function updateEndDateValidity(changed) {
  const startsInput = $('#custom-availability-starts');
  const endsDate = $('#custom-availability-ends-date');
  if (!endsDate) return;
  const start = startsInput?.value || '';
  const never = $('#custom-availability-ends-never')?.checked;
  const doneBtn = $('#custom-availability-done');
  const interval = Math.max(1, Math.min(52, parseInt($('#custom-availability-interval')?.value, 10) || 1));
  // Matches Google: the end must be at least 6 days after the start.
  const minEnd = start ? addDaysToYmd(start, 6) : '';
  let invalid = !never && (!start || !endsDate.value || endsDate.value < minEnd);
  // Also require at least one bookable slot in [start, end] (same as the backend),
  // so a too-short range under a multi-week interval can't pass Done then fail Save.
  if (!invalid && !never && start && endsDate.value) {
    const slots = buildDailySlots();
    const hasSelectedDay = Object.values(slots).some(a => Array.isArray(a) && a.length > 0);
    if (hasSelectedDay && !scheduleHasAnySlot(start, endsDate.value, interval, slots)) invalid = true;
  }
  // Highlight the field the user just changed — Google reddens the start when the
  // start is moved too close to the end; otherwise it reddens the end date.
  const startCulprit = invalid && changed === startsInput;
  startsInput?.closest('.custom-date')?.classList.toggle('custom-date--invalid', startCulprit);
  startsInput?.setAttribute('aria-invalid', startCulprit ? 'true' : 'false');
  endsDate.closest('.custom-date')?.classList.toggle('custom-date--invalid', invalid && !startCulprit);
  endsDate.setAttribute('aria-invalid', invalid && !startCulprit ? 'true' : 'false');
  if (doneBtn) doneBtn.disabled = invalid;
}

function applyDefaultWeeklyHiddenDateRange() {
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  const end = new Date(start.getTime());
  end.setFullYear(end.getFullYear() + 10);
  const dfEl = $('#schedule-date-from');
  const dtEl = $('#schedule-date-to');
  if (dfEl) dfEl.value = ymdFromLocalDate(start);
  if (dtEl) dtEl.value = ymdFromLocalDate(end);
}

function updateCustomAvailabilitySummary() {
  const intervalRaw = parseInt(String($('#schedule-repeat-interval-hidden')?.value || '1'), 10) || 1;
  const interval = Math.max(1, Math.min(52, intervalRaw));
  const weeksLabel = interval === 1 ? '1 week' : `${interval} weeks`;

  const labelOpt = document.querySelector('#schedule-availability-select option[value="custom-label"]');
  if (labelOpt) {
    labelOpt.textContent = `Repeat every ${weeksLabel}`;
    labelOpt.hidden = getAvailabilityMode() !== 'custom';
  }
  syncAvailabilityTrigger();
}

function updateAvailabilityPanels(mode) {
  const weekly = $('#weekly-availability-panel');
  const specific = $('#specific-dates-panel');
  const custom = $('#custom-availability-panel');
  if (!weekly || !specific || !custom) return;
  if (mode === 'none') {
    specific.classList.remove('is-hidden');
    weekly.classList.add('is-hidden');
    custom.classList.add('is-hidden');
    return;
  }

  // Both 'weekly' and 'custom' show the day-slots panel
  specific.classList.add('is-hidden');
  weekly.classList.remove('is-hidden');
  custom.classList.add('is-hidden');
}

function setAvailabilityMode(mode, repeatVal, interval, endNever, options = {}) {
  const availSelect = $('#schedule-availability-select');
  const root = $('#schedule-availability');
  const repeatHidden = $('#schedule-repeat-hidden');
  const intervalHidden = $('#schedule-repeat-interval-hidden');
  const endNeverHidden = $('#schedule-repeat-end-never-hidden');
  if (!availSelect) return;

  if (root) root.dataset.mode = mode;
  if (repeatHidden) repeatHidden.value = repeatVal;
  if (intervalHidden) intervalHidden.value = String(Math.max(1, Math.min(52, interval)));

  if (mode === 'custom') {
    if (endNeverHidden) endNeverHidden.value = endNever ? '1' : '0';
  } else if (mode === 'weekly' && endNever) {
    if (endNeverHidden) endNeverHidden.value = '1';
  } else {
    if (endNeverHidden) endNeverHidden.value = '0';
  }

  if (options.syncSelect !== false) {
    availabilitySelectProgrammatic = true;
    if (mode === 'custom') {
      availSelect.value = 'custom-label';
    } else {
      availSelect.value = mode === 'weekly' ? 'weekly' : 'none';
    }
    availabilitySelectProgrammatic = false;
    committedAvailabilitySelect = mode;
  }
  updateCustomAvailabilitySummary();
  updateAvailabilityPanels(mode);
}

function revertAvailabilitySelect() {
  const sel = $('#schedule-availability-select');
  if (!sel) return;
  availabilitySelectProgrammatic = true;
  if (committedAvailabilitySelect === 'custom') {
    sel.value = 'custom-label';
  } else if (committedAvailabilitySelect === 'weekly') {
    sel.value = 'weekly';
  } else {
    sel.value = 'none';
  }
  availabilitySelectProgrammatic = false;
  syncAvailabilityTrigger();
}

function resetAvailabilityDisplay() {
  if (!$('#schedule-availability-select')) return;
  setAvailabilityMode('none', 'does_not_repeat', 1, false);
}

function resetDailyAvailability() {
  const defaults = defaultDailySlotsForCustom();
  $$('#daily-availability .create-schedule-form__day').forEach(dayEl => {
    const dow = parseInt(dayEl.dataset.dow, 10);
    setDaySlots(dayEl, defaults[dow] || []);
  });
}

function updateSpecificDateRemoveState() {
  const rows = $$('#specific-dates-list .specific-date-row');
  const disable = rows.length <= 1;
  rows.forEach(row => {
    const btn = row.querySelector('.specific-date-row__remove');
    if (!btn) return;
    btn.disabled = disable;
    btn.classList.toggle('is-disabled', disable);
  });
}

function appendSpecificDateRow(dateYmd, start, end) {
  const templateEl = document.getElementById('specific-date-row-template');
  if (!templateEl?.content?.firstElementChild) return;
  const node = templateEl.content.firstElementChild.cloneNode(true);
  const s = start || '09:00';
  const en = end || '17:00';
  const dateInput = node.querySelector('.specific-date-row__date');
  const startInput = node.querySelector('.slot-start');
  const endInput = node.querySelector('.slot-end');
  if (dateInput) dateInput.value = dateYmd || '';
  if (startInput) startInput.value = s;
  if (endInput) endInput.value = en;
  node.querySelectorAll('.time-picker-gc').forEach((picker, i) => {
    const trigger = picker.querySelector('.time-picker-gc__trigger');
    if (trigger) trigger.textContent = i === 0 ? formatTime12(s) : formatTime12(en);
  });
  const list = $('#specific-dates-list');
  if (list) list.appendChild(node);
  node.querySelectorAll('.time-picker-gc').forEach(p => initTimePickerGc(p));
}

function resetSpecificDatesList() {
  const list = $('#specific-dates-list');
  if (list) list.innerHTML = '';
  appendSpecificDateRow(ymdFromLocalDate(new Date()), '09:00', '17:00');
  updateSpecificDateRemoveState();
}

// Returns the row's email input (or null) so callers can focus it on an
// explicit user add — focusing on initial render would scroll the page to this
// field and steal focus when the modal first opens.
function appendRecipientRow(value = '') {
  const templateEl = document.getElementById('recipient-row-template');
  if (!templateEl?.content?.firstElementChild) return null;
  const node = templateEl.content.firstElementChild.cloneNode(true);
  const input = node.querySelector('.recipient-row__email');
  if (input) input.value = value || '';
  const list = $('#schedule-recipients-list');
  if (list) list.appendChild(node);
  return input;
}

function resetRecipientsList() {
  const list = $('#schedule-recipients-list');
  if (list) list.innerHTML = '';
  appendRecipientRow('');
}

function fillRecipientsList(emails) {
  const list = $('#schedule-recipients-list');
  if (list) list.innerHTML = '';
  const items = Array.isArray(emails)
    ? emails.map(e => String(e || '').trim()).filter(Boolean)
    : [];
  if (items.length === 0) {
    appendRecipientRow('');
    return;
  }
  items.forEach(e => appendRecipientRow(e));
}

// Collect unique, non-empty, trimmed addresses from the recipient rows.
function collectRecipients() {
  const list = $('#schedule-recipients-list');
  if (!list) return [];
  const seen = {};
  const out = [];
  list.querySelectorAll('.recipient-row__email').forEach(inp => {
    const v = String(inp.value || '').trim();
    if (!v) return;
    const k = v.toLowerCase();
    if (seen[k]) return;
    seen[k] = true;
    out.push(v);
  });
  return out;
}

// Validate the recipient rows: each non-empty address must be a valid email
// and unique (case-insensitive). Marks the first offending row and shows an
// inline error. Returns true when every filled row passes.
function validateRecipients() {
  const rows = $$('#schedule-recipients-list .recipient-row');
  rows.forEach(r => r.querySelector('.recipient-row__email')?.classList.remove('recipient-row__email--invalid'));

  // Mirror WordPress is_email() closely: no leading/trailing/consecutive dots
  // in either part, and a dotted domain (TLD required). Keeps client and server
  // verdicts aligned so nothing passes here only to be silently dropped on save.
  const emailRe = /^[^\s@.]+(?:\.[^\s@.]+)*@[^\s@.]+(?:\.[^\s@.]+)+$/;
  const ownerEmail = String((window.schedule_calendar_vars || {}).owner_email || '').trim().toLowerCase();
  const seen = {};
  for (const row of rows) {
    const input = row.querySelector('.recipient-row__email');
    const v = String(input?.value || '').trim();
    if (!v) continue;

    if (!emailRe.test(v)) {
      input.classList.add('recipient-row__email--invalid');
      showFieldError(input, `"${v}" is not a valid email address.`);
      input.focus();
      return false;
    }

    const key = v.toLowerCase();

    if (ownerEmail && key === ownerEmail) {
      input.classList.add('recipient-row__email--invalid');
      showFieldError(input, 'You already receive these notifications as the schedule owner — no need to add your own address.');
      input.focus();
      return false;
    }

    if (seen[key]) {
      input.classList.add('recipient-row__email--invalid');
      showFieldError(input, `"${v}" is already in the list. Each address must be unique.`);
      input.focus();
      return false;
    }
    seen[key] = true;
  }
  return true;
}

function fillSpecificDatesFromRanges(ranges) {
  const list = $('#specific-dates-list');
  if (list) list.innerHTML = '';
  const items = [];
  (ranges || []).forEach(r => {
    if (r?.date && r?.start && r?.end) items.push({ date: r.date, start: r.start, end: r.end });
  });
  items.sort((a, b) => a.date < b.date ? -1 : a.date > b.date ? 1 : a.start < b.start ? -1 : a.start > b.start ? 1 : 0);
  if (items.length === 0) {
    appendSpecificDateRow(ymdFromLocalDate(new Date()), '09:00', '17:00');
    updateSpecificDateRemoveState();
    return;
  }
  items.forEach(it => appendSpecificDateRow(it.date, it.start, it.end));
  updateSpecificDateRemoveState();
}

function validateSpecificDates(vars) {
  const list = document.getElementById('specific-dates-list');

  let invalidRange = null;
  $$('#specific-dates-list .specific-date-row').forEach(row => {
    if (invalidRange) return;
    const st = row.querySelector('.slot-start')?.value;
    const en = row.querySelector('.slot-end')?.value;
    if (st && en && st === en) invalidRange = row;
  });
  if (invalidRange) {
    invalidRange.classList.add('create-schedule-form__field--invalid');
    showFieldError(list, 'Start and end time must be different.');
    invalidRange.querySelector('.slot-end .time-picker-gc__trigger')?.focus();
    return false;
  }

  const overlapping = findSpecificDateOverlap();
  if (overlapping) {
    overlapping.classList.add('create-schedule-form__field--invalid');
    showFieldError(list, 'Time ranges on the same date must not overlap.');
    overlapping.querySelector('.slot-start .time-picker-gc__trigger')?.focus();
    return false;
  }

  if (buildNonRepeatingSlots().length === 0) {
    showFieldError(list, vars.i18n_no_slots || 'Add at least one date with a valid time range.');
    return false;
  }

  return true;
}

function validateDailySlots(vars) {
  let invalidDaily = null;
  let invalidDailyMsg = '';
  $$('#daily-availability .create-schedule-form__slot').forEach(slotEl => {
    if (invalidDaily) return;
    const st = slotEl.querySelector('.slot-start')?.value;
    const en = slotEl.querySelector('.slot-end')?.value;
    if (st && en && st === en) { invalidDaily = slotEl; invalidDailyMsg = 'Start and end time must be different.'; }
    else if (st && en && en < st) { invalidDaily = slotEl; invalidDailyMsg = 'End time must be after start time.'; }
  });
  if (invalidDaily) {
    showFieldError(invalidDaily, invalidDailyMsg);
    invalidDaily.querySelector('.slot-end .time-picker-gc__trigger')?.focus();
    return false;
  }

  const overlappingSlots = findAllDailySlotOverlaps();
  if (overlappingSlots.length) {
    overlappingSlots.forEach(slotEl => {
      showFieldError(slotEl, 'Times overlap with another set of times.');
    });
    overlappingSlots[0].querySelector('.slot-start .time-picker-gc__trigger')?.focus();
    return false;
  }

  if (Object.keys(buildDailySlots()).length === 0) {
    showFieldError(document.getElementById('daily-availability'), vars.i18n_no_slots || 'Add at least one time slot for a day.');
    return false;
  }

  return true;
}

function findAllDailySlotOverlaps() {
  const out = [];
  const days = $$('#daily-availability .create-schedule-form__day');
  for (const dayEl of days) {
    if (dayEl.querySelector('.day-not-available')?.checked) continue;
    const entries = [];
    dayEl.querySelectorAll('.create-schedule-form__slot').forEach(slotEl => {
      const st = slotEl.querySelector('.slot-start')?.value;
      const en = slotEl.querySelector('.slot-end')?.value;
      if (st && en && st !== en) entries.push({ slotEl, start: st, end: en });
    });
    const overlapping = new Set();
    for (let i = 0; i < entries.length; i++) {
      for (let j = i + 1; j < entries.length; j++) {
        if (slotsOverlap(entries[i], entries[j])) {
          overlapping.add(entries[i].slotEl);
          overlapping.add(entries[j].slotEl);
        }
      }
    }
    overlapping.forEach(el => out.push(el));
  }
  return out;
}

function findSpecificDateOverlap() {
  const byDate = new Map();
  const rows = $$('#specific-dates-list .specific-date-row');
  for (const row of rows) {
    const date = row.querySelector('.specific-date-row__date')?.value;
    const st = row.querySelector('.slot-start')?.value;
    const en = row.querySelector('.slot-end')?.value;
    if (!date || !st || !en || st === en) continue;
    if (!byDate.has(date)) byDate.set(date, []);
    byDate.get(date).push({ row, start: st, end: en });
  }
  for (const [, entries] of byDate) {
    for (let i = 0; i < entries.length; i++) {
      for (let j = i + 1; j < entries.length; j++) {
        if (slotsOverlap(entries[i], entries[j])) return entries[j].row;
      }
    }
  }
  return null;
}

function buildNonRepeatingSlots() {
  const out = [];
  $$('#specific-dates-list .specific-date-row').forEach(row => {
    const date = String(row.querySelector('.specific-date-row__date')?.value || '');
    const st = row.querySelector('.slot-start')?.value;
    const en = row.querySelector('.slot-end')?.value;
    if (date && st && en && st !== en) out.push({ date, start: st, end: en });
  });
  return out;
}

function buildDailySlots() {
  const daily_slots = {};
  $$('#daily-availability .create-schedule-form__day').forEach(dayEl => {
    const dow = dayEl.dataset.dow;
    if (dayEl.querySelector('.day-not-available')?.checked) {
      daily_slots[dow] = [];
      return;
    }
    const slots = [];
    dayEl.querySelectorAll('.create-schedule-form__slot').forEach(slotEl => {
      const start = slotEl.querySelector('.slot-start')?.value;
      const end = slotEl.querySelector('.slot-end')?.value;
      if (start && end && start !== end) slots.push({ start, end });
    });
    if (slots.length) daily_slots[dow] = slots;
  });
  return daily_slots;
}

function hasAnyDailySlots(dailySlots) {
  return Object.values(dailySlots || {}).some(daySlots => Array.isArray(daySlots) && daySlots.length > 0);
}

function rangeMinutes(start, end) {
  const [sh, sm] = String(start).split(':').map(Number);
  const [eh, em] = String(end).split(':').map(Number);
  const result = (eh * 60 + em) - (sh * 60 + sm);
  return result <= 0 ? result + 24 * 60 : result;
}

function slotMinutes(start, end) {
  const [sh, sm] = String(start).split(':').map(Number);
  const [eh, em] = String(end).split(':').map(Number);
  const s = sh * 60 + sm;
  let e = eh * 60 + em;
  if (e <= s) e += 24 * 60;
  return { s, e };
}

function slotsOverlap(a, b) {
  const aCrosses = a.end <= a.start;
  const bCrosses = b.end <= b.start;
  if (aCrosses && bCrosses) return true; // both pass through midnight → always overlap
  const ra = slotMinutes(a.start, a.end);
  const rb = slotMinutes(b.start, b.end);
  if (!aCrosses && !bCrosses) return ra.s < rb.e && rb.s < ra.e;
  // Exactly one crosses midnight: it covers [s,1440)∪[0,e-1440).
  const [rC, rN] = aCrosses ? [ra, rb] : [rb, ra];
  const eTail = rC.e - 24 * 60; // cross-midnight slot's tail end (in [0,1440))
  return rN.s < eTail || rC.s < rN.e;
}

function nextDateYmd(ymd) {
  const [y, m, d] = ymd.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + 1));
  return dt.getUTCFullYear() + '-' +
    String(dt.getUTCMonth() + 1).padStart(2, '0') + '-' +
    String(dt.getUTCDate()).padStart(2, '0');
}

function maxAvailabilityRange(availabilityMode, dailySlots) {
  // Build sorted list of {key, start, end} entries.
  // key = date string (none mode) or DOW integer (weekly/custom mode).
  // Adjacent slots where prev ends at '24:00' and next starts at '00:00'
  // are merged — same as PHP mergeOverlappingIntervals does server-side.
  const items = [];
  if (availabilityMode === 'none') {
    buildNonRepeatingSlots().forEach(r => items.push({ key: r.date, start: r.start, end: r.end }));
    items.sort((a, b) => a.key < b.key ? -1 : a.key > b.key ? 1 : a.start.localeCompare(b.start));
  } else {
    Object.entries(dailySlots || {}).forEach(([dow, arr]) => {
      (arr || []).forEach(r => items.push({ key: parseInt(dow, 10), start: r.start, end: r.end }));
    });
    items.sort((a, b) => a.key - b.key || a.start.localeCompare(b.start));
  }

  if (!items.length) return 0;

  let max = 0;
  let running = 0;
  let prevEnd = null;
  let prevKey = null;

  for (const it of items) {
    const dur = rangeMinutes(it.start, it.end);
    let merges = false;
    if (prevEnd === '24:00' && it.start === '00:00' && prevKey !== null) {
      if (availabilityMode === 'none') {
        merges = nextDateYmd(String(prevKey)) === String(it.key);
      } else {
        merges = (Number(prevKey) % 7) + 1 === Number(it.key);
      }
    }
    running = merges ? running + dur : dur;
    max = Math.max(max, running);
    prevEnd = it.end;
    prevKey = it.key;
  }

  return max;
}

function defaultDailySlotsForCustom() {
  return {
    1: [{ start: '09:00', end: '18:00' }],
    2: [{ start: '09:00', end: '18:00' }],
    3: [{ start: '09:00', end: '18:00' }],
    4: [{ start: '09:00', end: '18:00' }],
    5: [{ start: '09:00', end: '18:00' }],
    6: [],
    7: [],
  };
}

function parseYmdToUtcDate(ymd) {
  if (!ymd) return null;
  const parts = String(ymd).split('-');
  if (parts.length !== 3) return null;
  const y = parseInt(parts[0], 10);
  const m = parseInt(parts[1], 10);
  const d = parseInt(parts[2], 10);
  if (!y || !m || !d) return null;
  return new Date(Date.UTC(y, m - 1, d));
}

function getIsoDowFromUtcDate(dt) {
  const d = dt.getUTCDay();
  return d === 0 ? 7 : d;
}

function getCoveredDows(fromUtc, toUtc) {
  const covered = new Set();
  let cur = new Date(fromUtc.getTime());
  while (cur.getTime() <= toUtc.getTime()) {
    covered.add(getIsoDowFromUtcDate(cur));
    cur = new Date(cur.getTime() + 86400000);
  }
  return covered;
}

function validateToDateCoversSelectedDows(daily_slots) {
  const f = form();
  const toInput = f?.querySelector('[name="date_to"]');
  if (!toInput) return true;
  toInput.setCustomValidity('');

  const fromUtc = parseYmdToUtcDate(f.querySelector('[name="date_from"]')?.value);
  const toUtc = parseYmdToUtcDate(toInput.value);
  if (!fromUtc || !toUtc) return true;

  const covered = getCoveredDows(fromUtc, toUtc);
  const dayNames = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun' };
  const missing = [];

  Object.keys(daily_slots || {}).forEach(dowStr => {
    const slots = daily_slots[dowStr];
    if (!Array.isArray(slots) || slots.length === 0) return;
    const dow = parseInt(dowStr, 10);
    if (!dow || dow < 1 || dow > 7) return;
    if (!covered.has(dow)) missing.push(dow);
  });

  if (missing.length === 0) return true;

  const missingStr = missing.map(d => dayNames[d] || String(d)).join(', ');
  showFieldError(document.querySelector('[name="date_from"]'), 'Date range must include selected days: ' + missingStr);
  return false;
}

function mondayOfWeekUtc(d) {
  const dow = getIsoDowFromUtcDate(d); // 1=Mon..7=Sun
  return new Date(d.getTime() - (dow - 1) * 86400000);
}

// Front-end mirror of AvailabilityService::buildScheduleRanges emptiness check:
// is there at least one active-week weekday with a slot in [dateFrom, dateTo]?
// Active weeks are counted by calendar week (Monday anchor) every `intervalWeeks`.
function scheduleHasAnySlot(dateFromYmd, dateToYmd, intervalWeeks, dailySlots) {
  if (!dateFromYmd || !dateToYmd) return true;
  const interval = Math.max(1, Math.min(52, intervalWeeks || 1));
  const from = parseYmdToUtcDate(dateFromYmd);
  const to = parseYmdToUtcDate(dateToYmd);
  if (!from || !to || from.getTime() > to.getTime()) return false;
  const anchorTs = mondayOfWeekUtc(from).getTime();
  for (let t = from.getTime(); t <= to.getTime(); t += 86400000) {
    const weekIndex = Math.floor(Math.floor((t - anchorTs) / 86400000) / 7);
    if (interval !== 1 && weekIndex % interval !== 0) continue;
    const slots = dailySlots[String(getIsoDowFromUtcDate(new Date(t)))];
    if (Array.isArray(slots) && slots.length > 0) return true;
  }
  return false;
}

function isWeeklyRuleEndless(rule) {
  return rule && typeof rule === 'object' &&
    (rule.date_to === null || rule.date_to === undefined || rule.date_to === '');
}

function parseRepeatFromSchedule(schedule) {
  let interval = 1;
  if (schedule?.repeat_interval_weeks != null && schedule.repeat_interval_weeks !== '') {
    interval = parseInt(String(schedule.repeat_interval_weeks), 10) || 1;
  }
  interval = Math.max(1, Math.min(52, interval));

  const weeklyRule = schedule?.schedule_weekly_rule;
  const endNever = isWeeklyRuleEndless(weeklyRule);
  const repeatType = String(schedule?.schedule_repeat || 'weekly');

  if (repeatType === 'does_not_repeat') {
    return { mode: 'none', repeat: 'does_not_repeat', interval: 1, endNever: false };
  }
  if (repeatType === 'custom') {
    return { mode: 'custom', repeat: 'custom', interval, endNever };
  }
  return { mode: 'weekly', repeat: 'weekly', interval: 1, endNever };
}

function fillAvailabilityFromSchedule(schedule) {
  if (!$('#schedule-availability-select')) return;
  const p = parseRepeatFromSchedule(schedule);
  setAvailabilityMode(p.mode, p.repeat, p.interval, p.endNever);
}

function parseScheduleRanges(rangesRaw) {
  try {
    if (typeof rangesRaw === 'string') return JSON.parse(rangesRaw);
    if (Array.isArray(rangesRaw)) return rangesRaw;
  } catch { /* ignore */ }
  return [];
}

function getDowFromDateStr(dateStr) {
  const dt = new Date(dateStr + 'T00:00:00');
  const d = dt.getDay();
  return d === 0 ? 7 : d;
}

function setDaySlots(dayEl, slots) {
  dayEl.querySelectorAll('.create-schedule-form__slot').forEach(el => el.remove());
  if (!slots || slots.length === 0) {
    const cb = dayEl.querySelector('.day-not-available');
    if (cb) { cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true })); }
    return;
  }
  const cb = dayEl.querySelector('.day-not-available');
  if (cb) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); }

  const templateEl = document.getElementById('slot-row-template');
  if (!templateEl?.content) return;

  slots.forEach(s => {
    const slotEl = templateEl.content.firstElementChild?.cloneNode(true);
    if (!slotEl) return;
    const startInput = slotEl.querySelector('.slot-start');
    const endInput = slotEl.querySelector('.slot-end');
    if (startInput) startInput.value = s.start;
    if (endInput) endInput.value = s.end;
    slotEl.querySelectorAll('.time-picker-gc').forEach((picker, i) => {
      const trigger = picker.querySelector('.time-picker-gc__trigger');
      if (trigger) trigger.textContent = i === 0 ? formatTime12(s.start) : formatTime12(s.end);
    });
    dayEl.querySelector('.create-schedule-form__slots')?.appendChild(slotEl);
    slotEl.querySelectorAll('.time-picker-gc').forEach(p => initTimePickerGc(p));
  });
}

function syncNameFormatCustomVisibility() {
  const f = form();
  const v = String(f?.querySelector('#name_format')?.value || 'full');
  const wrap = $('#name_format_custom_wrap');
  if (!wrap) return;
  wrap.style.display = v === 'custom' ? '' : 'none';
}

function getSelectedCommunicationTitle() {
  const sel = $('#schedule-calendar');
  if (!sel) return '';
  const opt = sel.options[sel.selectedIndex];
  return String(opt?.dataset?.title || opt?.textContent || '').trim();
}

function isGoogleCalendarSelected() {
  return getSelectedCommunicationTitle() === 'Google Calendar';
}

function renderGoogleCalendarState(connected) {
  const wrap = $('#google-calendar-connect');
  const status = $('#google-calendar-connect-status');
  const connectBtn = $('#google-calendar-connect-btn');
  const picker = $('#google-calendar-picker');
  if (!wrap) return;

  if (!isGoogleCalendarSelected()) {
    wrap.style.display = 'none';
    if (picker) picker.style.display = 'none';
    return;
  }

  wrap.style.display = '';
  state.set('googleCalendarConnected', !!connected);

  if (connected) {
    wrap.classList.add('is-connected');
    if (status) { status.style.display = 'none'; status.textContent = ''; }
    if (connectBtn) connectBtn.style.display = 'none';
    loadGoogleCalendars();
  } else {
    wrap.classList.remove('is-connected');
    if (status) { status.style.display = ''; status.textContent = 'Your Google Calendar is not connected'; }
    if (connectBtn) connectBtn.style.display = '';
    if (picker) picker.style.display = 'none';
  }
}

// Populate the calendar picker — shown only when the account has >1 writable
// calendar, so the owner can choose which one events are saved to.
function loadGoogleCalendars() {
  const picker = $('#google-calendar-picker');
  const select = $('#google-calendar-select');
  if (!picker || !select) return;
  api.googleCalendarList()
    .then(resp => {
      const cals = (resp?.success && Array.isArray(resp.data?.calendars)) ? resp.data.calendars : [];
      if (cals.length <= 1) { picker.style.display = 'none'; return; }
      const selected = String(resp.data?.selected || 'primary');
      select.innerHTML = '';
      cals.forEach(c => {
        const opt = document.createElement('option');
        opt.value = String(c.id);
        opt.textContent = String(c.summary || c.id);
        if (String(c.id) === selected || (selected === 'primary' && c.primary)) opt.selected = true;
        select.appendChild(opt);
      });
      picker.style.display = '';
    })
    .catch(() => { picker.style.display = 'none'; });
}

function loadGoogleCalendarStatus() {
  const vars = window.schedule_calendar_vars || {};
  if (!vars.ajax_url || !vars.google_calendar_nonce) {
    renderGoogleCalendarState(false);
    return;
  }
  api.googleCalendarStatus()
    .then(resp => {
      renderGoogleCalendarState(!!(resp?.success && resp?.data?.connected));
    })
    .catch(() => renderGoogleCalendarState(false));
}

function renderScopeWarning(missing) {
  const banner = $('#schedule-scope-warning');
  const text = $('.create-schedule-form__scope-warning-text');
  const list = Array.isArray(missing) ? missing : [];
  state.set('missingGoogleScopes', list);
  if (!banner) return;
  if (list.length === 0) {
    banner.hidden = true;
    return;
  }
  if (text) {
    text.textContent =
      'Not all access is granted. Reconnect and allow all requested permissions.';
  }
  banner.hidden = false;
}

function checkOwnerScopes() {
  const vars = window.schedule_calendar_vars || {};
  if (!vars.ajax_url || !vars.google_calendar_nonce) {
    renderScopeWarning([]);
    return;
  }
  api.googleCalendarStatus()
    .then(resp => {
      const data = (resp?.success && resp?.data) ? resp.data : {};
      renderScopeWarning(data.connected && data.scopes_ok === false ? data.missing_scopes : []);
    })
    .catch(() => renderScopeWarning([]));
}


function detectUserTimezone() {
  let tz = '';
  try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch { /* ignore */ }
  if (!tz && typeof jstz !== 'undefined' && jstz.determine) {
    try { tz = jstz.determine()?.name?.() || ''; } catch { /* ignore */ }
  }
  return canonicalTimezone(tz || 'UTC');
}

let tzPickerItems = [];

function initSchedulePicker() {
  const sel = $('#schedule-timezone');
  const input = $('#schedule-timezone-input');
  const dropdown = $('#schedule-timezone-dropdown');
  if (!sel || !input || !dropdown) return;

  function labelOf(name) {
    const it = tzPickerItems.find(i => i.name === name);
    return it ? it.label : name;
  }

  function commitSelection(name) {
    if (!name) return;
    if (!Array.from(sel.options).some(o => o.value === name)) {
      const opt = document.createElement('option');
      opt.value = name;
      opt.textContent = labelOf(name);
      sel.appendChild(opt);
    }
    sel.value = name;
    input.value = labelOf(name);
    input.setAttribute('aria-expanded', 'false');
    dropdown.hidden = true;
    dropdown.innerHTML = '';
    input.blur();
  }

  function renderDropdown(query) {
    const q = String(query || '').trim().toLowerCase();
    const currentName = String(sel.value || '').trim();
    let list = tzPickerItems;
    if (q) {
      const currentItem = tzPickerItems.find(i => i.name === currentName);
      const skipFilter = currentItem && q === currentItem.label.toLowerCase();
      if (!skipFilter) {
        list = tzPickerItems.filter(i => i.label.toLowerCase().includes(q) || i.name.toLowerCase().includes(q));
      }
    }
    dropdown.innerHTML = '';
    if (list.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'tz-picker__empty';
      empty.textContent = 'No timezones found';
      dropdown.appendChild(empty);
    } else {
      list.slice(0, 200).forEach(it => {
        const opt = document.createElement('div');
        opt.className = 'tz-picker__option';
        opt.setAttribute('role', 'option');
        opt.setAttribute('data-tz', it.name);
        if (it.name === currentName) opt.classList.add('is-selected');
        opt.textContent = it.label;
        opt.addEventListener('mousedown', e => { e.preventDefault(); commitSelection(it.name); });
        dropdown.appendChild(opt);
      });
    }
    dropdown.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  input.addEventListener('focus', () => {
    input.value = '';
    renderDropdown('');
  });
  input.addEventListener('input', () => renderDropdown(input.value));
  input.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      input.value = labelOf(sel.value);
      input.setAttribute('aria-expanded', 'false');
      dropdown.hidden = true;
      input.blur();
    }
  });
  document.addEventListener('mousedown', e => {
    if (!dropdown.contains(e.target) && e.target !== input) {
      dropdown.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      if (sel.value) input.value = labelOf(sel.value);
    }
  });
}

function populateTimezoneSelect() {
  const sel = $('#schedule-timezone');
  const input = $('#schedule-timezone-input');
  if (!sel || sel.options.length > 1) return;

  const userZone = detectUserTimezone();

  if (typeof Intl === 'undefined' || typeof Intl.supportedValuesOf !== 'function') {
    sel.innerHTML = `<option value="${userZone}" selected>${userZone}</option>`;
    return;
  }

  try {
    const zones = Intl.supportedValuesOf('timeZone').filter(tz => !EXCLUDED_TIMEZONES.has(tz));
    const userLocale = navigator.language;
    const now = new Date();

    const items = zones.map(tz => {
      const parts = new Intl.DateTimeFormat(userLocale, { timeZone: tz, timeZoneName: 'longOffset' }).formatToParts(now);
      const tzPart = (parts.find(p => p.type === 'timeZoneName') || {}).value || 'GMT+00:00';
      const m = String(tzPart).match(/GMT([+-]\d{2}:\d{2})/);
      const offset = m ? m[1] : '+00:00';
      const sign = offset.startsWith('-') ? -1 : 1;
      const [h, min] = offset.replace(/^[+-]/, '').split(':').map(Number);
      const minutes = sign * (h * 60 + min);
      const short = offset.replace(/^([+-])0?(\d+):(\d{2})$/, '$1$2:$3');
      // Use the canonical name so option values match what the server stores.
      const name = canonicalTimezone(tz);
      return { name, offset, minutes, label: `${name} (${short})` };
    });

    items.sort((a, b) => a.minutes !== b.minutes ? a.minutes - b.minutes : a.name.localeCompare(b.name));

    const fragment = document.createDocumentFragment();
    const seen = new Set();
    const deduped = [];
    items.forEach(item => {
      if (seen.has(item.name)) return;
      seen.add(item.name);
      deduped.push(item);
      const opt = document.createElement('option');
      opt.value = item.name;
      opt.textContent = item.label;
      if (item.name === userZone) opt.defaultSelected = true;
      fragment.appendChild(opt);
    });
    sel.appendChild(fragment);
    sel.value = userZone;
    tzPickerItems = deduped;
    if (input) input.value = (deduped.find(i => i.name === userZone) || { label: userZone }).label;
  } catch (err) {
    sel.innerHTML = `<option value="${userZone}" selected>${userZone}</option>`;
    if (input) input.value = userZone;
  }
}

function closeCustomAvailabilityModal(opts = {}) {
  if (!opts.skipRevert && customAvailabilityPending) {
    revertAvailabilitySelect();
    applySelectValueToAvailabilityState();
  }
  customAvailabilityPending = false;
  const cm = customModal();
  if (cm) { cm.setAttribute('aria-hidden', 'true'); cm.classList.remove('is-open'); }
}

function openCustomAvailabilityModal() {
  const cm = customModal();
  if (!cm) return;
  customAvailabilityPending = true;
  const f = form();

  // "First open" = entering custom mode for the first time (not re-editing an existing custom config)
  const isFirstOpen = committedAvailabilitySelect !== 'custom';

  const intervalHidden = $('#schedule-repeat-interval-hidden');
  const interval = isFirstOpen ? 2 : (parseInt(intervalHidden?.value, 10) || 2);
  const intervalInput = $('#custom-availability-interval');
  if (intervalInput) intervalInput.value = String(Math.max(1, Math.min(52, interval)));
  intervalInput?.closest('.custom-availability-form__row--inline')?.classList.remove('is-muted');

  const startsInput = $('#custom-availability-starts');
  const existingDateFrom = f?.querySelector('[name="date_from"]')?.value || '';
  if (startsInput) startsInput.value = existingDateFrom || ymdFromLocalDate(new Date());

  const endNeverHidden = $('#schedule-repeat-end-never-hidden');
  const never = isFirstOpen ? true : endNeverHidden?.value === '1';
  const neverRadio = $('#custom-availability-ends-never');
  const onRadio = $('#custom-availability-ends-on');
  const endsDate = $('#custom-availability-ends-date');

  // Like Google, the "On" date is never blank: it defaults to Starts + 90 days
  // and stays visible (disabled) even while "Never" is selected.
  const startVal = startsInput?.value || ymdFromLocalDate(new Date());
  const defaultEndDate = addDaysToYmd(startVal, 90);
  const committedEndDate = f?.querySelector('[name="date_to"]')?.value || '';

  if (never) {
    if (neverRadio) neverRadio.checked = true;
    if (endsDate) { endsDate.value = defaultEndDate; endsDate.disabled = true; }
  } else {
    if (onRadio) onRadio.checked = true;
    if (endsDate) { endsDate.value = committedEndDate || defaultEndDate; endsDate.disabled = false; }
  }

  syncDateDisplay(startsInput);
  if (endsDate) syncDateDisplay(endsDate);
  updateEndDateValidity();

  cm.setAttribute('aria-hidden', 'false');
  cm.classList.add('is-open');
}

function fillEditModal(schedule) {
  const f = form();
  if (!f) return;
  const id = schedule?.id ? String(schedule.id) : '';
  state.set('editingScheduleId', id);

  f.querySelector('[name="name"]').value = schedule?.subject || '';
  f.querySelector('[name="description"]').value = schedule?.description || '';

  const verifyCb = f.querySelector('[name="require_email_verification"]');
  if (verifyCb) verifyCb.checked = !!schedule?.require_email_verification;

  const commSel = f.querySelector('[name="calendar_means_of_communication_id"]');
  if (commSel) commSel.value = schedule?.calendar_means_of_communication_id || '';

  if (isGoogleCalendarSelected()) loadGoogleCalendarStatus();
  else renderGoogleCalendarState(false);

  if (schedule?.color != null) {
    const colorSel = f.querySelector('[name="color"]');
    if (colorSel) colorSel.value = String(schedule.color);
  }

  if (typeof schedule?.reminder_minutes === 'number') {
    const min = schedule.reminder_minutes;
    const unitSel = f.querySelector('[name="booking_lead_unit"]');
    const valInput = f.querySelector('[name="booking_lead_value"]');
    if (min % 60 === 0) {
      if (unitSel) unitSel.value = 'hours';
      if (valInput) valInput.value = String(min / 60);
    } else {
      if (unitSel) unitSel.value = 'minutes';
      if (valInput) valInput.value = String(min);
    }
  }

  const nfSel = f.querySelector('[name="name_format"]');
  if (nfSel) nfSel.value = schedule?.name_format || 'full';
  const nfcInput = f.querySelector('[name="name_format_custom"]');
  if (nfcInput) nfcInput.value = schedule?.name_format_custom || '';
  syncNameFormatCustomVisibility();

  fillRecipientsList(schedule?.additional_recipients);

  const weeklyRule = schedule?.schedule_weekly_rule;
  const hasWeeklyRule = weeklyRule && typeof weeklyRule === 'object' &&
    typeof weeklyRule.date_from === 'string' && weeklyRule.date_from;
  const repeatInfo = parseRepeatFromSchedule(schedule);

  let dateFrom = '';
  let dateTo = '';
  let tz = '';
  const dailyMap = { 1: [], 2: [], 3: [], 4: [], 5: [], 6: [], 7: [] };
  let ranges = [];

  if (hasWeeklyRule && repeatInfo.mode !== 'none') {
    tz = weeklyRule.timezone ? String(weeklyRule.timezone) : '';
    dateFrom = weeklyRule.date_from;
    dateTo = isWeeklyRuleEndless(weeklyRule) ? addYearsToYmd(weeklyRule.date_from, 10) : String(weeklyRule.date_to);
    const slots = (weeklyRule.daily_slots && typeof weeklyRule.daily_slots === 'object') ? weeklyRule.daily_slots : {};
    Object.keys(dailyMap).forEach(k => {
      dailyMap[k] = Array.isArray(slots[k]) ? slots[k].slice() : [];
    });
  } else {
    ranges = parseScheduleRanges(schedule?.schedule_ranges ?? []);
    ranges.forEach(r => {
      if (!r?.date || !r?.start || !r?.end) return;
      if (!dateFrom || r.date < dateFrom) dateFrom = r.date;
      if (!dateTo || r.date > dateTo) dateTo = r.date;
      if (!tz && r.timezone) tz = r.timezone;
      if (repeatInfo.mode !== 'none') {
        const dow = getDowFromDateStr(r.date);
        dailyMap[dow].push({ start: r.start, end: r.end });
      }
    });
  }

  if (repeatInfo.mode !== 'none') {
    Object.keys(dailyMap).forEach(dowKey => {
      const seen = {};
      const uniq = [];
      (dailyMap[dowKey] || []).forEach(s => {
        const k = s.start + '-' + s.end;
        if (seen[k]) return;
        seen[k] = true;
        uniq.push(s);
      });
      uniq.sort((a, b) => a.start < b.start ? -1 : a.start > b.start ? 1 : a.end < b.end ? -1 : a.end > b.end ? 1 : 0);
      dailyMap[dowKey] = uniq;
    });
  }

  if (tz) {
    const tzSel = f.querySelector('[name="timezone"]');
    if (tzSel) {
      const canonical = canonicalTimezone(tz);
      tzSel.value = canonical;
      // If the stored timezone isn't in the option list (an alias the browser
      // doesn't expose), add it so the schedule's timezone is still shown.
      if (tzSel.value !== canonical) {
        const opt = document.createElement('option');
        opt.value = canonical;
        opt.textContent = canonical;
        tzSel.appendChild(opt);
        tzSel.value = canonical;
      }
      const tzInput = $('#schedule-timezone-input');
      if (tzInput) {
        const found = tzPickerItems.find(i => i.name === canonical);
        tzInput.value = found ? found.label : canonical;
      }
    }
  }
  if (dateFrom) { const dfEl = f.querySelector('[name="date_from"]'); if (dfEl) dfEl.value = dateFrom; }
  if (dateTo) { const dtEl = f.querySelector('[name="date_to"]'); if (dtEl) dtEl.value = dateTo; }

  fillAvailabilityFromSchedule(schedule);

  if (repeatInfo.mode === 'none') {
    fillSpecificDatesFromRanges(ranges);
  } else {
    resetSpecificDatesList();
    $$('#daily-availability .create-schedule-form__day').forEach(dayEl => {
      const dow = String(dayEl.dataset.dow);
      setDaySlots(dayEl, dailyMap[dow] || []);
    });
  }

  setDurations(schedule?.durations ?? DEFAULT_DURATIONS);

  syncSelectPickers();
}

function clearFormErrors() {
  document.querySelectorAll('.create-schedule-form__error').forEach(el => el.remove());
  document.querySelectorAll('.create-schedule-form__field--invalid').forEach(el => el.classList.remove('create-schedule-form__field--invalid'));
  document.querySelectorAll('.recipient-row__email--invalid').forEach(el => el.classList.remove('recipient-row__email--invalid'));
}

function revalidateDayInline(dayEl) {
  dayEl.querySelectorAll('.create-schedule-form__slot').forEach(s => {
    s.querySelectorAll(':scope > .create-schedule-form__error').forEach(el => el.remove());
    s.classList.remove('create-schedule-form__field--invalid');
  });

  let hasRangeError = false;
  dayEl.querySelectorAll('.create-schedule-form__slot').forEach(slotEl => {
    const st = slotEl.querySelector('.slot-start')?.value;
    const en = slotEl.querySelector('.slot-end')?.value;
    if (!st || !en) return;
    let msg = '';
    if (st === en) {
      msg = 'Start and end time must be different.';
    } else if (en < st) {
      msg = 'End time must be after start time.';
    }
    if (msg) {
      hasRangeError = true;
      slotEl.classList.add('create-schedule-form__field--invalid');
      const err = document.createElement('p');
      err.className = 'create-schedule-form__error';
      err.textContent = msg;
      slotEl.appendChild(err);
    }
  });

  if (hasRangeError) return;

  const entries = [];
  dayEl.querySelectorAll('.create-schedule-form__slot').forEach(slotEl => {
    const s = slotEl.querySelector('.slot-start')?.value;
    const e = slotEl.querySelector('.slot-end')?.value;
    if (s && e && s !== e) entries.push({ slotEl, start: s, end: e });
  });
  const overlapping = new Set();
  for (let i = 0; i < entries.length; i++) {
    for (let j = i + 1; j < entries.length; j++) {
      if (slotsOverlap(entries[i], entries[j])) {
        overlapping.add(entries[i].slotEl);
        overlapping.add(entries[j].slotEl);
      }
    }
  }
  overlapping.forEach(slotEl => {
    slotEl.classList.add('create-schedule-form__field--invalid');
    const err = document.createElement('p');
    err.className = 'create-schedule-form__error';
    err.textContent = 'Times overlap with another set of times.';
    slotEl.appendChild(err);
  });
}

function validateSlotRangeInline(slot) {
  if (!slot) return;
  const dayEl = slot.closest('#daily-availability .create-schedule-form__day');
  if (dayEl) {
    revalidateDayInline(dayEl);
    return;
  }
  // Specific-date rows: zero-duration check only
  slot.querySelectorAll(':scope > .create-schedule-form__error').forEach(el => el.remove());
  slot.classList.remove('create-schedule-form__field--invalid');
  const st = slot.querySelector('.slot-start')?.value;
  const en = slot.querySelector('.slot-end')?.value;
  let rangeMsg = '';
  if (st && en) {
    if (st === en) rangeMsg = 'Start and end time must be different.';
    else if (en < st) rangeMsg = 'End time must be after start time.';
  }
  if (rangeMsg) {
    slot.classList.add('create-schedule-form__field--invalid');
    const err = document.createElement('p');
    err.className = 'create-schedule-form__error';
    err.textContent = rangeMsg;
    slot.appendChild(err);
  }
}

function showFieldError(anchorEl, message) {
  if (!anchorEl) return;
  const wrap = anchorEl.closest('.recipient-row, .create-schedule-form__field, .specific-date-row, .create-schedule-form__slot, .create-schedule-form__day') || anchorEl;
  wrap.classList.add('create-schedule-form__field--invalid');
  const err = document.createElement('p');
  err.className = 'create-schedule-form__error';
  err.textContent = message;
  wrap.appendChild(err);
  if (!document.querySelector('.create-schedule-form__error[data-scrolled="1"]')) {
    err.setAttribute('data-scrolled', '1');
    wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function handleFormSubmit(e) {
  e.preventDefault();
  const f = form();
  if (!f) return;
  const editingId = state.get('editingScheduleId');

  clearFormErrors();

  if (isGoogleCalendarSelected() && !state.get('googleCalendarConnected')) {
    alert('Your Google Calendar is not connected');
    return;
  }

  const missingScopes = state.get('missingGoogleScopes');
  if (Array.isArray(missingScopes) && missingScopes.length > 0) {
    renderScopeWarning(missingScopes);
    $('#schedule-scope-warning')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  const availabilityMode = getAvailabilityMode();
  let daily_slots = {};
  let nonRepeatingPayload = '';
  const vars = window.schedule_calendar_vars || {};

  if (availabilityMode === 'none') {
    if (!validateSpecificDates(vars)) return;
    const nonRepeating = buildNonRepeatingSlots();
    const sortedDates = nonRepeating.map(r => r.date).sort();
    const dfEl = f.querySelector('[name="date_from"]');
    const dtEl = f.querySelector('[name="date_to"]');
    if (dfEl) dfEl.value = sortedDates[0];
    if (dtEl) dtEl.value = sortedDates[sortedDates.length - 1];
    nonRepeatingPayload = JSON.stringify(nonRepeating);
  } else if (availabilityMode === 'weekly') {
    if (!validateDailySlots(vars)) return;
    daily_slots = buildDailySlots();
  } else {
    if (!validateDailySlots(vars)) return;
    daily_slots = buildDailySlots();
  }

  if (availabilityMode === 'weekly' || availabilityMode === 'custom') {
    const intervalWeeks = availabilityMode === 'custom'
      ? (parseInt(f.querySelector('[name="repeat_interval_weeks"]')?.value, 10) || 1)
      : 1;
    if (!scheduleHasAnySlot(
      f.querySelector('[name="date_from"]')?.value,
      f.querySelector('[name="date_to"]')?.value,
      intervalWeeks,
      daily_slots
    )) {
      showFieldError(document.querySelector('[name="date_from"]'), 'At least one time slot is required');
      return;
    }
  }

  const dailySlotsInput = $('#daily-slots-input');
  if (dailySlotsInput) dailySlotsInput.value = JSON.stringify(daily_slots);

  if (!Array.isArray(currentDurations) || currentDurations.length === 0) {
    showFieldError(durationsChips(), 'Select at least one meeting duration.');
    durationsChips()?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  const minDuration = Math.min(...currentDurations);
  const maxRange = maxAvailabilityRange(availabilityMode, daily_slots);
  if (maxRange > 0 && maxRange < minDuration) {
    showFieldError(
      durationsChips(),
      `Your slot is ${maxRange} min but the shortest duration is ${minDuration} min. Extend the slot or pick a shorter duration.`
    );
    durationsChips()?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  if (!validateRecipients()) return;

  const data = {
    name: f.querySelector('[name="name"]')?.value,
    description: f.querySelector('[name="description"]')?.value,
    // No toggle in the form: every schedule is public.
    is_public: '1',
    require_email_verification: f.querySelector('[name="require_email_verification"]')?.checked ? '1' : '0',
    calendar_means_of_communication_id: f.querySelector('[name="calendar_means_of_communication_id"]')?.value,
    color: f.querySelector('[name="color"]')?.value,
    booking_lead_value: f.querySelector('[name="booking_lead_value"]')?.value,
    booking_lead_unit: f.querySelector('[name="booking_lead_unit"]')?.value,
    timezone: f.querySelector('[name="timezone"]')?.value,
    repeat: f.querySelector('[name="repeat"]')?.value || 'does_not_repeat',
    repeat_interval_weeks: f.querySelector('[name="repeat_interval_weeks"]')?.value || '1',
    repeat_end_never: f.querySelector('[name="repeat_end_never"]')?.value || '0',
    date_from: f.querySelector('[name="date_from"]')?.value,
    date_to: f.querySelector('[name="date_to"]')?.value,
    daily_slots: dailySlotsInput?.value,
    non_repeating_slots: nonRepeatingPayload,
    name_format: f.querySelector('[name="name_format"]')?.value,
    name_format_custom: f.querySelector('[name="name_format_custom"]')?.value || '',
    durations: JSON.stringify(currentDurations),
    additional_recipients: JSON.stringify(collectRecipients()),
  };

  if (editingId) data.id = editingId;

  const promise = editingId ? api.updateSchedule(data) : api.createSchedule(data);

  promise.then(resp => {
    if (resp?.success) {
      const m = modal();
      if (m) { m.setAttribute('aria-hidden', 'true'); m.classList.remove('is-open'); }
      window.location.reload();
    } else if (resp?.data?.code === 'missing_scopes') {
      renderScopeWarning(resp.data.missing_scopes || []);
      $('#schedule-scope-warning')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
      alert(resp?.data?.message || 'Error saving schedule');
    }
  }).catch(() => alert('Request failed'));
}

const DEFAULT_DURATIONS = [15, 30, 45, 60, 90];
let currentDurations = DEFAULT_DURATIONS.slice();

function durationsChips() { return document.getElementById('schedule-durations-chips'); }
function durationsHidden() { return document.getElementById('schedule-durations-input-hidden'); }

function renderDurationsChips() {
  const wrap = durationsChips();
  const hidden = durationsHidden();
  if (!wrap) return;
  wrap.innerHTML = '';
  DEFAULT_DURATIONS.forEach(n => {
    const selected = currentDurations.includes(n);
    const chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'durations-chip' + (selected ? ' is-selected' : '');
    chip.setAttribute('role', 'checkbox');
    chip.setAttribute('aria-checked', selected ? 'true' : 'false');
    chip.textContent = String(n);
    chip.addEventListener('click', () => {
      if (currentDurations.includes(n)) {
        currentDurations = currentDurations.filter(x => x !== n);
      } else {
        currentDurations.push(n);
        currentDurations.sort((a, b) => a - b);
      }
      renderDurationsChips();
      clearDurationsFieldError();
    });
    wrap.appendChild(chip);
  });
  if (hidden) hidden.value = JSON.stringify(currentDurations);
}

function clearDurationsFieldError() {
  const wrap = durationsChips()?.closest('.create-schedule-form__field');
  if (!wrap) return;
  wrap.classList.remove('create-schedule-form__field--invalid');
  wrap.querySelectorAll('.create-schedule-form__error').forEach(el => el.remove());
}

function setDurations(list) {
  let arr = Array.isArray(list) ? list : [];
  if (typeof list === 'string') {
    try { arr = JSON.parse(list); } catch { arr = []; }
  }
  const clean = [];
  const seen = {};
  (Array.isArray(arr) ? arr : []).forEach(v => {
    const n = parseInt(v, 10);
    if (DEFAULT_DURATIONS.includes(n) && !seen[n]) {
      seen[n] = true;
      clean.push(n);
    }
  });
  clean.sort((a, b) => a - b);
  currentDurations = clean.length ? clean : DEFAULT_DURATIONS.slice();
  renderDurationsChips();
}

function initDurationsField() {
  renderDurationsChips();
}

export function openCreateModal() {
  const f = form();
  if (!f) return;
  state.set('editingScheduleId', '');
  f.reset();
  const verifyCb = f.querySelector('[name="require_email_verification"]');
  if (verifyCb) verifyCb.checked = false;
  const nfSel = f.querySelector('[name="name_format"]');
  if (nfSel) nfSel.value = 'full';
  const nfcInput = f.querySelector('[name="name_format_custom"]');
  if (nfcInput) nfcInput.value = '';
  const nfcWrap = $('#name_format_custom_wrap');
  if (nfcWrap) nfcWrap.style.display = 'none';

  // f.reset() clears the timezone text input; restore its label from the
  // select (which reset to the user's detected zone via defaultSelected).
  const tzSel = $('#schedule-timezone');
  const tzInput = $('#schedule-timezone-input');
  if (tzSel && tzInput) {
    const it = tzPickerItems.find(i => i.name === tzSel.value);
    tzInput.value = it ? it.label : tzSel.value;
  }

  clearFormErrors();
  resetAvailabilityDisplay();
  resetDailyAvailability();
  resetSpecificDatesList();
  resetRecipientsList();
  currentDurations = [];
  renderDurationsChips();
  syncSelectPickers();

  const m = modal();
  if (m) { m.setAttribute('aria-hidden', 'false'); m.classList.add('is-open'); }
  loadGoogleCalendarStatus();
  checkOwnerScopes();
}

export function openEditModal(scheduleData) {
  fillEditModal(scheduleData);
  const m = modal();
  if (m) { m.setAttribute('aria-hidden', 'false'); m.classList.add('is-open'); }
  checkOwnerScopes();
}

export function closeModal() {
  const m = modal();
  if (m) { m.setAttribute('aria-hidden', 'true'); m.classList.remove('is-open'); }
  closeCustomAvailabilityModal();
  state.set('editingScheduleId', '');
}

export function initScheduleModal() {
  initSchedulePicker();
  populateTimezoneSelect();
  initDurationsField();

  document.querySelectorAll('.time-picker-gc').forEach(p => initTimePickerGc(p));
  document.addEventListener('click', closeAllTimeDropdowns);

  const f = form();
  if (f) {
    f.addEventListener('submit', handleFormSubmit);

    f.addEventListener('change', (e) => {
      if (e.target.matches('#name_format')) syncNameFormatCustomVisibility();
      if (e.target.matches('[name="date_to"], [name="date_from"]')) {
        const toInput = f.querySelector('[name="date_to"]');
        if (toInput) toInput.setCustomValidity('');
      }
    });
  }

  const m = modal();
  if (m) {
    m.querySelectorAll('.create-schedule-modal__close, .create-schedule-modal__backdrop').forEach(el => {
      el.addEventListener('click', closeModal);
    });
  }

  // Custom dropdowns (tz-picker style) for the remaining native selects.
  // Time Zone and the availability picker have their own dedicated widgets.
  [
    $('#name_format'),
    $('#schedule-calendar'),
    f ? f.querySelector('[name="booking_lead_unit"]') : null,
    $('#schedule-color'),
  ].forEach(initSelectPicker);

  const availSelect = $('#schedule-availability-select');
  if (availSelect) {
    committedAvailabilitySelect = 'none';
    updateAvailabilityPanels(getAvailabilityMode());
    if (getAvailabilityMode() === 'none') resetSpecificDatesList();

    availSelect.addEventListener('change', () => {
      if (availabilitySelectProgrammatic) return;
      const v = String(availSelect.value || 'none');
      if (v === 'custom') {
        openCustomAvailabilityModal();
        updateAvailabilityPanels('custom');
        return;
      }
      if (v === 'weekly') {
        if (committedAvailabilitySelect !== 'weekly') applyDefaultWeeklyHiddenDateRange();
        setAvailabilityMode('weekly', 'weekly', 1, true);
        return;
      }
      if (v === 'none') {
        setAvailabilityMode('none', 'does_not_repeat', 1, false);
      }
      // v === 'custom-label' is set programmatically only, never by user interaction
    });
    initAvailabilityPicker();
  }

  const cm = customModal();
  if (cm) {
    cm.querySelectorAll('.create-schedule-modal__backdrop, #custom-availability-modal-close').forEach(el => {
      el.addEventListener('click', () => closeCustomAvailabilityModal());
    });
    const box = cm.querySelector('.create-schedule-modal__box');
    if (box) box.addEventListener('click', e => e.stopPropagation());
  }

  document.querySelectorAll('input[name="custom_availability_ends"]').forEach(radio => {
    radio.addEventListener('change', () => {
      const never = $('#custom-availability-ends-never')?.checked;
      const endsDate = $('#custom-availability-ends-date');
      if (!endsDate) return;
      endsDate.disabled = !!never;
      if (!never && !endsDate.value) {
        // Match Google: selecting "On" prefills the end date with Starts + 90 days.
        const start = $('#custom-availability-starts')?.value || ymdFromLocalDate(new Date());
        endsDate.value = addDaysToYmd(start, 90);
      }
      syncDateDisplay(endsDate);
      updateEndDateValidity();
    });
  });

  document.querySelectorAll('.custom-date__native').forEach(input => {
    input.min = ymdFromLocalDate(new Date()); // can't pick a date earlier than today
    const refresh = () => { syncDateDisplay(input); updateEndDateValidity(input); };
    input.addEventListener('input', refresh);
    input.addEventListener('change', refresh);
    // Open the picker only on the calendar indicator (not on the text segments),
    // so a click meant to place the caret for typing doesn't force the picker open.
    input.addEventListener('click', (e) => {
      if (input.disabled || typeof input.showPicker !== 'function') return;
      const onIndicator = e.offsetX > input.clientWidth - 28;
      if (onIndicator) {
        try { input.showPicker(); } catch (err) { /* showPicker requires gesture/support */ }
      }
    });
    refresh();
  });

  const intervalEl = $('#custom-availability-interval');
  if (intervalEl) intervalEl.addEventListener('input', () => updateEndDateValidity());

  const doneBtn = $('#custom-availability-done');
  if (doneBtn) {
    doneBtn.addEventListener('click', () => {
      const interval = Math.max(1, Math.min(52, parseInt($('#custom-availability-interval')?.value, 10) || 1));
      const start = String($('#custom-availability-starts')?.value || '');
      const never = !!$('#custom-availability-ends-never')?.checked;
      const endDate = String($('#custom-availability-ends-date')?.value || '');

      if (!start) { alert('Start date is required'); return; }
      if (!never && !endDate) { alert('End date is required'); return; }
      if (!never && start && endDate < addDaysToYmd(start, 6)) { return; } // min 6-day span, like Google

      const f = form();
      const dfEl = f?.querySelector('[name="date_from"]');
      const dtEl = f?.querySelector('[name="date_to"]');
      if (dfEl) dfEl.value = start;

      const endNeverHidden = $('#schedule-repeat-end-never-hidden');
      if (never) {
        if (endNeverHidden) endNeverHidden.value = '1';
        if (dtEl) dtEl.value = addYearsToYmd(start, 10);
      } else {
        if (endNeverHidden) endNeverHidden.value = '0';
        if (dtEl) dtEl.value = endDate;
      }
      setAvailabilityMode('custom', 'custom', interval, never);
      closeCustomAvailabilityModal({ skipRevert: true });
    });
  }

  // Specific dates: add / remove
  document.addEventListener('click', (e) => {
    if (e.target.closest('#specific-date-add')) {
      appendSpecificDateRow(ymdFromLocalDate(new Date()), '09:00', '17:00');
      updateSpecificDateRemoveState();
    }

    const removeBtn = e.target.closest('.specific-date-row__remove');
    if (removeBtn && !removeBtn.disabled) {
      const row = removeBtn.closest('.specific-date-row');
      const all = $$('#specific-dates-list .specific-date-row');
      if (all.length <= 1) return;
      row.remove();
      updateSpecificDateRemoveState();
    }
  });
  updateSpecificDateRemoveState();

  // Recipient inputs ship readonly to suppress browser/password-manager
  // autofill; lift it on first focus (CSP-safe — no inline handler).
  document.addEventListener('focusin', (e) => {
    const input = e.target.closest('.recipient-row__email[readonly]');
    if (input) input.removeAttribute('readonly');
  });

  // Additional recipients: add / remove. Last empty row is kept so the field
  // never collapses to nothing.
  document.addEventListener('click', (e) => {
    if (e.target.closest('#schedule-recipient-add')) {
      const input = appendRecipientRow('');
      input?.focus();
      return;
    }

    const removeBtn = e.target.closest('.recipient-row__remove');
    if (removeBtn) {
      const row = removeBtn.closest('.recipient-row');
      const all = $$('#schedule-recipients-list .recipient-row');
      if (all.length <= 1) {
        const input = row?.querySelector('.recipient-row__email');
        if (input) input.value = '';
        return;
      }
      row?.remove();
    }
  });

  // Daily availability: unavailable toggle, add period, remove slot
  const dailyRoot = $('#daily-availability');
  if (dailyRoot) {
    dailyRoot.addEventListener('change', (e) => {
      if (!e.target.matches('.day-not-available')) return;
      const dayEl = e.target.closest('.create-schedule-form__day');
      if (!dayEl) return;
      if (e.target.checked) {
        dayEl.classList.add('is-unavailable');
        dayEl.querySelectorAll('.create-schedule-form__slot').forEach(el => el.remove());
      } else {
        dayEl.classList.remove('is-unavailable');
      }
    });

    $$('#daily-availability .day-not-available').forEach(cb => {
      if (cb.checked) {
        const dayEl = cb.closest('.create-schedule-form__day');
        if (dayEl) {
          dayEl.classList.add('is-unavailable');
          dayEl.querySelectorAll('.create-schedule-form__slot').forEach(el => el.remove());
        }
      }
    });

    dailyRoot.addEventListener('click', (e) => {
      if (e.target.closest('.create-schedule-form__day-action--add-period')) {
        const dayEl = e.target.closest('.create-schedule-form__day');
        if (!dayEl) return;
        const templateEl = document.getElementById('slot-row-template');
        if (!templateEl?.content) return;

        if (dayEl.classList.contains('is-unavailable')) {
          const cb = dayEl.querySelector('.day-not-available');
          if (cb) cb.checked = false;
          dayEl.classList.remove('is-unavailable');
          dayEl.querySelectorAll('.create-schedule-form__slot').forEach(el => el.remove());
        }

        const slotEl = templateEl.content.firstElementChild?.cloneNode(true);
        if (!slotEl) return;
        const existingSlots = dayEl.querySelectorAll('.create-schedule-form__slot');
        const lastSlot = existingSlots.length > 0 ? existingSlots[existingSlots.length - 1] : null;
        const lastEnd = lastSlot?.querySelector('.slot-end')?.value || '';
        const newStart = (lastEnd && lastEnd !== '24:00') ? lastEnd : '09:00';
        const newEnd = lastEnd ? addMinutesToTime(newStart, 60) : '18:00';
        slotEl.querySelector('.slot-start').value = newStart;
        slotEl.querySelector('.slot-end').value = newEnd;
        slotEl.querySelectorAll('.time-picker-gc').forEach((p, i) => {
          const t = p.querySelector('.time-picker-gc__trigger');
          if (t) t.textContent = i === 0 ? formatTime12(newStart) : formatTime12(newEnd);
        });
        dayEl.querySelector('.create-schedule-form__slots')?.appendChild(slotEl);
        slotEl.querySelectorAll('.time-picker-gc').forEach(p => initTimePickerGc(p));
      }

      if (e.target.closest('.create-schedule-form__slot-remove')) {
        const slotEl = e.target.closest('.create-schedule-form__slot');
        const dayEl = slotEl?.closest('.create-schedule-form__day');
        if (!slotEl || !dayEl) return;
        const allSlots = dayEl.querySelectorAll('.create-schedule-form__slot');
        if (allSlots.length <= 1) {
          slotEl.remove();
          const cb = dayEl.querySelector('.day-not-available');
          if (cb) cb.checked = true;
          dayEl.classList.add('is-unavailable');
          return;
        }
        slotEl.remove();
        revalidateDayInline(dayEl);
      }
    });
  }

  document.addEventListener('change', (e) => {
    if (e.target.matches('#schedule-calendar')) {
      if (isGoogleCalendarSelected()) loadGoogleCalendarStatus();
      else renderGoogleCalendarState(false);
    }
    if (e.target.matches?.('.slot-start, .slot-end')) {
      const slot = e.target.closest('.create-schedule-form__slot, .specific-date-row');
      if (slot) validateSlotRangeInline(slot);
    }
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    if (isGoogleCalendarSelected()) {
      loadGoogleCalendarStatus();
    }
    const m = modal();
    if (m && m.classList.contains('is-open')) {
      checkOwnerScopes();
    }
  });

  document.addEventListener('click', (e) => {
    if (e.target.closest('#google-calendar-connect-btn') || e.target.closest('#schedule-scope-reconnect')) {
      e.preventDefault();
      // Reconnect from the form opens a new tab so an in-progress schedule is
      // not lost; on return, visibilitychange re-checks scopes and clears the warning.
      const newTab = !!e.target.closest('#schedule-scope-reconnect');
      const win = newTab ? window.open('about:blank', '_blank') : null;
      api.googleCalendarConnectUrl().then(resp => {
        if (resp?.success && resp?.data?.url) {
          if (win) win.location.href = String(resp.data.url);
          else window.location.href = String(resp.data.url);
          return;
        }
        if (win) win.close();
        alert(resp?.data?.message || 'Unable to start Google authorization');
      }).catch(() => { if (win) win.close(); alert('Request failed'); });
    }

  });

  // Persist the owner's chosen Google calendar when the picker changes.
  document.addEventListener('change', (e) => {
    const sel = e.target.closest('#google-calendar-select');
    if (!sel) return;
    api.googleCalendarSetCalendar(sel.value).catch(() => alert('Failed to save calendar choice'));
  });
}
