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
 * PHP-side state machine for the booking chat. All decisions about what to
 * ask next, when to query the backend, when to confirm, and when to execute
 * happen here — NOT in the LLM.
 *
 * Per-turn algorithm:
 *
 *   1. If we are awaiting confirmation, check if the user said yes/no in
 *      English via a pure-PHP word match (fast path, no LLM).
 *
 *   2. Make ONE LLM call (SlotExtractor) to read the user's message as
 *      structured JSON: {intent, slots}. If we were still in the confirming
 *      state and the LLM returns intent=confirm|deny (i.e. the user replied
 *      yes/no in a language the English fast-path doesn't recognise), act on it.
 *
 *   3. Validate every extracted slot value. Discard invalid ones.
 *
 *   4. For reschedule / cancel: load the upcoming-meetings list from the
 *      backend if we have not yet; resolve `meeting_index` → `meeting_id`.
 *
 *   5. For book / reschedule: as soon as date + time are filled, query the
 *      backend (`checkAvailability`) and either accept the slot (PHP computes
 *      `slot_start`) or clear the time and show alternative free windows.
 *
 *   6. Auto-fill fields the chat context already knows (user_email,
 *      user_display_name) so we never have to ask an authenticated user.
 *
 *   7. If anything is still missing, ask the user for the next slot using a
 *      MessageTemplates phrase. Otherwise build the confirmation summary
 *      and switch status to 'confirming'.
 *
 *   8. Every outgoing assistant message is translated to `user_language` by
 *      {@see Translator} just before being returned. Templates themselves
 *      are always English; the translator handles any target language.
 */
final class ConversationFlow
{
    public function __construct(
        private SlotExtractor $extractor,
        private BackendOperations $backend,
        private Translator $translator
    ) {
    }

    /**
     * Diagnostic record of LLM calls made during the most recent handle() —
     * one entry per slot_extract or translate call. AiAjaxHandler persists
     * these to `ai_chat_action_log` so you can read them back from the DB.
     *
     * @var list<array{name:string, request:array<string,mixed>, response:array<string,mixed>}>
     */
    public array $llmCalls = [];

    /**
     * Compute clickable quick-reply chips for the current conversation state.
     *
     * Called by AiAjaxHandler after handle() so the context['flow'] is final.
     * Returns an array of {id, label} objects:
     *   - id: sent back in button_payload (unused by the new flow, kept for compat)
     *   - label: the text that gets sent as the user's next message when clicked
     *
     * Cases covered:
     *   confirming state       → "Yes" / "No"
     *   last_asked=meeting_id  → one button per meeting in listed_meetings ("1", "2", ...)
     *   last_asked=duration    → one button per allowed duration ("15 min", "30 min", ...)
     *
     * @param array<string,mixed> $context
     * @return list<array{id:string,label:string}>
     */
    public function computeUiActions(array $context): array
    {
        $flow      = is_array($context['flow'] ?? null) ? $context['flow'] : [];
        $status    = (string) ($flow['status']           ?? 'collecting');
        $lastAsked = (string) ($flow['last_asked_slot']  ?? '');
        $listed    = is_array($flow['listed_meetings']   ?? null) ? $flow['listed_meetings'] : [];
        $lang      = (string) ($context['user_language'] ?? 'en');

        // Translates a label while keeping the English text as the chip id
        // (id = what the bot receives when the user clicks; label = what is displayed).
        $t = function (string $english) use ($lang): string {
            return $this->translator->translate($english, $lang);
        };

        // Awaiting yes/no confirmation.
        // id and label are both translated so the user sees their language
        // in the button AND in the chat bubble after clicking.
        // The server handles non-English confirmations via the LLM extractor
        // rule for awaiting-confirm state.
        if ($status === 'confirming') {
            $yes = $t('Yes');
            $no  = $t('No');
            return [
                ['id' => $yes, 'label' => $yes],
                ['id' => $no,  'label' => $no],
            ];
        }

        // Meeting selection from a list — numbers are universal.
        if ($lastAsked === 'meeting_id' && !empty($listed)) {
            $chips = [];
            foreach ($listed as $i => $m) {
                $n       = $i + 1;
                $chips[] = ['id' => (string) $n, 'label' => (string) $n];
            }
            return $chips;
        }

        // Duration chips are intentionally omitted: short numeric strings like
        // "15 minutes" translate inconsistently across languages (some models
        // render them as "in 15 minutes" or with interrogative/possessive framing).
        // The user can simply type the number they want instead.

        return [];
    }

    /**
     * @param array<string,mixed> $context  Mutated in place (flow state).
     * @return array{assistant_message:string, executed:bool, action_result:?array<string,mixed>}
     */
    public function handle(string $userMessage, array &$context): array
    {
        $this->llmCalls = [];
        $flow           = $this->loadFlow($context);
        $wasConfirming  = ($flow['status'] ?? 'collecting') === 'confirming';

        // ─── 1. Fast path: English yes/no when awaiting confirmation. ──────
        if ($wasConfirming) {
            if (self::isConfirmation($userMessage)) {
                return $this->finish($this->executeFlow($flow, $context), $context);
            }
            if (self::isDenial($userMessage)) {
                $pendingIntent = (string) ($flow['intent'] ?? '');
                $this->resetFlow($context);
                return $this->finish(['assistant_message' => MessageTemplates::deniedByUser($pendingIntent), 'executed' => false, 'action_result' => null], $context);
            }
        }

        // ─── 1b. Fast path: accept / decline a discovered earliest slot. ───
        if (($flow['last_asked_slot'] ?? '') === 'accept_earliest' && !$wasConfirming) {
            if (self::isConfirmation($userMessage)) {
                $flow['last_asked_slot'] = null;
                return $this->finish($this->proceedAfterEarliestAccepted($flow, $context), $context);
            }
            if (self::isDenial($userMessage)) {
                return $this->finish($this->declineEarliestSlot($flow, $context), $context);
            }
        }

        // ─── 2. The single LLM call per turn. ──────────────────────────────
        $extracted = $this->extractor->extract($userMessage, $flow, $context);

        // If the LLM call itself failed (timeout / network error), raw_content
        // starts with 'ERROR:'. Don't silently re-ask the same question — throw
        // so AiAjaxHandler can return the proper "temporarily unavailable" message.
        if (isset($extracted['raw_content']) && strncmp((string) $extracted['raw_content'], 'ERROR:', 6) === 0) {
            throw new \RuntimeException('LLM unreachable: ' . esc_html((string) $extracted['raw_content']));
        }

        $this->llmCalls[] = [
            'name'     => 'slot_extract',
            'request'  => [
                'user_message' => $userMessage,
                'flow_before'  => $flow,
            ],
            'response' => [
                'intent' => $extracted['intent'] ?? null,
                'slots'  => $extracted['slots']  ?? [],
                'raw'    => (string) ($extracted['raw_content'] ?? ''),
            ],
        ];

        // ─── 2a. Accept / decline earliest slot (LLM path for non-English yes/no). ──
        if (($flow['last_asked_slot'] ?? '') === 'accept_earliest' && !$wasConfirming) {
            $extractedIntent = $extracted['intent'] ?? null;
            if ($extractedIntent === 'confirm') {
                $flow['last_asked_slot'] = null;
                return $this->finish($this->proceedAfterEarliestAccepted($flow, $context), $context);
            }
            if ($extractedIntent === 'deny') {
                return $this->finish($this->declineEarliestSlot($flow, $context), $context);
            }
            $flow['last_asked_slot'] = null;
        }

        // ─── 2b. LLM-detected confirm / deny / new-intent while confirming. ──
        if ($wasConfirming) {
            $extractedIntent = $extracted['intent'] ?? null;

            if ($extractedIntent === 'confirm') {
                return $this->finish($this->executeFlow($flow, $context), $context);
            }
            if ($extractedIntent === 'deny') {
                $pendingIntent = (string) ($flow['intent'] ?? '');
                $this->resetFlow($context);
                return $this->finish(['assistant_message' => MessageTemplates::deniedByUser($pendingIntent), 'executed' => false, 'action_result' => null], $context);
            }
            if (in_array($extractedIntent, ['book', 'reschedule', 'cancel', 'list'], true)) {
                // User abandoned the pending confirmation and asked for a
                // different operation. Wipe the stale slots / listed_meetings
                // and start a fresh flow with the new intent.
                $flow = [
                    'intent'          => $extractedIntent,
                    'status'          => 'collecting',
                    'slots'           => [],
                    'last_asked_slot' => null,
                    'listed_meetings' => [],
                ];
            } else {
                // Single-slot correction. Drop to collecting and re-evaluate.
                // If user said something like "I can't at 19:00" (meaning they want
                // a different time), clear slot_start and time so we ask for new time.
                // Keep date/duration/etc intact unless user explicitly changed them.
                $flow['status'] = 'collecting';
                if (!empty($flow['slots']['slot_start'])) {
                    unset($flow['slots']['slot_start']);
                }
                // If no new time was extracted but we had one, clear it so system
                // asks for a new time or shows available windows for the same day.
                if (empty($extracted['slots']['time'] ?? null) && !empty($flow['slots']['time'])) {
                    unset($flow['slots']['time']);
                }
                // Only clear date if user explicitly mentioned wanting a different date
                // (LLM would have extracted it in that case). Don't auto-clear.
            }
        }

        // ─── 2c. Deny in collecting state → restore last confirmed snapshot. ──
        // When user was mid-change (e.g. "change to May 22", then "no, keep May 21"),
        // restore the last confirmed slots so they don't have to re-enter everything.
        if (!$wasConfirming && ($extracted['intent'] ?? null) === 'deny'
            && !empty($flow['backup_slots'])
        ) {
            $flow['slots']  = $flow['backup_slots'];
            $flow['status'] = 'confirming';
            $msg = $this->buildConfirmation($flow, $context);
            $this->saveFlow($context, $flow);
            return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
        }

        // ─── 3. Set / update intent. ───────────────────────────────────────
        $extractedIntent = $extracted['intent'] ?? null;
        if (($flow['intent'] ?? null) === null) {
            // Fallback: period without intent — browse only when the user asked about
            // free slots, not when they asked about meetings they already booked.
            if ($extractedIntent === null
                && (!empty($extracted['slots']['date'])
                    || !empty($extracted['slots']['start_date'])
                    || !empty($extracted['slots']['end_date']))
            ) {
                $extractedIntent = 'browse';
            }

            // No intent yet — use the extracted one.
            if (in_array($extractedIntent, ['book', 'reschedule', 'cancel', 'list', 'browse'], true)) {
                $flow['intent'] = $extractedIntent;
            } elseif ($extractedIntent === 'other') {
                return $this->finish(['assistant_message' => MessageTemplates::offTopic((string) ($context['meeting_type'] ?? '')), 'executed' => false, 'action_result' => null], $context);
            } else {
                return $this->finish(['assistant_message' => MessageTemplates::couldNotUnderstand((string) ($context['meeting_type'] ?? '')), 'executed' => false, 'action_result' => null], $context);
            }
        } elseif (
            in_array($extractedIntent, ['book', 'reschedule', 'cancel', 'list', 'browse'], true)
            && $extractedIntent !== (string) $flow['intent']
        ) {
            // Special case: when the user is in browse mode and says something
            // like "show all" (which the LLM maps to list), don't switch to
            // list-my-meetings — the user just wants to see more availability.
            $stayOnBrowse = ($flow['intent'] === 'browse' && $extractedIntent === 'list');

            // Special case: browse → book ("show slots at 11pm" then "book the earliest").
            // Preserve time/date from browse so "book earliest" finds earliest at that time.
            $browseThenBook = ($flow['intent'] === 'browse' && $extractedIntent === 'book');

            // Special case: reschedule → browse/book when user is exploring new times.
            // Preserve meeting_id, listed_meetings, and extracted slots.
            // This happens when user says "show me times next week" during reschedule.
            $rescheduleToExplore = ($flow['intent'] === 'reschedule'
                && in_array($extractedIntent, ['browse', 'book'], true)
                && !empty($flow['slots']['meeting_id']));

            if (!$stayOnBrowse && !$browseThenBook && !$rescheduleToExplore) {
                // User wants a genuinely different operation → reset flow.
                $flow = [
                    'intent'          => $extractedIntent,
                    'status'          => 'collecting',
                    'slots'           => [],
                    'last_asked_slot' => null,
                    'listed_meetings' => [],
                ];
            } elseif ($rescheduleToExplore) {
                // Keep meeting_id, listed_meetings, and original_meeting_time, change intent to browse/book.
                $preservedMeetingId = (string) $flow['slots']['meeting_id'];
                $preservedListedMeetings = $flow['listed_meetings'] ?? [];
                $preservedDuration = !empty($flow['slots']['duration']) ? (int) $flow['slots']['duration'] : null;
                $preservedOriginalTime = (string) ($flow['slots']['original_meeting_time'] ?? '');
                $flow['intent'] = $extractedIntent;
                $flow['status'] = 'collecting';
                $flow['slots']['meeting_id'] = $preservedMeetingId;
                $flow['listed_meetings'] = $preservedListedMeetings;
                if ($preservedDuration !== null && empty($flow['slots']['duration'])) {
                    $flow['slots']['duration'] = $preservedDuration;
                }
                if ($preservedOriginalTime !== '') {
                    $flow['slots']['original_meeting_time'] = $preservedOriginalTime;
                }
                $flow['last_asked_slot'] = null;
            } elseif ($browseThenBook) {
                // Keep time/date from browse, just change intent to book.
                $preservedTime = (string) ($flow['slots']['time'] ?? '');
                $preservedDate = (string) ($flow['slots']['date'] ?? '');
                $preservedDuration = !empty($flow['slots']['duration']) ? (int) $flow['slots']['duration'] : null;
                $flow['intent'] = 'book';
                $flow['status'] = 'collecting';
                if ($preservedTime !== '') {
                    $flow['slots']['time'] = $preservedTime;
                }
                if ($preservedDate !== '') {
                    $flow['slots']['date'] = $preservedDate;
                }
                if ($preservedDuration !== null) {
                    $flow['slots']['duration'] = $preservedDuration;
                }
                // Clear browse-specific state
                $flow['last_asked_slot'] = null;
            }
        }

        // ─── 4. Merge validated slot values. ───────────────────────────────
        if (($flow['last_asked_slot'] ?? '') === 'duration'
            && empty($extracted['slots']['duration'] ?? null)
        ) {
            $directDuration = SlotExtractor::parseDurationMinutes(trim($userMessage));
            if ($directDuration !== null) {
                $extracted['slots']['duration'] = $directDuration;
            }
        }
        $isAuthenticated = !empty($context['user_is_authenticated']);
        foreach ($extracted['slots'] as $field => $value) {
            if ($isAuthenticated && ($field === 'guest_email' || $field === 'guest_name')) {
                continue;
            }
            // When the bot asked for duration and the user typed something like
            // "18:00", the LLM extracts a time instead. Don't store it — the
            // user was clearly specifying a duration or made a typo.
            if ($field === 'time'
                && ($flow['last_asked_slot'] ?? '') === 'duration'
                && empty($extracted['slots']['duration'] ?? null)
            ) {
                continue;
            }

            $check = self::validateSlot($field, $value, $context);
            if ($check['valid']) {
                // Any new date or time invalidates the cached slot_start so the
                // availability check re-runs with the fresh preference. This covers
                // both "value changed" and "value newly set from null".
                if (in_array($field, ['date', 'time'], true) && !empty($flow['slots']['slot_start'])) {
                    unset($flow['slots']['slot_start']);
                }
                // When user selects a specific date, clear the period (start_date/end_date)
                // so we show windows for that day instead of the entire period again.
                if ($field === 'date') {
                    unset($flow['slots']['start_date'], $flow['slots']['end_date'], $flow['slots']['prefer_earliest']);
                }
                // Conversely: when user asks for a period (start_date/end_date),
                // clear any specific date from a previous query ("show friday" then "show next week").
                if (in_array($field, ['start_date', 'end_date'], true)) {
                    unset($flow['slots']['date']);
                }
                $flow['slots'][$field] = $check['value'];
            } elseif ($field === 'duration' && isset($check['allowed'])) {
                // User specified a duration outside the admin-configured list.
                if (!empty($check['fixed_violation'])) {
                    // Single allowed value — owner locked it.
                    $fixedMin = (int) ($check['fixed_value'] ?? 0);
                    $this->saveFlow($context, $flow);
                    return $this->finish([
                        'assistant_message' => MessageTemplates::fixedDurationError($fixedMin),
                        'executed'          => false,
                        'action_result'     => null,
                    ], $context);
                } else {
                    // Multiple allowed values — user picked one that isn't available.
                    $this->saveFlow($context, $flow);
                    return $this->finish([
                        'assistant_message' => MessageTemplates::invalidDurationError(
                            $check['allowed'],
                            (string) ($context['meeting_type'] ?? '')
                        ),
                        'executed'          => false,
                        'action_result'     => null,
                    ], $context);
                }
            }
        }

        // LLM often sets only start_date or only end_date (sometimes with a wrong year) — ignore incomplete ranges.
        if (!empty($flow['slots']['start_date']) xor !empty($flow['slots']['end_date'])) {
            unset($flow['slots']['start_date'], $flow['slots']['end_date']);
        }

        // Persist a non-auth user's identity into $context so subsequent
        // operations in the same chat (e.g. book then reschedule then cancel)
        // don't have to re-ask — and don't expose the user to typo opportunities.
        // flow.slots is wiped on resetFlow; $context survives.
        if (!$isAuthenticated) {
            if (!empty($flow['slots']['guest_email'])) {
                $context['guest_email'] = (string) $flow['slots']['guest_email'];
            }
            if (!empty($flow['slots']['guest_name'])) {
                $context['guest_name'] = (string) $flow['slots']['guest_name'];
            }
        }

        // Resolve meeting_index / meeting_indices → meeting_id(s) from the listed_meetings cache.
        $listed = is_array($flow['listed_meetings'] ?? null) ? $flow['listed_meetings'] : [];

        // Auto-select if there's only one meeting and user hasn't explicitly chosen yet.
        // "reschedule it later" / "cancel it" should work without asking "which one?"
        if (count($listed) === 1
            && in_array($flow['intent'], ['reschedule', 'cancel'], true)
            && empty($flow['slots']['meeting_id'])
            && empty($flow['slots']['meeting_index'])
        ) {
            $flow['slots']['meeting_index'] = 1;
        }

        // Convert meeting_indices with single element to meeting_index for reschedule/list.
        // The LLM sometimes uses meeting_indices even when user picks just one meeting.
        if (isset($flow['slots']['meeting_indices']) && is_array($flow['slots']['meeting_indices'])
            && count($flow['slots']['meeting_indices']) === 1
            && in_array($flow['intent'], ['reschedule', 'list'], true)
        ) {
            $flow['slots']['meeting_index'] = (int) $flow['slots']['meeting_indices'][0];
            unset($flow['slots']['meeting_indices']);
        }

        // Multi-select: user picked several meetings for cancellation.
        if (isset($flow['slots']['meeting_indices']) && is_array($flow['slots']['meeting_indices'])
            && $flow['intent'] === 'cancel'
        ) {
            $resolvedIds     = [];
            $resolvedLabels  = [];
            foreach ($flow['slots']['meeting_indices'] as $idx) {
                $idx = (int) $idx;
                if ($idx >= 1 && $idx <= count($listed) && !empty($listed[$idx - 1]['id'])) {
                    $resolvedIds[]    = (string) $listed[$idx - 1]['id'];
                    $resolvedLabels[] = (string) ($listed[$idx - 1]['label'] ?? $listed[$idx - 1]['id']);
                }
            }
            if ($resolvedIds !== []) {
                $flow['slots']['meeting_ids']    = $resolvedIds;
                $flow['slots']['meeting_labels'] = $resolvedLabels;
            }
            unset($flow['slots']['meeting_indices']);
        }

        // Single-select: user picked one meeting.
        if (isset($flow['slots']['meeting_index'])) {
            $idx = (int) $flow['slots']['meeting_index'];
            if ($idx >= 1 && $idx <= count($listed) && !empty($listed[$idx - 1]['id'])) {
                $meeting = $listed[$idx - 1];
                $flow['slots']['meeting_id'] = (string) $meeting['id'];
                if ($flow['intent'] === 'reschedule') {
                    // Pre-fill duration from the original meeting so the user
                    // doesn't have to re-specify it.
                    if (empty($flow['slots']['duration']) && !empty($meeting['duration'])) {
                        $flow['slots']['duration'] = (int) $meeting['duration'];
                    }
                    // Pre-fill date from the original meeting's date so phrases
                    // like "same day but later" resolve to the meeting's date,
                    // not today. User can override by naming a different date.
                    if (empty($flow['slots']['date']) && !empty($meeting['datetime'])) {
                        try {
                            $tz  = (string) ($context['timezone'] ?? 'UTC');
                            // meeting['datetime'] is ISO 8601 from BackendOperations::listUpcomingMeetings
                            $mDt = new \DateTime((string) $meeting['datetime']);
                            $mDt->setTimezone(new \DateTimeZone($tz));
                            $flow['slots']['date'] = $mDt->format('Y-m-d');
                            // Store the original meeting time for "later"  phrases.
                            // When user says "move it later", we need to find slots AFTER this time.
                            $flow['slots']['original_meeting_time'] = $mDt->format('H:i');
                        } catch (\Throwable $e) { /* ignore */ }
                    }
                }
            }
            unset($flow['slots']['meeting_index']);
        }

        // ─── 4b. prefer_earliest / "any time" handling. ────────────────────────
        // If prefer_earliest is set AND a specific date is already in slots,
        // the user means "any time on that date" → treat as browse for that date
        // (shows free windows) rather than finding the global earliest slot.
        if (!empty($flow['slots']['prefer_earliest'])
            && $flow['intent'] === 'book'
            && !empty($flow['slots']['date'])
            && empty($flow['slots']['time'])
            && empty($flow['slots']['slot_start'])
        ) {
            $flow['intent'] = 'browse';
            unset($flow['slots']['prefer_earliest']);
            // Fall through to step 5b which will show windows for the date.
        }

        // Drop any duration value the model invented that isn't on the
        // schedule's allowed list — forces step 5 to re-ask cleanly.
        $allowedDurations = self::allowedDurationsFromContext($context);
        if (!empty($flow['slots']['duration']) && !in_array((int) $flow['slots']['duration'], $allowedDurations, true)) {
            unset($flow['slots']['duration']);
        }

        // Auto-fill duration early (before step 4b prefer_earliest check) when
        // the schedule has exactly one allowed value. autoFillFromContext runs
        // in step 7 — too late for step 4b which needs duration to call
        // findEarliestSlot rather than asking the user.
        if (empty($flow['slots']['duration']) && count($allowedDurations) === 1) {
            $flow['slots']['duration'] = (int) $allowedDurations[0];
        }

        // prefer_earliest + explicit time for book: "after 14:30" or "book earliest after 2pm".
        // Find the first free slot on any day after the specified time.
        if (!empty($flow['slots']['prefer_earliest'])
            && $flow['intent'] === 'book'
            && !empty($flow['slots']['time'])
            && empty($flow['slots']['slot_start'])
        ) {
            if (empty($flow['slots']['duration'])) {
                $flow['last_asked_slot'] = 'duration';
                $this->saveFlow($context, $flow);
                return $this->finish(['assistant_message' => MessageTemplates::askForSlot('duration', (string) ($context['meeting_type'] ?? ''), $allowedDurations), 'executed' => false, 'action_result' => null], $context);
            }
            
            $afterTime = (string) $flow['slots']['time'];
            $duration  = (int) $flow['slots']['duration'];
            $startDate = !empty($flow['slots']['date']) ? (string) $flow['slots']['date'] : null;
            
            // Get available days (without filtering by specific time)
            $days = $this->backend->getAvailableDays($context, $duration, 14, '', $startDate ?? '');
            $found = false;
            foreach ($days as $date) {
                $windows = $this->backend->getAvailableWindows($date, $context);
                // For each window, check if it contains any slots at or after the requested time
                foreach ($windows as $w) {
                    $windowStart = (string) ($w['start_local'] ?? '');
                    $windowEnd   = (string) ($w['end_local'] ?? '');
                    if ($windowStart === '' || $windowEnd === '') {
                        continue;
                    }
                    
                    // Determine the earliest slot to check in this window:
                    // - If window starts at or after afterTime, use window start
                    // - If window starts before but ends after afterTime, round afterTime up to next 15-min grid
                    $checkTime = $windowStart >= $afterTime ? $windowStart : null;
                    if ($checkTime === null && $windowEnd > $afterTime) {
                        // Window spans across afterTime (e.g. window 18:00-23:00, afterTime 22:00)
                        // Round afterTime up to next 15-min grid slot
                        try {
                            $dt = \DateTime::createFromFormat('H:i', $afterTime);
                            if ($dt !== false) {
                                $minutes = (int) $dt->format('H') * 60 + (int) $dt->format('i');
                                $roundedMinutes = (int) (ceil($minutes / 15) * 15);
                                $h = (int) floor($roundedMinutes / 60);
                                $m = $roundedMinutes % 60;
                                $checkTime = sprintf('%02d:%02d', $h, $m);
                            }
                        } catch (\Throwable $e) {
                            // Skip this window on parse error
                            continue;
                        }
                    }
                    
                    if ($checkTime !== null && $checkTime < $windowEnd) {
                        // Check if this time slot is available
                        $avail = $this->backend->checkAvailability($date, $checkTime, $duration, $context);
                        if ($avail['free']) {
                            $flow['slots']['date']       = $date;
                            $flow['slots']['time']       = $checkTime;
                            $flow['slots']['slot_start'] = (int) $avail['slot_start'];
                            $found = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$found) {
                $this->resetFlow($context);
                return $this->finish(['assistant_message' => MessageTemplates::noAvailabilityForFind(), 'executed' => false, 'action_result' => null], $context);
            }
            unset($flow['slots']['prefer_earliest']);
        }

        // prefer_earliest without a specific time: find the globally earliest free slot.
        // Never when the user already named a calendar day (would ignore their weekday).
        if (!empty($flow['slots']['prefer_earliest'])
            && in_array($flow['intent'], ['book', 'reschedule'], true)
            && empty($flow['slots']['slot_start'])
            && empty($flow['slots']['time'])
            && empty($flow['slots']['date'])
        ) {
            if (empty($flow['slots']['duration'])) {
                $flow['last_asked_slot'] = 'duration';
                $this->saveFlow($context, $flow);
                return $this->finish(['assistant_message' => MessageTemplates::askForSlot('duration', (string) ($context['meeting_type'] ?? ''), $allowedDurations), 'executed' => false, 'action_result' => null], $context);
            }
            
            // If user said "find earliest" during reschedule but no meetings found,
            // treat it as a new booking request instead of continuing reschedule flow.
            if ($flow['intent'] === 'reschedule' 
                && (empty($flow['listed_meetings']) || $flow['listed_meetings'] === [])
                && empty($flow['slots']['meeting_id'])
            ) {
                $flow['intent'] = 'book';
                unset($flow['slots']['guest_email'], $flow['listed_meetings'], $flow['slots']['original_meeting_time']);
            }
            
            // For reschedule with "later", use the original meeting time as starting point
            if ($flow['intent'] === 'reschedule' && !empty($flow['slots']['original_meeting_time'])) {
                $flow['slots']['time'] = (string) $flow['slots']['original_meeting_time'];
                // Keep original_meeting_time in slots so user can say "same time" later.
                // It will be cleared when flow resets.
                // Fall through to the next block which handles prefer_earliest + time for reschedule
            } else {
                // For book, find the globally earliest slot
                $earliest = $this->backend->findEarliestSlot((int) $flow['slots']['duration'], $context);
                if ($earliest === null) {
                    $this->resetFlow($context);
                    return $this->finish(['assistant_message' => MessageTemplates::noAvailabilityForFind(), 'executed' => false, 'action_result' => null], $context);
                }
                $flow['slots']['date']       = $earliest['date'];
                $flow['slots']['time']       = $earliest['time'];
                $flow['slots']['slot_start'] = $earliest['slot_start'];
                unset($flow['slots']['prefer_earliest']);
                $flow['last_asked_slot'] = 'accept_earliest';
                $this->saveFlow($context, $flow);
                return $this->finish([
                    'assistant_message' => MessageTemplates::earliestSlotFound(
                        (int) $earliest['slot_start'],
                        (int) $flow['slots']['duration'],
                        (string) ($context['timezone'] ?? 'UTC')
                    ),
                    'executed'      => false,
                    'action_result' => null,
                ], $context);
            }
        }

        // prefer_earliest + time for reschedule: "same day but after 14:30" or "later than 2pm".
        // Find the first free slot on the meeting's date (or next available date) after the specified time.
        if (!empty($flow['slots']['prefer_earliest'])
            && $flow['intent'] === 'reschedule'
            && !empty($flow['slots']['time'])
            && empty($flow['slots']['slot_start'])
        ) {
            if (empty($flow['slots']['duration'])) {
                $flow['last_asked_slot'] = 'duration';
                $this->saveFlow($context, $flow);
                return $this->finish(['assistant_message' => MessageTemplates::askForSlot('duration', (string) ($context['meeting_type'] ?? ''), $allowedDurations), 'executed' => false, 'action_result' => null], $context);
            }
            $afterTime = (string) $flow['slots']['time'];
            $duration  = (int) $flow['slots']['duration'];
            $startDate = !empty($flow['slots']['date']) ? (string) $flow['slots']['date'] : null;
            
            // Get available days starting from the meeting's date (if set) or today
            $days = $this->backend->getAvailableDays($context, $duration, 14, '', $startDate ?? '');
            $found = false;
            foreach ($days as $date) {
                $windows = $this->backend->getAvailableWindows($date, $context);
                // For each window, check if it contains any slots at or after the requested time
                foreach ($windows as $w) {
                    $windowStart = (string) ($w['start_local'] ?? '');
                    $windowEnd   = (string) ($w['end_local'] ?? '');
                    if ($windowStart === '' || $windowEnd === '') {
                        continue;
                    }
                    
                    // Determine the earliest slot to check in this window
                    $checkTime = $windowStart >= $afterTime ? $windowStart : null;
                    if ($checkTime === null && $windowEnd > $afterTime) {
                        // Window spans across afterTime - round up to next 15-min grid
                        try {
                            $dt = \DateTime::createFromFormat('H:i', $afterTime);
                            if ($dt !== false) {
                                $minutes = (int) $dt->format('H') * 60 + (int) $dt->format('i');
                                $roundedMinutes = (int) (ceil($minutes / 15) * 15);
                                $h = (int) floor($roundedMinutes / 60);
                                $m = $roundedMinutes % 60;
                                $checkTime = sprintf('%02d:%02d', $h, $m);
                            }
                        } catch (\Throwable $e) {
                            continue;
                        }
                    }
                    
                    if ($checkTime !== null && $checkTime < $windowEnd) {
                        $avail = $this->backend->checkAvailability($date, $checkTime, $duration, $context);
                        if ($avail['free']) {
                            $flow['slots']['date']       = $date;
                            $flow['slots']['time']       = $checkTime;
                            $flow['slots']['slot_start'] = (int) $avail['slot_start'];
                            $found = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$found) {
                $this->resetFlow($context);
                return $this->finish(['assistant_message' => MessageTemplates::noAvailabilityForFind(), 'executed' => false, 'action_result' => null], $context);
            }
            unset($flow['slots']['prefer_earliest']);
        }

        // ─── 4c. Early viability checks for book/reschedule. ────────────────
        // Check 1: If time is set but no date yet, check if this time is available
        // on ANY day in the next 2 weeks. If not, tell user immediately instead of
        // asking for duration/date only to say "no slots" later.
        if (in_array($flow['intent'], ['book', 'reschedule'], true)
            && !empty($flow['slots']['time'])
            && empty($flow['slots']['date'])
            && empty($flow['slots']['slot_start'])
            && !empty($flow['slots']['duration']) // Only check if we have duration
        ) {
            $requestedTime = (string) $flow['slots']['time'];
            $duration = (int) $flow['slots']['duration'];
            $daysWithThisTime = $this->backend->getAvailableDays($context, $duration, 14, $requestedTime);
            if (empty($daysWithThisTime)) {
                // This specific time is not available anywhere in the next 2 weeks.
                $flow['slots']['time'] = null;
                $this->resetFlow($context);
                return $this->finish([
                    'assistant_message' => MessageTemplates::noAvailabilityForTime($requestedTime, $duration),
                    'executed'          => false,
                    'action_result'     => null,
                ], $context);
            }
        }
        
        // Check 2: If a date is now set but no time yet, check upfront whether ANY slot
        // exists on that date. If not, tell the user immediately instead of
        // asking for a time only to say "no slots" on the same response.
        if (in_array($flow['intent'], ['book', 'reschedule'], true)
            && !empty($flow['slots']['date'])
            && empty($flow['slots']['time'])
            && empty($flow['slots']['slot_start'])
        ) {
            $earlyWindows = $this->backend->getAvailableWindows((string) $flow['slots']['date'], $context);
            if ($earlyWindows === []) {
                $busyDate = (string) $flow['slots']['date'];
                $flow['slots']['date'] = null;
                $flow['last_asked_slot'] = 'date';
                $this->saveFlow($context, $flow);
                return $this->finish([
                    'assistant_message' => MessageTemplates::noAvailability($busyDate, (string) ($context['timezone'] ?? 'UTC')),
                    'executed'          => false,
                    'action_result'     => null,
                ], $context);
            }
        }

        // ─── 4d. Week/date range without a specific day — list available days. ──
        if (in_array($flow['intent'], ['book', 'browse'], true)
            && empty($flow['slots']['date'])
            && empty($flow['slots']['time'])
            && empty($flow['slots']['slot_start'])
            && !empty($flow['slots']['start_date'])
            && !empty($flow['slots']['end_date'])
        ) {
            $rangeResult = $this->tryFinishDateRangeDayList($flow, $context, $allowedDurations);
            if ($rangeResult !== null) {
                return $this->finish($rangeResult, $context);
            }
        }

        // ─── 5. Reschedule / cancel / list: lazy-load meetings. ────────────
        if (in_array($flow['intent'], ['reschedule', 'cancel', 'list'], true)
            && empty($flow['slots']['meeting_id'])
            && ($flow['intent'] !== 'list' || empty($flow['listed_meetings']))
        ) {
            if (self::needsGuestEmail($flow, $context)) {
                $flow['last_asked_slot'] = 'guest_email';
                $this->saveFlow($context, $flow);
                return $this->finish(['assistant_message' => MessageTemplates::needGuestEmail(), 'executed' => false, 'action_result' => null], $context);
            }

            if (empty($flow['listed_meetings'])) {
                $email  = (string) ($flow['slots']['guest_email'] ?? $context['user_email'] ?? $context['guest_email'] ?? '');
                $listed = $this->backend->listUpcomingMeetings($context, $email);
                $flow['listed_meetings'] = $listed;

                if ($listed === []) {
                    $askedForEmail = empty($context['user_is_authenticated'])
                        && !empty($flow['slots']['guest_email']);
                    $msg = $askedForEmail
                        ? MessageTemplates::noMeetingsForEmail((string) $flow['slots']['guest_email'], (string) ($context['meeting_type'] ?? ''))
                        : MessageTemplates::showMeetings([], false, (string) ($context['meeting_type'] ?? ''));

                    if ($askedForEmail && in_array($flow['intent'], ['reschedule', 'cancel'], true)) {
                        // Keep the intent so the user can immediately retry with a
                        // different email without having to say "reschedule" again.
                        // Only clear the bad email and the (empty) meetings cache.
                        unset($flow['slots']['guest_email'], $flow['listed_meetings']);
                        $flow['last_asked_slot'] = 'guest_email';
                        $this->saveFlow($context, $flow);
                    } else {
                        $this->resetFlow($context);
                    }
                    return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
                }

                if ($flow['intent'] === 'list') {
                    $tz         = (string) ($context['timezone'] ?? 'UTC');
                    $startDate  = (string) ($flow['slots']['start_date'] ?? '');
                    $endDate    = (string) ($flow['slots']['end_date'] ?? '');
                    $singleDate = (string) ($flow['slots']['date'] ?? '');
                    $periodLabel = MessageTemplates::formatMeetingPeriodLabel($startDate, $endDate, $singleDate, $tz);

                    if ($this->isListPeriodBeyondHorizon($startDate, $endDate, $singleDate, $context)) {
                        $this->resetFlow($context);
                        return $this->finish([
                            'assistant_message' => MessageTemplates::listMeetingsBeyondHorizon(
                                BackendOperations::MEETING_LIST_HORIZON_MONTHS,
                                $periodLabel
                            ),
                            'executed'      => false,
                            'action_result' => null,
                        ], $context);
                    }

                    $filtered = $this->filterMeetingsInRange($listed, $startDate, $endDate, $singleDate, $context);
                    $this->resetFlow($context);
                    return $this->finish([
                        'assistant_message' => MessageTemplates::showMeetings(
                            $filtered,
                            false,
                            (string) ($context['meeting_type'] ?? ''),
                            '',
                            $periodLabel
                        ),
                        'executed'      => false,
                        'action_result' => null,
                    ], $context);
                }

                // If user has only one meeting, automatically select it instead of
                // asking "Which one?" — it's obvious and saves a round-trip.
                if (count($listed) === 1) {
                    $meeting = $listed[0];
                    $flow['slots']['meeting_id'] = (string) $meeting['id'];
                    
                    // Pre-fill duration and date for reschedule (same logic as lines 411-430).
                    if ($flow['intent'] === 'reschedule') {
                        if (empty($flow['slots']['duration']) && !empty($meeting['duration'])) {
                            $flow['slots']['duration'] = (int) $meeting['duration'];
                        }
                        if (empty($flow['slots']['date']) && !empty($meeting['datetime'])) {
                            try {
                                // $meeting['datetime'] is ISO 8601 from BackendOperations::listUpcomingMeetings
                                $dt = new \DateTime((string) $meeting['datetime']);
                                $dt->setTimezone(new \DateTimeZone((string) ($context['timezone'] ?? 'UTC')));
                                $flow['slots']['date'] = $dt->format('Y-m-d');
                            } catch (\Throwable $e) {
                                // Ignore parsing errors
                            }
                        }
                        if (empty($flow['slots']['original_meeting_time']) && !empty($meeting['datetime'])) {
                            try {
                                // $meeting['datetime'] is ISO 8601 from BackendOperations::listUpcomingMeetings
                                $dt = new \DateTime((string) $meeting['datetime']);
                                $dt->setTimezone(new \DateTimeZone((string) ($context['timezone'] ?? 'UTC')));
                                $flow['slots']['original_meeting_time'] = $dt->format('H:i');
                            } catch (\Throwable $e) {
                                // Ignore
                            }
                        }
                    }
                    // Continue processing (will go to confirmation for cancel or slot collection for reschedule).
                } else {
                    // Multiple meetings: ask user to pick one.
                    $flow['last_asked_slot'] = 'meeting_id';
                    $this->saveFlow($context, $flow);
                    return $this->finish(['assistant_message' => MessageTemplates::showMeetings($listed, true, (string) ($context['meeting_type'] ?? ''), (string) ($flow['intent'] ?? '')), 'executed' => false, 'action_result' => null], $context);
                }
            }

            if ($flow['intent'] !== 'list' && empty($flow['slots']['meeting_id'])) {
                $flow['last_asked_slot'] = 'meeting_id';
                $this->saveFlow($context, $flow);
                return $this->finish(['assistant_message' => MessageTemplates::askForSlot('meeting_id', (string) ($context['meeting_type'] ?? ''), $allowedDurations), 'executed' => false, 'action_result' => null], $context);
            }
        }

        // ─── 5b. Browse mode. ──────────────────────────────────────────────────
        if ($flow['intent'] === 'browse') {
            $browseTime = (string) ($flow['slots']['time'] ?? '');
            $browseDate = (string) ($flow['slots']['date'] ?? '');
            $startDate  = (string) ($flow['slots']['start_date'] ?? '');
            $endDate    = (string) ($flow['slots']['end_date'] ?? '');

            // If user selected a specific date, clear the period (start_date/end_date).
            // They are mutually exclusive: either browsing a period OR a specific day.
            if ($browseDate !== '') {
                unset($flow['slots']['start_date'], $flow['slots']['end_date']);
                $startDate = '';
                $endDate = '';
            }

            // Handle date range queries (e.g. "from june 1-15", "this month", "in july")
            if ($startDate !== '' && $endDate !== '') {
                $rangeResult = $this->tryFinishDateRangeDayList($flow, $context, $allowedDurations);
                if ($rangeResult !== null) {
                    return $this->finish($rangeResult, $context);
                }
            }

            if ($browseDate !== '' && $browseTime !== '') {
                // Both known: user picked a time inside the available windows.
                // If we have a meeting_id, this is a reschedule/cancel flow that went into browse mode.
                // Otherwise, it's a new booking.
                if (!empty($flow['slots']['meeting_id'])) {
                    $flow['intent'] = 'reschedule';
                } else {
                    $flow['intent'] = 'book';
                }
                // Don't return here - let step 6 (for book) or step 7 (for reschedule) handle it.
            } elseif ($browseDate !== '') {
                // Date known, no preferred time: show all windows on that day.
                $windows = $this->backend->getAvailableWindows($browseDate, $context);
                
                // Filter windows by min_time (after X) or max_time (before X) if specified.
                // getAvailableWindows returns {start_local, end_local} as HH:MM strings.
                // "00:00" means midnight = end of day (24:00) — normalise for correct comparison.
                $minTime = (string) ($flow['slots']['min_time'] ?? '');
                $maxTime = (string) ($flow['slots']['max_time'] ?? '');
                if ($minTime !== '' || $maxTime !== '') {
                    $normaliseEndTime = static fn(string $t): string => $t === '00:00' ? '24:00' : $t;
                    $filteredWindows  = [];
                    foreach ($windows as $window) {
                        $windowStart = (string) ($window['start_local'] ?? '');
                        $windowEnd   = $normaliseEndTime((string) ($window['end_local'] ?? ''));
                        if ($windowStart === '' || $windowEnd === '') {
                            continue;
                        }
                        if ($minTime !== '' && $windowEnd <= $minTime) {
                            continue;
                        }
                        if ($maxTime !== '' && $windowStart >= $maxTime) {
                            continue;
                        }
                        $filteredWindows[] = $window;
                    }
                    $windows = $filteredWindows;
                    unset($flow['slots']['min_time'], $flow['slots']['max_time']);
                }
                
                $msg     = MessageTemplates::showAvailableWindows($browseDate, $windows, (string) ($context['timezone'] ?? 'UTC'), null, $allowedDurations);
                if ($windows === []) {
                    $flow['slots']['date'] = null;
                    $flow['last_asked_slot'] = 'date';
                } else {
                    $flow['last_asked_slot'] = 'time';
                }
                $this->saveFlow($context, $flow);
                return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
            } elseif ($browseTime !== '') {
                // Time known, no date: show which days have a free slot at that time.
                // Don't switch to book yet — user is still browsing.
                $duration = !empty($flow['slots']['duration']) ? (int) $flow['slots']['duration'] : 30;
                $days     = $this->backend->getAvailableDays($context, $duration, 14, $browseTime);
                unset($flow['slots']['start_date'], $flow['slots']['end_date']);
                
                if (count($days) === 1) {
                    // Only one day available - auto-select it and switch to book
                    $flow['slots']['date'] = $days[0];
                    $flow['intent'] = 'book';
                    // Let step 6 handle the booking verification
                } else {
                    $msg = MessageTemplates::showAvailableDays($days, (string) ($context['timezone'] ?? 'UTC'));
                    $flow['last_asked_slot'] = 'date';
                    $this->saveFlow($context, $flow);
                    return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
                }
            } else {
                // No date, no time: show all days with any availability.
                // Limit to end-of-week if user mentioned "this week".
                $duration   = !empty($flow['slots']['duration']) ? (int) $flow['slots']['duration'] : 30;
                $maxDays    = 14;
                $lowerMsg   = mb_strtolower($userMessage);
                $fromDate = '';
                
                // "This week" (in any language via LLM) = from today until Sunday (end of current week)
                if (str_contains($lowerMsg, 'this week')) {
                    try {
                        $tz      = new \DateTimeZone((string) ($context['timezone'] ?? 'UTC'));
                        $nowDt   = new \DateTime('now', $tz);
                        $isoDay  = (int) $nowDt->format('N'); // 1 (Mon) through 7 (Sun)
                        // Days to scan from today until Sunday (inclusive)
                        // getAvailableDays uses modify("+{$maxDays} days") which creates [from, to) range
                        // so we need +1 to include the end date (Sunday).
                        // e.g. if today is Wednesday (3), then 8 - 3 = 5 → scanning Wed, Thu, Fri, Sat, Sun
                        $maxDays = max(1, 8 - $isoDay);
                    } catch (\Throwable $e) { /* keep default */ }
                }
                // "Next week" (in any language via LLM) = from next Monday through next Sunday
                elseif (str_contains($lowerMsg, 'next week')) {
                    try {
                        $tz     = new \DateTimeZone((string) ($context['timezone'] ?? 'UTC'));
                        $nowDt  = new \DateTime('now', $tz);
                        $isoDay = (int) $nowDt->format('N'); // 1 (Mon) through 7 (Sun)
                        // Advance to next Monday: if today is Wed (3), then 8 - 3 = 5 days
                        $daysToNextMonday = 8 - $isoDay;
                        $nextMon  = (clone $nowDt)->modify("+{$daysToNextMonday} days");
                        $fromDate = $nextMon->format('Y-m-d');
                        // Next week = Mon through Sun (7 days), but modify creates [from, to) range,
                        // so we need 8 days to include Sunday.
                        $maxDays  = 8;
                    } catch (\Throwable $e) { /* keep default */ }
                }
                $days = $this->backend->getAvailableDays($context, $duration, $maxDays, '', $fromDate);
                unset($flow['slots']['start_date'], $flow['slots']['end_date']);
                
                if (count($days) === 1) {
                    // Only one day available - auto-select it and show time windows
                    $flow['slots']['date'] = $days[0];
                    $windows = $this->backend->getAvailableWindows($days[0], $context);
                    $msg = MessageTemplates::showAvailableWindows($days[0], $windows, (string) ($context['timezone'] ?? 'UTC'), null, $allowedDurations);
                    $flow['last_asked_slot'] = 'time';
                    $this->saveFlow($context, $flow);
                    return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
                } else {
                    $msg = MessageTemplates::showAvailableDays($days, (string) ($context['timezone'] ?? 'UTC'));
                    $flow['last_asked_slot'] = 'date';
                    $this->saveFlow($context, $flow);
                    return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
                }
            }
        }

        // When the user picks a date from the browse day list (and time was
        // already set from a prior query), switch to book so step 6 can verify.
        if ($flow['intent'] === 'book'
            && !empty($flow['slots']['date'])
            && !empty($flow['slots']['time'])
            && empty($flow['slots']['slot_start'])
        ) {
            // Fall through to step 6 which will run checkAvailability.
        }

        // ─── 5c. Viability check for date+time without duration. ──────────
        // IMPORTANT: This check MUST run BEFORE step 6 (availability check).
        // When user picked a specific time on a specific date but hasn't specified
        // duration yet, check upfront whether ANY allowed duration can fit.
        // If NONE of the allowed durations fit, tell the user immediately instead
        // of asking for duration only to reject all options.
        if (in_array($flow['intent'], ['book', 'reschedule'], true)
            && !empty($flow['slots']['date'])
            && !empty($flow['slots']['time'])
            && empty($flow['slots']['duration'])
            && empty($flow['slots']['slot_start'])
        ) {
            $requestedDate = (string) $flow['slots']['date'];
            $requestedTime = (string) $flow['slots']['time'];
            $viableDurations = [];
            
            foreach ($allowedDurations as $dur) {
                $avail = $this->backend->checkAvailability($requestedDate, $requestedTime, $dur, $context);
                if ($avail['free']) {
                    $viableDurations[] = $dur;
                }
            }
            
            if ($viableDurations === []) {
                // None of the allowed durations fit at this time on this day.
                $flow['slots']['time'] = null;
                $flow['last_asked_slot'] = 'time';
                $this->saveFlow($context, $flow);
                return $this->finish([
                    'assistant_message' => MessageTemplates::timeNotViableForAnyDuration(
                        $requestedTime,
                        $requestedDate,
                        (string) ($context['timezone'] ?? 'UTC'),
                        $allowedDurations
                    ),
                    'executed' => false,
                    'action_result' => null,
                ], $context);
            }
            
            // At least one duration fits - proceed normally to step 6 or step 8.
        }

        // ─── 6. Book / reschedule: verify slot is free as soon as date+time. ──
        if (in_array($flow['intent'], ['book', 'reschedule'], true)
            && !empty($flow['slots']['date'])
            && !empty($flow['slots']['time'])
        ) {
            // CRITICAL: Use duration from flow['slots'] if available. This ensures that
            // when the user specified duration=5 and then tries multiple times (17:50, 17:45, etc),
            // we check availability for 5 minutes, not default 30.
            $duration = 30;
            if (!empty($flow['slots']['duration']) && (int) $flow['slots']['duration'] > 0) {
                $duration = (int) $flow['slots']['duration'];
            } elseif (count($allowedDurations) > 0) {
                $duration = (int) $allowedDurations[0];
            }

            $avail = $this->backend->checkAvailability(
                (string) $flow['slots']['date'],
                (string) $flow['slots']['time'],
                $duration,
                $context
            );

            if (!$avail['free']) {
                $busyTime = (string) $flow['slots']['time'];
                $busyDate = (string) $flow['slots']['date'];
                $flow['slots']['time'] = null;
                unset($flow['slots']['slot_start']);

                if ($avail['free_windows'] === []) {
                    // No windows at all on this date — clear the date too so the
                    // next turn asks for a new date, not a new time on the same
                    // fully-blocked day. Otherwise the user gets stuck in a loop.
                    $flow['slots']['date'] = null;
                    $flow['last_asked_slot'] = 'date';
                    $msg = MessageTemplates::noAvailability($busyDate, (string) ($context['timezone'] ?? 'UTC'));
                } else {
                    $flow['last_asked_slot'] = 'time';
                    $msg = MessageTemplates::slotBusy($busyDate, $busyTime, $avail['free_windows'], (string) ($context['timezone'] ?? 'UTC'), $allowedDurations);
                }

                $this->saveFlow($context, $flow);
                return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
            }
            // PHP — not the LLM — owns the Unix timestamp.
            $flow['slots']['slot_start'] = (int) $avail['slot_start'];
        }

        // ─── 5d. Named day + duration, no time yet → show windows or report no availability. ──
        if (in_array($flow['intent'], ['book', 'reschedule', 'browse'], true)
            && !empty($flow['slots']['date'])
            && !empty($flow['slots']['duration'])
            && empty($flow['slots']['time'])
            && empty($flow['slots']['slot_start'])
        ) {
            $namedDate = (string) $flow['slots']['date'];
            $windows   = $this->backend->getAvailableWindows($namedDate, $context);
            if ($windows === []) {
                $flow['slots']['date'] = null;
                $flow['last_asked_slot'] = 'date';
                $this->saveFlow($context, $flow);
                return $this->finish([
                    'assistant_message' => MessageTemplates::noAvailability($namedDate, (string) ($context['timezone'] ?? 'UTC')),
                    'executed'          => false,
                    'action_result'     => null,
                ], $context);
            }
            $msg = MessageTemplates::showAvailableWindows(
                $namedDate,
                $windows,
                (string) ($context['timezone'] ?? 'UTC'),
                null,
                $allowedDurations
            );
            if ($flow['intent'] === 'browse') {
                $flow['intent'] = 'book';
            }
            $flow['last_asked_slot'] = 'time';
            $this->saveFlow($context, $flow);
            return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
        }

        // ─── 7. Auto-fill from authenticated user. ─────────────────────────
        self::autoFillFromContext($flow, $context);

        // ─── 7b. Multi-cancel fast path: meeting_ids (plural) resolved → confirm. ──
        if ($flow['intent'] === 'cancel'
            && !empty($flow['slots']['meeting_ids'])
            && is_array($flow['slots']['meeting_ids'])
            && count($flow['slots']['meeting_ids']) >= 2
        ) {
            $labels = is_array($flow['slots']['meeting_labels'] ?? null)
                ? $flow['slots']['meeting_labels']
                : $flow['slots']['meeting_ids'];
            $flow['status'] = 'confirming';
            $this->saveFlow($context, $flow);
            return $this->finish(['assistant_message' => MessageTemplates::confirmCancelMultiple($labels), 'executed' => false, 'action_result' => null], $context);
        }

        // ─── 8. What's still missing? ──────────────────────────────────────
        $required = self::requiredSlotsFor((string) $flow['intent'], $context);
        $missing  = [];
        foreach ($required as $field) {
            if (empty($flow['slots'][$field])) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            $next = $missing[0];
            // Save old last_asked_slot BEFORE updating it, so we can check if this is
            // the first time asking for identity details.
            $previousAskedSlot = (string) ($flow['last_asked_slot'] ?? '');
            $flow['last_asked_slot'] = $next;
            $this->saveFlow($context, $flow);

            // For non-authenticated users booking a meeting: when the slot is
            // already resolved (prefer_earliest or user-specified + validated)
            // but we still need identity details, show the found slot alongside
            // the question. Without this, users type their name without knowing
            // what they're booking.
            $msg = MessageTemplates::askForSlot($next, (string) ($context['meeting_type'] ?? ''), $allowedDurations);
            if ($flow['intent'] === 'book'
                && !empty($flow['slots']['slot_start'])
                && in_array($next, ['guest_name', 'guest_email', 'phone'], true)
            ) {
                // Only prepend "Found:" if we haven't asked for identity details yet.
                // Prevents duplication when asking for second/third field (e.g. email after name).
                $alreadyAskedIdentity = in_array($previousAskedSlot, ['guest_name', 'guest_email', 'phone'], true);
                
                if (!$alreadyAskedIdentity) {
                    $tz       = (string) ($context['timezone'] ?? 'UTC');
                    $label    = MessageTemplates::formatDateTime((int) $flow['slots']['slot_start'], $tz);
                    $durLabel = MessageTemplates::formatDuration((int) $flow['slots']['duration']);
                    $msg      = "Found: {$label} ({$durLabel}). " . $msg;
                }
            }

            // For reschedule/cancel: when the system auto-selected the only meeting
            // (see lines 684-713), prepend the meeting info so the user knows WHICH
            // meeting they're rescheduling/cancelling. Without this, user sees
            // "What date?" but doesn't know what meeting is being changed.
            if (in_array($flow['intent'], ['reschedule', 'cancel'], true)
                && !empty($flow['slots']['meeting_id'])
                && is_array($flow['listed_meetings'] ?? null)
                && count($flow['listed_meetings']) === 1
            ) {
                // Only prepend on the FIRST question after auto-selection.
                // Check if previous question was about meeting selection.
                $wasSelectingMeeting = in_array($previousAskedSlot, ['meeting_id', 'guest_email'], true) || $previousAskedSlot === '';
                
                if ($wasSelectingMeeting) {
                    $meeting = $flow['listed_meetings'][0];
                    $meetingLabel = (string) ($meeting['label'] ?? '');
                    if ($meetingLabel !== '') {
                        $action = $flow['intent'] === 'reschedule' ? 'Rescheduling' : 'Cancelling';
                        $msg = "{$action}: {$meetingLabel}.\n\n{$msg}";
                    }
                }
            }

            return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
        }

        // ─── 9. All collected → present confirmation. ──────────────────────
        $flow['status'] = 'confirming';
        // Store a snapshot so we can restore if the user later says "deny"
        // during a mid-flow change (e.g. "actually keep the original time").
        $flow['backup_slots'] = $flow['slots'];
        $msg = $this->buildConfirmation($flow, $context);
        $this->saveFlow($context, $flow);
        return $this->finish(['assistant_message' => $msg, 'executed' => false, 'action_result' => null], $context);
    }

    // -----------------------------------------------------------------------
    //  Output: translate the English template into the user's language.
    // -----------------------------------------------------------------------

    /**
     * @param array{assistant_message:string, executed:bool, action_result:?array<string,mixed>} $result
     * @return array{assistant_message:string, executed:bool, action_result:?array<string,mixed>}
     */
    private function finish(array $result, array $context): array
    {
        $lang = (string) ($context['user_language'] ?? 'en');
        $original = $result['assistant_message'];
        $result['assistant_message'] = $this->translator->translate($original, $lang);

        // Pull any fresh Translator LLM calls (cache hits are skipped) into our log.
        foreach ($this->translator->calls as $call) {
            $this->llmCalls[] = [
                'name'     => 'translate',
                'request'  => ['text' => $call['text'], 'lang' => $call['lang']],
                'response' => ['translated' => $call['translated'], 'raw' => $call['raw']],
            ];
        }
        $this->translator->calls = [];
        return $result;
    }

    // -----------------------------------------------------------------------
    //  Execution
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $flow
     * @return array{assistant_message:string, executed:bool, action_result:?array<string,mixed>}
     */
    private function executeFlow(array $flow, array &$context): array
    {
        $intent = (string) ($flow['intent'] ?? '');
        $slots  = is_array($flow['slots'] ?? null) ? $flow['slots'] : [];
        $tz     = (string) ($context['timezone'] ?? 'UTC');

        if ($intent === 'book') {
            $result = $this->backend->book(
                (int) $slots['slot_start'],
                (int) $slots['duration'],
                (string) ($slots['guest_name'] ?? ''),
                (string) ($slots['guest_email'] ?? ''),
                (string) ($slots['phone'] ?? ''),
                $context
            );
            $this->resetFlow($context);
            if (!empty($result['ok'])) {
                return [
                    'assistant_message' => MessageTemplates::bookingSucceeded((int) $slots['slot_start'], (int) $slots['duration'], $tz, !empty($result['awaiting_email_confirmation'])),
                    'executed'          => true,
                    'action_result'     => $result,
                ];
            }
            return ['assistant_message' => MessageTemplates::actionFailed((string) ($result['message'] ?? '')), 'executed' => true, 'action_result' => $result];
        }

        if ($intent === 'reschedule') {
            $originalLabel = $this->meetingLabelForId(
                (string) ($slots['meeting_id'] ?? ''),
                is_array($flow['listed_meetings'] ?? null) ? $flow['listed_meetings'] : []
            );
            $result = $this->backend->reschedule((string) $slots['meeting_id'], (int) $slots['slot_start'], (int) $slots['duration'], $context);
            $this->resetFlow($context);
            if (!empty($result['ok'])) {
                return [
                    'assistant_message' => MessageTemplates::rescheduleSucceeded(
                        (int) $slots['slot_start'],
                        (int) $slots['duration'],
                        $tz,
                        !empty($result['awaiting_email_confirmation']),
                        $originalLabel
                    ),
                    'executed'          => true,
                    'action_result'     => $result,
                ];
            }
            return ['assistant_message' => MessageTemplates::actionFailed((string) ($result['message'] ?? '')), 'executed' => true, 'action_result' => $result];
        }

        if ($intent === 'cancel') {
            // Multi-cancel: meeting_ids is a list of UUIDs.
            if (!empty($slots['meeting_ids']) && is_array($slots['meeting_ids']) && count($slots['meeting_ids']) >= 2) {
                $cancelled = 0;
                $lastErr   = '';
                foreach ($slots['meeting_ids'] as $mid) {
                    $r = $this->backend->cancel((string) $mid, $context);
                    if (!empty($r['ok'])) {
                        $cancelled++;
                    } else {
                        $lastErr = (string) ($r['message'] ?? '');
                    }
                }
                $this->resetFlow($context);
                return [
                    'assistant_message' => $cancelled > 0
                        ? MessageTemplates::cancelSucceededMultiple($cancelled)
                        : MessageTemplates::actionFailed($lastErr),
                    'executed'      => true,
                    'action_result' => ['ok' => $cancelled > 0, 'cancelled_count' => $cancelled],
                ];
            }

            // Single-cancel.
            $result = $this->backend->cancel((string) $slots['meeting_id'], $context);
            $this->resetFlow($context);
            if (!empty($result['ok'])) {
                return [
                    'assistant_message' => MessageTemplates::cancelSucceeded(
                        (string) ($context['meeting_type'] ?? ''),
                        !empty($result['awaiting_email_confirmation'])
                    ),
                    'executed'       => true,
                    'action_result'  => $result,
                ];
            }
            return ['assistant_message' => MessageTemplates::actionFailed((string) ($result['message'] ?? '')), 'executed' => true, 'action_result' => $result];
        }

        $this->resetFlow($context);
        return ['assistant_message' => MessageTemplates::couldNotUnderstand((string) ($context['meeting_type'] ?? '')), 'executed' => false, 'action_result' => null];
    }

    /** @param array<string,mixed> $flow */
    private function buildConfirmation(array $flow, array $context): string
    {
        $tz     = (string) ($context['timezone'] ?? 'UTC');
        $intent = (string) ($flow['intent'] ?? '');
        $slots  = is_array($flow['slots'] ?? null) ? $flow['slots'] : [];

        if ($intent === 'book') {
            // For authenticated users, name + email are auto-filled from the
            // OAuth identity and cannot be changed — echoing them back is just
            // noise. Phone is still meaningful because the user typed it.
            $isAuth = !empty($context['user_is_authenticated']);
            return MessageTemplates::confirmBooking(
                (int) $slots['slot_start'],
                (int) $slots['duration'],
                $tz,
                $isAuth ? null : (isset($slots['guest_name'])  ? (string) $slots['guest_name']  : null),
                $isAuth ? null : (isset($slots['guest_email']) ? (string) $slots['guest_email'] : null),
                isset($slots['phone']) ? (string) $slots['phone'] : null
            );
        }

        if ($intent === 'reschedule' || $intent === 'cancel') {
            $label  = '';
            $listed = is_array($flow['listed_meetings'] ?? null) ? $flow['listed_meetings'] : [];
            foreach ($listed as $m) {
                if (is_array($m) && (string) ($m['id'] ?? '') === (string) ($slots['meeting_id'] ?? '')) {
                    $label = (string) ($m['label'] ?? '');
                    break;
                }
            }
            if ($intent === 'reschedule') {
                return MessageTemplates::confirmReschedule((int) $slots['slot_start'], (int) $slots['duration'], $label, $tz, (string) ($context['meeting_type'] ?? ''));
            }
            return MessageTemplates::confirmCancel($label !== '' ? $label : (string) $slots['meeting_id']);
        }

        return MessageTemplates::couldNotUnderstand((string) ($context['meeting_type'] ?? ''));
    }

    /**
     * @param list<array{id:string,label:string}> $listedMeetings
     */
    private function meetingLabelForId(string $meetingId, array $listedMeetings): string
    {
        if ($meetingId === '') {
            return '';
        }
        foreach ($listedMeetings as $meeting) {
            if (is_array($meeting) && (string) ($meeting['id'] ?? '') === $meetingId) {
                return (string) ($meeting['label'] ?? '');
            }
        }
        return '';
    }

    // -----------------------------------------------------------------------
    //  Flow state helpers
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function loadFlow(array $context): array
    {
        $flow = is_array($context['flow'] ?? null) ? $context['flow'] : [];
        return [
            'intent'          => $flow['intent']          ?? null,
            'status'          => $flow['status']          ?? 'collecting',
            'slots'           => is_array($flow['slots'] ?? null) ? $flow['slots'] : [],
            'last_asked_slot' => $flow['last_asked_slot'] ?? null,
            'listed_meetings' => is_array($flow['listed_meetings'] ?? null) ? $flow['listed_meetings'] : [],
            'backup_slots'    => is_array($flow['backup_slots'] ?? null) ? $flow['backup_slots'] : [],
        ];
    }

    /** @param array<string,mixed> $flow */
    private function saveFlow(array &$context, array $flow): void
    {
        $context['flow'] = $flow;
    }

    private function resetFlow(array &$context): void
    {
        unset($context['flow']);
    }

    /**
     * User accepted a discovered earliest slot — collect any missing identity fields,
     * then present the full booking confirmation (not before).
     *
     * @param array<string,mixed> $flow
     * @return array{assistant_message:string, executed:bool, action_result:?array<string,mixed>}
     */
    private function proceedAfterEarliestAccepted(array $flow, array &$context): array
    {
        $allowedDurations = self::allowedDurationsFromContext($context);
        self::autoFillFromContext($flow, $context);

        $required = self::requiredSlotsFor((string) ($flow['intent'] ?? 'book'), $context);
        $missing  = [];
        foreach ($required as $field) {
            if (empty($flow['slots'][$field])) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            $next = $missing[0];
            $flow['last_asked_slot'] = $next;
            $this->saveFlow($context, $flow);
            return [
                'assistant_message' => MessageTemplates::askForSlot($next, (string) ($context['meeting_type'] ?? ''), $allowedDurations),
                'executed'          => false,
                'action_result'     => null,
            ];
        }

        $flow['status']       = 'confirming';
        $flow['backup_slots'] = $flow['slots'];
        $msg                  = $this->buildConfirmation($flow, $context);
        $this->saveFlow($context, $flow);

        return [
            'assistant_message' => $msg,
            'executed'          => false,
            'action_result'     => null,
        ];
    }

    /**
     * @param array<string,mixed> $flow
     * @return array{assistant_message:string, executed:bool, action_result:?array<string,mixed>}
     */
    private function declineEarliestSlot(array $flow, array &$context): array
    {
        unset($flow['slots']['date'], $flow['slots']['time'], $flow['slots']['slot_start']);
        $flow['last_asked_slot'] = null;
        $this->saveFlow($context, $flow);

        return [
            'assistant_message' => MessageTemplates::earliestSlotDeclined(),
            'executed'          => false,
            'action_result'     => null,
        ];
    }

    /**
     * Required slots per intent. Maps to what the DB / BookingService need:
     *
     *   - email, first_name, last_name, datetime, duration → DB requires
     *     them NOT NULL. Authenticated users get email + name from their
     *     OAuth identity (autoFillFromContext); everyone else is asked.
     *   - phone → ALWAYS required for phone_call, even for authenticated
     *     users. The organisation's OAuth provider exposes only email and
     *     name; phone numbers aren't part of the user profile, so there's
     *     no way for the organiser to know the number unless the booker
     *     tells us. (The manual booking form has a stale rule that skips
     *     phone for OAuth users — that's a bug we deliberately don't copy.)
     *   - subject / description → never asked; subject auto-fills from
     *     schedule.subject inside BookingService.
     *
     * @return list<string>  ordered list of required slots for this intent
     */
    /**
     * @param array<string, mixed> $context
     * @return array<int, int>
     */
    private static function allowedDurationsFromContext(array $context): array
    {
        $raw = $context['allowed_durations'] ?? [15, 30, 45, 60, 90];
        if (!is_array($raw) || $raw === []) {
            return [15, 30, 45, 60, 90];
        }
        $clean = [];
        foreach ($raw as $v) {
            $n = (int) $v;
            if ($n >= 1 && $n <= 1440) {
                $clean[$n] = true;
            }
        }
        if ($clean === []) {
            return [15, 30, 45, 60, 90];
        }
        $list = array_keys($clean);
        sort($list, SORT_NUMERIC);
        return $list;
    }

    private static function requiredSlotsFor(string $intent, array $context): array
    {
        $isAuthenticated = !empty($context['user_is_authenticated']);
        $meetingType     = (string) ($context['meeting_type'] ?? '');

        switch ($intent) {
            case 'book':
                $slots = ['date', 'time', 'duration'];
                if (!$isAuthenticated) {
                    $slots[] = 'guest_name';
                    $slots[] = 'guest_email';
                }
                if ($meetingType === 'phone_call') {
                    $slots[] = 'phone';
                }
                $slots[] = 'slot_start'; // computed by PHP after availability check
                return $slots;
            case 'reschedule':
                return ['meeting_id', 'date', 'time', 'duration', 'slot_start'];
            case 'cancel':
                return ['meeting_id'];
            case 'list':
                return [];
            case 'browse':
                return []; // date is optional — step 5b shows days if no date, windows if date is set
        }
        return [];
    }

    private static function autoFillFromContext(array &$flow, array $context): void
    {
        // Authenticated users: identity from OAuth / WP login.
        // Non-authenticated users: identity remembered from earlier in this chat.
        if (empty($flow['slots']['guest_email'])) {
            $candidate = (string) ($context['user_email'] ?? $context['guest_email'] ?? '');
            if ($candidate !== '') {
                $flow['slots']['guest_email'] = $candidate;
            }
        }
        if (empty($flow['slots']['guest_name'])) {
            $candidate = (string) ($context['user_display_name'] ?? $context['guest_name'] ?? '');
            if ($candidate !== '') {
                $flow['slots']['guest_name'] = $candidate;
            }
        }
        // If the schedule has exactly one allowed duration, auto-fill it — the
        // user has no choice anyway and should not be asked.
        if (empty($flow['slots']['duration'])) {
            $allowed = is_array($context['allowed_durations'] ?? null) ? $context['allowed_durations'] : [];
            if (count($allowed) === 1) {
                $flow['slots']['duration'] = (int) $allowed[0];
            }
        }
    }

    private static function needsGuestEmail(array $flow, array $context): bool
    {
        if (empty($context['is_public']) || !empty($context['user_is_authenticated'])) {
            return false;
        }
        if (!empty($context['user_email']) || !empty($context['guest_email']) || !empty($flow['slots']['guest_email'])) {
            return false;
        }
        return true;
    }

    // -----------------------------------------------------------------------
    //  Slot validation
    // -----------------------------------------------------------------------

    /**
     * @return array{valid:bool, value?:mixed}
     */
    private static function validateSlot(string $field, $value, array $context): array
    {
        $tz = (string) ($context['timezone'] ?? 'UTC');

        switch ($field) {
            case 'date':
                if (!is_string($value)) {
                    return ['valid' => false];
                }
                $normalized = SlotExtractor::normalizeFutureCalendarDate($value, $tz);
                if ($normalized === null) {
                    return ['valid' => false];
                }

                return ['valid' => true, 'value' => $normalized];

            case 'start_date':
            case 'end_date':
                if (!is_string($value)) {
                    return ['valid' => false];
                }
                $normalized = SlotExtractor::normalizeRangeCalendarDate($value, $tz);
                if ($normalized === null) {
                    return ['valid' => false];
                }

                return ['valid' => true, 'value' => $normalized];

            case 'time':
                if (!is_string($value) || !preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
                    return ['valid' => false];
                }
                $h  = (int) $m[1];
                $mi = (int) $m[2];
                if ($h < 0 || $h > 23 || $mi < 0 || $mi > 59) {
                    return ['valid' => false];
                }
                // Round minutes to the nearest 15-minute interval (0, 15, 30, 45)
                // to avoid odd times like 20:37. Calendar slots are typically on a 15-min grid.
                $roundedMinutes = (int) (round($mi / 15) * 15);
                if ($roundedMinutes >= 60) {
                    $h = ($h + 1) % 24;
                    $roundedMinutes = 0;
                }
                return ['valid' => true, 'value' => sprintf('%02d:%02d', $h, $roundedMinutes)];

            case 'min_time':
            case 'max_time':
                if (!is_string($value) || !preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
                    return ['valid' => false];
                }
                $h  = (int) $m[1];
                $mi = (int) $m[2];
                if ($h < 0 || $h > 23 || $mi < 0 || $mi > 59) {
                    return ['valid' => false];
                }
                return ['valid' => true, 'value' => sprintf('%02d:%02d', $h, $mi)];

            case 'duration':
                $intVal = SlotExtractor::parseDurationMinutes($value);
                if ($intVal === null || $intVal < 5 || $intVal > 1440) {
                    return ['valid' => false];
                }
                $allowed = is_array($context['allowed_durations'] ?? null) ? $context['allowed_durations'] : [];
                if (!empty($allowed) && !in_array($intVal, $allowed, true)) {
                    return [
                        'valid'           => false,
                        'fixed_violation' => count($allowed) === 1,
                        'fixed_value'     => count($allowed) === 1 ? (int) $allowed[0] : null,
                        'allowed'         => $allowed,
                    ];
                }
                return ['valid' => true, 'value' => $intVal];

            case 'meeting_index':
                $intVal = (int) $value;
                return $intVal >= 1 ? ['valid' => true, 'value' => $intVal] : ['valid' => false];

            case 'guest_email':
                if (!is_string($value) || !filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
                    return ['valid' => false];
                }
                return ['valid' => true, 'value' => trim($value)];

            case 'guest_name':
                if (!is_string($value) || trim($value) === '') {
                    return ['valid' => false];
                }
                return ['valid' => true, 'value' => trim($value)];

            case 'phone':
                if (!is_string($value)) {
                    return ['valid' => false];
                }
                $clean = preg_replace('/[^\d+]/', '', $value) ?? '';
                if (strlen($clean) < 6) {
                    return ['valid' => false];
                }
                return ['valid' => true, 'value' => trim($value)];

            case 'prefer_earliest':
                return $value === true ? ['valid' => true, 'value' => true] : ['valid' => false];
        }
        return ['valid' => false];
    }

    /**
     * Keep only meetings whose start time falls within the requested period.
     *
     * @param list<array{id:string,label:string,datetime:string,duration:int}> $meetings
     * @return list<array{id:string,label:string,datetime:string,duration:int}>
     */
    private function filterMeetingsInRange(
        array $meetings,
        string $startDate,
        string $endDate,
        string $singleDate,
        array $context
    ): array {
        if ($singleDate !== '' && $startDate === '' && $endDate === '') {
            $startDate = $singleDate;
            $endDate   = $singleDate;
        }
        if ($startDate === '' && $endDate === '') {
            return $meetings;
        }

        try {
            $tz    = new DateTimeZone((string) ($context['timezone'] ?? 'UTC'));
            $start = new DateTime(($startDate !== '' ? $startDate : $endDate) . ' 00:00:00', $tz);
            $end   = new DateTime(($endDate !== '' ? $endDate : $startDate) . ' 23:59:59', $tz);
        } catch (\Throwable $e) {
            return $meetings;
        }

        $filtered = [];
        foreach ($meetings as $meeting) {
            if (!is_array($meeting) || empty($meeting['datetime'])) {
                continue;
            }
            try {
                $dt = new DateTime((string) $meeting['datetime']);
                $dt->setTimezone($tz);
            } catch (\Throwable $e) {
                continue;
            }
            if ($dt >= $start && $dt <= $end) {
                $filtered[] = $meeting;
            }
        }

        return $filtered;
    }

    /**
     * True when the user asked for meetings starting after the backend list window.
     */
    private function isListPeriodBeyondHorizon(
        string $startDate,
        string $endDate,
        string $singleDate,
        array $context
    ): bool {
        if ($singleDate !== '' && $startDate === '' && $endDate === '') {
            $startDate = $singleDate;
        }
        if ($startDate === '') {
            return false;
        }

        try {
            $tz      = new DateTimeZone((string) ($context['timezone'] ?? 'UTC'));
            $now     = new DateTime('now', $tz);
            $horizon = (clone $now)->modify('+' . BackendOperations::MEETING_LIST_HORIZON_MONTHS . ' months');
            $start   = new DateTime($startDate . ' 00:00:00', $tz);
        } catch (\Throwable $e) {
            return false;
        }

        return $start > $horizon;
    }

    /**
     * When start_date and end_date are set but no specific day yet, list available
     * days in that range instead of asking "which day?".
     *
     * @param array<string,mixed> $flow
     * @param array<string,mixed> $context
     * @param int[] $allowedDurations
     * @return array{assistant_message:string, executed:bool, action_result:?mixed}|null
     */
    private function tryFinishDateRangeDayList(array &$flow, array $context, array $allowedDurations): ?array
    {
        $startDate = (string) ($flow['slots']['start_date'] ?? '');
        $endDate   = (string) ($flow['slots']['end_date'] ?? '');
        if ($startDate === '' || $endDate === '') {
            return null;
        }

        try {
            $tz       = new DateTimeZone((string) ($context['timezone'] ?? 'UTC'));
            $start    = new DateTime($startDate, $tz);
            $end      = new DateTime($endDate, $tz);
            $daysDiff = (int) $start->diff($end)->days + 1;
            $duration = !empty($flow['slots']['duration']) ? (int) $flow['slots']['duration'] : 30;

            $days = $this->backend->getAvailableDays($context, $duration, $daysDiff, '', $startDate);

            $filteredDays = [];
            foreach ($days as $day) {
                $dayDt = new DateTime($day, $tz);
                if ($dayDt >= $start && $dayDt <= $end) {
                    $filteredDays[] = $day;
                }
            }

            if ($filteredDays === []) {
                $startLabel = $start->format('F j');
                $endLabel   = $end->format('F j, Y');
                $msg = "I'm sorry, there are no available days in the period from {$startLabel} to {$endLabel}. Would you like to try a different date range?";
                unset($flow['slots']['start_date'], $flow['slots']['end_date']);
                $flow['last_asked_slot'] = 'date';
                $this->saveFlow($context, $flow);
                return ['assistant_message' => $msg, 'executed' => false, 'action_result' => null];
            }

            if (count($filteredDays) === 1) {
                $flow['slots']['date'] = $filteredDays[0];
                unset($flow['slots']['start_date'], $flow['slots']['end_date']);
                $windows = $this->backend->getAvailableWindows($filteredDays[0], $context);
                $msg = MessageTemplates::showAvailableWindows(
                    $filteredDays[0],
                    $windows,
                    (string) ($context['timezone'] ?? 'UTC'),
                    null,
                    $allowedDurations
                );
                $flow['last_asked_slot'] = 'time';
                $this->saveFlow($context, $flow);
                return ['assistant_message' => $msg, 'executed' => false, 'action_result' => null];
            }

            $msg = MessageTemplates::showAvailableDays($filteredDays, (string) ($context['timezone'] ?? 'UTC'));
            unset($flow['slots']['start_date'], $flow['slots']['end_date']);
            $flow['last_asked_slot'] = 'date';
            $this->saveFlow($context, $flow);
            return ['assistant_message' => $msg, 'executed' => false, 'action_result' => null];
        } catch (\Throwable $e) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    //  English fast-path for yes/no
    //
    //  Customers using other languages fall through to the LLM intent
    //  classifier ("confirm" / "deny") in step 2b — no hardcoded word lists
    //  per language so this stays maintainable.
    // -----------------------------------------------------------------------

    public static function isConfirmation(string $msg): bool
    {
        $m = strtolower(trim($msg));
        if ($m === '' || strlen($m) > 30 || str_ends_with($m, '?')) {
            return false;
        }
        foreach (['yes', 'yeah', 'yep', 'yup', 'ok', 'okay', 'sure', 'confirm', 'go ahead', 'do it', 'proceed', 'y'] as $w) {
            if (self::startsWithWord($m, $w)) {
                return true;
            }
        }
        return false;
    }

    public static function isDenial(string $msg): bool
    {
        $m = strtolower(trim($msg));
        if ($m === '' || strlen($m) > 30 || str_ends_with($m, '?')) {
            return false;
        }
        foreach (['no', 'nope', 'cancel', 'stop', 'n'] as $w) {
            if (self::startsWithWord($m, $w)) {
                return true;
            }
        }
        return false;
    }

    private static function startsWithWord(string $msg, string $word): bool
    {
        $wLen = strlen($word);
        if (strncmp($msg, $word, $wLen) !== 0) {
            return false;
        }
        $next = $msg[$wLen] ?? '';
        return $next === '' || ctype_space($next) || in_array($next, ['.', '!', ','], true);
    }
}
