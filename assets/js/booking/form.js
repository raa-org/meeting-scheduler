/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Booking form submission, captcha, slot click handling, cancel/reschedule/resend flows.
 * Ported from calendar_ajax.js.
 */

import * as state from 'apexianlab-booking/state';
import * as api from 'apexianlab-booking/api';
import { $, $$, startLoad, endLoad, formatDetailsDate, formatTimeRange, buildTimezoneLabel } from 'apexianlab-booking/utils';
import { goToFrame } from 'apexianlab-booking/frames';
import { loadSlots } from 'apexianlab-booking/slots';

function showAlert(msg) {
  const alertEl = $('#booking-alert');
  if (alertEl) {
    const frame1 = $('#frame-1');
    const row = $('#frame-1 .booking__calendar-slots-row');
    const isFrame1Active = frame1 && frame1.classList.contains('current');

    if (isFrame1Active && row) {
      if (!row.contains(alertEl)) row.appendChild(alertEl);
      row.classList.add('booking__calendar-slots-row--alert');
      row.style.display = 'flex';
      alertEl.classList.add('booking__alert--inline');
      alertEl.textContent = msg;
      alertEl.style.display = '';
      return;
    }

    if (alertEl.parentElement !== document.body) {
      document.body.appendChild(alertEl);
    }
    alertEl.classList.remove('booking__alert--inline');
    alertEl.textContent = msg;
    alertEl.style.display = '';
  } else {
    alert(msg);
  }
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function setFieldError(field, msg) {
  if (!field) return;
  field.classList.add('error');
  const alertEl = field.querySelector('.field__alert');
  if (alertEl) alertEl.textContent = msg;
}

function clearFieldError(field) {
  if (!field) return;
  field.classList.remove('error');
  const alertEl = field.querySelector('.field__alert');
  if (alertEl) alertEl.textContent = '';
}

function validateForm(form) {
  let firstInvalid = null;
  const inputs = form.querySelectorAll('.booking__form-field input, .booking__form-field textarea');
  inputs.forEach(input => {
    const field = input.closest('.booking__form-field');
    if (!field || !field.querySelector('.field__alert')) return;
    clearFieldError(field);
    if (!input.required) return;
    const value = (input.value || '').trim();
    if (!value) {
      setFieldError(field, 'This field is required');
      if (!firstInvalid) firstInvalid = input;
      return;
    }
    if (input.type === 'email' && !EMAIL_RE.test(value)) {
      setFieldError(field, 'Please enter a valid email address');
      if (!firstInvalid) firstInvalid = input;
      return;
    }
    if (input.name === 'full_name' && value.length < 2) {
      setFieldError(field, 'Name must be at least 2 characters');
      if (!firstInvalid) firstInvalid = input;
      return;
    }
    if (input.name === 'phone') {
      const digits = value.replace(/\D/g, '');
      if (!/^[+\d][\d\s().-]*$/.test(value) || digits.length < 7 || digits.length > 15) {
        setFieldError(field, 'Please enter a valid phone number');
        if (!firstInvalid) firstInvalid = input;
      }
    }
  });
  if (firstInvalid && typeof firstInvalid.focus === 'function') firstInvalid.focus();
  return !firstInvalid;
}

function bindFieldClear(form) {
  form.querySelectorAll('.booking__form-field').forEach(field => {
    if (!field.querySelector('.field__alert')) return;
    const input = field.querySelector('input, textarea');
    if (!input) return;
    const clear = () => {
      clearFieldError(field);
      if (input.name === 'code') setCaptchaError(false);
    };
    input.addEventListener('input', clear);
    input.addEventListener('change', clear);
  });
}

function setCaptchaError(isError) {
  const codeAlert = $('#code-alert');
  const captchaField = $(".booking__form-field input[name='code']");

  if (isError) {
    if (captchaField) captchaField.closest('.booking__form-field')?.classList.add('error');
    if (codeAlert) codeAlert.style.display = '';
  } else {
    if (captchaField) captchaField.closest('.booking__form-field')?.classList.remove('error');
    if (codeAlert) codeAlert.style.display = 'none';
  }
}

function refreshCaptcha(img) {
  if (!img) return;
  const link = img.getAttribute('src') || '';
  if (!link) return;
  try {
    const url = new URL(link, window.location.origin);
    url.searchParams.set('r', String(Math.random()));
    img.setAttribute('src', url.toString());
  } catch {
    // ignore
  }
}

function setDetailsTimeValue(container, timeText) {
  const value = String(timeText || '');
  const parts = value.split('\n');
  const main = parts[0] ? parts[0].trim() : '';
  const zone = parts[1] ? parts.slice(1).join('\n').trim() : '';
  const timeEl = container ? $('.details__time', container) : null;
  if (!timeEl) return;
  const mainEl = $('.details__time-main', timeEl);
  const zoneEl = $('.details__time-zone', timeEl);
  if (mainEl && zoneEl) {
    mainEl.textContent = main;
    zoneEl.textContent = zone;
  } else {
    timeEl.textContent = value;
  }
}

function getDetailsTimeValue(container) {
  const timeEl = container ? $('.details__time', container) : null;
  if (!timeEl) return '';
  const mainEl = $('.details__time-main', timeEl);
  const zoneEl = $('.details__time-zone', timeEl);
  if (mainEl && zoneEl) {
    const main = (mainEl.textContent || '').trim();
    const zone = (zoneEl.textContent || '').trim();
    return zone ? `${main}\n${zone}` : main;
  }
  return (timeEl.textContent || '').trim();
}

function setPlatformWithJoinLink(container, platformText, joinUrl) {
  const platform = container ? $('.details__platform', container) : null;
  if (!platform) return;
  const text = String(platformText || '');
  const url = String(joinUrl || '').trim();
  if (url) {
    const escapedUrl = url.replace(/"/g, '&quot;');
    platform.innerHTML = `${text}<button class="join-link-btn text-btn" type="button" data-link="${escapedUrl}">Join Link</button>`;
  } else {
    platform.textContent = text;
  }
}

function getPlatformLocationText(platformEl) {
  if (!platformEl) return '';
  const clone = platformEl.cloneNode(true);
  const joinBtn = clone.querySelector('.join-link-btn');
  if (joinBtn) joinBtn.remove();
  return (clone.textContent || '').trim();
}

function hasAnyAvailableSlot() {
  if (!state.get('slotsDataReady')) return false;
  const dateSlots = state.get('dateSlots') || {};
  const y = state.get('selectedYear');
  const m = state.get('selectedMonth');
  const d = state.get('selectedDay');
  if (y == null || m == null || d == null) return false;
  const key = String(y) + String(m).padStart(2, '0') + String(d).padStart(2, '0');
  const list = dateSlots[key];
  return Array.isArray(list) && list.some(s => s && s.available);
}

function updateFrame1DetailsDate() {
  const y = state.get('selectedYear');
  const m = state.get('selectedMonth');
  const d = state.get('selectedDay');
  const timezone = state.get('timezone');
  const dateObj = y != null ? { year: y, month: (m || 1) - 1, day: d || 1 } : null;
  const dateEl = $('#frame-1 .details .details__date');
  const headingEl = $('#frame-1 .details .details__meeting-heading');

  const hasAvail = hasAnyAvailableSlot();
  if (dateEl) {
    dateEl.textContent = hasAvail ? formatDetailsDate(dateObj, timezone) : '';
    dateEl.style.display = hasAvail ? '' : 'none';
  }
  if (headingEl) headingEl.style.display = hasAvail ? '' : 'none';
}

/**
 * Apply a slot selection: update state, refresh frame-2 datetime details.
 * dateObj.month is 0-based (Date constructor convention); state stores month as 1-based.
 */
export function applySlotSelection(dateObj, slotTime) {
  state.set('selectedTime', slotTime);
  state.set('selectedYear', dateObj.year);
  state.set('selectedMonth', dateObj.month + 1);
  state.set('selectedDay', dateObj.day);

  const timezone = state.get('timezone');
  const duration = state.get('duration');

  const frame2DateEl = $('.booking__frame-2 .details__date');
  if (frame2DateEl) {
    const tm = slotTime?.trim().match(/^(\d{1,2}):(\d{2})\s*(am|pm)$/i);
    let crossesMidnight = false;
    if (tm && duration) {
      let sh = parseInt(tm[1], 10);
      const sm = parseInt(tm[2], 10);
      if (tm[3].toLowerCase() === 'pm' && sh !== 12) sh += 12;
      if (tm[3].toLowerCase() === 'am' && sh === 12) sh = 0;
      crossesMidnight = (sh * 60 + sm + duration) >= 24 * 60;
    }
    if (crossesMidnight) {
      const startStr = formatDetailsDate(dateObj, timezone);
      const nextDay = new Date(Date.UTC(dateObj.year, dateObj.month, dateObj.day + 1));
      const nextObj = { year: nextDay.getUTCFullYear(), month: nextDay.getUTCMonth(), day: nextDay.getUTCDate() };
      frame2DateEl.textContent = `${startStr} – ${formatDetailsDate(nextObj, timezone)}`;
    } else {
      frame2DateEl.textContent = formatDetailsDate(dateObj, timezone);
    }
  }

  const tzLabel = buildTimezoneLabel(timezone, dateObj);
  const timeValue = formatTimeRange(slotTime, duration, dateObj);
  const frame2Time = $('.booking__frame-2 .details__time');
  if (frame2Time) {
    const mainEl = $('.details__time-main', frame2Time);
    const zoneEl = $('.details__time-zone', frame2Time);
    if (mainEl && zoneEl) {
      mainEl.textContent = timeValue;
      zoneEl.textContent = tzLabel;
    } else {
      frame2Time.textContent = `${timeValue}\n${tzLabel}`;
    }
  }

  const row = $('.booking__frame-2 .row--datetime');
  if (row) row.hidden = false;
}

function bindSlotClick() {
  document.addEventListener('click', e => {
    const slotEl = e.target.closest('.slots__item');
    if (!slotEl) return;
    if (slotEl.dataset.available === 'false') {
      e.preventDefault();
      e.stopPropagation();
      return;
    }

    const span = slotEl.querySelector('span');
    const slotTime = span ? span.textContent.trim() : slotEl.textContent.trim();

    // Resolve date from data-attributes (week view) or from state
    const slotY = slotEl.dataset.slotYear;
    let dateObj;
    if (slotY != null && slotY !== '') {
      dateObj = {
        year: parseInt(slotY, 10),
        month: parseInt(slotEl.dataset.slotMonth, 10),
        day: parseInt(slotEl.dataset.slotDay, 10),
      };
    } else {
      dateObj = {
        year: state.get('selectedYear'),
        month: (state.get('selectedMonth') || 1) - 1,
        day: state.get('selectedDay'),
      };
    }

    applySlotSelection(dateObj, slotTime);
    refreshCaptcha($('#code'));
    goToFrame(2);
  });
}

function bindFormSubmit() {
  const form = $('.booking__form');
  if (!form) return;

  bindFieldClear(form);

  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (!validateForm(form)) return;
    setCaptchaError(false);

    const submitBtn = form.querySelector('.booking__form-btn');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.classList.add('booking__form-btn--loading');
    }

    const fullName = (form.querySelector("input[name='full_name']")?.value || '').trim();
    const spaceIdx = fullName.indexOf(' ');
    const firstName = spaceIdx === -1 ? fullName : fullName.substring(0, spaceIdx);
    const lastName = spaceIdx === -1 ? '' : fullName.substring(spaceIdx + 1).trim();

    const isReschedule = state.get('isReschedule');
    const rescheduleMeetingId = isReschedule ? state.get('meetingId') : '';

    const payload = {
      first_name: firstName,
      last_name: lastName,
      email: form.querySelector("input[name='email']")?.value || '',
      phone: form.querySelector("input[name='phone']")?.value || '',
      subject: form.querySelector("input[name='subject']")?.value || '',
      description: (form.querySelector("textarea[name='description']") || form.querySelector("input[name='description']"))?.value || '',
      code: form.querySelector("input[name='code']")?.value || '',
      timezone: state.get('timezone'),
      year: state.get('selectedYear'),
      month: state.get('selectedMonth'),
      day: state.get('selectedDay'),
      time: state.get('selectedTime'),
      duration: state.get('duration'),
    };

    if (rescheduleMeetingId) {
      payload.meeting_id = rescheduleMeetingId;
    }

    startLoad();
    try {
      const response = rescheduleMeetingId
        ? await api.rescheduleMeetingRequest(payload)
        : await api.bookingRequest(payload);

      if (response.success) {
        setCaptchaError(false);
        const data = response.data;

        if (data?.frame === 'confirmed') {
          applyConfirmedData(data);
          state.set('meetingId', '');
          state.set('isReschedule', false);
          goToFrame(4);
          return;
        }

        if (data?.frame === 'confirm') {
          const mid = data.meeting_id;
          const confirmUrl = data.redirect?.length > 0
            ? data.redirect
            : mid ? buildConfirmUrl(mid) : null;
          if (confirmUrl) {
            window.location.replace(confirmUrl);
            return;
          }
        }

        applyPendingConfirmData(data);
        goToFrame(3);
      } else {
        handleBookingError(response, rescheduleMeetingId);
      }
    } catch {
      // network error
    } finally {
      const codeInput = form.querySelector("input[name='code']");
      if (codeInput) codeInput.value = '';
      refreshCaptcha($('#code'));
      endLoad();
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('booking__form-btn--loading');
      }
    }
  });
}

function buildConfirmUrl(meetingId) {
  const u = new URL(window.location.href);
  u.searchParams.set('meeting_id', String(meetingId));
  u.searchParams.delete('check');
  u.searchParams.delete('reschedule');
  u.searchParams.set('frame', 'confirm');
  return u.toString();
}

function applyConfirmedData(data) {
  const frame4 = $('.booking__frame-4');
  if (!frame4) return;

  if (data?.is_reschedule) {
    const h1 = frame4.querySelector('h1');
    const p = frame4.querySelector('p');
    if (h1) h1.textContent = 'Reschedule confirmed';
    if (p) p.innerHTML = 'Your meeting has been successfully rescheduled.<br>We\'ve also sent you an email with all the details.';
  }

  const name = $('.details__name', frame4);
  const date = $('.details__date', frame4);
  if (name) name.textContent = data.name;
  if (date) date.textContent = data.date;
  setDetailsTimeValue(frame4, data.time);
  setPlatformWithJoinLink(frame4, data.location, data.meeting_join_url || '');

  const tok = data.meeting_access_token || '';
  const applyMeetingAttrs = (el) => {
    if (!el) return;
    el.setAttribute('data-meeting_id', data.meeting_id);
    if (tok) el.setAttribute('data-meeting_access_token', tok);
  };

  applyMeetingAttrs($('.resend-btn', frame4));
  applyMeetingAttrs($('.cancel-btn', frame4));

  if (data.meeting_id) {
    const mid = String(data.meeting_id);
    $$('.reschedule-btn').forEach(btn => applyMeetingAttrs(btn));
    if (tok) api.setMeetingAccessToken(tok);
    const url = new URL(window.location.href);
    url.searchParams.set('meeting_id', mid);
    url.searchParams.delete('check');
    window.history.replaceState({}, '', url.toString());
  }
}

function applyPendingConfirmData(data) {
  const frame3 = $('.booking__frame-3');
  const frame4 = $('.booking__frame-4');
  const tok = data.meeting_access_token || '';
  const apply = (el) => {
    if (!el) return;
    el.setAttribute('data-meeting_id', data.meeting_id);
    if (tok) el.setAttribute('data-meeting_access_token', tok);
  };

  if (frame3) {
    const name3 = $('.details__name', frame3);
    const date3 = $('.details__date', frame3);
    if (name3) name3.textContent = data.name;
    if (date3) date3.textContent = data.date;
    setDetailsTimeValue(frame3, data.time);
    const platform3 = $('.details__platform', frame3);
    if (platform3) platform3.textContent = data.location;
    apply($('.resend-btn', frame3));
  }

  if (frame4) {
    const name4 = $('.details__name', frame4);
    const date4 = $('.details__date', frame4);
    if (name4) name4.textContent = data.name;
    if (date4) date4.textContent = data.date;
    setDetailsTimeValue(frame4, data.time);
    setPlatformWithJoinLink(frame4, data.location, data.meeting_join_url || '');
    apply($('.resend-btn', frame4));
    apply($('.cancel-btn', frame4));
    if (data.meeting_id) {
      $$('.reschedule-btn').forEach(btn => apply(btn));
    }
  }

  if (data.meeting_id && tok) api.setMeetingAccessToken(tok);

  const url = new URL(window.location.href);
  if (data.meeting_id) {
    url.searchParams.set('meeting_id', String(data.meeting_id));
  } else {
    url.searchParams.delete('meeting_id');
  }
  url.searchParams.delete('check');
  url.searchParams.set('frame', 'confirm');
  window.history.replaceState({}, '', url.toString());

  if (frame3) {
    const loader = $('.booking__confirm-loader', frame3);
    const content = $('.booking__confirm-content', frame3);
    if (loader) loader.style.display = 'none';
    if (content) content.style.display = '';
  }
}

function handleBookingError(response, rescheduleMeetingId) {
  const msg = response.data?.message || 'Error';
  const code = response.data?.code || '';
  if (msg !== 'Invalid code.') setCaptchaError(false);

  if (code === 'slot_not_available') {
    goToFrame(7);
  } else if (code === 'schedule_unavailable') {
    window.location.reload();
  } else if (msg === 'Invalid code.') {
    setCaptchaError(true);
  } else if (rescheduleMeetingId && msg === 'Meeting not found.') {
    showAlert('This meeting no longer exists. It may have been cancelled.');
  } else {
    showAlert(msg);
  }
}

function bindEmailConfirmation() {
  const loader = $('.booking__confirm-loader');
  if (!loader) return;
  const meetingId = String(loader.dataset.meeting_id || '');
  const check = String(loader.dataset.check || '');
  if (!meetingId || !check) return;

  api.confirmBookingRequest(meetingId, check).then(resp => {
    if (resp?.success && resp.data?.redirect) {
      window.location.href = String(resp.data.redirect);
      return;
    }
    if (resp?.data?.code === 'slot_not_available') {
      goToFrame(7);
      return;
    }
    showAlert(resp?.data?.message || 'Confirmation failed.');
  }).catch(() => {
    showAlert('Server error. Please try again later.');
  });
}

function bindCancelFromFrame4() {
  document.addEventListener('click', async e => {
    const btn = e.target.closest('#frame-4 .cancel-btn');
    if (!btn) return;
    e.preventDefault();

    const meetingId = btn.getAttribute('data-meeting_id') || btn.dataset.meeting_id;
    const frame4Details = $('#frame-4 .final-details');
    const frame5 = $('#frame-5');

    if (frame4Details && frame5) {
      const name5 = $('.details__name', frame5);
      const date5 = $('.details__date', frame5);
      if (name5) name5.textContent = ($('.details__name', frame4Details)?.textContent || '');
      if (date5) date5.textContent = ($('.details__date', frame4Details)?.textContent || '');
      setDetailsTimeValue(frame5, getDetailsTimeValue(frame4Details));

      const platform4 = $('.details__platform', frame4Details);
      if (platform4) {
        const platform5 = $('.details__platform', frame5);
        if (platform5) {
          platform5.className = platform4.className;
          platform5.textContent = getPlatformLocationText(platform4);
        }
      }
    }

    if (meetingId) {
      const cancelBtn = $('.cancel-meeting-btn', frame5);
      if (cancelBtn) cancelBtn.setAttribute('data-meeting_id', meetingId);
    }

    goToFrame(5);
  });
}

function bindCancelFromFrame5() {
  document.addEventListener('click', async e => {
    const btn = e.target.closest('#frame-5 .cancel-meeting-btn');
    if (!btn) return;

    const meetingId = btn.getAttribute('data-meeting_id') || btn.dataset.meeting_id;
    if (!meetingId) return;

    const cancelToken = new URLSearchParams(window.location.search).get('cancel_token') || '';
    const bookingForm = document.querySelector('.booking__form');
    const requireEmailVerification = bookingForm ? bookingForm.dataset.requireEmailVerification === '1' : false;

    btn.disabled = true;
    btn.classList.add('cancel-meeting-btn--loading');
    startLoad();

    try {
      if (cancelToken) {
        // Arrived via the emailed link — apply the cancellation.
        const response = await api.confirmCancelMeeting(meetingId, cancelToken);
        if (response.success) {
          if (response.data && response.data.already) {
            // Already cancelled: show the cancelled frame with details.
            copyDetailsToFrame6();
            showFrame5State('already');
          } else {
            copyDetailsToFrame6();
            goToFrame(6);
            loadSlots();
          }
        } else if (response.data && response.data.code === 'invalid') {
          showFrame5State('expired');
        } else {
          showAlert(response.data?.message || 'Error');
        }
      } else if (!requireEmailVerification) {
        // Schedule does not require email verification — cancel directly.
        const response = await api.cancelMeetingRequest(meetingId);
        if (response.success) {
          copyDetailsToFrame6();
          goToFrame(6);
          loadSlots();
        } else {
          showAlert(response.data?.message || 'Error');
          goToFrame(1);
        }
      } else {
        // Email verification required — email a confirmation link, do not cancel yet.
        const response = await api.requestCancelMeeting(meetingId);
        if (response.success) {
          showFrame5State('sent');
        } else {
          showAlert(response.data?.message || 'Error');
        }
      }
    } catch {
      // network error
    } finally {
      endLoad();
      btn.disabled = false;
      btn.classList.remove('cancel-meeting-btn--loading');
    }
  });
}

// Auto-apply the cancellation when the page boots on frame-5 via the emailed
// confirm-cancellation link (frame=5 + cancel_token) — mirrors the booking
// email-confirm loader so the user no longer has to click "Cancel meeting" a
// second time. In-page cancels (from frame-4) carry no cancel_token and still
// show the prompt.
function bindCancelConfirmation() {
  const frame5 = $('#frame-5');
  if (!frame5) return;
  const params = new URLSearchParams(window.location.search);
  if ((params.get('frame') || '') !== '5') return;
  const cancelToken = params.get('cancel_token') || '';
  if (!cancelToken) return;
  const cancelBtn = $('.cancel-meeting-btn', frame5);
  // The "already cancelled" state renders no button — nothing to confirm.
  if (!cancelBtn) return;
  const meetingId = cancelBtn.getAttribute('data-meeting_id') || cancelBtn.dataset.meeting_id || '';
  if (!meetingId) return;

  showFrame5State('cancelling');
  startLoad();
  api.confirmCancelMeeting(meetingId, cancelToken).then(response => {
    if (response.success) {
      copyDetailsToFrame6();
      if (response.data && response.data.already) {
        showFrame5State('already');
      } else {
        goToFrame(6);
        loadSlots();
      }
    } else if (response.data && response.data.code === 'invalid') {
      showFrame5State('expired');
    } else {
      showAlert(response.data?.message || 'Error');
      showFrame5State('prompt');
    }
  }).catch(() => {
    // Network error — fall back to the manual prompt so the user can retry.
    showFrame5State('prompt');
  }).finally(() => {
    endLoad();
  });
}

let frame5ResendTimer = null;

// 15s cooldown on the frame-5 "resend cancellation email" button,
// matching the resend countdown used by the other confirmation frames.
function startFrame5ResendCountdown() {
  const frame5 = $('#frame-5');
  if (!frame5) return;
  const countdown = $('.frame5-resend .frame5-countdown', frame5);
  const resendBtn = $('.frame5-resend .resend-btn', frame5);
  if (frame5ResendTimer) clearInterval(frame5ResendTimer);
  let seconds = 15;
  if (resendBtn) resendBtn.disabled = true;
  if (countdown) countdown.textContent = `wait for ${seconds}s to`;
  frame5ResendTimer = setInterval(() => {
    if (seconds > 1) {
      seconds--;
      if (countdown) countdown.textContent = `wait for ${seconds}s to`;
    } else {
      clearInterval(frame5ResendTimer);
      frame5ResendTimer = null;
      if (countdown) countdown.textContent = '';
      if (resendBtn) resendBtn.disabled = false;
    }
  }, 1000);
}

function bindResendCancelConfirmation() {
  document.addEventListener('click', async e => {
    const btn = e.target.closest('#frame-5 .frame5-resend .resend-btn');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    e.stopPropagation();
    const meetingId = btn.getAttribute('data-meeting_id') || btn.dataset.meeting_id;
    if (!meetingId) return;

    startLoad();
    try {
      const response = await api.requestCancelMeeting(meetingId);
      if (response.success) {
        startFrame5ResendCountdown();
      } else {
        showAlert(response.data?.message || 'Error');
      }
    } catch {
      // network error
    } finally {
      endLoad();
    }
  });
}

// Swap frame-5 between the prompt / "check your email" / "expired" states.
function showFrame5State(stateName) {
  const frame5 = $('#frame-5');
  if (!frame5) return;
  const groups = {
    prompt: ['.frame5-prompt-title', '.frame5-prompt-text'],
    cancelling: ['.frame5-cancelling-title', '.frame5-cancelling-text', '.frame5-spinner'],
    sent: ['.frame5-sent-title', '.frame5-sent-text', '.frame5-resend'],
    expired: ['.frame5-expired-title', '.frame5-expired-text'],
  };
  Object.entries(groups).forEach(([key, selectors]) => {
    selectors.forEach(sel => {
      const el = $(sel, frame5);
      if (el) el.hidden = key !== stateName;
    });
  });
  const btn = $('.cancel-meeting-btn', frame5);
  // Keep the button on the "expired" state (re-request a link); hide it while
  // the email is out ("sent") or while auto-cancelling from the email link.
  if (btn) btn.hidden = stateName === 'sent' || stateName === 'cancelling';
  if (stateName === 'sent') {
    // Email just went out — put the resend button on the same 15s cooldown
    // used by the other confirmation frames.
    startFrame5ResendCountdown();
  }
  if (stateName === 'expired') {
    // Drop the dead token from the URL so the next button click falls through
    // to requestCancelMeeting (emails a fresh link) instead of re-confirming
    // the expired one — otherwise the user is trapped on "Link expired".
    try {
      const url = new URL(window.location.href);
      url.searchParams.delete('cancel_token');
      window.history.replaceState({}, '', url);
    } catch {
      // ignore
    }
  }
  if (stateName === 'already') {
    // No dedicated markup — fall back to the cancelled frame.
    goToFrame(6);
  }
}

// Mirror frame-5 meeting details onto frame-6 before showing it.
function copyDetailsToFrame6() {
  const frame5Details = $('#frame-5 .final-details');
  const frame6Details = $('#frame-6 .final-details');
  if (!frame5Details || !frame6Details) return;
  const name6 = $('.details__name', frame6Details);
  const date6 = $('.details__date', frame6Details);
  if (name6) name6.textContent = ($('.details__name', frame5Details)?.textContent || '');
  if (date6) date6.textContent = ($('.details__date', frame5Details)?.textContent || '');
  setDetailsTimeValue(frame6Details, getDetailsTimeValue(frame5Details));
  const platform5 = $('.details__platform', frame5Details);
  if (platform5) {
    const platform6 = $('.details__platform', frame6Details);
    if (platform6) {
      platform6.className = platform5.className;
      platform6.textContent = getPlatformLocationText(platform5);
    }
  }
}

function bindResendConfirmed() {
  document.addEventListener('click', async e => {
    const btn = e.target.closest('.booking__frame-4 .resend-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const meetingId = btn.getAttribute('data-meeting_id') || btn.dataset.meeting_id;
    if (!meetingId) return;

    startLoad();
    try {
      const resp = await api.resendConfirmed(meetingId);
      showAlert(resp.data?.message || (resp.success ? 'Sent' : 'Error'));
    } catch {
      // ignore
    } finally {
      endLoad();
    }
  });
}

function bindResendConfirmation() {
  document.addEventListener('click', async e => {
    const btn = e.target.closest('.booking__frame-3 .resend-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const meetingId = btn.getAttribute('data-meeting_id') || btn.dataset.meeting_id;
    if (!meetingId) return;

    startLoad();
    try {
      const resp = await api.resendConfirmation(meetingId);
      showAlert(resp.data?.message || (resp.success ? 'Sent' : 'Error'));
    } catch {
      // ignore
    } finally {
      endLoad();
    }
  });
}

function bindRequestNewConfirmation() {
  document.addEventListener('click', async e => {
    const btn = e.target.closest('.booking__frame-3 .request-new-confirmation');
    if (!btn) return;
    e.preventDefault();
    const meetingId = btn.dataset.meeting_id;
    if (!meetingId) return;

    btn.classList.add('disabled');
    startLoad();
    try {
      const resp = await api.requestNewConfirmationEmail(meetingId);
      if (resp.success) {
        showAlert(resp.data?.message || 'New confirmation email sent. Please check your inbox.');
        setTimeout(() => window.location.reload(), 1200);
      } else {
        showAlert(resp.data?.message || 'Something went wrong.');
      }
    } catch {
      showAlert('Request failed');
    } finally {
      endLoad();
      btn.classList.remove('disabled');
    }
  });
}

function bindJoinLink() {
  document.addEventListener('click', e => {
    const btn = e.target.closest('.join-link-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const url = (btn.getAttribute('data-link') || btn.dataset.link || '').trim();
    if (url) window.open(url, '_blank', 'noopener');
  });
}

function bindSelectNewTime() {
  document.addEventListener('click', e => {
    const btn = e.target.closest('.select-new-time-btn');
    if (!btn) return;
    e.preventDefault();
    goToFrame(1);
    loadSlots();
  });
}

function bindCaptchaClick() {
  const codeImg = $('#code');
  if (codeImg) {
    codeImg.addEventListener('click', () => refreshCaptcha(codeImg));
  }
}

export function initForm() {
  bindSlotClick();
  bindFormSubmit();
  bindEmailConfirmation();
  bindCancelFromFrame4();
  bindCancelFromFrame5();
  bindCancelConfirmation();
  bindResendCancelConfirmation();
  bindResendConfirmed();
  bindResendConfirmation();
  bindRequestNewConfirmation();
  bindJoinLink();
  bindSelectNewTime();
  bindCaptchaClick();

  state.on('change:selectedDay', updateFrame1DetailsDate);
  state.on('change:timezone', updateFrame1DetailsDate);
  state.on('change:dateSlots', updateFrame1DetailsDate);
  state.on('change:slotsDataReady', updateFrame1DetailsDate);

  updateFrame1DetailsDate();
}
