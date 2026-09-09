/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * booking-availability-slots.js — shared availability → slot grid (booking page + AI assistant).
 * Keep in sync with booking: single place for slot expansion and timezone formatting.
 */
(function (global) {
  'use strict';

  /**
   * @param {Date} date
   * @param {string} timezone IANA or +HH:MM
   * @returns {{ date: string, time: string }} date as YYYYMMDD (for keys), time 12h
   */
  function formatDateInTimezone(date, timezone) {
    const tz = String(timezone || 'UTC');
    let calculated_year;
    let calculated_month;
    let calculated_day;
    let calculated_hours;
    let calculated_minutes;

    if (/^[+-]\d{2}:\d{2}$/.test(tz)) {
      // Legacy offset format
      const match = tz.match(/^([+-])(\d{2}):(\d{2})$/);
      const sign = match[1] === '+' ? 1 : -1;
      const hours = parseInt(match[2], 10);
      const minutes = parseInt(match[3], 10);
      const offsetInMilliseconds = (hours * 60 + minutes) * 60 * 1000 * sign;
      const localDate = new Date(date.getTime() + offsetInMilliseconds);
      const iso_date = localDate.toISOString();
      const [datePart, timePart] = iso_date.split('T');
      [calculated_year, calculated_month, calculated_day] = datePart.split('-');
      [calculated_hours, calculated_minutes] = timePart.slice(0, 8).split(':');
    } else {
      // IANA timezone format (Europe/Kiev, America/New_York, etc.)
      try {
        const formatter = new Intl.DateTimeFormat('en-US', {
          timeZone: tz,
          year: 'numeric',
          month: '2-digit',
          day: '2-digit',
          hour: '2-digit',
          minute: '2-digit',
          hour12: false,
        });

        const parts = formatter.formatToParts(date);
        calculated_year = parts.find(p => p.type === 'year').value;
        calculated_month = parts.find(p => p.type === 'month').value;
        calculated_day = parts.find(p => p.type === 'day').value;
        calculated_hours = parts.find(p => p.type === 'hour').value;
        calculated_minutes = parts.find(p => p.type === 'minute').value;
      } catch (e) {
        throw new Error('Invalid timezone format: ' + tz);
      }
    }

    let hourInt = parseInt(calculated_hours, 10);
    let period = 'am';

    if (hourInt >= 12) {
      period = 'pm';
      if (hourInt > 12) {
        hourInt -= 12;
      }
    } else if (hourInt === 0) {
      hourInt = 12;
    }

    const formattedTime = String(hourInt).padStart(2, '0') + ':' + calculated_minutes + ' ' + period;
    const formattedDate = calculated_year + calculated_month + calculated_day;
    
    return { date: formattedDate, time: formattedTime };
  }

  /**
   * Same rules as calendar_ajax.js update_date_slots (without mutating globals).
   *
   * @param {Array<{start: number, end: number}>|Record<string, {start: number, end: number}>} list
   * @param {Array<{start: number, end: number}>} bookedIntervals sorted by start
   * @param {string} timezone
   * @param {number} duration minutes
   * @returns {Record<string, Array<{time: string, available: boolean}>>}
   */
  function buildDateSlotsMap(list, bookedIntervals, timezone, duration) {
    const calculate_slots = [];
    const arrList = Array.isArray(list) ? list : Object.values(list || {});
    const booked = Array.isArray(bookedIntervals) ? bookedIntervals : [];

    arrList.forEach(function (item) {
      if (!item || typeof item.start !== 'number' || typeof item.end !== 'number') {
        return;
      }
      let currentStart = item.start;
      const rangeEnd = item.end;
      const step = duration * 60;

      while (currentStart + step <= rangeEnd) {
        const currentEnd = currentStart + step;

        // Expand all schedule_ranges into slots and mark each slot as available/unavailable
        let isAvailable = true;
        const slotStartTs = currentStart;
        const slotEndTs = currentEnd;

        // Optimization: break early once booked.start >= slotEnd
        for (let i = 0; i < booked.length; i++) {
          const b = booked[i];

          // Since booked_intervals are sorted, we can break early
          if (b.start >= slotEndTs) {
            break;
          }

          // Slot is busy if intervals overlap: booked.start < slotEnd && booked.end > slotStart
          if (b.start < slotEndTs && b.end > slotStartTs) {
            isAvailable = false;
            break;
          }
        }

        calculate_slots.push({
          start: currentStart,
          end: currentEnd,
          duration_minutes: duration,
          available: isAvailable,
        });

        currentStart = currentEnd;
      }
    });

    const slots = {};
    const nowSec = Math.floor(Date.now() / 1000);
    const todayKey = formatDateInTimezone(new Date(), timezone).date;

    calculate_slots.forEach(function (i) {
      const slot = formatDateInTimezone(new Date(i.start * 1000), timezone);
      if (slot.date === todayKey && i.start < nowSec) {
        return;
      }
      if (!slots[slot.date]) {
        slots[slot.date] = [];
      }
      slots[slot.date].push({
        time: slot.time,
        available: i.available,
      });
    });

    return slots;
  }

  function parseYmdKey(key) {
    if (!key || String(key).length !== 8) {
      return null;
    }
    const y = parseInt(String(key).slice(0, 4), 10);
    const m = parseInt(String(key).slice(4, 6), 10);
    const d = parseInt(String(key).slice(6, 8), 10);
    if (!y || !m || !d) {
      return null;
    }
    return new Date(y, m - 1, d);
  }

  /**
   * @param {Record<string, Array<{time: string, available: boolean}>>} slotsMap
   * @param {string} timezone
   * @param {{ maxSlots?: number, horizonDays?: number }} [options]
   * @returns {string[]}
   */
  function slotsMapToChatLines(slotsMap, timezone, options) {
    options = options || {};
    const maxSlots = typeof options.maxSlots === 'number' ? options.maxSlots : 48;
    const horizonDays = typeof options.horizonDays === 'number' ? options.horizonDays : 14;

    const todayKey = formatDateInTimezone(new Date(), timezone).date;
    const todayDate = parseYmdKey(todayKey);
    if (!todayDate) {
      return [];
    }

    const horizonEnd = new Date(todayDate.getTime());
    horizonEnd.setDate(horizonEnd.getDate() + Math.max(1, horizonDays));
    horizonEnd.setHours(23, 59, 59, 999);

    const keys = Object.keys(slotsMap)
      .filter(function (k) {
        return k >= todayKey;
      })
      .sort();

    let collected = 0;
    const lines = [];

    keys.forEach(function (key) {
      if (collected >= maxSlots) {
        return;
      }
      const dayDate = parseYmdKey(key);
      if (!dayDate || dayDate > horizonEnd) {
        return;
      }
      const entries = slotsMap[key] || [];
      const times = entries
        .filter(function (e) {
          return e && e.available;
        })
        .map(function (e) {
          return e.time;
        });
      if (!times.length) {
        return;
      }
      const take = times.slice(0, maxSlots - collected);
      collected += take.length;
      const dateLabel = dayDate.toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
      });
      lines.push(dateLabel + ': ' + take.join(', '));
    });

    return lines;
  }

  /**
   * Collect available slots as flat list for interactive buttons.
   *
   * @param {Record<string, Array<{time: string, available: boolean}>>} slotsMap
   * @param {string} timezone
   * @param {{ maxSlots?: number, horizonDays?: number }} [options]
   * @returns {Array<{dateKey: string, dateLabel: string, time: string, label: string}>}
   */
  function collectAvailableSlots(slotsMap, timezone, options) {
    options = options || {};
    const maxSlots = typeof options.maxSlots === 'number' ? options.maxSlots : 12;
    const horizonDays = typeof options.horizonDays === 'number' ? options.horizonDays : 14;

    const todayKey = formatDateInTimezone(new Date(), timezone).date;
    const todayDate = parseYmdKey(todayKey);
    if (!todayDate) {
      return [];
    }

    const horizonEnd = new Date(todayDate.getTime());
    horizonEnd.setDate(horizonEnd.getDate() + Math.max(1, horizonDays));
    horizonEnd.setHours(23, 59, 59, 999);

    const keys = Object.keys(slotsMap)
      .filter(function (k) {
        return k >= todayKey;
      })
      .sort();

    const out = [];
    for (let i = 0; i < keys.length; i++) {
      if (out.length >= maxSlots) {
        break;
      }
      const key = keys[i];
      const dayDate = parseYmdKey(key);
      if (!dayDate || dayDate > horizonEnd) {
        continue;
      }
      const entries = (slotsMap[key] || []).filter(function (e) {
        return e && e.available;
      });
      if (!entries.length) {
        continue;
      }
      const dateLabel = dayDate.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
      });
      for (let j = 0; j < entries.length; j++) {
        if (out.length >= maxSlots) {
          break;
        }
        out.push({
          dateKey: key,
          dateLabel: dateLabel,
          time: entries[j].time,
          label: dateLabel + ' ' + entries[j].time,
        });
      }
    }
    return out;
  }

  /**
   * Find earliest available slot meta within horizon.
   *
   * @param {Record<string, Array<{time: string, available: boolean}>>} slotsMap
   * @param {string} timezone
   * @param {{ horizonDays?: number }} [options]
   * @returns {{dateKey: string, dateLabel: string, time: string}|null}
   */
  function findEarliestAvailableSlotMeta(slotsMap, timezone, options) {
    options = options || {};
    const horizonDays = typeof options.horizonDays === 'number' ? options.horizonDays : 14;

    const todayKey = formatDateInTimezone(new Date(), timezone).date;
    const todayDate = parseYmdKey(todayKey);
    if (!todayDate) {
      return null;
    }

    const horizonEnd = new Date(todayDate.getTime());
    horizonEnd.setDate(horizonEnd.getDate() + Math.max(1, horizonDays));
    horizonEnd.setHours(23, 59, 59, 999);

    const keys = Object.keys(slotsMap)
      .filter(function (k) {
        return k >= todayKey;
      })
      .sort();

    for (let i = 0; i < keys.length; i++) {
      const key = keys[i];
      const dayDate = parseYmdKey(key);
      if (!dayDate || dayDate > horizonEnd) {
        continue;
      }
      const entries = slotsMap[key] || [];
      const firstAvailable = entries.find(function (e) {
        return e && e.available;
      });
      if (!firstAvailable) {
        continue;
      }
      const dateLabel = dayDate.toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
      });
      return {
        dateKey: key,
        dateLabel: dateLabel,
        time: firstAvailable.time,
      };
    }

    return null;
  }

  /**
   * Find earliest available slot within horizon.
   *
   * @param {Record<string, Array<{time: string, available: boolean}>>} slotsMap
   * @param {string} timezone
   * @param {{ horizonDays?: number }} [options]
   * @returns {string|null}
   */
  function findEarliestAvailableSlot(slotsMap, timezone, options) {
    const meta = findEarliestAvailableSlotMeta(slotsMap, timezone, options);
    if (!meta) {
      return null;
    }
    return meta.dateLabel + ' at ' + meta.time;
  }

  global.BookingAvailabilitySlots = {
    formatDateInTimezone: formatDateInTimezone,
    buildDateSlotsMap: buildDateSlotsMap,
    slotsMapToChatLines: slotsMapToChatLines,
    collectAvailableSlots: collectAvailableSlots,
    findEarliestAvailableSlotMeta: findEarliestAvailableSlotMeta,
    findEarliestAvailableSlot: findEarliestAvailableSlot,
  };
})(typeof window !== 'undefined' ? window : this);
