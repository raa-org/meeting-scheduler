/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Sidebar: schedule list, filters, actions (edit / delete / open booking link).
 */

import * as api from 'apexianlab-schedule/api';
import { refetchEvents } from 'apexianlab-schedule/fullcalendar-adapter';
import { openCreateModal, openEditModal } from 'apexianlab-schedule/schedule-modal';
import { getFilterStates, saveFilterStates } from 'apexianlab-schedule/userSettings';

function closeScheduleMenus() {
  document.querySelectorAll('.checkbox-container__menu, .checkbox-container__link-menu')
    .forEach(m => m.classList.remove('is-open'));
  document.querySelectorAll('.checkbox-container__menu-trigger, .js-schedule-link-toggle')
    .forEach(t => t.setAttribute('aria-expanded', 'false'));
}

function getScheduleVars() {
  return window.schedule_calendar_vars || {};
}

let copyToastTimeout = null;
const COPY_TOAST_HIDE_MS = 6000;
const COPY_TOAST_TRANSITION_MS = 280;

function getCopyToastEl() {
  return document.getElementById('schedule-copy-snackbar');
}

function showScheduleCopySnackbar() {
  const el = getCopyToastEl();
  if (!el) return;
  const vars = getScheduleVars();
  const textEl = el.querySelector('.schedule-copy-snackbar__text');
  if (textEl) {
    textEl.textContent =
      vars.i18n_link_copied_toast || 'Link successfully copied to clipboard';
  }
  el.removeAttribute('hidden');
  el.setAttribute('aria-hidden', 'false');
  requestAnimationFrame(() => {
    requestAnimationFrame(() => el.classList.add('is-visible'));
  });
  if (copyToastTimeout) {
    window.clearTimeout(copyToastTimeout);
  }
  copyToastTimeout = window.setTimeout(() => {
    hideScheduleCopySnackbar();
  }, COPY_TOAST_HIDE_MS);
}

function hideScheduleCopySnackbar() {
  const el = getCopyToastEl();
  if (!el) return;
  el.classList.remove('is-visible');
  if (copyToastTimeout) {
    window.clearTimeout(copyToastTimeout);
    copyToastTimeout = null;
  }
  window.setTimeout(() => {
    el.setAttribute('hidden', '');
    el.setAttribute('aria-hidden', 'true');
  }, COPY_TOAST_TRANSITION_MS);
}

function copyTextToClipboard(text) {
  if (navigator.clipboard?.writeText) {
    return navigator.clipboard.writeText(text);
  }
  return new Promise((resolve, reject) => {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      resolve();
    } catch (e) {
      reject(e);
    } finally {
      document.body.removeChild(ta);
    }
  });
}

let qrModalEl = null;

function onQrModalKeydown(e) {
  if (e.key === 'Escape') closeQrModal();
}

function closeQrModal() {
  if (qrModalEl) qrModalEl.setAttribute('hidden', '');
  document.removeEventListener('keydown', onQrModalKeydown);
}

function buildQrModal() {
  const el = document.createElement('div');
  el.className = 'apexianlab-qr-modal';
  el.setAttribute('hidden', '');
  el.innerHTML = `
    <div class="apexianlab-qr-modal__backdrop"></div>
    <div class="apexianlab-qr-modal__box" role="dialog" aria-modal="true" aria-label="Booking QR code">
      <button type="button" class="apexianlab-qr-modal__close" aria-label="Close">&times;</button>
      <h3 class="apexianlab-qr-modal__title">Booking QR code</h3>
      <p class="apexianlab-qr-modal__subtitle">Guests scan this to open your booking page.</p>
      <div class="apexianlab-qr-modal__body">
        <span class="apexianlab-qr-modal__status">Generating QR code&hellip;</span>
        <img class="apexianlab-qr-modal__img" alt="Booking QR code" hidden />
      </div>
      <a class="apexianlab-qr-modal__download" download="schedule-qr.png" hidden>Download</a>
    </div>`;
  document.body.appendChild(el);
  el.querySelector('.apexianlab-qr-modal__backdrop').addEventListener('click', closeQrModal);
  el.querySelector('.apexianlab-qr-modal__close').addEventListener('click', closeQrModal);
  return el;
}

function openQrModal(scheduleId) {
  if (!qrModalEl) qrModalEl = buildQrModal();
  const img = qrModalEl.querySelector('.apexianlab-qr-modal__img');
  const status = qrModalEl.querySelector('.apexianlab-qr-modal__status');
  const dl = qrModalEl.querySelector('.apexianlab-qr-modal__download');

  img.hidden = true;
  dl.hidden = true;
  status.hidden = false;
  status.textContent = 'Generating QR code…';
  qrModalEl.removeAttribute('hidden');
  document.addEventListener('keydown', onQrModalKeydown);

  api.getScheduleQr(scheduleId)
    .then(resp => {
      if (resp?.success && resp.data?.qr) {
        img.src = resp.data.qr;
        img.hidden = false;
        dl.href = resp.data.qr;
        dl.download = resp.data.filename || 'schedule-qr.png';
        dl.hidden = false;
        status.hidden = true;
      } else {
        status.textContent = resp?.data?.message || 'Could not generate the QR code.';
      }
    })
    .catch(() => {
      status.textContent = 'Server error. Please try again later.';
    });
}

function collectFilterStates() {
  const states = {};
  document.querySelectorAll('.menu-section__filtration input.js-schedule-filter').forEach(cb => {
    states[cb.value] = cb.checked;
  });
  const invitedCb = document.querySelector('.menu-section__filtration input.js-invited-filter');
  if (invitedCb) {
    states['__invited__'] = invitedCb.checked;
  }
  return states;
}

export function restoreFilterState() {
  const saved = getFilterStates();
  if (!saved || typeof saved !== 'object') return;

  document.querySelectorAll('.menu-section__filtration input.js-schedule-filter').forEach(cb => {
    if (Object.prototype.hasOwnProperty.call(saved, cb.value)) {
      cb.checked = !!saved[cb.value];
    }
  });
  const invitedCb = document.querySelector('.menu-section__filtration input.js-invited-filter');
  if (invitedCb && Object.prototype.hasOwnProperty.call(saved, '__invited__')) {
    invitedCb.checked = !!saved['__invited__'];
  }
}

export function initSidebar() {
  getCopyToastEl()
    ?.querySelector('.schedule-copy-snackbar__close')
    ?.addEventListener('click', (e) => {
      e.preventDefault();
      hideScheduleCopySnackbar();
    });

  // "Appointment Schedule" button → open create modal
  const createBtn = document.querySelector('.menu-section__btn');
  if (createBtn) {
    createBtn.addEventListener('click', () => openCreateModal());
  }

  // Filter checkboxes → persist state and refetch calendar events
  document.addEventListener('change', (e) => {
    if (e.target.matches('.menu-section__filtration input.js-schedule-filter')
      || e.target.matches('.menu-section__filtration input.js-invited-filter')) {
      saveFilterStates(collectFilterStates());
      refetchEvents();
    }
  });

  // Click on schedule row (anywhere except the checkbox box / menu / actions) → open booking URL in new tab.
  document.addEventListener('click', (e) => {
    const container = e.target.closest('.menu-section__filtration .checkbox-container');
    if (!container) return;
    if (e.target.closest('input, .custom-checkbox, .checkbox-container__menu, .checkbox-container__link-menu, .checkbox-container__menu-trigger, .checkbox-container__actions, .checkbox-container__copy-link')) return;
    e.preventDefault();
    const url = container.dataset.scheduleBookingUrl;
    if (!url) return;
    window.open(url, '_blank', 'noopener');
  });

  // Close menus on outside click
  document.addEventListener('click', () => closeScheduleMenus());

  // Three-dot menu trigger
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.checkbox-container__menu-trigger');
    if (!trigger) return;
    e.preventDefault();
    e.stopPropagation();

    const container = trigger.closest('.checkbox-container');
    const menu = container?.querySelector('.checkbox-container__menu');
    const isOpen = menu?.classList.contains('is-open');

    closeScheduleMenus();

    if (!isOpen && menu) {
      menu.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
    }
  });

  // Prevent click inside menu from closing it
  document.addEventListener('click', (e) => {
    if (e.target.closest('.checkbox-container__menu')) e.stopPropagation();
  });

  // Share-link icon → toggle the link dropdown (Copy link / Generate QR)
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.js-schedule-link-toggle');
    if (!trigger) return;
    e.preventDefault();
    e.stopPropagation();

    const container = trigger.closest('.checkbox-container');
    const menu = container?.querySelector('.checkbox-container__link-menu');
    const isOpen = menu?.classList.contains('is-open');

    closeScheduleMenus();

    if (!isOpen && menu) {
      menu.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
    }
  });

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.js-schedule-copy-link');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const container = btn.closest('.checkbox-container');
    const url = container?.dataset.scheduleBookingUrl || '';
    closeScheduleMenus();
    if (!url) return;
    const vars = getScheduleVars();
    copyTextToClipboard(url)
      .then(() => {
        btn.blur();
        container?.classList.add('is-suppress-row-hover');
        const clearSuppress = () => container?.classList.remove('is-suppress-row-hover');
        container?.addEventListener('pointerleave', clearSuppress, { once: true });
        showScheduleCopySnackbar();
      })
      .catch(() => {
        alert(vars.i18n_copy_failed || 'Could not copy link');
      });
  });

  // QR icon or "Generate QR" menu item → open the QR modal
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.js-schedule-qr, .js-schedule-generate-qr');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const scheduleId = btn.closest('.checkbox-container')?.dataset.scheduleId;
    closeScheduleMenus();
    if (scheduleId) openQrModal(scheduleId);
  });

  // Open booking link
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.checkbox-container__menu-item.js-schedule-open');
    if (!btn) return;
    e.preventDefault();
    const bookingUrl = btn.closest('.checkbox-container')?.dataset.scheduleBookingUrl;
    closeScheduleMenus();
    if (bookingUrl) window.open(bookingUrl, '_blank', 'noopener');
  });

  // Edit schedule
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.checkbox-container__menu-item.js-schedule-edit');
    if (!btn) return;
    e.preventDefault();
    const container = btn.closest('.checkbox-container');
    const raw = container?.dataset.schedule || '';
    closeScheduleMenus();

    let schedule = null;
    try { schedule = JSON.parse(raw); } catch { schedule = null; }

    if (!schedule?.id) {
      alert('Cannot open schedule for edit');
      return;
    }
    openEditModal(schedule);
  });

  // Delete schedule
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.checkbox-container__menu-item.js-schedule-delete');
    if (!btn) return;
    e.preventDefault();

    const container = btn.closest('.checkbox-container');
    const scheduleId = container?.dataset.scheduleId;
    if (!scheduleId) { closeScheduleMenus(); alert('Configuration error'); return; }

    if (!confirm('Delete this schedule?')) { closeScheduleMenus(); return; }

    api.deleteSchedule(scheduleId).then(resp => {
      closeScheduleMenus();
      if (resp?.success) {
        container.remove();
        if (!document.querySelector('.menu-section__filtration .checkbox-container')) {
          const filtration = document.querySelector('.menu-section__filtration');
          if (filtration) filtration.innerHTML += '<p class="filtration__empty">No schedules found.</p>';
        }
        refetchEvents();
      } else {
        alert(resp?.data?.message || 'Cannot delete this schedule');
      }
    }).catch(() => { closeScheduleMenus(); alert('Request failed'); });
  });
}
