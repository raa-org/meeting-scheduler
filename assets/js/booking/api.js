/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Booking page API calls via fetch (replaces $.ajax).
 * All functions return Promise.
 */

function getVars() {
  return window.calendar_vars || {};
}

// Per-meeting HMAC access token from the current URL. Server requires it for
// any action that targets a specific booking (reschedule, cancel, resend,
// request-new-confirmation). Without it, knowing the meeting UUID would be
// enough to act on someone else's meeting. See DisplayHelper::bookingUrl().
function getMeetingAccessToken() {
  try {
    const fromUrl = new URLSearchParams(window.location.search).get('t') || '';
    if (fromUrl) return fromUrl;
  } catch {
    // ignore
  }
  return window.__raaMeetingAccessToken || '';
}

export function setMeetingAccessToken(token) {
  window.__raaMeetingAccessToken = token || '';
}

function postData(action, params = {}) {
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
  }).then(r => r.json());
}

export function getAvailableRanges({ timezone, year, month, day, duration, meetingId = '' }) {
  const vars = getVars();
  const payload = {
    timezone, year, month, day, duration,
    calendar_nonce: vars.calendar_nonce,
    booking_short_id: vars.schedule_booking_short_id,
  };
  // Reschedule mode: tell the server which meeting we're rescheduling so it
  // re-blocks the meeting's current slot in the returned booked intervals
  // (otherwise the user could "reschedule" to the same time they already
  // have). The server validates `t` against the meeting_id.
  if (meetingId) {
    payload.meeting_id = meetingId;
    payload.t = getMeetingAccessToken();
  }
  return postData('get_available_ranges', payload);
}

export function saveBookingTimezone(timezone) {
  const vars = getVars();
  return postData('save_booking_timezone', {
    timezone,
    calendar_nonce: vars.calendar_nonce,
  });
}

export function bookingRequest(data) {
  const vars = getVars();
  return postData('booking_request', {
    ...data,
    calendar_nonce: vars.calendar_nonce,
    booking_short_id: vars.schedule_booking_short_id,
  });
}

export function confirmBookingRequest(meetingId, check) {
  return postData('confirm_booking_request', { meeting_id: meetingId, check });
}

export function rescheduleMeetingRequest(data) {
  const vars = getVars();
  return postData('reschedule_meeting_request', {
    ...data,
    calendar_nonce: vars.calendar_nonce,
    booking_short_id: vars.schedule_booking_short_id,
    t: getMeetingAccessToken(),
  });
}

export function cancelMeetingRequest(meetingId) {
  const vars = getVars();
  const payload = {
    meeting_id: meetingId,
    calendar_nonce: vars.calendar_nonce,
    t: getMeetingAccessToken(),
  };
  const sid = String(vars.schedule_booking_short_id || '').trim();
  if (sid !== '') {
    payload.booking_short_id = sid;
  }
  return postData('cansel_meeting_request', payload);
}

export function requestCancelMeeting(meetingId) {
  const vars = getVars();
  const payload = {
    meeting_id: meetingId,
    calendar_nonce: vars.calendar_nonce,
    t: getMeetingAccessToken(),
  };
  const sid = String(vars.schedule_booking_short_id || '').trim();
  if (sid !== '') {
    payload.booking_short_id = sid;
  }
  return postData('request_cancel_meeting', payload);
}

export function confirmCancelMeeting(meetingId, cancelToken) {
  const vars = getVars();
  return postData('confirm_cancel_meeting', {
    meeting_id: meetingId,
    cancel_token: cancelToken,
    calendar_nonce: vars.calendar_nonce,
  });
}

export function resendConfirmed(meetingId) {
  const vars = getVars();
  return postData('resend_confirmed', {
    meeting_id: meetingId,
    calendar_nonce: vars.calendar_nonce,
    t: getMeetingAccessToken(),
  });
}

export function resendConfirmation(meetingId) {
  const vars = getVars();
  return postData('resend_confirmation', {
    meeting_id: meetingId,
    calendar_nonce: vars.calendar_nonce,
    t: getMeetingAccessToken(),
  });
}

export function requestNewConfirmationEmail(meetingId) {
  const vars = getVars();
  return postData('request_new_confirmation_email', {
    meeting_id: meetingId,
    calendar_nonce: vars.calendar_nonce,
    t: getMeetingAccessToken(),
  });
}
