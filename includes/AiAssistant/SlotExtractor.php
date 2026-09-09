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
 * The primary LLM call in the conversation. Per turn, this class asks the model
 * to extract two things from the user's message:
 *
 *   1. Intent classification: "book", "reschedule", "cancel", "list", "other"
 *   2. Slot values mentioned in this turn: date, time, duration, ...
 *
 * When the main extractor leaves intent=null on a full user command (common with
 * multilingual messages), a tiny English-only fallback classifier may run once.
 *
 * The model never:
 *   - Decides what to do next
 *   - Generates user-facing text
 *   - Calls backend functions
 *   - Computes Unix timestamps
 *
 * PHP does all of that downstream. The model's only job is to turn natural
 * language into structured JSON. That is what small local models (ministral
 * 3:14b, llama3) can do reliably — anything else they hallucinate.
 *
 * The endpoint is called with `format: json` (Ollama) so the response is
 * guaranteed to be a JSON object.
 */
final class SlotExtractor
{
    private const ALLOWED_INTENTS = ['book', 'reschedule', 'cancel', 'list', 'browse', 'confirm', 'deny', 'other'];
    private const SLOT_KEYS = ['date', 'time', 'duration', 'meeting_index', 'meeting_indices', 'guest_name', 'guest_email', 'phone', 'prefer_earliest', 'prefer_latest', 'original_meeting_time', 'start_date', 'end_date', 'weekday_iso', 'week_scope', 'min_time', 'max_time'];

    public function __construct(
        private AiLlmClient $client
    ) {
    }

    /**
     * @param array<string,mixed> $flowState  Current `flow` state from context_json
     * @param array<string,mixed> $context    Full chat context (timezone, locale, etc.)
     * @return array{intent: ?string, slots: array<string,mixed>, raw_content: string}
     *         `raw_content` is the unparsed LLM output — useful for `ai_chat_action_log`
     *         debugging when extraction is wrong.
     */
    public function extract(string $userMessage, array $flowState, array $context): array
    {
        $tz       = (string) ($context['timezone'] ?? 'UTC');
        $nowDt    = new DateTime('now', new DateTimeZone($tz));
        $today    = $nowDt->format('Y-m-d');
        $todayDow = $nowDt->format('l');

        $weekParts = [];
        for ($d = 0; $d < 7; $d++) {
            $dayDt       = (clone $nowDt)->modify("+{$d} days");
            $label = $dayDt->format('l');
            // Add explicit "Tomorrow" label for d=1 to prevent LLM confusion.
            if ($d === 1) {
                $label .= ' (Tomorrow)';
            }
            $isoDow = (int) $dayDt->format('N'); // 1=Mon .. 7=Sun
            $weekParts[] = '  ' . $label . ' (ISO ' . $isoDow . ') = ' . $dayDt->format('Y-m-d');
        }
        $weekCalendar = "\n" . implode("\n", $weekParts);

        $currentSlots = is_array($flowState['slots'] ?? null) ? $flowState['slots'] : [];
        $intentNow    = isset($flowState['intent']) && is_string($flowState['intent']) ? $flowState['intent'] : null;
        $askedSlot    = isset($flowState['last_asked_slot']) && is_string($flowState['last_asked_slot'])
            ? $flowState['last_asked_slot'] : '';
        $awaitingConfirm = ($flowState['status'] ?? '') === 'confirming';
        $backupSlots = is_array($flowState['backup_slots'] ?? null) ? $flowState['backup_slots'] : [];

        $allowedDurations = is_array($context['allowed_durations'] ?? null) && $context['allowed_durations'] !== []
            ? array_values(array_map('intval', $context['allowed_durations']))
            : [15, 30, 45, 60, 90];

        // Preprocess: detect browse commands (show/check/view/etc) before LLM.
        // "Find earliest slot" starts with "find" but is a booking request, not browse.
        $isPreferEarliest = $this->isPreferEarliestRequest($userMessage);
        $phraseIntent     = $this->detectIntentPhrase($userMessage);
        // "Show/list my meetings" is intent=list, not browse — do not force browse.
        $isBrowseCommand  = !$isPreferEarliest
            && $phraseIntent !== 'list'
            && $this->detectBrowseCommand($userMessage);

        // Preprocess: "after/before X time" → min_time/max_time before any LLM call.
        $afterBeforeSlots = $this->preprocessAfterBeforeTime($userMessage);

        // Preprocess date/time hints before intent detection (needed to decide early-return).
        $preprocessedSlots = $this->preprocessMiddleOfNextWeek($userMessage, $nowDt);
        if ($preprocessedSlots === []) {
            $preprocessedSlots = $this->preprocessRelativeDay($userMessage, $nowDt);
        }
        if ($preprocessedSlots === []) {
            $preprocessedSlots = $this->preprocessWeekRange($userMessage, $nowDt);
        }
        if ($preprocessedSlots === []) {
            $preprocessedSlots = $this->preprocessMonthDayRange($userMessage, $nowDt);
        }
        if ($preprocessedSlots === []) {
            $preprocessedSlots = $this->preprocessWeekdayName($userMessage, $nowDt);
        }

        // Detect clear intent phrases (reschedule/cancel/list/book) before LLM.
        // Skip LLM only when the message is a bare command with no scheduling detail.
        if ($phraseIntent !== null
            && !$isBrowseCommand
            && $preprocessedSlots === []
            && $afterBeforeSlots === []
            && !$this->messageContainsSchedulingDetail($userMessage)
        ) {
            return ['intent' => $phraseIntent, 'slots' => [], 'raw_content' => ''];
        }
        
        $system = $this->buildSystemPrompt($today, $todayDow, $tz, $weekCalendar, $allowedDurations);
        $user   = $this->buildUserPrompt($userMessage, $intentNow, $currentSlots, $askedSlot, $awaitingConfirm, $backupSlots);

        try {
            $response = $this->client->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                ['temperature' => 0.0, 'max_tokens' => 250]
            );
        } catch (\Throwable $e) {
            error_log('[SlotExtractor] LLM call failed: ' . $e->getMessage());
            return ['intent' => null, 'slots' => [], 'raw_content' => 'ERROR: ' . $e->getMessage()];
        }

        $content = (string) ($response['content'] ?? '');
        $parsed  = $this->parseResponse($content);
        $parsed['raw_content'] = $content;
        $this->stripPlaceholderSlotValues($parsed);
        $this->recoverWeekdayIsoFromRaw($parsed, $content);
        $this->recoverWeekScopeFromRaw($parsed, $content);
        $this->recoverIntentFromRaw($parsed, $content);
        $this->recoverListIntentFromRaw($parsed, $content);
        $this->recoverListIntentFromMessage($parsed, $userMessage);

        // Apply after/before-time constraints BEFORE sanitizePreferEarliest so that
        // prefer_earliest (set by LLM for "after X") is replaced here and not stripped.
        if ($afterBeforeSlots !== []) {
            unset($parsed['slots']['time'], $parsed['slots']['prefer_earliest'], $parsed['slots']['prefer_latest']);
            $parsed['slots'] = array_merge($parsed['slots'] ?? [], $afterBeforeSlots);
        }

        // Merge preprocessed slots (higher priority than LLM output for specific patterns).
        if ($preprocessedSlots !== []) {
            $parsed['slots'] = array_merge($parsed['slots'] ?? [], $preprocessedSlots);
            if (!empty($preprocessedSlots['date'])) {
                unset($parsed['slots']['prefer_earliest'], $parsed['slots']['start_date'], $parsed['slots']['end_date']);
            }
            if (!empty($preprocessedSlots['start_date']) || !empty($preprocessedSlots['end_date'])) {
                unset($parsed['slots']['date'], $parsed['slots']['prefer_earliest']);
            }
        }

        $this->sanitizePreferEarliest($parsed, $userMessage);
        $this->applyWeekScope($parsed, $nowDt);
        $this->applyWeekdayIso($parsed, $nowDt, $currentSlots);
        $this->sanitizeInvertedDateRange($parsed);

        if ($isPreferEarliest) {
            $parsed['slots']['prefer_earliest'] = true;
            if (($parsed['intent'] ?? null) !== 'reschedule') {
                $parsed['intent'] = 'book';
            }
        }
        
        // Override intent if this is a browse command (show/check/view/what/which/when).
        // Never override when the user asked for the globally earliest slot or is listing meetings.
        if ($isBrowseCommand
            && empty($parsed['slots']['prefer_earliest'])
            && ($parsed['intent'] ?? null) !== 'list'
        ) {
            $parsed['intent'] = 'browse';
            unset($parsed['slots']['prefer_earliest']);
        }
        
        // Context-based intent correction (language-agnostic):
        // 'deny' can ONLY occur during confirmation (when user rejects a proposed booking).
        // If LLM returned 'deny' but we're NOT awaiting confirmation, it likely misinterpreted
        // a cancellation command as 'deny'. Override to 'cancel'.
        if ($parsed['intent'] === 'deny' && !$awaitingConfirm) {
            $parsed['intent'] = 'cancel';
        }

        // LLM often returns book for reschedule/cancel phrases — trust PHP phrase detection.
        $this->applyPhraseIntentOverride($parsed, $phraseIntent, $awaitingConfirm);

        if ($this->needsIntentClassifierFallback($parsed, $userMessage, $askedSlot, $awaitingConfirm)) {
            $fallbackIntent = $this->classifyIntentFallback($userMessage);
            if ($fallbackIntent !== null) {
                $parsed['intent'] = $fallbackIntent;
            }
        }
        
        // Fallback: if LLM still set time+prefer_earliest/prefer_latest on browse intent
        // and the PHP preprocess didn't catch it, convert now.
        if ($parsed['intent'] === 'browse' && !empty($parsed['slots']['time'])) {
            if (!empty($parsed['slots']['prefer_earliest'])) {
                $parsed['slots']['min_time'] = $parsed['slots']['time'];
                unset($parsed['slots']['time'], $parsed['slots']['prefer_earliest']);
            } elseif (!empty($parsed['slots']['prefer_latest'])) {
                $parsed['slots']['max_time'] = $parsed['slots']['time'];
                unset($parsed['slots']['time'], $parsed['slots']['prefer_latest']);
            }
        }
        
        return $parsed;
    }

    /**
     * @param array<int, int> $allowedDurations
     */
    private function buildSystemPrompt(string $today, string $todayDow, string $tz, string $weekCalendar, array $allowedDurations): string
    {
        $allowedStr = implode(', ', $allowedDurations);
        return "You are a structured information extractor for a meeting-scheduling chat.\n"
            . "Today: {$today} ({$todayDow}). Timezone: {$tz}.\n"
            . "Next 7 days calendar: {$weekCalendar}.\n"
            . "Allowed durations (minutes): {$allowedStr}.\n"
            . "\n"
            . "⚠️ CRITICAL OUTPUT FORMAT:\n"
            . "- Output ONLY ONE valid JSON object.\n"
            . "- NO comments, NO explanations, NO prose, NO markdown.\n"
            . "- NO text before or after the JSON.\n"
            . "- NO duplicate keys in JSON.\n"
            . "- Just the JSON object, nothing else.\n"
            . "\n"
            . "CRITICAL INSTRUCTIONS FOR DATE EXTRACTION:\n"
            . "1. For 'today': Use the exact date shown in 'Today: YYYY-MM-DD (Weekday)' above.\n"
            . "2. For 'tomorrow': Take the date from 'Today' and add 1 day.\n"
            . "3. For weekday references (any human language): map the meaning to the matching English weekday / ISO number in 'Next 7 days calendar', then copy that line's YYYY-MM-DD into slots.date.\n"
            . "   - NEVER set prefer_earliest when the user names a weekday or calendar day — they asked about THAT day, not the global earliest slot.\n"
            . "   - When the user asks to find/show/check availability on a weekday, intent=browse and slots.date must be set from the calendar.\n"
            . "4. NEVER use placeholders. NEVER output the literal string YYYY-MM-DD. Copy real dates from the calendar.\n"
            . "\n"
            . "Schema:\n"
            . "{\n"
            . "  \"intent\": \"book\"|\"reschedule\"|\"cancel\"|\"list\"|\"confirm\"|\"deny\"|\"other\"|null,\n"
            . "  NEVER add an \"action\" field — put cancel/reschedule/book/list in \"intent\" only.\n"
            . "  \"slots\": {\n"
            . "    \"date\":           null | \"<real date copied from calendar>\",\n"
            . "    \"time\":           \"HH:MM\" 24-hour | null,\n"
            . "    \"duration\":       <integer minutes> | null,\n"
            . "    \"meeting_index\":  <1-based integer> | null  -- CRITICAL: Use when user picks ONE meeting from a list by NUMBER or ORDINAL (e.g., '1', '2', '3', 'first', 'second', 'the first one'). NEVER use 'meeting_id'.\n"
            . "    \"meeting_indices\": [<integers>] | null  -- Use ONLY when user picks MULTIPLE meetings (e.g., '1 and 3', 'meetings 2, 4'). For a SINGLE meeting, use meeting_index instead.\n"
            . "    \"guest_name\":     \"<string>\" | null,\n"
            . "    \"guest_email\":    \"<email>\" | null,\n"
            . "    \"phone\":          \"<digits>\" | null  -- CRITICAL: Field name is 'phone', NOT 'guest_phone' or 'phone_number'.\n"
            . "    \"prefer_earliest\": true | null,  -- Use for 'after X time' phrases\n"
            . "    \"prefer_latest\":   true | null,  -- Use for 'before X time' phrases\n"
            . "    \"start_date\":     \"YYYY-MM-DD\" | null,\n"
            . "    \"end_date\":       \"YYYY-MM-DD\" | null,\n"
            . "    \"weekday_iso\":    <integer 1-7, Monday=1 .. Sunday=7> | null  -- when user names a weekday in any language but you need PHP to resolve the date\n"
            . "    \"week_scope\":     null | \"this_week\" | \"next_week\"  -- when user means this week or next week in ANY language. NEVER compute start_date/end_date for week phrases — PHP resolves dates from week_scope.\n"
            . "  }\n"
            . "}\n"
            . "\n"
            . "Example: User says 'reschedule' -> {\"intent\": \"reschedule\", \"slots\": {}}\n"
            . "Example: User says 'cancel my meeting' (any language) -> {\"intent\": \"cancel\", \"slots\": {}}\n"
            . "Example: User says 'second' or '2' when picking ONE meeting -> {\"intent\": null, \"slots\": {\"meeting_index\": 2}}\n"
            . "Example: User says '1 and 3' when picking MULTIPLE meetings -> {\"intent\": null, \"slots\": {\"meeting_indices\": [1, 3]}}\n"
            . "Example: User says 'show me all times' -> {\"intent\": \"browse\", \"slots\": {}}\n"
            . "Example: User says 'show my upcoming meetings' -> {\"intent\": \"list\", \"slots\": {}}\n"
            . "Example: User says '3pm' -> {\"intent\": null, \"slots\": {\"time\": \"15:00\"}}\n"
            . "\n"
            . "RULES (apply in order — check EACH rule from top to bottom):\n"
            . "- CRITICAL RULE #0a — MY MEETINGS vs AVAILABILITY (check BEFORE rule #0):\n"
            . "    * User wants to see THEIR booked / scheduled / upcoming meetings (in any language) -> intent=list.\n"
            . "    * Keywords meaning list: my meetings, upcoming meetings, scheduled meetings, what meetings do I have, meetings I booked.\n"
            . "    * This is NOT browse. browse is ONLY for free/available time slots on the calendar.\n"
            . "    * When the user asks about THEIR meetings WITHIN a period, intent=list AND extract the period slots:\n"
            . "        - 'this week' / 'next week' (any language) -> week_scope=\"this_week\" or \"next_week\" (NOT browse).\n"
            . "        - A named month ('in July', 'during August', any language) -> start_date + end_date for that month's first/last day.\n"
            . "        - Explicit ranges ('from June 1 to June 15') -> start_date + end_date.\n"
            . "        - A single calendar day or weekday -> date (or weekday_iso).\n"
            . "    * NEVER use intent=browse when the user asks which meetings they already have scheduled.\n"
            . "    * Example: 'what meetings do I have next week' -> {\"intent\": \"list\", \"slots\": {\"week_scope\": \"next_week\"}}\n"
            . "    * Example: 'my scheduled meetings in July' -> {\"intent\": \"list\", \"slots\": {\"start_date\": \"2026-07-01\", \"end_date\": \"2026-07-31\"}}\n"
            . "    * Example: 'which meetings do I have this week' (any human language) -> {\"intent\": \"list\", \"slots\": {\"week_scope\": \"this_week\"}}\n"
            . "    * NEVER return intent=null for meeting-list questions — always intent=list.\n"
            . "- CRITICAL RULE #0 — availability / find-time requests (any language):\n"
            . "    * If the user asks to find, show, check, see, or list AVAILABLE times/slots (NOT their own meetings), intent=browse — NOT book.\n"
            . "    * If they ask what slots are available in a week or date range without naming one day, intent=browse and set week_scope (this_week/next_week) or start_date+end_date — do NOT use intent=book.\n"
            . "    * NEVER set prefer_earliest for these messages unless they explicitly ask for the earliest slot overall with no named day (as soon as possible / next free time).\n"
            . "    * If a weekday or calendar day is mentioned, set slots.date from 'Next 7 days calendar' (exact line match). Do NOT leave date null.\n"
            . "\n"
            . "- CRITICAL RULE #1 — BROWSE vs BOOK distinction (only check if rule #0 did not apply):\n"
            . "    * Commands with 'book', 'schedule', 'reserve', 'set up', 'arrange' = intent=book (user wants to COMMIT to a time)\n"
            . "    * Commands asking about availability without booking = intent=browse\n"
            . "    * Example BROWSE: 'are you free friday', 'is monday available' -> intent=browse\n"
            . "    * Example BOOK: 'book a meeting friday', 'schedule for 3pm', 'reserve a slot' -> intent=book\n"
            . "- CRITICAL PRIORITY 2: Detect intent commands in ANY language (SKIP this rule if RULE #0 already matched - i.e. message starts with show/check/view/what/which/when):\n"
            . "    * FIRST: Check if the message contains a verb (book/cancel/reschedule/delete) + noun (meeting/appointment/booking). If yes:\n"
            . "        - verb(book/schedule/reserve) + noun(meeting/appointment) -> intent=book (ONLY if message does NOT start with show/check/view/what/which/when)\n"
            . "        - verb(cancel/delete/remove) + noun(meeting/appointment/booking) -> intent=cancel\n"
            . "        - verb(reschedule/change/move/rebook/replan) + noun(meeting/appointment) -> intent=reschedule (NOT book)\n"
            . "        - CRITICAL: Any request to change, move, or replan an EXISTING meeting (in any human language) -> intent=reschedule, NEVER book. intent=book is ONLY for a brand-new meeting.\n"
            . "    * IMPORTANT: If the message starts with 'show'/'check'/'view'/'what'/'which'/'when', DO NOT apply this rule - intent is already browse from RULE #0.\n"
            . "    * SECOND: If NO noun detected, check if it's a single standalone command word:\n"
            . "        - ONE word meaning 'Book/Schedule/Reserve' alone -> intent=book\n"
            . "        - ONE word meaning 'Cancel/Delete/Remove' alone -> intent=cancel\n"
            . "        - ONE word meaning 'Reschedule/Rebook/Change' alone -> intent=reschedule\n"
            . "        - ONE word meaning 'List/Meetings' alone -> intent=list\n"
            . "    * The user message may be in ANY language. Always classify based on MEANING (what the words mean), not literal English words.\n"
            . "- CRITICAL: LIST overrides Current intent (check BEFORE intent preservation):\n"
            . "    * If the user asks to see THEIR scheduled / upcoming / booked meetings (any language), ALWAYS return intent=list — even when Current intent is book, reschedule, or browse.\n"
            . "    * NEVER return intent=null for this case. null is wrong; list is correct.\n"
            . "    * Example: Current intent=book, User asks what meetings they have scheduled -> {\"intent\": \"list\", \"slots\": {}}\n"
            . "- CRITICAL: Intent preservation rules:\n"
            . "    * If 'Current intent' is already set (not null) AND the user is providing slot values (date, time, duration, etc.) or changing them, you MUST preserve the Current intent. Return the same intent value in your response.\n"
            . "    * This applies EVEN during confirmation (when 'Awaiting yes/no confirmation: true'). If user modifies a slot instead of saying yes/no, preserve the Current intent.\n"
            . "    * Examples:\n"
            . "        - Current intent: reschedule, User: 'to <date> at 18:15' -> intent=reschedule (preserve it).\n"
            . "        - Current intent: book, User: '15:30' -> intent=book (preserve it).\n"
            . "        - Current intent: reschedule, Awaiting confirmation: true, User: 'later' or 'even later' -> intent=reschedule (preserve it).\n"
            . "        - Current intent: book, Awaiting confirmation: true, User: 'change time to 3pm' -> intent=book (preserve it).\n"
            . "    * EXCEPTION: If the user explicitly says a NEW action ('no, I want to cancel it instead' / 'actually book a new meeting' / 'list my meetings'), extract the NEW intent and discard Current intent.\n"
            . "- The user message may be in ANY language. Classify the meaning, not the literal words.\n"
            . "- CRITICAL: If the user prompt contains 'Last asked: guest_name' and the user replies with a short text (1-3 words, no '@' symbol, no URL), extract it as guest_name even if it's ambiguous. The user message may be in any language. Examples:\n"
            . "    * User: 'John' -> {\"intent\": null, \"slots\": {\"guest_name\": \"John\"}}\n"
            . "    * User: 'Maria Garcia' -> {\"intent\": null, \"slots\": {\"guest_name\": \"Maria Garcia\"}}\n"
            . "- CRITICAL: If the user prompt contains 'Last asked: guest_email' and the user replies with text containing '@', extract it as guest_email. If no '@' but looks like an email was attempted, still extract it.\n"
            . "- CRITICAL: If the user prompt contains 'Last asked: phone' and the user replies with digits, extract it as phone.\n"
            . "- CRITICAL: If the user prompt contains 'Last asked: date' and the user replies with ONLY a weekday name (any language):\n"
            . "    * Set slots.weekday_iso from 'Next 7 days calendar' (1=Mon .. 7=Sun). Set slots.date to null.\n"
            . "    * Do NOT guess or invent a YYYY-MM-DD date — PHP resolves weekday_iso to the correct calendar day.\n"
            . "    * Example: User: 'Tuesday' -> {\"intent\": null, \"slots\": {\"weekday_iso\": 2}}\n"
            . "- CRITICAL: intent=deny can ONLY be used when 'Awaiting yes/no confirmation: true' is present in the user prompt. If there is NO confirmation prompt, NEVER use intent=deny - use intent=cancel instead for cancellation commands.\n"
            . "- If the user prompt contains 'Awaiting yes/no confirmation: true', the user is responding to a confirmation prompt. Classify the reply:\n"
            . "    * Affirmative (yes / ok / sure / confirm and short equivalents in any language) -> intent=confirm, all slots null.\n"
            . "    * Negative (no / cancel / stop equivalents in any language) -> intent=deny, all slots null. HOWEVER: phrases like 'I can't at X time' / 'X time doesn't work' / 'not available at X' / 'X is not good for me' are NOT a full denial — the user wants to change a specific slot. For these, set intent=null and time=null (system will ask for a new time).\n"
            . "    * CRITICAL DISAMBIGUATION during confirmation:\n"
            . "        - Single words meaning 'cancel/deny/no/stop' -> intent=deny (user is refusing the current proposal)\n"
            . "        - Phrases like 'cancel this' / 'cancel it' / 'don't book this' / 'cancel the meeting' (referring to the proposed booking) -> intent=deny\n"
            . "        - Phrases with qualifiers like 'cancel ANOTHER meeting' / 'cancel a DIFFERENT meeting' / 'cancel my OTHER meeting' / 'cancel my PREVIOUS meeting' -> intent=cancel (user wants to cancel an existing meeting, not deny the current proposal)\n"
            . "        - Rule of thumb: if the user is clearly referring to a DIFFERENT/OTHER/ANOTHER meeting (not the one being confirmed), extract intent=cancel. Otherwise, during confirmation, 'cancel' means deny.\n"
            . "    * A clear request to start a NEW / different operation (e.g. 'book a different meeting', 'list my meetings') -> the matching intent and extract any slots.\n"
            . "    * CRITICAL: Phrases like 'change time to X' / 'change date to Y' / 'make it at Z' / 'change duration' during confirmation are NOT a reschedule of an existing meeting. The user is modifying the CURRENT booking before confirming it. Set intent=null and extract the new slot values (time, date, duration, etc.). Do NOT set intent=reschedule.\n"
            . "    * CRITICAL: Phrases like 'keep previous day' / 'go back to previous time' / 'use the original time' / 'keep the previous date' during confirmation mean the user wants to return to the 'Proposed date' and 'Proposed time' shown above. Extract the date and time values shown in 'Proposed date' and 'Proposed time'. Do NOT interpret 'previous day' as yesterday from today.\n"
            . "    * A person's name, an email address, a time, a date, or any other factual data -> intent=null, extract the value as the appropriate slot (guest_name, guest_email, time, date, etc.). NEVER treat factual data as a confirmation.\n"
            . "- Phrases like 'move it later' / 'reschedule to later' / 'same day but later' mean the user wants the NEXT available slot AFTER the original meeting time. Set prefer_earliest=true and leave date/time null. The system will use the original meeting's datetime as the starting point.\n"
            . "- Any request to view / list upcoming meetings -> intent=list (including when a week, month, or date range is mentioned — extract period slots per rule #0a).\n"
            . "- Date ranges, weeks, and months (apply period slots for intent=list OR intent=browse; rule #0a decides which intent):\n"
            . "    * CRITICAL: A single weekday name (monday, tuesday, wednesday, thursday, friday, saturday, sunday) is ALWAYS a SINGLE DATE, NOT a period. Look up the weekday in 'Next 7 days calendar' above and extract date='YYYY-MM-DD'. NEVER use start_date/end_date for single weekdays.\n"
            . "    * CRITICAL: A single date like '15 june' / 'june 15' is ALWAYS a single date (YYYY-MM-DD format), NOT a period. Use the year from 'Today' above. ONLY use start_date/end_date when BOTH endpoints are explicitly mentioned.\n"
            . "    * CRITICAL: 'middle of the week' / 'middle of next week' / 'middle of this week' -> These are SINGLE DATES (Wednesday), NOT periods. ALWAYS use date='YYYY-MM-DD', NEVER use start_date/end_date for 'middle of X week'. See weekday rules above for how to compute Wednesday.\n"
            . "    * Explicit date ranges ('from <day> to <day>', '<month> 1-15', 'between <date> and <date>') -> start_date and end_date in YYYY-MM-DD using the year from 'Today'. Set date=null.\n"
            . "    * 'this week' / 'current week' (in ANY language) -> set week_scope=\"this_week\". Do NOT set start_date, end_date, or date. PHP computes Monday–Sunday of the current week.\n"
            . "    * 'next week' (in ANY language) -> set week_scope=\"next_week\". Do NOT set start_date, end_date, or date. PHP computes Monday–Sunday of the following week.\n"
            . "    * 'this month' / 'current month' -> Calculate start_date (first day of current month) and end_date (last day of current month) based on 'Today' above. date=null.\n"
            . "    * 'next month' -> Calculate start_date (first day of next month) and end_date (last day of next month). date=null.\n"
            . "    * 'in july' / 'during july' (in any language) -> Extract first and last day of that month in YYYY-MM-DD format. Determine the year: if that month has already passed this year (check 'Today'), use next year. Set date=null.\n"
            . "    * When start_date and end_date are extracted, ALWAYS set date=null (the system will filter days within the period).\n"
            . "- Any phrase meaning 'find the earliest available slot' / 'as soon as possible' / 'next free time' -> intent=book, prefer_earliest=true, date=null, time=null.\n"
            . "  CRITICAL: DO NOT set prefer_earliest=true when the user specifies a SPECIFIC TIME like '11:30', 'at 3pm'. prefer_earliest is ONLY for phrases like 'earliest', 'as soon as possible', 'after X', 'later than X'. For simple time specifications like '11:30' or 'at 3pm', extract time='11:30' WITHOUT prefer_earliest.\n"
            . "- An email address (anything containing '@') -> extract as guest_email, intent=null. NEVER map an email to intent=confirm.\n"
            . "- A standalone person's name (no '@', no digits, just a name) -> extract as guest_name, intent=null. NEVER map a name to intent=confirm.\n"
            . "- A short affirmative reply (yes / ok / sure / confirm equivalents in any language) -> intent=confirm, all slots null. NOTE: an email address or a person's name is NOT an affirmative reply.\n"
            . "- A short negative reply (no / cancel / stop equivalents in any language) -> intent=deny, all slots null.\n"
            . "- Dates:\n"
            . "    * 'today' -> use the date shown in 'Today: YYYY-MM-DD (Weekday)' above.\n"
            . "    * 'tomorrow' (any language) -> Take the date from 'Today' above and add 1 calendar day (handle month/year rollover).\n"
            . "    * Weekday name (Monday..Sunday in any human language) -> set slots.date from the matching calendar line OR set weekday_iso (1=Mon..7=Sun) if unsure of the exact date string.\n"
            . "        NEVER set prefer_earliest when a weekday is mentioned.\n"
            . "    * CRITICAL: 'midweek' / 'mid-week' / 'middle of the week' / 'in the middle of the week' / 'around midweek' (in any language) -> ALWAYS means WEDNESDAY of CURRENT week. Look up Wednesday in the 7-day calendar above and extract that exact date. NEVER use Monday or Friday for 'middle of the week'.\n"
            . "    * CRITICAL: 'middle of next week' / 'midweek next week' / 'mid-week next week' (in any language) -> ALWAYS means WEDNESDAY of NEXT week. Compute as follows:\n"
            . "        Step 1: Find 'Sunday' line in the 'Next 7 days calendar' above. That's the last day of the current week.\n"
            . "        Step 2: Add 3 days to that Sunday date to get Wednesday of next week.\n"
            . "        Find Sunday in calendar, add 3 days to get Wednesday of next week.\n"
            . "        NEVER use Wednesday from the 7-day calendar — that's THIS week. You MUST add 3 days to Sunday to get next week's Wednesday.\n"
            . "    * 'same day' / 'that day' / 'the same date' (when rescheduling) -> leave date=null. The system will auto-fill the original meeting's date.\n"
            . "    * An explicit calendar date such as 'May 25', '25 May', '5/25', '25.05' -> resolve to YYYY-MM-DD. The year MUST be the year shown in 'Today' above. Only use the next year if that month+day has already passed in the current year. Month names may be in any language.\n"
            . "    * IMPORTANT: In phrases like '20 after 14:30', '20' is a DATE (day of month), NOT a time. Use the year and month from 'Today' above. The 'after 14:30' part is handled separately below.\n"
            . "- Times: '4 pm' = '16:00', '4-30 pm' = '16:30', '16-30' = '16:30', '15.00' = '15:00', '9' = '09:00'. IMPORTANT: '17:00', '17:30', '09:00' etc. are TIMES (HH:MM format), NOT dates. Never extract HH:MM as a date.\n"
            . "    * 'same time' / 'that time' / 'the same time' (when rescheduling) -> IMPORTANT: If 'Current slots' contains 'original_meeting_time' OR user prompt contains 'Original meeting time: HH:MM', extract time with the value of original_meeting_time. If 'original_meeting_time' is NOT present anywhere, leave time=null. NEVER set prefer_earliest=true when user says 'same time'.\n"
            . "    * CRITICAL: Phrases like 'after 14:30' / 'later than 2pm' (in any language):\n"
            . "        - Extract time='14:30' (or the specified time) and set prefer_earliest=true.\n"
            . "    * CRITICAL: Phrases like 'before 14:30' / 'earlier than 2pm' / 'no later than 3pm' (in any language):\n"
            . "        - Extract time='14:30' (or the specified time) and set prefer_latest=true.\n"
            . "    * CRITICAL: During confirmation, phrases like 'even later' / 'later than that' (any language) mean a time AFTER the 'Proposed time' shown above. Copy that proposed time into slots.time and set prefer_earliest=true.\n"
            . "    * Relative time of day phrases (in any language):\n"
            . "        - 'morning'  -> time='09:00'\n"
            . "        - 'noon' / 'lunchtime' / 'lunch' / 'around lunch' -> time='12:00'\n"
            . "        - 'afternoon'  -> time='14:00'\n"
            . "        - 'evening' -> time='18:00'\n"
            . "        - If combined with 'around' or approximate phrases (e.g. 'around lunchtime', 'around noon'), still extract the base time (e.g. '12:00').\n"
            . "- A bare number after 'How long?' -> duration in minutes. A bare number after 'Which meeting?' -> meeting_index (single) or meeting_indices (multiple, as JSON array).\n"
            . "- When the user names more than one meeting (e.g. '1 and 3', 'cancel 2 and 4'), set meeting_indices=[1,3] (array). Do NOT repeat the same key twice.\n"
            . "- Extract duration exactly as the user states it. Do NOT round or change the value — PHP will validate it and inform the user if it is unavailable.\n"
            . "- Set a slot to null if the user did NOT mention it in this turn.\n"
            . "- If unsure, set intent=null and only fill slots you are certain about.\n"
            . "- Do not invent values. Empty/null is always safe.\n";
    }

    /**
     * @param array<string,mixed> $currentSlots
     * @param array<string,mixed> $backupSlots
     */
    private function buildUserPrompt(string $userMessage, ?string $intent, array $currentSlots, string $askedSlot, bool $awaitingConfirm, array $backupSlots = []): string
    {
        $lines = [];

        if ($awaitingConfirm) {
            // The bot just asked the user to confirm or deny a pending action.
            // Don't show the current intent/slots — they confuse the model into
            // echoing them back. The model should focus on classifying the
            // user's reply as yes/no/something-else.
            // EXCEPTION: Show original_meeting_time so user can say "same time" instead of yes.
            // EXCEPTION: Show proposed date/time from backup_slots so user can say "keep previous" or "go back to the original time".
            $lines[] = 'Awaiting yes/no confirmation: true';
            if (!empty($currentSlots['original_meeting_time'])) {
                $lines[] = 'Original meeting time: ' . (string) $currentSlots['original_meeting_time'];
            }
            if (!empty($backupSlots['date'])) {
                $lines[] = 'Proposed date: ' . (string) $backupSlots['date'];
            }
            if (!empty($backupSlots['time'])) {
                $lines[] = 'Proposed time: ' . (string) $backupSlots['time'];
            }
        } elseif ($askedSlot === 'accept_earliest') {
            $lines[] = 'Awaiting yes/no to book the earliest slot shown above: true';
            if (!empty($currentSlots['date'])) {
                $lines[] = 'Proposed date: ' . (string) $currentSlots['date'];
            }
            if (!empty($currentSlots['time'])) {
                $lines[] = 'Proposed time: ' . (string) $currentSlots['time'];
            }
        } else {
            $slotsJson = wp_json_encode(
                array_intersect_key($currentSlots, array_flip(self::SLOT_KEYS)),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $lines[] = 'Current intent: ' . ($intent ?? 'null');
            $lines[] = 'Current slots: ' . ($slotsJson ?: '{}');
            if ($askedSlot !== '') {
                $lines[] = 'Last asked: ' . $askedSlot;
            }
        }

        $lines[] = 'User said: "' . str_replace('"', '\\"', $userMessage) . '"';
        $lines[] = '';
        $lines[] = 'Output JSON now:';

        return implode("\n", $lines);
    }

    /**
     * @return array{intent: ?string, slots: array<string,mixed>}
     */
    private function parseResponse(string $content): array
    {
        $trimmed = trim($content);

        // Some models wrap output in ```json ... ``` even when asked not to.
        $trimmed = preg_replace('/^\s*```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```\s*$/i', '', $trimmed) ?? $trimmed;

        // Models sometimes add prose around the JSON; isolate the outermost
        // {...} substring.
        $start = strpos($trimmed, '{');
        $end   = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end <= $start) {
            error_log('[SlotExtractor] no JSON object found in response: ' . substr($content, 0, 200));
            return ['intent' => null, 'slots' => []];
        }
        $json   = substr($trimmed, $start, $end - $start + 1);
        
        // Remove comments that LLM sometimes adds (e.g., "// Assuming today is...")
        // This is safe because our slot values (dates, times) don't contain '//'
        $json = preg_replace('/\s*\/\/[^\n]*/', '', $json) ?? $json;
        
        $parsed = json_decode($json, true);
        
        // If first JSON is invalid (e.g., duplicate keys), try to find the last valid JSON object
        if (!is_array($parsed)) {
            // Try to find all JSON objects in the response
            preg_match_all('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $trimmed, $matches);
            if (!empty($matches[0])) {
                // Try each JSON object from last to first
                foreach (array_reverse($matches[0]) as $jsonCandidate) {
                    $cleanJson = preg_replace('/\s*\/\/[^\n]*/', '', $jsonCandidate) ?? $jsonCandidate;
                    $candidateParsed = json_decode($cleanJson, true);
                    if (is_array($candidateParsed)) {
                        $parsed = $candidateParsed;
                        break;
                    }
                }
            }
        }
        
        if (!is_array($parsed)) {
            error_log('[SlotExtractor] json_decode failed: ' . substr($content, 0, 200));
            return ['intent' => null, 'slots' => []];
        }

        $intent = $parsed['intent'] ?? null;
        if (is_string($intent) && in_array($intent, self::ALLOWED_INTENTS, true)) {
            // Canonical intent from the model.
        } elseif (is_string($intent)) {
            // Model invented an alias (e.g. find_scheduled_meetings) — map it.
            $intent = self::resolveIntentAlias($intent);
        } else {
            $intent = null;
        }
        if ($intent === null) {
            $intent = self::resolveIntentAlias($parsed['action'] ?? null);
        }

        // Support both nested and flat formats. Prefer nested 'slots' object,
        // but fall back to top-level keys if the model didn't nest them.
        $slotsIn  = is_array($parsed['slots'] ?? null) ? $parsed['slots'] : [];
        if ($intent === null) {
            $intent = self::resolveIntentAlias($slotsIn['action'] ?? null);
        }
        $slotsOut = [];
        foreach (self::SLOT_KEYS as $key) {
            // Check nested 'slots' first, then top-level as fallback
            $val = $slotsIn[$key] ?? $parsed[$key] ?? null;
            if ($val === null) {
                continue;
            }
            // Per-key type coercion. Validation (range, format) is done by
            // SlotValidator downstream.
            switch ($key) {
                case 'prefer_earliest':
                case 'prefer_latest':
                    // Only true is meaningful (a boolean flag). Any falsy → skip.
                    if ($val === true || $val === 'true' || $val === 1 || $val === '1') {
                        $slotsOut[$key] = true;
                    }
                    break;
                case 'duration':
                case 'meeting_index':
                case 'weekday_iso':
                    if ($key === 'duration') {
                        $minutes = self::parseDurationMinutes($val);
                        if ($minutes !== null) {
                            $slotsOut[$key] = $minutes;
                        }
                    } elseif (is_numeric($val)) {
                        $slotsOut[$key] = (int) $val;
                    }
                    break;
                case 'week_scope':
                    if (is_scalar($val)) {
                        $scope = strtolower(trim((string) $val));
                        if (in_array($scope, ['this_week', 'next_week'], true)) {
                            $slotsOut[$key] = $scope;
                        }
                    }
                    break;
                case 'meeting_indices':
                    // Accept both a proper JSON array and a comma-separated string.
                    if (is_array($val)) {
                        $indices = array_values(array_filter(array_map('intval', $val), fn($v) => $v >= 1));
                        if ($indices !== []) {
                            $slotsOut[$key] = $indices;
                        }
                    }
                    break;
                case 'date':
                case 'time':
                case 'min_time':
                case 'max_time':
                case 'start_date':
                case 'end_date':
                case 'original_meeting_time':
                case 'guest_name':
                case 'guest_email':
                case 'phone':
                    if (is_scalar($val) && (string) $val !== '') {
                        $trimmed = trim((string) $val);
                        if (in_array($key, ['date', 'start_date', 'end_date'], true)
                            && self::isPlaceholderDateValue($trimmed)
                        ) {
                            break;
                        }
                        $slotsOut[$key] = $trimmed;
                    }
                    break;
            }
        }

        // Fallback: LLM sometimes incorrectly uses 'meeting_id' instead of 'meeting_index'.
        // If meeting_id is present (as integer 1-9) and meeting_index is missing, convert it.
        if (!isset($slotsOut['meeting_index']) && !isset($slotsOut['meeting_indices'])) {
            $meetingId = $slotsIn['meeting_id'] ?? $parsed['meeting_id'] ?? null;
            if (is_numeric($meetingId)) {
                $idx = (int) $meetingId;
                if ($idx >= 1 && $idx <= 9) {
                    $slotsOut['meeting_index'] = $idx;
                }
            }
        }

        // Fallback: LLM sometimes uses 'guest_phone' instead of 'phone'.
        // Accept both variants.
        if (!isset($slotsOut['phone'])) {
            $guestPhone = $slotsIn['guest_phone'] ?? $parsed['guest_phone'] ?? null;
            if (is_scalar($guestPhone) && (string) $guestPhone !== '') {
                $slotsOut['phone'] = trim((string) $guestPhone);
            }
        }

        return ['intent' => $intent, 'slots' => $slotsOut];
    }

    /**
     * Preprocess "after/before X time" phrases → min_time/max_time.
     * Handles: "after 9pm", "after 21:00", "after 9:30 pm", "before 5pm", "before 17:00".
     *
     * @return array{min_time?: string, max_time?: string}
     */
    private function preprocessAfterBeforeTime(string $userMessage): array
    {
        $result = [];
        $pattern = '/\b(after|before)\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/i';
        if (preg_match_all($pattern, $userMessage, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }
        foreach ($matches as $m) {
            $keyword = strtolower($m[1]);
            $h       = (int) $m[2];
            $min     = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
            $ampm    = strtolower(trim($m[4] ?? ''));
            if ($ampm === 'pm' && $h < 12) {
                $h += 12;
            } elseif ($ampm === 'am' && $h === 12) {
                $h = 0;
            }
            if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
                continue;
            }
            $time = sprintf('%02d:%02d', $h, $min);
            if ($keyword === 'after') {
                $result['min_time'] = $time;
            } else {
                $result['max_time'] = $time;
            }
        }

        return $result;
    }

    /**
     * prefer_earliest is only for global ASAP / after-time searches — never when a day is named.
     * Non-English earliest requests rely on the LLM setting prefer_earliest; do not strip those here.
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function sanitizePreferEarliest(array &$parsed, string $userMessage): void
    {
        $slots = &$parsed['slots'];
        if (!empty($slots['date']) || !empty($slots['start_date']) || !empty($slots['end_date']) || !empty($slots['weekday_iso'])) {
            unset($slots['prefer_earliest']);
        }
        if (($parsed['intent'] ?? null) === 'browse') {
            unset($slots['prefer_earliest']);
        }
    }

    /**
     * Map LLM alias fields (e.g. action=cancel) to a canonical intent.
     */
    public static function resolveIntentAlias(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $v = strtolower(trim($value));
        if (in_array($v, self::ALLOWED_INTENTS, true)) {
            return $v;
        }
        if (str_contains($v, 'cancel')) {
            return 'cancel';
        }
        if (str_contains($v, 'reschedule') || str_contains($v, 'rebook') || str_contains($v, 'replan')) {
            return 'reschedule';
        }
        if (str_contains($v, 'available') || str_contains($v, 'slot')) {
            return 'browse';
        }
        // List aliases — before book/schedule (scheduled contains schedule substring).
        if (in_array($v, [
            'find_scheduled_meetings', 'show_meetings', 'scheduled_meetings',
            'my_meetings', 'upcoming_meetings', 'list_meetings',
        ], true)) {
            return 'list';
        }
        if (str_contains($v, 'show_meeting') || str_contains($v, 'find_scheduled')
            || str_contains($v, 'scheduled_meeting') || str_contains($v, 'upcoming_meeting')
            || str_contains($v, 'list_meeting') || str_contains($v, 'my_meeting')) {
            return 'list';
        }
        if (str_contains($v, 'list')) {
            return 'list';
        }
        if (str_contains($v, 'book') || str_contains($v, 'schedule')) {
            return 'book';
        }

        return null;
    }

    /**
     * When JSON uses "action" instead of "intent", recover from raw text.
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function recoverIntentFromRaw(array &$parsed, string $rawContent): void
    {
        if (!empty($parsed['intent'])) {
            return;
        }
        if (preg_match_all('/"(?:intent|action)"\s*:\s*"([^"]+)"/i', $rawContent, $matches) !== false) {
            foreach ($matches[1] as $candidate) {
                if (strtolower(trim($candidate)) === 'null') {
                    continue;
                }
                $resolved = self::resolveIntentAlias($candidate);
                if ($resolved !== null) {
                    $parsed['intent'] = $resolved;
                    return;
                }
            }
        }
    }

    /**
     * LLM sometimes returns intent=null but explains the user wants scheduled meetings.
     * Recover list from its own English explanation text (language-agnostic via model prose).
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function recoverListIntentFromRaw(array &$parsed, string $rawContent): void
    {
        if (!empty($parsed['intent'])) {
            return;
        }
        if (preg_match(
            '/\b(find_scheduled|scheduled meetings|upcoming meetings|my meetings|list (?:of )?meetings|request for scheduled meetings|what meetings)\b/i',
            $rawContent
        ) === 1) {
            $parsed['intent'] = 'list';
        }
    }

    /**
     * English-only fast path when the LLM leaves intent=null but the user clearly
     * asks which meetings they already have — not free slots. Other languages rely on the LLM.
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function recoverListIntentFromMessage(array &$parsed, string $userMessage): void
    {
        if (in_array($parsed['intent'] ?? null, ['list', 'cancel', 'reschedule'], true)) {
            return;
        }
        if (!$this->messageMentionsScheduledMeetings($userMessage)) {
            return;
        }
        $parsed['intent'] = 'list';
    }

    /**
     * English-only: distinguish "my scheduled meetings" from "available slots".
     */
    private function messageMentionsScheduledMeetings(string $userMessage): bool
    {
        $lower = mb_strtolower(trim($userMessage), 'UTF-8');
        if ($lower === '') {
            return false;
        }

        if (preg_match('/\b(available|availability|free)\b/i', $lower) === 1) {
            return false;
        }
        if (preg_match('/\b(slots?)\b/i', $lower) === 1) {
            return false;
        }
        if (preg_match('/^(?:book|schedule|reserve|arrange|set up)\b/i', $lower) === 1) {
            return false;
        }
        if (preg_match('/\b(book|schedule|reserve)\b/i', $lower) === 1) {
            return false;
        }

        if (preg_match('/\bwhat\s+meetings\b/i', $lower) === 1) {
            return true;
        }
        if (preg_match('/\b(my|scheduled|booked|upcoming)\b.{0,40}\b(meetings?|appointments?)\b/i', $lower) === 1) {
            return true;
        }
        if (preg_match('/\b(meetings?|appointments?)\b.{0,40}\b(scheduled|booked|planned|upcoming)\b/i', $lower) === 1) {
            return true;
        }
        if (preg_match('/\bmeetings?\s+(?:do\s+)?i\s+have\b/i', $lower) === 1) {
            return true;
        }

        return preg_match('/^my\s+(?:upcoming\s+)?meetings?$/i', $lower) === 1;
    }

    /**
     * True when the main extractor left intent=null on what looks like a new command
     * (not a short answer to last_asked_slot).
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function needsIntentClassifierFallback(
        array $parsed,
        string $userMessage,
        string $askedSlot,
        bool $awaitingConfirm
    ): bool {
        if ($awaitingConfirm) {
            return false;
        }
        if ($askedSlot !== '' && !in_array($askedSlot, ['meeting_id', 'accept_earliest'], true)) {
            return false;
        }

        $msg = trim($userMessage);
        if ($msg === '') {
            return false;
        }

        $slots = is_array($parsed['slots'] ?? null) ? $parsed['slots'] : [];
        $hasConcreteSlots = !empty($slots['date'])
            || !empty($slots['time'])
            || !empty($slots['slot_start'])
            || !empty($slots['meeting_index'])
            || !empty($slots['meeting_indices']);

        $currentIntent = $parsed['intent'] ?? null;

        // Bare commands like "replan the meeting" are often misclassified as book.
        if ($currentIntent === 'book' && !$hasConcreteSlots) {
            return strlen($msg) >= 8;
        }

        if ($currentIntent !== null) {
            return false;
        }

        $hasPeriod = !empty($slots['start_date']) && !empty($slots['end_date']);

        return $hasPeriod || str_ends_with($msg, '?') || strlen($msg) >= 25;
    }

    /**
     * Tiny second LLM call — language-agnostic list vs browse vs book, etc.
     * English prompt only; classifies meaning in any human language.
     */
    private function classifyIntentFallback(string $userMessage): ?string
    {
        try {
            $response = $this->client->chat(
                [
                    [
                        'role'    => 'system',
                        'content' => "Classify a meeting-scheduler chat message.\n"
                            . "Reply with EXACTLY ONE word from: list, browse, book, cancel, reschedule, other.\n"
                            . "list = user wants meetings they have ALREADY booked or scheduled.\n"
                            . "browse = user wants FREE or AVAILABLE calendar time slots (not booked yet).\n"
                            . "book = user wants to create a BRAND NEW meeting (not changing an existing one).\n"
                            . "cancel = cancel an existing meeting.\n"
                            . "reschedule = move, replan, or change an EXISTING meeting to a new date/time.\n"
                            . "other = unrelated to scheduling.\n"
                            . "The user message may be in ANY human language. Classify by meaning.\n"
                            . "Output one word only. No punctuation. No explanation.",
                    ],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                ['temperature' => 0.0, 'max_tokens' => 8]
            );
        } catch (\Throwable $e) {
            error_log('[SlotExtractor] intent classifier failed: ' . $e->getMessage());
            return null;
        }

        $word = strtolower(trim((string) ($response['content'] ?? '')));
        $word = preg_replace('/[^a-z]/', '', $word) ?? '';

        return in_array($word, self::ALLOWED_INTENTS, true) ? $word : null;
    }

    public static function isPlaceholderDateValue(string $value): bool
    {
        $v = strtoupper(trim($value));
        if ($v === 'YYYY-MM-DD' || str_contains($v, 'YYYY') || str_contains($v, 'MM-DD')) {
            return true;
        }

        return !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value));
    }

    /**
     * Map YYYY-MM-DD to the nearest future occurrence (same month/day) in the user timezone.
     * Fixes LLM returning wrong years (2023, 2024, 2028).
     */
    public static function normalizeFutureCalendarDate(string $value, string $timezone): ?string
    {
        if (self::isPlaceholderDateValue($value)) {
            return null;
        }
        try {
            $today = new DateTime('today', new DateTimeZone($timezone));
        } catch (\Throwable $e) {
            return null;
        }
        $parts = explode('-', $value);
        $month = (int) ($parts[1] ?? 0);
        $day   = (int) ($parts[2] ?? 0);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        $todayYear = (int) $today->format('Y');
        $thisYear  = sprintf('%04d-%02d-%02d', $todayYear, $month, $day);
        try {
            $thisYearDt = new DateTime($thisYear, new DateTimeZone($timezone));
            // Guard against PHP silently rolling over invalid dates (e.g. Feb 30 → Mar 2).
            if ((int) $thisYearDt->format('n') === $month
                && (int) $thisYearDt->format('j') === $day
                && $thisYearDt >= $today
            ) {
                return $thisYear;
            }
        } catch (\Throwable $e) {
            /* fall through */
        }
        $nextYear = sprintf('%04d-%02d-%02d', $todayYear + 1, $month, $day);
        try {
            $nextYearDt = new DateTime($nextYear, new DateTimeZone($timezone));
            if ((int) $nextYearDt->format('n') === $month
                && (int) $nextYearDt->format('j') === $day
                && $nextYearDt >= $today
            ) {
                return $nextYear;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Normalize a date-range endpoint (start_date / end_date).
     *
     * Unlike {@see normalizeFutureCalendarDate}, past dates are kept — a range
     * like "this week" legitimately starts on Monday even when today is Wednesday.
     * Only fixes obviously wrong LLM years (e.g. 2024 when today is 2026).
     */
    public static function normalizeRangeCalendarDate(string $value, string $timezone): ?string
    {
        if (self::isPlaceholderDateValue($value)) {
            return null;
        }
        try {
            $today = new DateTime('today', new DateTimeZone($timezone));
            $dt    = new DateTime($value, new DateTimeZone($timezone));
        } catch (\Throwable $e) {
            return null;
        }

        $year       = (int) $dt->format('Y');
        $todayYear  = (int) $today->format('Y');
        $month      = (int) $dt->format('n');
        $day        = (int) $dt->format('j');

        // LLM often returns stale training years — remap to the current year.
        if ($year < $todayYear - 1 || $year > $todayYear + 1) {
            $fixed = sprintf('%04d-%02d-%02d', $todayYear, $month, $day);
            try {
                $fixedDt = new DateTime($fixed, new DateTimeZone($timezone));
                if ((int) $fixedDt->format('n') === $month && (int) $fixedDt->format('j') === $day) {
                    return $fixed;
                }
            } catch (\Throwable $e) {
                return null;
            }
            return null;
        }

        return $dt->format('Y-m-d');
    }

    /**
     * Parse duration from LLM output or user text ("15", "15 min", "15 minutes").
     */
    public static function parseDurationMinutes(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value) && $value === (float) (int) $value) {
            return (int) $value;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d+)\s*(?:min(?:ute)?s?|m)?$/iu', $s, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function stripPlaceholderSlotValues(array &$parsed): void
    {
        foreach (['date', 'start_date', 'end_date'] as $key) {
            if (isset($parsed['slots'][$key])
                && is_string($parsed['slots'][$key])
                && self::isPlaceholderDateValue($parsed['slots'][$key])
            ) {
                unset($parsed['slots'][$key]);
            }
        }
    }

    /**
     * When JSON is broken but weekday_iso appears in the raw text, recover it in PHP.
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function recoverWeekdayIsoFromRaw(array &$parsed, string $rawContent): void
    {
        if (!empty($parsed['slots']['weekday_iso'])) {
            return;
        }
        if (preg_match('/"weekday_iso"\s*:\s*([1-7])\b/', $rawContent, $m) === 1) {
            $parsed['slots']['weekday_iso'] = (int) $m[1];
        }
    }

    /**
     * Resolve weekday_iso (1=Mon .. 7=Sun) to a calendar date in the user timezone.
     * When weekday_iso is present it wins over an LLM-hallucinated date string.
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     * @param array<string,mixed> $flowSlots
     */
    private function applyWeekdayIso(array &$parsed, DateTime $nowDt, array $flowSlots = []): void
    {
        $iso = (int) ($parsed['slots']['weekday_iso'] ?? 0);
        $hasWeekdayIso = $iso >= 1 && $iso <= 7;

        if ($hasWeekdayIso) {
            unset($parsed['slots']['date']);
        } elseif (!empty($parsed['slots']['date'])
            && is_string($parsed['slots']['date'])
            && !self::isPlaceholderDateValue($parsed['slots']['date'])
        ) {
            unset($parsed['slots']['weekday_iso']);
            return;
        } elseif (!empty($parsed['slots']['date'])) {
            unset($parsed['slots']['date']);
        }

        if (!$hasWeekdayIso) {
            unset($parsed['slots']['weekday_iso']);
            return;
        }

        $startDate = (string) ($flowSlots['start_date'] ?? $parsed['slots']['start_date'] ?? '');
        $endDate   = (string) ($flowSlots['end_date'] ?? $parsed['slots']['end_date'] ?? '');

        if ($startDate !== '' && $endDate !== '') {
            try {
                $tz     = $nowDt->getTimezone();
                $start  = new DateTime($startDate, $tz);
                $end    = new DateTime($endDate, $tz);
                $cursor = clone $start;
                while ($cursor <= $end) {
                    if ((int) $cursor->format('N') === $iso) {
                        $parsed['slots']['date'] = $cursor->format('Y-m-d');
                        break;
                    }
                    $cursor->modify('+1 day');
                }
            } catch (\Throwable $e) {
                // Fall through to next occurrence from today.
            }
        }

        if (empty($parsed['slots']['date'])) {
            $current   = (int) $nowDt->format('N');
            $daysToAdd = $iso >= $current ? $iso - $current : 7 - $current + $iso;
            $target    = clone $nowDt;
            $target->modify("+{$daysToAdd} days");
            $parsed['slots']['date'] = $target->format('Y-m-d');
        }

        unset($parsed['slots']['weekday_iso'], $parsed['slots']['prefer_earliest']);
        if (($parsed['intent'] ?? null) === 'book' && empty($parsed['slots']['time'])) {
            $parsed['intent'] = 'browse';
        }
    }

    /**
     * Resolve week_scope (this_week / next_week) to start_date + end_date.
     * LLM classifies meaning in any language; PHP does calendar math.
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function applyWeekScope(array &$parsed, DateTime $nowDt): void
    {
        $scope = isset($parsed['slots']['week_scope'])
            ? strtolower(trim((string) $parsed['slots']['week_scope']))
            : '';
        if ($scope !== 'this_week' && $scope !== 'next_week') {
            unset($parsed['slots']['week_scope']);
            return;
        }

        $currentDayOfWeek = (int) $nowDt->format('N');

        if ($scope === 'this_week') {
            $daysBackToMonday = $currentDayOfWeek - 1;
            $monday = clone $nowDt;
            if ($daysBackToMonday > 0) {
                $monday->modify("-{$daysBackToMonday} days");
            }
            $sunday = clone $monday;
            $sunday->modify('+6 days');
            $parsed['slots']['start_date'] = $monday->format('Y-m-d');
            $parsed['slots']['end_date']   = $sunday->format('Y-m-d');
        } else {
            $daysToNextMonday = 8 - $currentDayOfWeek;
            $nextMonday = clone $nowDt;
            $nextMonday->modify("+{$daysToNextMonday} days");
            $nextSunday = clone $nextMonday;
            $nextSunday->modify('+6 days');
            $parsed['slots']['start_date'] = $nextMonday->format('Y-m-d');
            $parsed['slots']['end_date']   = $nextSunday->format('Y-m-d');
        }

        unset(
            $parsed['slots']['week_scope'],
            $parsed['slots']['date'],
            $parsed['slots']['prefer_earliest']
        );
    }

    /**
     * When JSON is broken but week_scope appears in the raw text, recover it in PHP.
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function recoverWeekScopeFromRaw(array &$parsed, string $rawContent): void
    {
        if (!empty($parsed['slots']['week_scope'])) {
            return;
        }
        if (preg_match('/"week_scope"\s*:\s*"(this_week|next_week)"/i', $rawContent, $m) === 1) {
            $parsed['slots']['week_scope'] = strtolower($m[1]);
        }
    }

    /**
     * LLM sometimes returns start_date after end_date — swap when both are valid.
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function sanitizeInvertedDateRange(array &$parsed): void
    {
        $start = (string) ($parsed['slots']['start_date'] ?? '');
        $end   = (string) ($parsed['slots']['end_date'] ?? '');
        if ($start === '' || $end === '' || $start <= $end) {
            return;
        }
        $parsed['slots']['start_date'] = $end;
        $parsed['slots']['end_date']   = $start;
    }

    /**
     * Detect intent from English command phrases. Non-English messages rely on the LLM.
     */
    private function detectIntentPhrase(string $userMessage): ?string
    {
        $normalized = mb_strtolower(trim($userMessage), 'UTF-8');
        $normalized = preg_replace('/[.!?,;]+$/', '', $normalized) ?? $normalized;

        $patterns = [
            'cancel' => [
                '/\b(cancel|delete|remove)\b.{0,40}\b(meeting|appointment|booking|call)\b/i',
                '/^(i\s+)?(want to|would like to|need to)\s+(cancel|delete)/i',
            ],
            'reschedule' => [
                '/\b(reschedule|rebook|replan)\b/i',
                '/\b(move|change|shift)\b.{0,40}\b(meeting|appointment|call)\b/i',
            ],
            'list' => [
                '/^(list|meetings)$/i',
                '/\b(list|show)\b.{0,40}\b(my\s+)?(upcoming\s+|scheduled\s+)?(meetings|appointments)\b/i',
                '/\bwhat\s+meetings\b/i',
                '/\b(which|any)\s+meetings\b/i',
                '/\bmeetings\s+(do\s+)?i\s+have\b/i',
                '/^my\s+meetings$/i',
            ],
            'book' => [
                '/^book\s*(a|an)?\s*(meeting|appointment|slot)?$/i',
                '/^(i\s+)?(want to|would like to|need to)\s+book/i',
                '/^(schedule|reserve)\s*(a|an)?\s*(meeting|appointment|slot)?$/i',
            ],
        ];

        // Check reschedule/cancel/list before book — "reschedule a meeting" must not become book.
        foreach (['cancel', 'reschedule', 'list', 'book'] as $intent) {
            foreach ($patterns[$intent] as $regex) {
                if (preg_match($regex, $normalized) === 1) {
                    return $intent;
                }
            }
        }

        return null;
    }

    /**
     * True when the message likely contains a date, time, or duration (not just a bare command).
     */
    private function messageContainsSchedulingDetail(string $userMessage): bool
    {
        if (preg_match('/\d{1,2}:\d{2}|\d{1,2}[-–]\d{2}/u', $userMessage) === 1) {
            return true;
        }
        if (preg_match('/\b\d+\s*(min|minute|minutes)\b/i', $userMessage) === 1) {
            return true;
        }
        if (preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $userMessage) === 1) {
            return true;
        }

        return false;
    }

    /**
     * When PHP detected a reschedule/cancel/list phrase but the LLM disagreed, trust PHP.
     *
     * @param array{intent:?string, slots:array<string,mixed>} $parsed
     */
    private function applyPhraseIntentOverride(array &$parsed, ?string $phraseIntent, bool $awaitingConfirm): void
    {
        if ($phraseIntent === null || $awaitingConfirm) {
            return;
        }
        if (!in_array($phraseIntent, ['reschedule', 'cancel', 'list'], true)) {
            return;
        }
        $llmIntent = $parsed['intent'] ?? null;
        if ($phraseIntent === 'list') {
            if (in_array($llmIntent, ['book', 'browse', 'other', null], true)) {
                $parsed['intent'] = 'list';
            }
            return;
        }
        if ($llmIntent === 'book' || $llmIntent === null || $llmIntent === 'other') {
            $parsed['intent'] = $phraseIntent;
        }
    }

    /**
     * Preprocess "middle of next week" phrase and compute Wednesday of next week.
     * LLM struggles with date arithmetic, so we handle it here.
     * Returns array with 'date' key if detected, empty array otherwise.
     * 
     * @return array<string, string>
     */
    private function preprocessMiddleOfNextWeek(string $userMessage, \DateTime $nowDt): array
    {
        $normalized = mb_strtolower(trim($userMessage), 'UTF-8');
        
        // Patterns for "middle of next week" (English only).
        $patterns = [
            '/\b(middle|mid|midweek)\s+of\s+(the\s+)?next\s+week\b/i',
            '/\b(middle|mid|midweek)\s+next\s+week\b/i',
        ];
        
        $matches = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                $matches = true;
                break;
            }
        }
        
        if (!$matches) {
            return [];
        }
        
        // Find Sunday of current week (end of week)
        $currentDayOfWeek = (int) $nowDt->format('N'); // 1 (Monday) to 7 (Sunday)
        $daysUntilSunday = 7 - $currentDayOfWeek;
        
        $sunday = clone $nowDt;
        $sunday->modify("+{$daysUntilSunday} days");
        
        // Wednesday of next week = Sunday + 3 days
        $wednesdayNextWeek = clone $sunday;
        $wednesdayNextWeek->modify('+3 days');
        
        return ['date' => $wednesdayNextWeek->format('Y-m-d')];
    }

    /**
     * Preprocess "today" / "tomorrow" → slots.date (English only; other languages via LLM).
     *
     * @return array<string, string>
     */
    private function preprocessRelativeDay(string $userMessage, \DateTime $nowDt): array
    {
        $normalized = mb_strtolower(trim($userMessage), 'UTF-8');
        if (preg_match('/\btomorrow\b/i', $normalized) === 1) {
            $tomorrow = clone $nowDt;
            $tomorrow->modify('+1 day');

            return ['date' => $tomorrow->format('Y-m-d')];
        }
        if (preg_match('/\btoday\b/i', $normalized) === 1) {
            return ['date' => $nowDt->format('Y-m-d')];
        }

        return [];
    }

    /**
     * Preprocess weekday names (monday, tuesday, etc) and extract date from calendar.
     * Returns array with 'date' key if detected, empty array otherwise.
     * 
     * @return array<string, string>
     */
    private function preprocessWeekdayName(string $userMessage, \DateTime $nowDt): array
    {
        $normalized = mb_strtolower(trim($userMessage), 'UTF-8');
        
        // Map of weekday names to PHP day numbers (1=Monday, 7=Sunday)
        $weekdayPatterns = [
            'monday' => 1, 'mon' => 1,
            'tuesday' => 2, 'tue' => 2, 'tues' => 2,
            'wednesday' => 3, 'wed' => 3,
            'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
            'friday' => 5, 'fri' => 5,
            'saturday' => 6, 'sat' => 6,
            'sunday' => 7, 'sun' => 7,
        ];
        
        foreach ($weekdayPatterns as $weekdayName => $targetDayNumber) {
            if (!preg_match('/\b' . preg_quote($weekdayName, '/') . '\b/u', $normalized)) {
                continue;
            }

            $currentDayNumber = (int) $nowDt->format('N');
            if ($targetDayNumber >= $currentDayNumber) {
                $daysToAdd = $targetDayNumber - $currentDayNumber;
            } else {
                $daysToAdd = 7 - $currentDayNumber + $targetDayNumber;
            }
            $targetDate = clone $nowDt;
            $targetDate->modify("+{$daysToAdd} days");

            return ['date' => $targetDate->format('Y-m-d')];
        }

        return [];
    }

    /**
     * Preprocess "this week" / "next week" phrases and compute correct date ranges.
     * Returns array with 'start_date' and 'end_date' keys if detected, empty array otherwise.
     * 
     * @return array<string, string>
     */
    private function preprocessWeekRange(string $userMessage, \DateTime $nowDt): array
    {
        $normalized = mb_strtolower(trim($userMessage), 'UTF-8');
        
        $isThisWeek = preg_match('/\b(this|current)\s+week\b/i', $normalized);
        $isNextWeek = preg_match('/\bnext\s+week\b/i', $normalized);
        
        if (!$isThisWeek && !$isNextWeek) {
            return [];
        }
        
        // Get current day of week (1 = Monday, 7 = Sunday)
        $currentDayOfWeek = (int) $nowDt->format('N');
        
        if ($isThisWeek) {
            // This week = from Monday of current week to Sunday of current week
            // Calculate days back to Monday (if today is Wednesday (3), go back 2 days)
            $daysBackToMonday = $currentDayOfWeek - 1;
            
            $monday = clone $nowDt;
            if ($daysBackToMonday > 0) {
                $monday->modify("-{$daysBackToMonday} days");
            }
            
            // Sunday = Monday + 6 days
            $sunday = clone $monday;
            $sunday->modify('+6 days');
            
            return [
                'start_date' => $monday->format('Y-m-d'),
                'end_date' => $sunday->format('Y-m-d'),
            ];
        }
        
        if ($isNextWeek) {
            // Next week = from Monday of next week to Sunday of next week
            // Calculate days forward to next Monday
            $daysToNextMonday = 8 - $currentDayOfWeek; // If Wednesday (3), forward 5 days to Monday
            
            $nextMonday = clone $nowDt;
            $nextMonday->modify("+{$daysToNextMonday} days");
            
            // Sunday of next week = Monday + 6 days
            $nextSunday = clone $nextMonday;
            $nextSunday->modify('+6 days');
            
            return [
                'start_date' => $nextMonday->format('Y-m-d'),
                'end_date' => $nextSunday->format('Y-m-d'),
            ];
        }
        
        return [];
    }

    /**
     * Preprocess explicit month day ranges: "june 10-15", "in june 10 to 15".
     *
     * @return array<string, string>
     */
    private function preprocessMonthDayRange(string $userMessage, \DateTime $nowDt): array
    {
        $normalized = mb_strtolower(trim($userMessage), 'UTF-8');
        $monthMap   = [
            'january' => 1, 'jan' => 1,
            'february' => 2, 'feb' => 2,
            'march' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'may' => 5,
            'june' => 6, 'jun' => 6,
            'july' => 7, 'jul' => 7,
            'august' => 8, 'aug' => 8,
            'september' => 9, 'sep' => 9, 'sept' => 9,
            'october' => 10, 'oct' => 10,
            'november' => 11, 'nov' => 11,
            'december' => 12, 'dec' => 12,
        ];

        $patterns = [
            '/\b(' . implode('|', array_keys($monthMap)) . ')\s*(\d{1,2})\s*(?:-|–|to)\s*(\d{1,2})\b/i',
            '/\b(\d{1,2})\s*(?:-|–|to)\s*(\d{1,2})\s+(' . implode('|', array_keys($monthMap)) . ')\b/i',
        ];

        foreach ($patterns as $i => $pattern) {
            if (preg_match($pattern, $normalized, $m) !== 1) {
                continue;
            }
            if ($i === 0) {
                $monthKey = strtolower($m[1]);
                $dayStart = (int) $m[2];
                $dayEnd   = (int) $m[3];
            } else {
                $dayStart = (int) $m[1];
                $dayEnd   = (int) $m[2];
                $monthKey = strtolower($m[3]);
            }
            $month = $monthMap[$monthKey] ?? 0;
            if ($month < 1 || $dayStart < 1 || $dayEnd < $dayStart) {
                return [];
            }
            $year = (int) $nowDt->format('Y');
            if ($month < (int) $nowDt->format('n')) {
                $year++;
            }
            $start = sprintf('%04d-%02d-%02d', $year, $month, $dayStart);
            $end   = sprintf('%04d-%02d-%02d', $year, $month, $dayEnd);

            return ['start_date' => $start, 'end_date' => $end];
        }

        return [];
    }

    /**
     * User wants the globally earliest / ASAP slot — not a day-browsing query.
     */
    private function isPreferEarliestRequest(string $userMessage): bool
    {
        return preg_match('/\b(earliest|asap|soonest|next\s+available|as\s+soon\s+as)\b/i', $userMessage) === 1;
    }

    /**
     * Detect if the user message is a browse command (show/check/view/what/which/when).
     * Returns true if message starts with browse keywords, false otherwise.
     */
    private function detectBrowseCommand(string $userMessage): bool
    {
        $normalized = mb_strtolower(trim($userMessage), 'UTF-8');
        
        // Browse command keywords (English only).
        $browseKeywords = [
            'show', 'check', 'view', 'see', 'display', 'find',
            'what', 'which', 'when', 'tell', 'list',
            'can you show', 'could you show', 'please show',
            'can you check', 'could you check',
            'can you find', 'could you find', 'please find',
            'i want to see', 'i would like to see',
            'i need to see', 'i\'d like to see',
        ];
        
        foreach ($browseKeywords as $keyword) {
            if (strpos($normalized, $keyword) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Detect ordinal numbers (first, second, third, etc) for meeting selection.
     * Returns meeting_index integer if detected, null otherwise.
     */
    private function detectOrdinalNumber(string $userMessage): ?int
    {
        $normalized = mb_strtolower(trim($userMessage), 'UTF-8');
        
        // Remove punctuation
        $normalized = preg_replace('/[.!?,;]+$/', '', $normalized) ?? $normalized;
        
        // Map ordinal words/phrases to integers
        $ordinalMap = [
            // English
            'first' => 1, 'the first' => 1, 'first one' => 1, '1st' => 1,
            'second' => 2, 'the second' => 2, 'second one' => 2, '2nd' => 2,
            'third' => 3, 'the third' => 3, 'third one' => 3, '3rd' => 3,
            'fourth' => 4, 'the fourth' => 4, '4th' => 4,
            'fifth' => 5, 'the fifth' => 5, '5th' => 5,
        ];
        
        foreach ($ordinalMap as $word => $index) {
            if ($normalized === $word) {
                return $index;
            }
        }
        
        return null;
    }
}
