/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Observable store for schedule page state.
 * Usage: state.set('visibleScheduleIds', [1,2]); state.on('change:visibleScheduleIds', cb);
 */

const listeners = new Map();

const store = {
  schedules: [],
  visibleScheduleIds: [],
  calendarInstance: null,
  editingScheduleId: null,
  missingGoogleScopes: [],
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
