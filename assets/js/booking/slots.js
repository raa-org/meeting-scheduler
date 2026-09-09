/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Available time slot loading, caching, and rendering.
 * Ported from calendar_ajax.js — get_available_ranges / update_range_slots / parse_range_slots.
 */

import * as state from 'apexianlab-booking/state';
import * as api from 'apexianlab-booking/api';
import { $, buildDateKey, formatDateInTimezone, addClass, removeClass, toggleClass } from 'apexianlab-booking/utils';
import { renderWeekGrid, isWeekView } from 'apexianlab-booking/calendar';

let rawRanges = [];

// Becomes true as soon as the browser starts unloading the page (link
// click, back/forward, tab close). We use it to distinguish genuine
// network failures from fetches that the browser itself aborted because
// the user is navigating away — the latter should not show an alert.
let isLeavingPage = false;
if (typeof window !== 'undefined') {
  const markLeaving = () => { isLeavingPage = true; };
  window.addEventListener('pagehide', markLeaving);
  window.addEventListener('beforeunload', markLeaving);
}

function slotEntryIsAvailable(slot) {
  if (typeof slot === 'string') return true;
  if (slot && typeof slot === 'object' && Object.prototype.hasOwnProperty.call(slot, 'available')) {
    return !!slot.available;
  }
  return true;
}

/**
 * Expand raw server ranges into per-day slot maps considering booked intervals.
 */
// Slot start granularity: offer a fresh start every SLOT_STEP_MINUTES regardless
// of the meeting length, so a 60-min meeting is bookable at 12:00, 12:15, 12:30,
// 12:45, 13:00 … (each slot is still `duration` long). Previously the step
// equalled the duration, so a 60-min meeting only offered 12:00, 13:00, 14:00 …
const SLOT_STEP_MINUTES = 15;

function buildDateSlots(ranges, bookedIntervals, timezone, duration) {
  const calculated = [];
  const stepSec = SLOT_STEP_MINUTES * 60;

  Object.values(ranges).forEach(item => {
    let currentStart = item.start;
    const end = item.end;

    while (currentStart + duration * 60 <= end) {
      const currentEnd = currentStart + duration * 60;
      let isAvailable = true;

      for (let i = 0; i < bookedIntervals.length; i++) {
        const booked = bookedIntervals[i];
        if (booked.start >= currentEnd) break;
        if (booked.start < currentEnd && booked.end > currentStart) {
          isAvailable = false;
          break;
        }
      }

      calculated.push({ start: currentStart, end: currentEnd, available: isAvailable });
      currentStart += stepSec;
    }
  });

  const slots = {};
  const nowSec = Math.floor(Date.now() / 1000);
  const todayKey = formatDateInTimezone(new Date(), timezone).date;

  calculated.forEach(item => {
    const slot = formatDateInTimezone(new Date(item.start * 1000), timezone);
    if (slot.date === todayKey && item.start < nowSec) return;

    if (!slots[slot.date]) slots[slot.date] = [];
    slots[slot.date].push({ time: slot.time, available: item.available });
  });

  return slots;
}

function setCalendarSlotsRowVisible(visible) {
  const row = $('#frame-1 .booking__calendar-slots-row');
  if (!row) return;
  const alert = $('#booking-alert');
  if (visible) {
    removeClass(row, 'booking__calendar-slots-row--alert');
    row.style.display = '';
    if (alert) {
      alert.style.display = 'none';
      removeClass(alert, 'booking__alert--inline');
    }
  } else {
    row.style.display = 'none';
  }
}

function showAlert(msg) {
  const alertEl = $('#booking-alert');
  if (alertEl) {
    const frame1 = $('#frame-1');
    const row = $('#frame-1 .booking__calendar-slots-row');
    const isFrame1Active = frame1 && frame1.classList.contains('current');

    if (isFrame1Active && row) {
      if (!row.contains(alertEl)) row.appendChild(alertEl);
      addClass(row, 'booking__calendar-slots-row--alert');
      row.style.display = 'flex';
      addClass(alertEl, 'booking__alert--inline');
      alertEl.textContent = msg;
      alertEl.style.display = '';
      return;
    }

    if (alertEl.parentElement !== document.body) {
      document.body.appendChild(alertEl);
    }
    removeClass(alertEl, 'booking__alert--inline');
    alertEl.textContent = msg;
    alertEl.style.display = '';
  } else {
    alert(msg);
  }
}

/**
 * On first load only: if current day has no available slots, jump to the
 * earliest date that does. Returns the date key to render.
 */
function autoSelectFirstAvailableDay(dateSlots, currentKey) {
  if (state.get('initialAutoSelected')) return currentKey;
  state.set('initialAutoSelected', true);

  if ((dateSlots[currentKey] || []).some(s => s.available)) return currentKey;

  const sortedKeys = Object.keys(dateSlots).sort();
  const firstAvail = sortedKeys.find(k => k >= currentKey && (dateSlots[k] || []).some(s => s.available));
  if (!firstAvail) return currentKey;

  const yy = parseInt(firstAvail.slice(0, 4), 10);
  const mm = parseInt(firstAvail.slice(4, 6), 10);
  const dd = parseInt(firstAvail.slice(6, 8), 10);
  state.set('selectedYear', yy);
  state.set('selectedMonth', mm);
  state.set('selectedDay', dd);
  return firstAvail;
}

let slotsLoading = false;

/**
 * Fetch available ranges from API and update state.
 */
export async function loadSlots() {
  if (slotsLoading) return;
  slotsLoading = true;
  const year = state.get('selectedYear');
  const month = state.get('selectedMonth');
  const day = state.get('selectedDay');
  const timezone = state.get('timezone');
  const duration = state.get('duration');

  const slotsContainer = $('.slots__container');
  const weekGrid = $('#frame-1 .calendar__week-grid');
  if (slotsContainer) addClass(slotsContainer, 'slots__container--loading');
  if (weekGrid) addClass(weekGrid, 'calendar__week-grid--loading');

  // Reschedule mode: forward the meeting id so the server can re-block the
  // meeting's own current slot (otherwise it would appear free again, since
  // the row is either filtered out by findForCalendar — non-confirmed
  // mid-reschedule — or its external event has not been pushed yet).
  const meetingId = state.get('isReschedule') ? state.get('meetingId') : '';

  try {
    const response = await api.getAvailableRanges({ timezone, year, month, day, duration, meetingId });

    if (!response || response.success !== true) {
      if (response?.data?.code === 'schedule_unavailable') {
        window.location.reload();
        return;
      }
      showAlert(response?.data?.message || 'Failed to load available slots');
      setCalendarSlotsRowVisible(false);
      return;
    }

    const data = response.data;
    if (!data || !data.from || !data.to || !Array.isArray(data.list)) {
      showAlert('Invalid server response');
      setCalendarSlotsRowVisible(false);
      return;
    }

    const fromKey = formatDateInTimezone(new Date(data.from * 1000), timezone);
    const toKey = formatDateInTimezone(new Date(data.to * 1000), timezone);

    const booked = Array.isArray(data.booked_intervals) ? data.booked_intervals : [];
    booked.sort((a, b) => a.start - b.start);

    rawRanges = data.list;

    state.set('cachedFrom', fromKey.date);
    state.set('cachedTo', toKey.date);
    state.set('bookedIntervals', booked);

    const dateSlots = buildDateSlots(data.list, booked, timezone, duration);
    state.set('dateSlots', dateSlots);
    state.set('slotsDataReady', true);

    const key = autoSelectFirstAvailableDay(dateSlots, buildDateKey(year, month, day));

    renderSlots(dateSlots[key] || []);
    setCalendarSlotsRowVisible(true);
    if (isWeekView()) renderWeekGrid();
  } catch {
    // The browser cancels in-flight fetches when the page is navigated
    // away (e.g. the user clicked "Schedule with AI" before the calendar
    // finished loading). That is not a real server failure, so don't
    // flash the alert.
    if (isLeavingPage || document.visibilityState === 'hidden') return;
    showAlert('Server error. Try again later.');
    setCalendarSlotsRowVisible(false);
  } finally {
    slotsLoading = false;
    if (slotsContainer) removeClass(slotsContainer, 'slots__container--loading');
    if (weekGrid) removeClass(weekGrid, 'calendar__week-grid--loading');
  }
}

/**
 * Use cached data if available, otherwise fetch.
 */
export function updateSlots() {
  const year = state.get('selectedYear');
  const month = state.get('selectedMonth');
  const day = state.get('selectedDay');
  const timezone = state.get('timezone');
  const duration = state.get('duration');
  const key = buildDateKey(year, month, day);

  const dateSlots = state.get('dateSlots') || {};
  const cachedFrom = state.get('cachedFrom') || 0;
  const cachedTo = state.get('cachedTo') || 0;

  if (dateSlots[key]) {
    renderSlots(dateSlots[key]);
    return;
  }

  if (key >= cachedFrom && key < cachedTo && rawRanges.length > 0) {
    const booked = state.get('bookedIntervals') || [];
    const rebuilt = buildDateSlots(rawRanges, booked, timezone, duration);
    state.set('dateSlots', rebuilt);
    renderSlots(rebuilt[key] || []);
    return;
  }

  loadSlots();
}

/**
 * Render time slot buttons for a given day's slot list.
 */
export function renderSlots(timeSlots) {
  const slotBox = $('.slots__container');
  if (!slotBox) return;

  const safeSlots = Array.isArray(timeSlots) ? timeSlots : [];
  const availableSlots = safeSlots.filter(slotEntryIsAvailable);

  let html = '';
  if (availableSlots.length === 0) {
    html = '<div class="slots__empty">No available times on this date.<span class="slots__empty-hint">Try another date.</span></div>';
  }

  availableSlots.forEach(slot => {
    const time = typeof slot === 'string' ? slot : slot.time;
    html += `<div class="slots__item slots__item--available" data-available="true"><span>${time}</span></div>`;
  });

  slotBox.innerHTML = html;

  const count = slotBox.querySelectorAll('.slots__item').length;
  if (count > 7) {
    slotBox.style.setProperty('--slots-col-rows', String(Math.ceil(count / 2)));
  } else {
    slotBox.style.removeProperty('--slots-col-rows');
  }
  toggleClass(slotBox, 'slots__container--two-columns', count > 7);

  const slotsWrap = $('#frame-1 .slots');
  if (slotsWrap) toggleClass(slotsWrap, 'slots--two-columns', count > 7);
}

let durationReloadTimer = null;

export function initSlots() {
  state.on('change:selectedDay', updateSlots);
  state.on('change:timezone', () => loadSlots());
  state.on('change:duration', () => {
    if (durationReloadTimer) clearTimeout(durationReloadTimer);
    durationReloadTimer = setTimeout(() => {
      durationReloadTimer = null;
      loadSlots();
    }, 1500);
  });
}
