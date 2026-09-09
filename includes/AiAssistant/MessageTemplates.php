<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\AiAssistant;

use DateTime;
use DateTimeZone;

/**
 * Every user-facing string the chat ever emits, in English.
 *
 * Translation to the user's language is done downstream by {@see Translator}.
 * Keeping templates English-only means:
 *   - we support any language a customer types in (no hardcoded language-specific strings)
 *   - dates and weekdays are still computed in PHP, so they're always
 *     correct — the translator just translates the English label
 */
final class MessageTemplates
{
    /** Get the appropriate noun for the meeting type. */
    private static function getMeetingNoun(string $meetingType): string
    {
        return match ($meetingType) {
            'phone_call' => 'call',
            'in_person'  => 'in-person meeting',
            default      => 'meeting',
        };
    }

    /** Get the plural form for the meeting type. */
    private static function getMeetingPlural(string $meetingType): string
    {
        return match ($meetingType) {
            'phone_call' => 'calls',
            'in_person'  => 'in-person meetings',
            default      => 'meetings',
        };
    }

    /** "Monday, May 4 at 4:30 pm" */
    public static function formatDateTime(int $slotStart, string $timezone): string
    {
        try {
            $dt = (new DateTime('@' . $slotStart))->setTimezone(new DateTimeZone($timezone));
        } catch (\Throwable $e) {
            return '';
        }
        return $dt->format('l, F j \\a\\t g:i a');
    }

    /** "Monday, May 4" */
    public static function formatDate(string $date, string $timezone): string
    {
        try {
            $dt = new DateTime($date, new DateTimeZone($timezone));
        } catch (\Throwable $e) {
            return $date;
        }
        return $dt->format('l, F j');
    }

    /**
     * Human-readable period for filtered meeting lists, e.g. "on Tuesday, June 23"
     * or "from Monday, June 22 to Sunday, June 28, 2026".
     */
    public static function formatMeetingPeriodLabel(
        string $startDate,
        string $endDate,
        string $singleDate,
        string $timezone
    ): string {
        if ($singleDate !== '' && $startDate === '' && $endDate === '') {
            return 'on ' . self::formatDate($singleDate, $timezone);
        }
        if ($startDate === '' || $endDate === '') {
            return '';
        }
        if ($startDate === $endDate) {
            return 'on ' . self::formatDate($startDate, $timezone);
        }
        $startLabel = self::formatDate($startDate, $timezone);
        try {
            $endDt = new DateTime($endDate, new DateTimeZone($timezone));
            $endLabel = $endDt->format('l, F j, Y');
        } catch (\Throwable $e) {
            $endLabel = $endDate;
        }
        return "from {$startLabel} to {$endLabel}";
    }

    public static function formatDuration(int $minutes): string
    {
        return $minutes . ' min';
    }

    public static function greeting(string $meetingType = ''): string
    {
        $plural = self::getMeetingPlural($meetingType);
        return "Hello! I'm here to help you schedule, reschedule, or cancel your {$plural}. How can I assist you today?";
    }

    public static function couldNotUnderstand(string $meetingType = ''): string
    {
        $plural = self::getMeetingPlural($meetingType);
        return "I'm sorry, I didn't quite catch that. I can help you book, reschedule, or cancel {$plural} — what would you like to do?";
    }

    public static function offTopic(string $meetingType = ''): string
    {
        $plural = self::getMeetingPlural($meetingType);
        return "I'm here specifically to assist with scheduling {$plural}. Would you like to book, reschedule, or cancel one?";
    }

    /**
     * Ask for one missing slot.
     *
     * @param array<int, int> $allowedDurations
     */
    public static function askForSlot(string $slot, string $meetingType = '', array $allowedDurations = [15, 30, 45, 60, 90]): string
    {
        $noun  = self::getMeetingNoun($meetingType);
        $durationsList = implode(', ', array_map('intval', $allowedDurations));
        return match ($slot) {
            'date'        => "What date would work best for you?",
            'time'        => "What time would be most convenient for you?",
            'duration'    => "How long would you like the {$noun} to be? Available options: {$durationsList} minutes.",
            'guest_email' => 'Could you please share your email address so I can send you the confirmation?',
            'guest_name'  => 'May I have your name, please?',
            'phone'       => 'Could you please share your phone number for the call?',
            'meeting_id'  => "Which {$noun} would you like to select? Please reply with the number from the list above.",
            default       => self::couldNotUnderstand($meetingType),
        };
    }

    /**
     * Slot busy → suggest alternative free windows.
     *
     * @param list<array{start_local:string,end_local:string}> $freeWindows
     * @param list<int> $allowedDurations Optional list of allowed durations in minutes
     */
    public static function slotBusy(string $date, string $time, array $freeWindows, string $timezone, array $allowedDurations = []): string
    {
        $dayLabel = self::formatDate($date, $timezone);
        // Convert time to 12-hour format with am/pm
        $displayTime = $time;
        try {
            $timeDt = \DateTime::createFromFormat('H:i', $time);
            if ($timeDt !== false) {
                $displayTime = $timeDt->format('g:i a');
            }
        } catch (\Throwable $e) {
            // Keep original on error
        }
        $lines = ["Unfortunately, {$dayLabel} at {$displayTime} is unavailable."];
        
        if ($freeWindows === []) {
            return $lines[0] . ' There are no available slots on this date. Would you like to try a different date?';
        }
        
        // Filter allowedDurations to show only those that fit in at least one window
        $viableDurations = [];
        if ($allowedDurations !== []) {
            foreach ($allowedDurations as $dur) {
                foreach ($freeWindows as $w) {
                    $s = (string) ($w['start_local'] ?? '');
                    $e = (string) ($w['end_local'] ?? '');
                    if ($s !== '' && $e !== '') {
                        try {
                            $start = \DateTime::createFromFormat('H:i', $s);
                            $end = \DateTime::createFromFormat('H:i', $e);
                            if ($start !== false && $end !== false) {
                                $windowMinutes = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
                                if ($windowMinutes >= $dur) {
                                    $viableDurations[] = $dur;
                                    break; // Found at least one window that fits this duration
                                }
                            }
                        } catch (\Throwable $ex) { /* skip */ }
                    }
                }
            }
            $viableDurations = array_unique($viableDurations);
            sort($viableDurations);
        }
        
        // Add explanation about viable durations
        if ($viableDurations !== []) {
            $durationsList = implode(', ', array_map(static fn($d) => $d . ' min', $viableDurations));
            $lines[] = "Note: Available meeting durations are {$durationsList}.";
        }
        
        // Find the nearest slot AFTER the requested time (strictly greater).
        // Check inside the current window first (e.g. if 10:58 is busy but window
        // is 10:58-11:30, next grid slot 11:00 may be free), then look at later windows.
        $nearest = null;
        $grid = 15; // minutes
        foreach ($freeWindows as $w) {
            $s = (string) ($w['start_local'] ?? '');
            $e = (string) ($w['end_local'] ?? '');
            if ($s === '' || $e === '') {
                continue;
            }
            
            // If busy time is within this window, try next grid slot inside the same window
            if ($time >= $s && $time < $e) {
                try {
                    $busyDt = \DateTime::createFromFormat('H:i', $time);
                    if ($busyDt !== false) {
                        $busyMinutes = (int) $busyDt->format('H') * 60 + (int) $busyDt->format('i');
                        // Round up to next 15-min grid slot
                        $nextGridMinutes = (int) (ceil($busyMinutes / $grid) * $grid);
                        $nextH = (int) floor($nextGridMinutes / 60);
                        $nextM = $nextGridMinutes % 60;
                        $nextTime = sprintf('%02d:%02d', $nextH, $nextM);
                        // Check if this next slot is still within the window
                        if ($nextTime > $time && $nextTime < $e) {
                            $nearest = $nextTime;
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    // Fall through to check next window
                }
            }
            
            // Find first window that starts AFTER the busy time
            if ($s > $time) {
                $nearest = $s;
                break;
            }
        }
        
        // If there's a slot after the requested time, suggest it specifically
        if ($nearest !== null) {
            $lines[] = "\nThe nearest available time after that is {$nearest}.";
            $lines[] = "Here are all available time slots:";
        } else {
            $lines[] = "\nHere are the available time slots:";
        }
        
        foreach ($freeWindows as $w) {
            $s = (string) ($w['start_local'] ?? '');
            $e = (string) ($w['end_local'] ?? '');
            if ($s !== '' && $e !== '') {
                // Round start time up to nearest 5-minute mark for cleaner display
                // (e.g. 10:58 → 11:00, 14:23 → 14:25)
                $displayStart = $s;
                try {
                    $dt = \DateTime::createFromFormat('H:i', $s);
                    if ($dt !== false) {
                        $minutes = (int) $dt->format('H') * 60 + (int) $dt->format('i');
                        $roundedMinutes = (int) (ceil($minutes / 5) * 5);
                        $h = (int) floor($roundedMinutes / 60);
                        $m = $roundedMinutes % 60;
                        $displayStart = sprintf('%02d:%02d', $h, $m);
                        // Only use rounded time if it's still before the window end
                        if ($displayStart >= $e) {
                            $displayStart = $s; // Keep original if rounding overshoots
                        }
                    }
                } catch (\Throwable $ex) {
                    // Keep original on error
                }
                // Convert to 12-hour format with am/pm
                try {
                    $startDt = \DateTime::createFromFormat('H:i', $displayStart);
                    $endDt = \DateTime::createFromFormat('H:i', $e);
                    if ($startDt !== false && $endDt !== false) {
                        $displayStart = $startDt->format('g:i a');
                        $displayEnd = $endDt->format('g:i a');
                        $lines[] = '- ' . $displayStart . ' – ' . $displayEnd;
                    } else {
                        $lines[] = '- ' . $displayStart . ' – ' . $e;
                    }
                } catch (\Throwable $ex) {
                    $lines[] = '- ' . $displayStart . ' – ' . $e;
                }
            }
        }
        
        $lines[] = "\nWhich time would work for you?";
        return implode("\n", $lines);
    }

    public static function noAvailability(string $date, string $timezone): string
    {
        return 'I\'m sorry, there are no available slots on ' . self::formatDate($date, $timezone) . '. Would you like to try a different date?';
    }

    /**
     * @param list<string> $days  YYYY-MM-DD strings
     */
    public static function showAvailableDays(array $days, string $timezone): string
    {
        if ($days === []) {
            return 'I\'m sorry, there are no available days in the next two weeks. Please contact us directly to arrange a time.';
        }
        $lines = ['Here are the days with open availability:'];
        foreach ($days as $date) {
            $lines[] = '- ' . self::formatDate($date, $timezone);
        }
        $lines[] = "\nWhich date would you prefer?";
        return implode("\n", $lines);
    }

    /**
     * @param list<array{start_local:string,end_local:string}> $windows
     * @param list<int> $allowedDurations Optional list of allowed durations in minutes
     */
    public static function showAvailableWindows(string $date, array $windows, string $timezone, ?string $afterTime = null, array $allowedDurations = []): string
    {
        $dayLabel = self::formatDate($date, $timezone);
        if ($windows === []) {
            return 'I\'m sorry, there are no available slots on ' . $dayLabel . '. Would you like to try a different date?';
        }
        
        $lines = [];
        
        // Filter allowedDurations to show only those that fit in at least one window
        $viableDurations = [];
        if ($allowedDurations !== []) {
            foreach ($allowedDurations as $dur) {
                foreach ($windows as $w) {
                    $s = (string) ($w['start_local'] ?? '');
                    $e = (string) ($w['end_local'] ?? '');
                    if ($s !== '' && $e !== '') {
                        try {
                            $start = \DateTime::createFromFormat('H:i', $s);
                            $end = \DateTime::createFromFormat('H:i', $e);
                            if ($start !== false && $end !== false) {
                                $windowMinutes = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
                                if ($windowMinutes >= $dur) {
                                    $viableDurations[] = $dur;
                                    break; // Found at least one window that fits this duration
                                }
                            }
                        } catch (\Throwable $ex) { /* skip */ }
                    }
                }
            }
            $viableDurations = array_unique($viableDurations);
            sort($viableDurations);
        }
        
        // Add explanation about viable durations
        if ($viableDurations !== []) {
            $durationsList = implode(', ', array_map(static fn($d) => $d . ' min', $viableDurations));
            $lines[] = "Available meeting durations: {$durationsList}.";
        }
        
        // If user asked for slots "after X", highlight the nearest one (strictly after)
        if ($afterTime !== null) {
            $nearest = null;
            $grid = 15; // minutes
            foreach ($windows as $w) {
                $s = (string) ($w['start_local'] ?? '');
                $e = (string) ($w['end_local'] ?? '');
                if ($s === '' || $e === '') {
                    continue;
                }
                
                // If reference time is within this window, try next grid slot inside the same window
                if ($afterTime >= $s && $afterTime < $e) {
                    try {
                        $refDt = \DateTime::createFromFormat('H:i', $afterTime);
                        if ($refDt !== false) {
                            $refMinutes = (int) $refDt->format('H') * 60 + (int) $refDt->format('i');
                            // Round up to next 15-min grid slot
                            $nextGridMinutes = (int) (ceil($refMinutes / $grid) * $grid);
                            $nextH = (int) floor($nextGridMinutes / 60);
                            $nextM = $nextGridMinutes % 60;
                            $nextTime = sprintf('%02d:%02d', $nextH, $nextM);
                            // Check if this next slot is still within the window
                            if ($nextTime > $afterTime && $nextTime < $e) {
                                $nearest = $nextTime;
                                break;
                            }
                        }
                    } catch (\Throwable $ex) {
                        // Fall through to check next window
                    }
                }
                
                // Find first window that starts AFTER the reference time
                if ($s > $afterTime) {
                    $nearest = $s;
                    break;
                }
            }
            if ($nearest !== null) {
                $lines[] = "\nThe nearest available time after {$afterTime} is {$nearest}.";
                $lines[] = "All available slots on {$dayLabel}:";
            } else {
                $lines[] = "\nThere are no slots after {$afterTime} on {$dayLabel}. Here are all available slots:";
            }
        } else {
            $lines[] = "\nHere are the available time slots on {$dayLabel}:";
        }
        
        foreach ($windows as $w) {
            $s = (string) ($w['start_local'] ?? '');
            $e = (string) ($w['end_local'] ?? '');
            if ($s !== '' && $e !== '') {
                // Round start time up to nearest 5-minute mark for cleaner display
                $displayStart = $s;
                try {
                    $dt = \DateTime::createFromFormat('H:i', $s);
                    if ($dt !== false) {
                        $minutes = (int) $dt->format('H') * 60 + (int) $dt->format('i');
                        $roundedMinutes = (int) (ceil($minutes / 5) * 5);
                        $h = (int) floor($roundedMinutes / 60);
                        $m = $roundedMinutes % 60;
                        $displayStart = sprintf('%02d:%02d', $h, $m);
                        if ($displayStart >= $e) {
                            $displayStart = $s;
                        }
                    }
                } catch (\Throwable $ex) {
                    // Keep original on error
                }
                // Convert to 12-hour format with am/pm
                try {
                    $startDt = \DateTime::createFromFormat('H:i', $displayStart);
                    $endDt = \DateTime::createFromFormat('H:i', $e);
                    if ($startDt !== false && $endDt !== false) {
                        $displayStart = $startDt->format('g:i a');
                        $displayEnd = $endDt->format('g:i a');
                        $lines[] = '- ' . $displayStart . ' – ' . $displayEnd;
                    } else {
                        $lines[] = '- ' . $displayStart . ' – ' . $e;
                    }
                } catch (\Throwable $ex) {
                    $lines[] = '- ' . $displayStart . ' – ' . $e;
                }
            }
        }
        $lines[] = "\nWhich time slot works best for you?";
        return implode("\n", $lines);
    }

    /**
     * The calendar owner has set a fixed meeting duration that cannot be changed.
     */
    public static function fixedDurationError(int $duration): string
    {
        return "I'm sorry, but the duration for this meeting is set to {$duration} minutes by the calendar owner and cannot be changed.";
    }

    /**
     * User specified a duration that is not in the admin-configured list.
     *
     * @param list<int> $allowed
     */
    public static function invalidDurationError(array $allowed, string $meetingType = ''): string
    {
        $noun    = self::getMeetingNoun($meetingType);
        $options = implode(', ', array_map(static fn($d) => $d . ' minutes', $allowed));
        return "I'm sorry, that duration isn't available for this {$noun}. You can book for: {$options}. Which would you prefer?";
    }

    /**
     * Ask for duration but only offer the admin-allowed options.
     *
     * @param list<int> $options
     */
    public static function askForDurationWithOptions(array $options): string
    {
        $list = empty($options) ? '15, 30, 45, 60, 90' : implode(', ', $options);
        return "How long would you like the meeting to be? ({$list} minutes)";
    }

    public static function noAvailabilityForFind(): string
    {
        return 'I\'m sorry, I couldn\'t find any available slots in the next 30 days. Please choose a specific date or try a different duration.';
    }

    public static function earliestSlotFound(int $slotStart, int $duration, string $timezone): string
    {
        $label = self::formatDateTime($slotStart, $timezone) . ' (' . self::formatDuration($duration) . ')';

        return 'The earliest available slot I found is ' . $label . '. Would you like to book this? Reply yes to continue or no to search for a different time.';
    }

    public static function earliestSlotDeclined(): string
    {
        return 'No problem. Would you like to choose a specific date or time instead?';
    }

    /**
     * When user requested a specific time (e.g. "11:30") but that time is not available
     * in the next 2 weeks. More specific than noAvailabilityForFind.
     */
    public static function noAvailabilityForTime(string $time, int $duration): string
    {
        // Convert time to 12-hour format with am/pm
        $displayTime = $time;
        try {
            $timeDt = \DateTime::createFromFormat('H:i', $time);
            if ($timeDt !== false) {
                $displayTime = $timeDt->format('g:i a');
            }
        } catch (\Throwable $e) {
            // Keep original on error
        }
        return "I'm sorry, {$displayTime} is not available for a {$duration}-minute meeting in the next 2 weeks. Would you like to choose a different time or see all available slots?";
    }

    /**
     * When user picked a specific date + time but NONE of the allowed durations
     * fit into the available windows at that time on that day.
     *
     * @param list<int> $allowedDurations
     */
    public static function timeNotViableForAnyDuration(string $time, string $date, string $timezone, array $allowedDurations): string
    {
        $dayLabel = self::formatDate($date, $timezone);
        // Convert time to 12-hour format with am/pm
        $displayTime = $time;
        try {
            $timeDt = \DateTime::createFromFormat('H:i', $time);
            if ($timeDt !== false) {
                $displayTime = $timeDt->format('g:i a');
            }
        } catch (\Throwable $e) {
            // Keep original on error
        }
        $durationsList = implode(', ', array_map(static fn($d) => $d . ' minutes', $allowedDurations));
        return "I'm sorry, {$dayLabel} at {$displayTime} doesn't have enough availability for any of the allowed meeting durations ({$durationsList}). Would you like to choose a different time on this day, or try a different date?";
    }

    public static function confirmBooking(int $slotStart, int $duration, string $timezone, ?string $guestName = null, ?string $guestEmail = null, ?string $phone = null): string
    {
        $lines = [
            'Great! Here\'s a summary of your booking — please review and confirm:',
            '- ' . self::formatDateTime($slotStart, $timezone) . ' (' . self::formatDuration($duration) . ')',
        ];
        if ($guestName !== null && $guestName !== '') {
            $lines[] = '- Name: ' . $guestName;
        }
        if ($guestEmail !== null && $guestEmail !== '') {
            $lines[] = '- Email: ' . $guestEmail;
        }
        if ($phone !== null && $phone !== '') {
            $lines[] = '- Phone: ' . $phone;
        }
        $lines[] = "\nShall I go ahead and book this for you? Reply 'yes' to confirm or 'no' to cancel.";
        return implode("\n", $lines);
    }

    public static function confirmReschedule(int $slotStart, int $duration, string $meetingLabel, string $timezone, string $meetingType = ''): string
    {
        $noun = ucfirst(self::getMeetingNoun($meetingType));
        $newLabel = self::formatDateTime($slotStart, $timezone) . ' (' . self::formatDuration($duration) . ')';
        $lines = [
            'Please review the rescheduling details and confirm:',
        ];
        if ($meetingLabel !== '') {
            $lines[] = '- Current time: ' . $meetingLabel;
        } else {
            $lines[] = "- {$noun}: (selected meeting)";
        }
        $lines[] = '- New time: ' . $newLabel;
        $lines[] = "\nShall I proceed? Reply 'yes' to confirm or 'no' to cancel.";
        return implode("\n", $lines);
    }

    /** @param list<string> $labels */
    public static function confirmCancelMultiple(array $labels): string
    {
        $lines = ['Please confirm that you\'d like to cancel the following ' . count($labels) . ' meetings:'];
        foreach ($labels as $label) {
            $lines[] = '- ' . $label;
        }
        $lines[] = "\nReply 'yes' to proceed or 'no' to keep them.";
        return implode("\n", $lines);
    }

    public static function cancelSucceededMultiple(int $count): string
    {
        return $count . ($count === 1 ? ' meeting has' : ' meetings have') . ' been successfully cancelled.';
    }

    public static function confirmCancel(string $meetingLabel): string
    {
        return "Please confirm that you'd like to cancel the following:\n- " . $meetingLabel
            . "\n\nReply 'yes' to cancel or 'no' to keep it.";
    }

    public static function bookingSucceeded(int $slotStart, int $duration, string $timezone, bool $awaitingEmailConfirmation = false): string
    {
        $label = self::formatDateTime($slotStart, $timezone) . ' (' . self::formatDuration($duration) . ')';
        if ($awaitingEmailConfirmation) {
            return 'Your request has been received for ' . $label . '. Please check your email and click the confirmation link to finalise your booking.';
        }
        return 'Your meeting has been successfully booked for ' . $label . '. See you then!';
    }

    public static function rescheduleSucceeded(
        int $slotStart,
        int $duration,
        string $timezone,
        bool $awaitingEmailConfirmation = false,
        string $originalMeetingLabel = ''
    ): string {
        $newLabel = self::formatDateTime($slotStart, $timezone) . ' (' . self::formatDuration($duration) . ')';
        if ($originalMeetingLabel !== '') {
            $base = "Your meeting has been moved from {$originalMeetingLabel} to {$newLabel}.";
        } else {
            $base = 'Your meeting has been successfully rescheduled to ' . $newLabel . '.';
        }
        if ($awaitingEmailConfirmation) {
            return $base . ' Please check your email and click the confirmation link to finalise the change.';
        }
        return $base;
    }

    public static function cancelSucceeded(string $meetingType = '', bool $awaitingEmailConfirmation = false): string
    {
        $noun = ucfirst(self::getMeetingNoun($meetingType));
        if ($awaitingEmailConfirmation) {
            return "I've sent a confirmation email to cancel your {$noun}. Please check your inbox and click the link to confirm the cancellation.";
        }
        return "{$noun} has been successfully cancelled.";
    }

    public static function actionFailed(string $reason = ''): string
    {
        $base = 'I\'m sorry, something went wrong.';
        return $reason !== '' ? $base . ' ' . $reason . ' Please try again.' : $base . ' Please try again.';
    }

    /**
     * Message shown when the user says "no" to a confirmation prompt.
     */
    public static function deniedByUser(string $intent = ''): string
    {
        if ($intent === 'cancel') {
            return "No worries! Your meeting will not be cancelled. Is there anything else I can help you with?";
        }
        return "Understood! No changes have been made. Is there anything else I can help you with?";
    }

    /**
     * "Your meetings:\n1. ...\n2. ..." + ask which one.
     *
     * @param list<array{label:string}> $meetings
     */
    public static function showMeetings(
        array $meetings,
        bool $askWhich = true,
        string $meetingType = '',
        string $intent = '',
        string $periodLabel = ''
    ): string {
        $plural = self::getMeetingPlural($meetingType);
        if ($meetings === []) {
            if ($periodLabel !== '') {
                return "It looks like you have no upcoming {$plural} {$periodLabel}.";
            }
            return "It looks like you have no upcoming {$plural} at the moment.";
        }
        $lines = [$periodLabel !== ''
            ? "Here are your upcoming {$plural} {$periodLabel}:"
            : "Here are your upcoming {$plural}:"];
        foreach ($meetings as $i => $m) {
            $lbl = (string) ($m['label'] ?? '');
            if ($lbl !== '') {
                $lines[] = ($i + 1) . '. ' . $lbl;
            }
        }
        if ($askWhich) {
            $action = match ($intent) {
                'cancel'    => 'cancel',
                'reschedule'=> 'reschedule',
                default     => 'select',
            };
            $lines[] = "\nWhich one would you like to {$action}? Please reply with the number.";
        }
        return implode("\n", $lines);
    }

    public static function needGuestEmail(): string
    {
        return 'Could you please provide the email address you used when making the booking?';
    }

    public static function noMeetingsForEmail(string $email, string $meetingType = ''): string
    {
        $plural = self::getMeetingPlural($meetingType);
        return "I couldn't find any {$plural} associated with {$email}. Could you double-check the email address you used when booking?";
    }

    public static function listMeetingsBeyondHorizon(int $months, string $periodLabel = ''): string
    {
        $window = $months === 1 ? '1 month' : "{$months} months";
        if ($periodLabel !== '') {
            return "I can only show scheduled meetings up to {$window} ahead, so I cannot look up meetings {$periodLabel}. Please try a date within the next {$window}.";
        }
        return "I can only show scheduled meetings up to {$window} ahead. Please try a date within that window.";
    }

    public static function reAskConfirmation(): string
    {
        return "I'm waiting for your confirmation. Please reply 'yes' to proceed or 'no' to cancel.";
    }
}
