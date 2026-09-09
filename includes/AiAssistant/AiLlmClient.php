<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\AiAssistant;

use Exception;
use Apexianlab\Calendar\Config;

/**
 * Thin client for OpenAI-compatible /chat/completions endpoints (Ollama, vLLM, etc).
 *
 * Default response mode is `format: json` (used by SlotExtractor). Callers
 * that want free-text output (Translator) pass `format: 'text'` and the field
 * is omitted from the request body so the model returns plain prose.
 */
final class AiLlmClient
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private ?string $apiKey = null,
    ) {
    }

    public static function fromConfig(): ?self
    {
        if (!Config::isLlmConfigured()) {
            return null;
        }
        return new self(Config::getLlmBaseUrl(), Config::getLlmModel(), Config::getLlmApiKey());
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,mixed>                     $parameters  temperature, max_tokens, seed,
     *                                                              format ('json'|'text' — default 'json')
     * @return array{content:string,model:string,raw:array<string,mixed>}
     */
    public function chat(array $messages, array $parameters = []): array
    {
        if ($messages === []) {
            throw new Exception('LLM messages must not be empty');
        }

        $format = $parameters['format'] ?? 'json';
        $body   = [
            'model'    => $this->model,
            'messages' => $messages,
        ];
        if ($format === 'json') {
            $body['format'] = 'json';
        }
        foreach (['temperature', 'max_tokens', 'top_p', 'top_k', 'repeat_penalty', 'seed', 'num_ctx', 'stop'] as $k) {
            if (isset($parameters[$k])) {
                $body[$k] = $parameters[$k];
            }
        }

        // Use curl directly instead of wp_remote_post. WordPress's HTTP API
        // caps CURLOPT_CONNECTTIMEOUT at 10 seconds regardless of the
        // 'timeout' arg, which causes silent failures against a slow remote
        // endpoint. Direct curl lets us set connect + transfer timeouts
        // independently.
        //
        // Retry once on transient failures (connect timeout, 502/503/504) so
        // a momentarily slow LLM endpoint doesn't resurface as a user-visible
        // error. If both attempts fail, we throw and the caller handles it.
        $curlHeaders = ['Content-Type: application/json'];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $curlHeaders[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $encodedBody = wp_json_encode($body);
        $rawBody     = false;
        $code        = 0;
        $curlErr     = '';
        $curlErrNo   = 0;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($this->baseUrl . '/chat/completions');
            if ($ch === false) {
                throw new Exception('curl_init failed');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $encodedBody,
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            ]);
            $rawBody   = curl_exec($ch);
            $code      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr   = curl_error($ch);
            $curlErrNo = curl_errno($ch);
            curl_close($ch);

            $transient = ($rawBody === false) || in_array($code, [502, 503, 504], true);
            if (!$transient) {
                break;
            }
            if ($attempt < 10) {
                error_log(sprintf('[AiLlmClient::chat] transient failure (attempt %d/2): http=%d curlErrNo=%d — retrying', $attempt, $code, $curlErrNo));
                usleep(500_000); // 0.5s
            }
        }

        if ($rawBody === false) {
            throw new Exception(esc_html('LLM endpoint is unreachable: curl error ' . $curlErrNo . ': ' . $curlErr));
        }

        $decoded = json_decode((string) $rawBody, true);
        if (!is_array($decoded)) {
            $decoded = ['_raw' => $rawBody];
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception(esc_html('LLM request failed HTTP ' . $code . ': ' . wp_json_encode($decoded)));
        }

        $choices = $decoded['choices'] ?? null;
        if (!is_array($choices) || $choices === [] || !is_array($choices[0] ?? null)) {
            throw new Exception('LLM response has no choices');
        }
        $message = $choices[0]['message'] ?? null;
        $content = is_array($message) ? (string) ($message['content'] ?? '') : '';
        if ($content === '') {
            throw new Exception('LLM response content is empty');
        }

        return ['content' => $content, 'model' => $this->model, 'raw' => $decoded];
    }
}
