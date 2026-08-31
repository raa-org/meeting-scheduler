<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\AiAssistant;

/**
 * Translate the chat's English templates into whatever language the user
 * is typing. One LLM call per uncached (text, lang) pair; results cached
 * in WordPress transients so the same template phrase isn't translated
 * twice.
 *
 * Uses **plain-text** mode (no JSON envelope). Earlier attempts with a
 * `{"text": "..."}` JSON wrapper failed routinely on ministral-3:14b — the
 * model would forget to close the string when the translation contained
 * line breaks, leaving us no way to parse the result. Plain text + a
 * "preserve numbers/dates/bullets" rule sidesteps that entirely.
 *
 * If the target language is empty or "en" the input is returned as-is.
 * If the LLM call fails we fall back to the English text so the chat
 * still works — the customer just sees English.
 */
final class Translator
{
    private const CACHE_PREFIX = 'apexianlab_ai_tr_v6_';
    private const CACHE_TTL    = 30 * 24 * 60 * 60; // 30 days

    /** In-process memo so repeats within the same request cost nothing. */
    private array $memo = [];

    /**
     * Records every LLM call made this request (skipping cache hits) so the
     * caller can persist them to `ai_chat_action_log` for later debugging.
     *
     * @var list<array{text:string, lang:string, raw:string, translated:string}>
     */
    public array $calls = [];

    public function __construct(private AiLlmClient $client)
    {
    }

    public function translate(string $englishText, string $targetLang): string
    {
        $lang = strtolower(trim($targetLang));
        if ($englishText === '' || $lang === '' || $lang === 'en') {
            return $englishText;
        }

        $cacheKey = self::CACHE_PREFIX . md5($englishText . '|' . $lang);
        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey];
        }
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $this->memo[$cacheKey] = $cached;
            return $cached;
        }

        $system = "You translate short chatbot messages into the language identified by ISO 639-1 code \"{$lang}\".\n"
            . "Rules:\n"
            . "- Output ONLY the translation, nothing else. No prose like \"Here is\", no markdown fences.\n"
            . "- Preserve numbers, times (e.g. 16:30), dates, emails, phone numbers, and proper names EXACTLY.\n"
            . "- CRITICAL: When translating times, preserve am/pm indicators EXACTLY. Examples: '9:00 am' must stay '9:00 am', '10:30 pm' must stay '10:30 pm'. Never remove or translate am/pm.\n"
            . "- CRITICAL: Meeting subjects (text after '—' following date/time) must NEVER be translated. Keep the subject phrase after the em dash in its original language.\n"
            . "- Preserve line breaks and bullet markers (- 1. 2. etc) exactly as in the source.\n"
            . "- Keep the translation short, natural, idiomatic.\n"
            . "- IMPORTANT: The chatbot asks the USER (not itself). \"your\" = the person receiving the message. Example: \"What is your name?\" its means = \"What is your name?\" (NOT \"What is my name?\").";

        try {
            $response = $this->client->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $englishText],
                ],
                ['temperature' => 0.1, 'max_tokens' => 600, 'format' => 'text']
            );
        } catch (\Throwable $e) {
            error_log('[Translator] LLM call failed: ' . $e->getMessage());
            $this->calls[] = ['text' => $englishText, 'lang' => $lang, 'raw' => 'ERROR: ' . $e->getMessage(), 'translated' => $englishText];
            return $englishText;
        }

        $raw        = (string) ($response['content'] ?? '');
        $translated = self::stripWrapping($raw);
        $this->calls[] = [
            'text'       => $englishText,
            'lang'       => $lang,
            'raw'        => $raw,
            'translated' => $translated,
        ];
        if ($translated === '') {
            return $englishText;
        }

        $this->memo[$cacheKey] = $translated;
        set_transient($cacheKey, $translated, self::CACHE_TTL);
        return $translated;
    }

    /**
     * Models sometimes wrap the answer in ```fences``` or prefix it with
     * "Translation:" / "Here is the translation:". Strip both.
     */
    private static function stripWrapping(string $text): string
    {
        $t = trim($text);
        if ($t === '') {
            return '';
        }
        // ```lang ... ``` fences
        $t = preg_replace('/^\s*```[a-zA-Z0-9_+\-]*\s*\n?/u', '', $t) ?? $t;
        $t = preg_replace('/\n?\s*```\s*$/u', '', $t) ?? $t;
        // Common English prefaces (the prompt tells the model not to add these,
        // but local models slip up; strip them defensively).
        $t = preg_replace('/^(?:translation|here(?:\'s| is)(?: the translation)?)\s*[:\-]\s*/iu', '', $t) ?? $t;
        return trim($t);
    }
}
