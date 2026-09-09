/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Schedule page API calls via fetch (replaces $.ajax).
 * All functions return Promise.
 */

function getVars() {
  return window.schedule_calendar_vars || {};
}

let redirectingToLogin = false;
function handleAuthResponse(r) {
  if (r.status === 401 && !redirectingToLogin) {
    redirectingToLogin = true;
    window.location.reload();
    return new Promise(() => {});
  }
  return r.json();
}

function post(action, params = {}) {
  const vars = getVars();
  const body = new URLSearchParams();
  body.set('action', action);

  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null) body.set(k, String(v));
  });

  return fetch(vars.ajax_url, {
    method: 'POST',
    body,
    credentials: 'same-origin',
  }).then(handleAuthResponse);
}

export function getScheduleEvents(start, end, scheduleIds, includeInvited = true) {
  const vars = getVars();
  const body = new URLSearchParams();
  body.set('action', 'get_schedule_events');
  body.set('start', start);
  body.set('end', end);
  body.set('schedule_events_nonce', vars.schedule_events_nonce || '');
  body.set('include_invited', includeInvited ? '1' : '0');
  scheduleIds.forEach(id => body.append('schedule_ids[]', id));

  return fetch(vars.ajax_url, {
    method: 'POST',
    body,
    credentials: 'same-origin',
  }).then(handleAuthResponse);
}

export function createSchedule(data) {
  const vars = getVars();
  return post('create_schedule', {
    ...data,
    create_schedule_nonce: vars.create_schedule_nonce,
    calendar_nonce: vars.calendar_nonce || '',
  });
}

export function updateSchedule(data) {
  const vars = getVars();
  return post('update_schedule', {
    ...data,
    schedule_manage_nonce: vars.schedule_manage_nonce,
  });
}

export function deleteSchedule(id) {
  const vars = getVars();
  return post('delete_schedule', {
    id,
    schedule_manage_nonce: vars.schedule_manage_nonce,
  });
}

export function getScheduleQr(id) {
  const vars = getVars();
  return post('get_schedule_qr', {
    id,
    schedule_manage_nonce: vars.schedule_manage_nonce,
  });
}

export function cancelMeeting(meetingId, accessToken = '') {
  const vars = getVars();
  return post('cansel_meeting_request', {
    meeting_id: meetingId,
    calendar_nonce: vars.calendar_nonce,
    t: accessToken,
  });
}


export function googleCalendarStatus() {
  const vars = getVars();
  return post('google_calendar_status', {
    nonce: vars.google_calendar_nonce,
  });
}

export function googleCalendarConnectUrl() {
  const vars = getVars();
  return post('google_calendar_connect_url', {
    nonce: vars.google_calendar_nonce,
    redirect: window.location.href,
  });
}

export function googleCalendarList() {
  const vars = getVars();
  return post('google_calendar_list', {
    nonce: vars.google_calendar_nonce,
  });
}

export function googleCalendarSetCalendar(calendarId) {
  const vars = getVars();
  return post('google_calendar_set', {
    nonce: vars.google_calendar_nonce,
    calendar_id: calendarId,
  });
}

export function logout(logoutUrl) {
  const body = new URLSearchParams();
  body.set('action', 'oauth2_logout');
  body.set('nonce', (window.apexianlab_oauth2_vars || {}).logout_nonce || '');

  return fetch(logoutUrl, {
    method: 'POST',
    body,
    credentials: 'same-origin',
  }).then(r => r.json());
}
