/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Observable store for booking page state.
 * Usage: state.set('selectedDate', new Date()); state.on('change:selectedDate', cb);
 */

const listeners = new Map();

const store = {
  timezone: '',
  duration: 30,
  selectedDate: null,     // Date object
  selectedYear: null,
  selectedMonth: null,    // 1-based
  selectedDay: null,
  selectedTime: null,     // "HH:MM"
  frame: 'frame-1',      // current step: frame-1..frame-7, confirm, confirmed
  slots: [],             // available ranges from API
  bookedIntervals: [],   // booked intervals from API
  dateSlots: {},         // { 'YYYYMMDD': [{ time, available }] }
  slotsDataReady: false,
  cachedFrom: 0,
  cachedTo: 0,
  scheduleBookingShortId: '',
  meetingId: '',
  isReschedule: false,
  isLoading: false,
};

export function get(key) { return store[key]; }

export function set(key, value) {
  const old = store[key];
  store[key] = value;
  emit('change:' + key, value, old);
  emit('change', key, value, old);
}

export function getAll() { return { ...store }; }

export function on(event, fn) {
  if (!listeners.has(event)) listeners.set(event, new Set());
  listeners.get(event).add(fn);
  return () => off(event, fn);
}

export function off(event, fn) {
  listeners.get(event)?.delete(fn);
}

function emit(event, ...args) {
  listeners.get(event)?.forEach(fn => {
    try { fn(...args); } catch (e) { console.error('[state]', event, e); }
  });
}

export function init(overrides = {}) {
  Object.entries(overrides).forEach(([k, v]) => {
    if (k in store) store[k] = v;
  });
}
