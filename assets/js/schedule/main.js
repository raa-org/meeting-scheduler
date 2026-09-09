/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Schedule page entry point (ES module).
 */

import { initFullCalendar } from 'apexianlab-schedule/fullcalendar-adapter';
import { initSidebar, restoreFilterState } from 'apexianlab-schedule/sidebar';
import { initScheduleModal } from 'apexianlab-schedule/schedule-modal';

const calendarEl = document.querySelector('.calendar-section');
if (calendarEl) {
  restoreFilterState();
  initFullCalendar(calendarEl);
  initSidebar();
  initScheduleModal();
}
