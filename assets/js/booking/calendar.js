/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Mini-calendar month + week view for booking page.
 * Uses state for selection instead of localStorage.
 */

import * as state from 'apexianlab-booking/state';
import { $, $$, buildDateKey, formatDateInTimezone } from 'apexianlab-booking/utils';
import { updateSlots } from 'apexianlab-booking/slots';

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

const DOW_SHORT = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
const VIEW_KEY_PREFIX = 'apexianlab_ms_booking_cal_view_';

let containerEl = null;
let currentYear = new Date().getFullYear();
let currentMonth = new Date().getMonth();
let currentView = 'month';
let weekStart = null;
let navigating = false;

const todayAtMidnight = new Date();
todayAtMidnight.setHours(0, 0, 0, 0);

function slotEntryIsAvailable(slot) {
  if (typeof slot === 'string') return true;
  if (slot && typeof slot === 'object' && Object.prototype.hasOwnProperty.call(slot, 'available')) {
    return !!slot.available;
  }
  return true;
}

function dateSlotKey(year, monthIndex, day) {
  return String(year) + String(monthIndex + 1).padStart(2, '0') + String(day).padStart(2, '0');
}

function renderCalendar() {
  const calendarDays = containerEl ? $(' .calendar__days', containerEl) : null;
  const currentDate = containerEl ? $('.calendar__current-date', containerEl) : null;
  if (!calendarDays) return;

  const lastDateOfMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
  const lastDateOfPrevMonth = new Date(currentYear, currentMonth, 0).getDate();
  const firstDayOfMonth = (new Date(currentYear, currentMonth, 1).getDay() + 6) % 7;
  const lastDayOfMonth = (new Date(currentYear, currentMonth, lastDateOfMonth).getDay() + 6) % 7;

  const selDay = state.get('selectedDay');
  const selMonth = state.get('selectedMonth');
  const selYear = state.get('selectedYear');
  const today = new Date();

  const dateSlots = state.get('dateSlots') || {};
  const slotsReady = state.get('slotsDataReady');

  let html = '';

  for (let i = firstDayOfMonth; i > 0; i--) {
    html += `<li class="disabled">${lastDateOfPrevMonth - i + 1}</li>`;
  }

  for (let i = 1; i <= lastDateOfMonth; i++) {
    const cellDate = new Date(currentYear, currentMonth, i);
    cellDate.setHours(0, 0, 0, 0);
    const isPast = cellDate < todayAtMidnight;

    let noAvailableSlots = false;
    if (!isPast && slotsReady && dateSlots) {
      const dk = dateSlotKey(currentYear, currentMonth, i);
      const slotList = dateSlots[dk];
      noAvailableSlots = !Array.isArray(slotList) || slotList.length === 0 || !slotList.some(slotEntryIsAvailable);
    }

    const isDisabled = isPast || noAvailableSlots;
    const isToday = i === today.getDate() && currentMonth === today.getMonth() && currentYear === today.getFullYear();
    // selMonth is 1-based in state
    const isSelected = selDay === i && selMonth === currentMonth + 1 && selYear === currentYear;

    const classes = [
      isDisabled ? 'disabled' : '',
      isToday ? 'today' : '',
      isSelected ? 'selected' : '',
    ].filter(Boolean).join(' ');

    html += `<li ${classes ? `class="${classes}"` : ''}>${i}</li>`;
  }

  for (let i = lastDayOfMonth; i < 6; i++) {
    html += `<li class="disabled">${i - lastDayOfMonth + 1}</li>`;
  }

  calendarDays.innerHTML = html;

  if (currentDate) {
    const monthEl = $('.calendar__current-month', currentDate);
    const dayEl = $('.calendar__current-day', currentDate);
    const rangeEl = $('.calendar__week-range', currentDate);
    const calRoot = $('#frame-1 .calendar') || $('.calendar');

    if (monthEl) monthEl.textContent = MONTHS[currentMonth];
    if (dayEl) dayEl.textContent = '';
    if (rangeEl && calRoot && !calRoot.classList.contains('calendar--view-week')) {
      rangeEl.textContent = String(currentYear);
      rangeEl.removeAttribute('hidden');
    }
  }

  bindDayClicks();
}

function bindDayClicks() {
  const cells = $$('.calendar__days li:not(.disabled)', containerEl);
  cells.forEach(cell => {
    cell.addEventListener('click', () => {
      const day = parseInt(cell.textContent, 10);
      state.set('selectedYear', currentYear);
      state.set('selectedMonth', currentMonth + 1);
      state.set('selectedDay', day);
    });
  });
}

function navigateMonth(delta) {
  const raw = new Date(currentYear, currentMonth + delta);
  const newYear = raw.getFullYear();
  const newMonth = raw.getMonth();

  const storedDay = state.get('selectedDay') || 1;
  const daysInMonth = new Date(newYear, newMonth + 1, 0).getDate();
  const clamped = Math.min(Math.max(storedDay, 1), daysInMonth);

  navigating = true;
  currentYear = newYear;
  currentMonth = newMonth;
  state.set('selectedYear', newYear);
  state.set('selectedMonth', newMonth + 1);
  state.set('selectedDay', clamped);
  navigating = false;

  renderCalendar();
}

function syncFromState() {
  if (navigating) return;
  const y = state.get('selectedYear');
  const m = state.get('selectedMonth');
  if (y != null && m != null) {
    currentYear = y;
    currentMonth = m - 1;
  }
  renderCalendar();
}

// --- Week view helpers ---

function viewKey() {
  return VIEW_KEY_PREFIX + (String(window.location.pathname || '/').replace(/\/+$/, '') || '/');
}

function isWeekView() {
  return currentView === 'week';
}

function mondayOfDate(ref) {
  const d = new Date(ref.getFullYear(), ref.getMonth(), ref.getDate());
  const dow = (d.getDay() + 6) % 7;
  d.setDate(d.getDate() - dow);
  return d;
}

function syncWeekStart() {
  const y = state.get('selectedYear');
  const m = state.get('selectedMonth');
  const d = state.get('selectedDay');
  if (y != null && m != null && d != null) {
    weekStart = mondayOfDate(new Date(y, m - 1, d));
  } else {
    weekStart = mondayOfDate(new Date());
  }
}

function weekRangeParts(monday) {
  const sun = new Date(monday);
  sun.setDate(monday.getDate() + 6);
  const m1 = monday.getMonth();
  const d1 = monday.getDate();
  const m2 = sun.getMonth();
  const d2 = sun.getDate();
  const y1 = monday.getFullYear();
  const y2 = sun.getFullYear();

  const rangeText = m1 === m2
    ? `${d1} \u2013 ${d2} ${MONTHS[m1]}`
    : `${d1} ${MONTHS[m1].slice(0, 3)} \u2013 ${d2} ${MONTHS[m2].slice(0, 3)}`;
  const yearText = y1 === y2 ? String(y1) : `${y1} \u2013 ${y2}`;
  return { rangeText, yearText };
}

function renderWeekGrid() {
  const grid = $('#frame-1 .calendar__week-grid');
  const rangeEl = $('#frame-1 .calendar__week-range');
  if (!grid || !isWeekView()) return;

  syncWeekStart();
  const monday = weekStart;

  if (rangeEl) {
    const parts = weekRangeParts(monday);
    rangeEl.innerHTML = '';
    const datesSpan = document.createElement('span');
    datesSpan.className = 'calendar__week-range-dates';
    datesSpan.textContent = parts.rangeText;
    const yearSpan = document.createElement('span');
    yearSpan.className = 'calendar__week-range-year';
    yearSpan.textContent = parts.yearText;
    rangeEl.appendChild(datesSpan);
    rangeEl.appendChild(yearSpan);
    rangeEl.removeAttribute('hidden');
  }

  const tz = state.get('timezone') || 'UTC';
  const todayKeyTz = formatDateInTimezone(new Date(), tz).date;
  const dateSlots = state.get('dateSlots') || {};
  const selYear = state.get('selectedYear');
  const selMonth = state.get('selectedMonth');

  let cols = '';
  for (let i = 0; i < 7; i++) {
    const colDate = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + i);
    const y = colDate.getFullYear();
    const m0 = colDate.getMonth();
    const d = colDate.getDate();
    const isOutside = y !== selYear || m0 !== (selMonth - 1);
    const key = buildDateKey(y, m0 + 1, d);
    const slots = isOutside ? [] : (dateSlots[key] || []);
    const isPast = key < todayKeyTz;
    const isToday = key === todayKeyTz;
    const isWeekend = i >= 5;
    const selDay = state.get('selectedDay');
    const isSelected = selYear === y && (selMonth - 1) === m0 && selDay === d;

    const cls = [
      'calendar__week-col',
      isWeekend ? 'calendar__week-col--weekend' : '',
      isToday ? 'calendar__week-col--today' : '',
      isSelected ? 'calendar__week-col--selected' : '',
      isOutside ? 'calendar__week-col--outside-month' : '',
    ].filter(Boolean).join(' ');

    let slotsHtml = '';
    if (isPast || !Array.isArray(slots) || slots.length === 0) {
      slotsHtml = '<div class="calendar__week-col-empty"></div>';
    } else {
      const available = slots.filter(slotEntryIsAvailable);
      if (available.length === 0) {
        slotsHtml = '<div class="calendar__week-col-empty"></div>';
      } else {
        available.forEach(slot => {
          const time = typeof slot === 'string' ? slot : slot.time;
          slotsHtml += `<div class="slots__item slots__item--available" data-available="true" data-slot-year="${y}" data-slot-month="${m0}" data-slot-day="${d}"><span>${time}</span></div>`;
        });
      }
    }

    cols += `<div class="${cls}" data-year="${y}" data-month="${m0}" data-day="${d}" data-outside-month="${isOutside ? '1' : '0'}"><div class="calendar__week-col-head"><span class="calendar__week-dow">${DOW_SHORT[i]}</span><span class="calendar__week-daynum">${d}</span></div><div class="calendar__week-col-slots">${slotsHtml}</div></div>`;
  }

  grid.innerHTML = cols;

}

function applyDomView(view) {
  const cal = $('#frame-1 .calendar');
  const container = $('#frame-1 .booking__container');
  const monthPanel = $('#frame-1 .calendar__month-panel');
  const weekPanel = $('#frame-1 .calendar__week-panel');
  const rangeEl = $('#frame-1 .calendar__week-range');
  const monthSpan = $('#frame-1 .calendar__current-month');
  const slotWrap = $('#frame-1 .slots');
  const slotBox = $('#frame-1 .slots__container');

  $$('#frame-1 .calendar__view-switch-btn').forEach(btn => {
    btn.classList.toggle('is-active', btn.getAttribute('data-calendar-view') === view);
  });

  if (view === 'week') {
    if (cal) cal.classList.add('calendar--view-week');
    if (container) container.classList.add('booking__container--week-view');
    if (slotWrap) slotWrap.classList.remove('slots--two-columns');
    if (slotBox) {
      slotBox.classList.remove('slots__container--two-columns');
      slotBox.style.removeProperty('--slots-col-rows');
    }
    if (monthPanel) monthPanel.hidden = true;
    if (weekPanel) weekPanel.hidden = false;
    if (monthSpan) monthSpan.style.display = 'none';
  } else {
    if (cal) cal.classList.remove('calendar--view-week');
    if (container) container.classList.remove('booking__container--week-view');
    if (monthPanel) monthPanel.hidden = false;
    if (weekPanel) weekPanel.hidden = true;
    if (monthSpan) monthSpan.style.display = '';
    if (rangeEl) {
      rangeEl.innerHTML = '';
    }
    renderCalendar();
    updateSlots();
  }
}

function setView(view) {
  currentView = view === 'week' ? 'week' : 'month';
  try { localStorage.setItem(viewKey(), currentView); } catch { /* ignore */ }
  applyDomView(currentView);
  if (currentView === 'week') {
    syncWeekStart();
    renderWeekGrid();
  }
}

function navigateWeek(dir) {
  syncWeekStart();
  const mon = new Date(weekStart.getFullYear(), weekStart.getMonth(), weekStart.getDate());
  mon.setDate(mon.getDate() + 7 * dir);
  weekStart = mon;

  const selDay = state.get('selectedDay') || 1;
  const selMonth = state.get('selectedMonth') || 1;
  const selYear = state.get('selectedYear') || mon.getFullYear();
  const prevMon = mondayOfDate(new Date(selYear, selMonth - 1, selDay));
  let idx = Math.round((new Date(selYear, selMonth - 1, selDay) - prevMon) / 86400000);
  if (idx < 0 || idx > 6) idx = 0;

  const newDay = new Date(mon.getFullYear(), mon.getMonth(), mon.getDate() + idx);

  navigating = true;
  currentYear = newDay.getFullYear();
  currentMonth = newDay.getMonth();
  state.set('selectedYear', newDay.getFullYear());
  state.set('selectedMonth', newDay.getMonth() + 1);
  state.set('selectedDay', newDay.getDate());
  navigating = false;

  renderWeekGrid();
}

export function initCalendar(containerSelector) {
  containerEl = $(containerSelector) || $('#frame-1');

  const now = new Date();
  currentYear = state.get('selectedYear') || now.getFullYear();
  currentMonth = (state.get('selectedMonth') || now.getMonth() + 1) - 1;

  renderCalendar();

  const navButtons = $$('.calendar__button', containerEl);
  navButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      if (isWeekView()) {
        const dir = btn.classList.contains('calendar__button--prev') ? -1 : 1;
        navigateWeek(dir);
        return;
      }
      const delta = btn.classList.contains('calendar__button--prev') ? -1 : 1;
      navigateMonth(delta);
    });
  });

  $$('#frame-1 .calendar__view-switch-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const v = btn.getAttribute('data-calendar-view');
      if (v === 'month' || v === 'week') setView(v);
    });
  });

  let savedView = 'month';
  try { savedView = localStorage.getItem(viewKey()) || 'month'; } catch { /* ignore */ }
  if (savedView === 'week') {
    setView('week');
  }

  state.on('change:selectedDay', () => { syncFromState(); if (isWeekView()) renderWeekGrid(); });
  state.on('change:selectedYear', () => { syncFromState(); if (isWeekView()) renderWeekGrid(); });
  state.on('change:selectedMonth', () => { syncFromState(); if (isWeekView()) renderWeekGrid(); });
  state.on('change:dateSlots', () => { renderCalendar(); if (isWeekView()) renderWeekGrid(); });
  state.on('change:slotsDataReady', () => { renderCalendar(); if (isWeekView()) renderWeekGrid(); });
}

export { renderCalendar, renderWeekGrid, isWeekView };
