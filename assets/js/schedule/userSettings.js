/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Persistent user preferences stored in localStorage under a single key.
 * Shape: { calendarView: string, filters: { [scheduleId]: bool, __invited__: bool } }
 */

const STORAGE_KEY = 'apexianlab_user_settings';

function load() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : {};
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch (_) {
    return {};
  }
}

function save(settings) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
  } catch (_) {}
}

export function getCalendarView() {
  return load().calendarView || null;
}

export function saveCalendarView(view) {
  const settings = load();
  settings.calendarView = view;
  save(settings);
}

export function getFilterStates() {
  return load().filters || null;
}

export function saveFilterStates(states) {
  const settings = load();
  settings.filters = states;
  save(settings);
}
