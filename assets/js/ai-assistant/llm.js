/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * AI Assistant — proxy to WordPress admin-ajax apexianlab_ai_llm_chat (OpenAI-compatible backend).
 */
(function ($) {
  'use strict';

  /**
   * @param {Array<{role: string, content: string}>} messages
   * @param {object} [options] model, parameters (object, sent as JSON)
   * @returns {JQuery.jqXHR}
   */
  function raaAiLlmChat(messages, options) {
    options = options || {};
    var data = {
      action: window.raaAiLlm.action,
      nonce: window.raaAiLlm.nonce,
      messages: JSON.stringify(messages)
    };
    if (options.model) {
      data.model = options.model;
    }
    if (options.parameters && typeof options.parameters === 'object') {
      data.parameters = JSON.stringify(options.parameters);
    }
    return $.post({
      url: window.raaAiLlm.ajaxUrl,
      data: data,
      dataType: 'json',
    });
  }

  /**
   * @param {string} prompt
   * @param {string} [systemPrompt]
   * @param {object} [options]
   * @returns {JQuery.jqXHR}
   */
  function raaAiLlmPrompt(prompt, systemPrompt, options) {
    options = options || {};
    var data = {
      action: window.raaAiLlm.action,
      nonce: window.raaAiLlm.nonce,
      prompt: prompt
    };
    if (systemPrompt) {
      data.system_prompt = systemPrompt;
    }
    if (options.model) {
      data.model = options.model;
    }
    if (options.parameters && typeof options.parameters === 'object') {
      data.parameters = JSON.stringify(options.parameters);
    }
    return $.post({
      url: window.raaAiLlm.ajaxUrl,
      data: data,
      dataType: 'json',
    });
  }

  window.raaAiLlmChat = raaAiLlmChat;
  window.raaAiLlmPrompt = raaAiLlmPrompt;
})(jQuery);
