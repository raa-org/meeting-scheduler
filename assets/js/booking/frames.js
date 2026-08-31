/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Frame (step) navigation for the booking flow.
 * Frames: frame-1 (calendar), frame-2 (form), frame-3 (confirm email),
 *   frame-4 (confirmed), frame-5 (cancel), frame-6 (cancelled), frame-7 (slot unavailable).
 */

import * as state from 'apexianlab-booking/state';
import { $, $$ } from 'apexianlab-booking/utils';
import { loadSlots } from 'apexianlab-booking/slots';
import { setMeetingAccessToken } from 'apexianlab-booking/api';

let suppressHistoryPush = false;

/**
 * Navigate to the given frame number (1–7). Applies prev/current/next classes.
 */
export function goToFrame(targetFrame) {
  if (targetFrame === 1) {
    resetFrame2ClientDatetimeRow();
  }

  const frames = $$("section[id^='frame-']");
  frames.forEach((frame, index) => {
    const num = index + 1;
    frame.classList.remove('current', 'prev', 'next');
    if (num < targetFrame) {
      frame.classList.add('prev');
    } else if (num > targetFrame) {
      frame.classList.add('next');
    } else {
      frame.classList.add('current');
    }
  });

  state.set('frame', `frame-${targetFrame}`);

  if (!suppressHistoryPush) {
    const current = history.state && typeof history.state.raaFrame === 'number' ? history.state.raaFrame : null;
    if (current !== targetFrame) {
      history.pushState({ raaFrame: targetFrame }, '', location.href);
    }
  }

  document.dispatchEvent(new CustomEvent('apexianlab-frame-changed', { detail: { frame: targetFrame } }));
}

function prevFrame() {
  const frames = $$("section[id^='frame-']");
  const currentIndex = Array.from(frames).findIndex(f => f.classList.contains('current'));
  const current = frames[currentIndex];
  const prev = frames[currentIndex - 1];

  if (current && current.id === 'frame-2') {
    resetFrame2ClientDatetimeRow();
  }

  if (current) {
    current.classList.remove('current');
    current.classList.add('next');
  }
  if (prev) {
    prev.classList.remove('prev');
    prev.classList.add('current');
  }
}

function resetFrame2ClientDatetimeRow() {
  const row = $('.booking__frame-2 .row--datetime');
  if (!row || row.getAttribute('data-has-server-datetime') === '1') return;
  row.hidden = true;
  const dateEl = $('.details__date', row);
  const timeMain = $('.details__time-main', row);
  const timeZone = $('.details__time-zone', row);
  if (dateEl) dateEl.textContent = '';
  if (timeMain) timeMain.textContent = '';
  if (timeZone) timeZone.textContent = '';
}

function handleRescheduleFromUrl() {
  try {
    const qs = new URLSearchParams(window.location.search);
    if (qs.get('reschedule') === '1' && qs.get('meeting_id')) {
      state.set('meetingId', qs.get('meeting_id'));
      state.set('isReschedule', true);
      const frameParam = qs.get('frame');
      if (frameParam === 'confirm' || frameParam === 'confirmed') {
        return;
      }
      goToFrame(1);
    }
  } catch {
    // ignore
  }
}

function bindResendCountdowns() {
  const activeTimers = new Map();

  function startCountdown(frame) {
    const countdown = $('.countdown', frame);
    const resendBtn = $('.resend-btn', frame);
    if (activeTimers.has(frame)) return;
    activeTimers.set(frame, true);

    if (resendBtn) resendBtn.disabled = true;
    let seconds = 15;
    if (countdown) countdown.textContent = `wait for ${seconds}s to`;

    const interval = setInterval(() => {
      if (seconds > 1) {
        seconds--;
        if (countdown) countdown.textContent = `wait for ${seconds}s to`;
      } else {
        clearInterval(interval);
        if (countdown) countdown.textContent = '';
        activeTimers.delete(frame);
        if (resendBtn) resendBtn.disabled = false;
      }
    }, 1000);
  }

  const observer = new MutationObserver(() => {
    $$('section').forEach(frame => {
      if ($('.countdown', frame) && frame.classList.contains('current')) {
        startCountdown(frame);
      }
    });
  });

  observer.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
}

function bindDetailsAccordion() {
  const accordion = $('#frame-1 .details.details--accordion');
  if (!accordion) return;

  const toggle = $('.details__accordion-toggle', accordion);
  const panel = $('.details__accordion-panel', accordion);
  const mq = window.matchMedia('(max-width: 767px)');

  function sync() {
    if (!mq.matches) {
      accordion.classList.remove('details--open');
      if (toggle) toggle.setAttribute('aria-expanded', 'true');
      if (panel) panel.setAttribute('aria-hidden', 'false');
      return;
    }
    const open = accordion.classList.contains('details--open');
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (panel) panel.setAttribute('aria-hidden', open ? 'false' : 'true');
  }

  if (toggle) {
    toggle.addEventListener('click', () => {
      if (!mq.matches) return;
      accordion.classList.toggle('details--open');
      sync();
    });
  }

  window.addEventListener('resize', sync);
  sync();
}

function currentFrameNumber() {
  const frames = $$("section[id^='frame-']");
  const idx = Array.from(frames).findIndex(f => f.classList.contains('current'));
  return idx >= 0 ? idx + 1 : 1;
}

export function initFrames() {
  if (!history.state || typeof history.state.raaFrame !== 'number') {
    history.replaceState({ raaFrame: currentFrameNumber() }, '', location.href);
  }

  window.addEventListener('popstate', e => {
    const target = e.state && typeof e.state.raaFrame === 'number' ? e.state.raaFrame : 1;
    suppressHistoryPush = true;
    goToFrame(target);
    suppressHistoryPush = false;
  });

  $$('.back-button').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      history.back();
    });
  });

  $$('.header__logo').forEach(logo => {
    logo.addEventListener('click', e => {
      e.preventDefault();
      goToFrame(1);
    });
  });

  $$('.reschedule-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.dataset.newBooking === '1') {
        state.set('meetingId', '');
        state.set('isReschedule', false);
        setMeetingAccessToken('');
        const url = new URL(window.location.href);
        url.searchParams.delete('meeting_id');
        url.searchParams.delete('t');
        url.searchParams.delete('reschedule');
        url.searchParams.delete('check');
        url.searchParams.delete('frame');
        window.history.replaceState({}, '', url.toString());
        goToFrame(1);
        loadSlots();
        return;
      }
      const qs = new URLSearchParams(window.location.search);
      const meetingId = qs.get('meeting_id') || btn.getAttribute('data-meeting_id');
      const token = qs.get('t') || btn.getAttribute('data-meeting_access_token') || '';
      if (meetingId) {
        state.set('meetingId', meetingId);
        state.set('isReschedule', true);
        setMeetingAccessToken(token);
      }
      goToFrame(1);
      loadSlots();
    });
  });

  bindResendCountdowns();
  bindDetailsAccordion();
  handleRescheduleFromUrl();
}
