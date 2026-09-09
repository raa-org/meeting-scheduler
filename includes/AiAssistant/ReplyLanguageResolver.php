<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\AiAssistant;

/**
 * Pick the ISO 639-1 reply language for the assistant.
 *
 * The resolver runs every turn on the user's last message. It detects the
 * dominant script and returns the closest ISO code. If the message carries
 * no language signal (digits-only, bare email, one short token) we keep the
 * stored language; if there is none either we fall back to the browser
 * locale, and finally English.
 *
 * This means the user can switch languages mid-conversation just by writing
 * in a new one — the next reply (and every reply afterwards) is in that
 * language.
 */
final class ReplyLanguageResolver
{
    /**
     * Locales whose native script is NOT Latin. If the browser is set to one
     * of these but the user is typing in Latin script, they have switched
     * away from their native language — answer in English, not in the
     * browser locale.
     */
    private const NON_LATIN_SCRIPT_LOCALES = [
        'ru', 'uk', 'be', 'bg', 'mk', 'sr', 'kk', 'ky', 'mn', // Cyrillic
        'zh', 'ja', 'ko',                                       // CJK
        'ar', 'fa', 'ur', 'ps',                                 // Arabic
        'he', 'yi',                                             // Hebrew
        'el',                                                   // Greek
        'hi', 'bn', 'ta', 'te', 'mr', 'gu', 'kn', 'ml', 'pa',   // Indic
        'th', 'lo', 'km', 'my', 'ka', 'hy', 'am',               // South / SE Asian
    ];

    public function resolve(string $userMessage, string $storedLanguage, string $clientLocale): string
    {
        $stored  = self::iso6391($storedLanguage);
        $browser = self::iso6391(self::localeToLanguage($clientLocale));

        $text = trim($userMessage);
        if ($text === '' || !self::isLinguistic($text)) {
            return $stored ?? $browser ?? 'en';
        }

        // Script-based detection: pick the dominant non-Latin script first.
        if (preg_match('/\p{Han}/u', $text) === 1)                     return 'zh';
        if (preg_match('/\p{Hiragana}|\p{Katakana}/u', $text) === 1)   return 'ja';
        if (preg_match('/\p{Hangul}/u', $text) === 1)                  return 'ko';
        if (preg_match('/\p{Arabic}/u', $text) === 1)                  return 'ar';
        if (preg_match('/\p{Hebrew}/u', $text) === 1)                  return 'he';
        if (preg_match('/\p{Greek}/u', $text) === 1)                   return 'el';
        if (preg_match('/\p{Devanagari}/u', $text) === 1)              return 'hi';
        if (preg_match('/\p{Thai}/u', $text) === 1)                    return 'th';

        // Cyrillic: distinguish Ukrainian from Russian by alphabet-exclusive letters.
        $cyrCount = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;
        $latCount = preg_match_all('/\p{Latin}/u',    $text) ?: 0;
        if ($cyrCount > 0 && $cyrCount >= $latCount) {
            if (preg_match('/[іїєґ]/u', $text) === 1)  return 'uk';
            if (preg_match('/[ыэъё]/iu', $text) === 1) return 'ru';
            return $stored !== null && in_array($stored, ['ru', 'uk'], true) ? $stored : 'ru';
        }

        // Mostly Latin. If the browser locale uses a Latin-script native
        // language (fr, de, es, ...), keep it. If the browser locale uses a
        // non-Latin native script (ru, uk, zh, ar, ...), the user has clearly
        // switched away from their browser language → answer in English.
        if ($browser !== null && !in_array($browser, self::NON_LATIN_SCRIPT_LOCALES, true)) {
            return $browser;
        }
        return 'en';
    }

    /**
     * "Substantive" check: a message with ≥2 Latin word tokens, OR any
     * non-Latin script character, carries a language signal. A lone "ok",
     * an email address, or pure digits do not.
     */
    private static function isLinguistic(string $text): bool
    {
        if (preg_match('/^\S+@\S+\.\S+$/u', $text) === 1) {
            return false;
        }
        if (preg_match('/^[\d\s.\-+,;:\/()]+$/u', $text) === 1) {
            return false;
        }
        // Any non-Latin letter is enough — short CJK/Arabic/etc messages are unambiguous.
        if (preg_match('/[^\p{Latin}\p{Common}\p{Inherited}\d\s]/u', $text) === 1) {
            return true;
        }
        return (int) preg_match_all('/[\p{L}]{2,}/u', $text) >= 2;
    }

    private static function localeToLanguage(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }
        return strtolower(explode('-', str_replace('_', '-', $locale), 2)[0]);
    }

    private static function iso6391(string $code): ?string
    {
        $c = strtolower(trim($code));
        return preg_match('/^[a-z]{2}$/', $c) === 1 ? $c : null;
    }
}
