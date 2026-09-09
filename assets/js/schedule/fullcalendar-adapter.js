/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * FullCalendar initialization and event popover.
 */

import * as state from 'apexianlab-schedule/state';
import * as api from 'apexianlab-schedule/api';
import { getCalendarView, saveCalendarView } from 'apexianlab-schedule/userSettings';

let eventPopoverEl = null;

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = String(str || '');
  return div.innerHTML;
}

function closeEventPopover() {
  if (eventPopoverEl) {
    eventPopoverEl.remove();
    eventPopoverEl = null;
  }
}

function formatPopoverTimeRange(start, end) {
  try {
    const dateFmt = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
    const timeFmt = new Intl.DateTimeFormat('en-US', { hour: '2-digit', minute: '2-digit' });
    const dayKeyFmt = new Intl.DateTimeFormat('en-CA'); // YYYY-MM-DD in local tz
    const tzLabel = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const startStr = timeFmt.format(start);
    const endStr = end ? timeFmt.format(end) : '';
    const timeRange = endStr ? `${startStr} – ${endStr}` : startStr;
    const crossesMidnight = end && dayKeyFmt.format(start) !== dayKeyFmt.format(end);
    const dateStr = crossesMidnight
      ? `${dateFmt.format(start)} – ${dateFmt.format(end)}`
      : dateFmt.format(start);
    return { date: dateStr, timeWithTz: `${timeRange} (${tzLabel})` };
  } catch {
    return null;
  }
}

function buildPopoverRow(label, value) {
  if (!value) return '';
  return `<div class="gc-popover__row"><span class="gc-popover__label">${escapeHtml(label)}</span><span class="gc-popover__value">${value}</span></div>`;
}

function openEventPopover(info) {
  closeEventPopover();
  const ev = info.event;
  const props = ev.extendedProps || {};
  const meetingId = props.meeting_id ? String(props.meeting_id) : String(ev.id || '');

  if (!meetingId || String(ev.id || '').startsWith('range_')) return;

  const title = props.subject ? String(props.subject) : String(ev.title || '');
  const when = formatPopoverTimeRange(ev.start, ev.end);
  const location = props.location ? String(props.location) : '';
  const joinUrl = props.meeting_join_url ? String(props.meeting_join_url) : '';
  const attendeeName = props.attendee_name ? String(props.attendee_name) : '';
  const attendeeEmail = props.attendee_email ? String(props.attendee_email) : '';
  const organiserName = props.organiser_name ? String(props.organiser_name) : '';
  const organiserEmail = props.organiser_email ? String(props.organiser_email) : '';
  const phone = props.phone ? String(props.phone).trim() : '';
  const desc = props.description ? String(props.description) : '';
  const accessToken = String(props.meeting_access_token || '');
  const rescheduleUrl = String(props.reschedule_url || '');

  // Show the *other* party: as the schedule owner you see the attendee; when
  // you are the attendee yourself (is_guest), you see the organiser instead.
  const isGuest = !!props.is_guest;
  const personLabel = isGuest ? 'Organiser' : 'Attendee';
  const personName = isGuest ? organiserName : attendeeName;
  const personEmail = isGuest ? organiserEmail : attendeeEmail;

  let attendeeRow = '';
  if (personName || personEmail) {
    const nameHtml = escapeHtml(personName);
    const emailHtml = personEmail
      ? `<a href="mailto:${escapeHtml(personEmail)}">${escapeHtml(personEmail)}</a>`
      : '';
    const spacer = nameHtml && emailHtml ? ' ' : '';
    attendeeRow = `<div class="gc-popover__row"><span class="gc-popover__label">${personLabel}</span>` +
      `<span class="gc-popover__value">${nameHtml}${spacer}${emailHtml}</span></div>`;
  }

  const whenHtml = when && when.date && when.timeWithTz
    ? buildPopoverRow('When', `<div>${escapeHtml(when.date)}</div><div>${escapeHtml(when.timeWithTz)}</div>`)
    : '';

  const pop = document.createElement('div');
  pop.className = 'gc-popover';
  pop.setAttribute('role', 'dialog');
  pop.setAttribute('aria-modal', 'false');
  pop.innerHTML = `
    <div class="gc-popover__header">
      <div class="gc-popover__title">${escapeHtml(title || '(No title)')}</div>
      <button type="button" class="gc-popover__close" aria-label="Close" title="Close">×</button>
    </div>
    <div class="gc-popover__body">
      ${whenHtml}
      ${location ? buildPopoverRow('Location', escapeHtml(location)) : ''}
      ${attendeeRow}
      ${!isGuest && phone ? buildPopoverRow('Phone', escapeHtml(phone)) : ''}
      ${desc ? `<div class="gc-popover__row gc-popover__row--desc"><span class="gc-popover__label">Description</span><div class="gc-popover__value">${escapeHtml(desc)}</div></div>` : ''}
      ${joinUrl ? buildPopoverRow('Join', `<a href="${escapeHtml(joinUrl)}" target="_blank" rel="noopener">Open link</a>`) : ''}
    </div>
    <div class="gc-popover__footer">
      <button type="button" class="reschedule-btn gc-popover__reschedule" data-meeting-id="${escapeHtml(meetingId)}" data-reschedule-url="${escapeHtml(rescheduleUrl)}">Reschedule</button>
      <button type="button" class="cancel-btn gc-popover__cancel" data-meeting-id="${escapeHtml(meetingId)}" data-meeting-access-token="${escapeHtml(accessToken)}">Cancel</button>
    </div>`;

  document.body.appendChild(pop);

  const rect = info.el ? info.el.getBoundingClientRect() : null;
  const vw = window.innerWidth || document.documentElement.clientWidth;
  const vh = window.innerHeight || document.documentElement.clientHeight;
  const popW = pop.offsetWidth || 320;
  const popH = pop.offsetHeight || 200;
  let left = 16;
  let top = 16;

  if (rect) {
    left = rect.left + window.scrollX + rect.width + 12;
    top = rect.top + window.scrollY;
  } else if (info.jsEvent) {
    left = info.jsEvent.pageX + 12;
    top = info.jsEvent.pageY + 12;
  }
  if (left + popW > vw + window.scrollX - 16) {
    left = rect ? (rect.left + window.scrollX - popW - 12) : (vw + window.scrollX - popW - 16);
  }
  if (top + popH > vh + window.scrollY - 16) {
    top = vh + window.scrollY - popH - 16;
  }
  if (left < 16 + window.scrollX) left = 16 + window.scrollX;
  if (top < 16 + window.scrollY) top = 16 + window.scrollY;

  pop.style.left = left + 'px';
  pop.style.top = top + 'px';
  eventPopoverEl = pop;
}

function updateNowIndicator() {
  const arrow = document.querySelector('.fc-timegrid-now-indicator-arrow');
  if (!arrow) return;

  const now = new Date();
  let hours = now.getHours();
  const minutes = String(now.getMinutes()).padStart(2, '0');
  const ampm = hours >= 12 ? 'pm' : 'am';
  hours = hours % 12 || 12;
  arrow.innerText = hours + ':' + minutes + ' ' + ampm;
}

function scheduleNextUpdate() {
  updateNowIndicator();
  const now = new Date();
  const msUntilNextMinute = (60 - now.getSeconds()) * 1000;
  setTimeout(scheduleNextUpdate, msUntilNextMinute);
}

function getCheckedScheduleIds() {
  const ids = [];
  document.querySelectorAll('.menu-section__filtration input.js-schedule-filter:checked').forEach(el => {
    ids.push(el.value);
  });
  return ids;
}

function isInvitedFilterChecked() {
  const cb = document.querySelector('.menu-section__filtration input.js-invited-filter');
  // No filter rendered (no invited bookings exist, or older template) →
  // default to TRUE so behaviour matches the pre-filter state.
  return cb ? !!cb.checked : true;
}

export function refetchEvents() {
  const cal = state.get('calendarInstance');
  if (cal) cal.refetchEvents();
}

const VALID_VIEWS = ['timeGridWeek', 'dayGridMonth'];

export function initFullCalendar(containerEl) {
  if (!containerEl) return;

  const savedView = getCalendarView();
  const calendar = new FullCalendar.Calendar(containerEl, {
    initialView: (savedView && VALID_VIEWS.includes(savedView)) ? savedView : 'timeGridWeek',

    views: {
      timeGridWeek: {
        titleFormat: { year: 'numeric', month: 'long', day: 'numeric' },
        dayHeaderContent(arg) {
          const parts = arg.date
            .toLocaleDateString('en-US', { weekday: 'short', day: 'numeric' })
            .split(' ');
          return parts[1] + ' ' + parts[0];
        },
        slotMinTime: '00:00:00',
        slotMaxTime: '24:00:00',
        slotDuration: '01:00:00',
        slotLabelFormat: { hour: 'numeric', minute: '2-digit', hour12: true },
        allDaySlot: true,
        allDayText: 'All day',
        slotEventOverlap: false,
        displayEventTime: false,
        eventOverlap: false,
        nowIndicator: true,
        datesSet() { requestAnimationFrame(() => requestAnimationFrame(updateNowIndicator)); },
      },
      dayGridMonth: {
        displayEventTime: false,
        eventTimeFormat: { hour: undefined, minute: undefined },
      },
    },

    datesSet(info) {
      if (VALID_VIEWS.includes(info.view.type)) {
        saveCalendarView(info.view.type);
      }
    },

    headerToolbar: {
      left: 'today prev,next title',
      center: '',
      right: 'timeGridWeek dayGridMonth',
    },

    buttonText: { today: 'Today' },
    firstDay: 1,
    windowResizeDelay: 0,

    events(info, successCallback, failureCallback) {
      const scheduleIds = getCheckedScheduleIds();
      const includeInvited = isInvitedFilterChecked();
      const vars = window.schedule_calendar_vars || {};
      if (!vars.ajax_url) { successCallback([]); return; }

      api.getScheduleEvents(info.startStr, info.endStr, scheduleIds, includeInvited)
        .then(resp => {
          if (resp && resp.success && Array.isArray(resp.data)) {
            successCallback(resp.data);
          } else {
            successCallback([]);
          }
        })
        .catch(() => failureCallback(new Error('Failed to load events')));
    },

    eventClick(info) {
      info.jsEvent.preventDefault();
      openEventPopover(info);
    },

    eventDidMount(info) {
      if (info.el && info.event && info.event.display !== 'background') {
        info.el.style.cursor = 'pointer';

        if (info.el.classList.contains('fc-timegrid-event')) {
          const isStart = info.el.classList.contains('fc-event-start');
          const isEnd = info.el.classList.contains('fc-event-end');
          if ((isStart && !isEnd) || (isEnd && !isStart)) {
            const titleEl = info.el.querySelector('.fc-event-title');
            if (titleEl) {
              const icon = document.createElement('span');
              icon.className = 'fc-event-midnight-icon';
              icon.setAttribute('aria-hidden', 'true');
              titleEl.insertBefore(icon, titleEl.firstChild);
            }
          }
        }
      }
    },
  });

  calendar.render();
  state.set('calendarInstance', calendar);

  const toolbar = document.querySelector('.fc-header-toolbar');
  const userMenu = document.querySelector('.user-menu');
  if (toolbar && userMenu) {
    const chunks = toolbar.querySelectorAll('.fc-toolbar-chunk');
    const rightChunk = chunks[chunks.length - 1];
    if (rightChunk) rightChunk.appendChild(userMenu);
  }

  updateNowIndicator();
  scheduleNextUpdate();

  bindPopoverEvents();
}

function bindPopoverEvents() {
  document.addEventListener('click', (e) => {
    if (!eventPopoverEl) return;
    if (e.target.closest('.gc-popover') || e.target.closest('.fc-event')) return;
    closeEventPopover();
  });

  document.addEventListener('click', (e) => {
    const closeBtn = e.target.closest('.gc-popover__close');
    if (closeBtn) { e.preventDefault(); closeEventPopover(); }
  });

  document.addEventListener('click', (e) => {
    const cancelBtn = e.target.closest('.gc-popover__cancel');
    if (!cancelBtn) return;
    e.preventDefault();
    const meetingId = cancelBtn.dataset.meetingId || '';
    const accessToken = cancelBtn.dataset.meetingAccessToken || '';
    if (!meetingId) return;
    if (!confirm('Are you sure you want to cancel this booking?')) return;

    api.cancelMeeting(meetingId, accessToken).then(resp => {
      if (resp && resp.success) {
        closeEventPopover();
        refetchEvents();
      } else {
        alert(resp?.data?.message || 'Error');
      }
    }).catch(() => alert('Request failed'));
  });

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.gc-popover__reschedule');
    if (!btn) return;
    e.preventDefault();
    const rescheduleUrl = btn.dataset.rescheduleUrl || '';
    if (!rescheduleUrl) return;

    window.open(rescheduleUrl, '_blank', 'noopener');
  });
}
