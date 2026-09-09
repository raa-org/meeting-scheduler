/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * DOM helper functions and utilities for the booking module.
 */

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => root.querySelectorAll(selector);

export function show(el) {
  if (el) el.classList.remove('is-hidden');
}

export function hide(el) {
  if (el) el.classList.add('is-hidden');
}

export function addClass(el, cls) {
  if (el) el.classList.add(cls);
}

export function removeClass(el, cls) {
  if (el) el.classList.remove(cls);
}

export function toggleClass(el, cls, force) {
  if (el) el.classList.toggle(cls, force);
}

export function pad(n) {
  return String(n).padStart(2, '0');
}

/**
 * Format a Date in a given IANA timezone → { date: 'YYYYMMDD', time: '02:30 pm' }
 */
export function formatDateInTimezone(date, timezone) {
  let year, month, day, hours, minutes;

  if (/^[+-]\d{2}:\d{2}$/.test(timezone)) {
    const m = timezone.match(/^([+-])(\d{2}):(\d{2})$/);
    const sign = m[1] === '+' ? 1 : -1;
    const offsetMs = (parseInt(m[2], 10) * 60 + parseInt(m[3], 10)) * 60_000 * sign;
    const shifted = new Date(date.getTime() + offsetMs);
    const iso = shifted.toISOString();
    const [datePart, timePart] = iso.split('T');
    [year, month, day] = datePart.split('-');
    [hours, minutes] = timePart.slice(0, 8).split(':');
  } else {
    const fmt = new Intl.DateTimeFormat('en-US', {
      timeZone: timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    });
    const parts = fmt.formatToParts(date);
    year = parts.find(p => p.type === 'year').value;
    month = parts.find(p => p.type === 'month').value;
    day = parts.find(p => p.type === 'day').value;
    hours = parts.find(p => p.type === 'hour').value;
    minutes = parts.find(p => p.type === 'minute').value;
  }

  let h = parseInt(hours, 10);
  const period = h >= 12 ? 'pm' : 'am';
  if (h > 12) h -= 12;
  if (h === 0) h = 12;

  return {
    date: `${year}${month}${day}`,
    time: `${pad(h)}:${minutes} ${period}`,
  };
}

/**
 * Build a YYYYMMDD key from numeric year, 1-based month, day.
 */
export function buildDateKey(year, month, day) {
  return String(year) + pad(month) + pad(day);
}

/**
 * Format a date object for display: "Monday, April 7"
 */
export function formatDetailsDate(dateObj, timezone) {
  if (!dateObj || dateObj.year == null) return '';
  const y = parseInt(dateObj.year, 10);
  const m = parseInt(dateObj.month, 10);
  const d = parseInt(dateObj.day, 10) || 1;
  if (Number.isNaN(y) || Number.isNaN(m)) return '';
  const ref = new Date(Date.UTC(y, m, d, 12, 0, 0));
  try {
    return new Intl.DateTimeFormat('en-US', {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
      timeZone: timezone || Intl.DateTimeFormat().resolvedOptions().timeZone,
    }).format(ref);
  } catch {
    return ref.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
  }
}

/**
 * "02:30 pm" + 30 min → "02:30 pm - 03:00 pm"
 */
export function formatTimeRange(timeStr, durationMinutes, startDateObj = null) {
  if (!timeStr) return '';
  const m = timeStr.trim().match(/^(\d{1,2}):(\d{2})\s*(am|pm)$/i);
  if (!m) return timeStr;
  let h = parseInt(m[1], 10);
  const min = parseInt(m[2], 10);
  const isPm = m[3].toLowerCase() === 'pm';
  if (isPm && h !== 12) h += 12;
  if (!isPm && h === 12) h = 0;
  const total = h * 60 + min + (durationMinutes || 0);
  const endH = Math.floor(total / 60) % 24;
  const endM = total % 60;
  const end12 = endH === 0 ? 12 : endH > 12 ? endH - 12 : endH;
  const endAmPm = endH < 12 ? 'am' : 'pm';
  let nextDaySuffix = '';
  if (total >= 24 * 60) {
    if (startDateObj && startDateObj.year != null) {
      const nd = new Date(Date.UTC(startDateObj.year, startDateObj.month, startDateObj.day + 1));
      const label = nd.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });
      nextDaySuffix = ` (${label})`;
    } else {
      nextDaySuffix = ' +1';
    }
  }
  return `${timeStr.trim()} - ${end12}:${pad(endM)} ${endAmPm}${nextDaySuffix}`;
}

// --- UTC offset helpers ---

const userLocale = navigator.language;

export function getUtcOffsetForTimezone(timezone, referenceDate) {
  try {
    const parts = new Intl.DateTimeFormat(userLocale, {
      timeZone: timezone,
      timeZoneName: 'longOffset',
    }).formatToParts(referenceDate || new Date());
    const tzName = (parts.find(p => p.type === 'timeZoneName') || {}).value || 'GMT+00:00';
    const m = String(tzName).match(/GMT([+-]\d{2}:\d{2})/);
    return m ? m[1] : '+00:00';
  } catch {
    return '+00:00';
  }
}

function formatOffsetForDisplay(offsetStr) {
  const m = String(offsetStr || '').trim().match(/^([+-])(\d{2}):(\d{2})$/);
  if (!m) return '+0:00';
  return m[1] + parseInt(m[2], 10) + ':' + pad(parseInt(m[3], 10));
}

export function formatTimezoneLabel(name, offsetStr) {
  return `${name} (${formatOffsetForDisplay(offsetStr)})`;
}

export function buildTimezoneLabel(timezone, dateObj) {
  const safeTz = timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
  let ref = new Date();
  if (dateObj && dateObj.year != null) {
    ref = new Date(Date.UTC(dateObj.year, dateObj.month, dateObj.day, 12, 0, 0));
  }
  const offset = getUtcOffsetForTimezone(safeTz, ref);
  return formatTimezoneLabel(safeTz, offset);
}

// --- Loading overlay ---

let loadCount = 0;

export function startLoad() {
  loadCount++;
  document.body.classList.add('load');
}

export function endLoad() {
  if (loadCount === 1) {
    document.body.classList.remove('load');
  }
  loadCount--;
}
