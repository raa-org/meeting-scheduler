/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * AI Assistant chat client:
 * all user actions (text and button clicks) go through apexianlab_ai_assistant_chat_step.
 */
(function ($) {
  'use strict';

  var assistantSendInFlight = false;
  var sessionChatId = null;

  /** Escapes HTML characters. */
  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /** Generates a time label for the current time. */
  function nowTimeLabel() {
    try {
      return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
      }).format(new Date());
    } catch (e) {
      return '';
    }
  }

  /** Converts markdown bold/italic to HTML after escaping. */
  function markdownInline(escaped) {
    return escaped
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>');
  }

  /** Converts user lines to HTML. */
  function userLinesToHtml(text) {
    return String(text)
      .split(/\n/)
      .map(function (line) {
        return escapeHtml(line);
      })
      .join('<br>');
  }

  /** Gets chat ID from page attributes or URL. */
  function getChatId() {
    if (sessionChatId) {
      return sessionChatId;
    }
    var $root = $('.ai-assistant');
    var fromData = String($root.attr('data-ai-chat-id') || '').trim();
    if (fromData) {
      sessionChatId = fromData;
      return sessionChatId;
    }
    try {
      var params = new URLSearchParams(window.location.search || '');
      var fromUrl = String(params.get('chat_id') || '').trim();
      if (fromUrl) {
        sessionChatId = fromUrl;
        return sessionChatId;
      }
    } catch (e) {}
    sessionChatId = '';
    return sessionChatId;
  }

  function setChatId(chatId) {
    sessionChatId = String(chatId || '').trim();
  }

  function detectBrowserTimezone() {
    try {
      var tz = String(Intl.DateTimeFormat().resolvedOptions().timeZone || '').trim();
      return tz || '';
    } catch (e) {
      return '';
    }
  }

  function scrollToBottom() {
    var scroller = document.querySelector('main.booking') || document.documentElement;
    scroller.scrollTop = scroller.scrollHeight;
  }

  /** Appends a bubble to the messages container. */
  function appendBubble(textHtml, isUser) {
    var $messages = $('.ai-assistant__messages');
    if (!$messages.length) {
      return;
    }
    var time = nowTimeLabel();
    var rowClass = 'ai-assistant__row' + (isUser ? ' ai-assistant__row--user' : '');
    var bubbleClass = 'ai-assistant__bubble' + (isUser ? ' ai-assistant__bubble--user' : '');
    var avatarHtml = '';
    if (!isUser && window.raaAiAssistant && window.raaAiAssistant.iconUrl) {
      avatarHtml =
        '<div class="ai-assistant__avatar" aria-hidden="true">' +
        '<img src="' +
        escapeHtml(window.raaAiAssistant.iconUrl) +
        '" alt="" width="32" height="32" decoding="async" />' +
        '</div>';
    }
    if (isUser) {
      avatarHtml = '<div class="ai-assistant__avatar ai-assistant__avatar--user" aria-hidden="true"></div>';
    }
    var $row = $(
      '<div class="' +
        rowClass +
        '">' +
        avatarHtml +
        '<div class="' +
        bubbleClass +
        '">' +
        '<div class="ai-assistant__bubble-text">' +
        textHtml +
        '</div>' +
        (time
          ? '<time class="ai-assistant__bubble-time">' + escapeHtml(time) + '</time>'
          : '') +
        '</div></div>'
    );
    $messages.append($row);
    scrollToBottom();
  }

  function ucFirst(s) {
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  function formatAssistantMessageHtml(message, messageMeta) {
    if (messageMeta && typeof messageMeta === 'object') {
      var prefix = String(messageMeta.prefix || '').trim();
      var highlight = String(messageMeta.highlight || '').trim();
      var suffix = String(messageMeta.suffix || '').trim();
      if (highlight) {
        var html = '<p>';
        if (prefix) {
          html += escapeHtml(prefix + ' ');
        }
        html += '<strong>' + escapeHtml(highlight) + '</strong>';
        if (suffix && /^[?!.,;:]+$/.test(suffix)) {
          html += escapeHtml(suffix);
        } else if (suffix) {
          html += '</p><p>' + escapeHtml(ucFirst(suffix));
        }
        html += '</p>';
        return html;
      }
    }
    var text = String(message || '').trim();
    if (!text) {
      return '';
    }
    var lines = text.split(/\n/).map(function (line) {
      return markdownInline(escapeHtml(line));
    });
    return '<p>' + lines.join('<br>') + '</p>';
  }

  function buildUiActionsHtml(uiActions) {
    if (!Array.isArray(uiActions) || !uiActions.length) {
      return '';
    }
    var html = '<div class="ai-assistant__inline-actions" role="group" aria-label="Assistant actions">';
    uiActions.forEach(function (action, idx) {
      if (!action || typeof action !== 'object') {
        return;
      }
      var id = String(action.id || 'action_' + idx);
      var label = String(action.label || '').trim();
      if (!label) {
        return;
      }
      var encodedPayload = encodeURIComponent(JSON.stringify(action.payload || {}));
      html +=
        '<button type="button" class="ai-assistant__inline-btn" data-ai-assistant-ui-action-id="' +
        escapeHtml(id) +
        '" data-ai-assistant-ui-action-label="' +
        escapeHtml(label) +
        '" data-ai-assistant-ui-action-payload="' +
        escapeHtml(encodedPayload) +
        '">' +
        escapeHtml(label) +
        '</button>';
    });
    html += '</div>';
    return html;
  }

  function renderAssistantReply(message, uiActions, messageMeta) {
    var contentHtml = '';
    contentHtml += formatAssistantMessageHtml(message, messageMeta);
    contentHtml += buildUiActionsHtml(uiActions);
    if (!contentHtml) {
      return;
    }
    appendBubble(contentHtml, false);
  }

  function loadChatHistory() {
    var cfg = window.raaAiAssistant || {};
    if (!cfg || !cfg.ajaxUrl || !cfg.assistantStepNonce) {
      return;
    }
    var chatId = getChatId();
    if (!chatId) {
      return;
    }

    $.ajax({
      url: cfg.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      timeout: 30000,
      data: {
        action: cfg.actionChatHistory || 'apexianlab_ai_assistant_chat_history',
        nonce: cfg.assistantStepNonce,
        chat_id: chatId,
      },
    }).done(function (response) {
      if (!response || response.success !== true) {
        return;
      }
      var data = response.data || {};
      var messages = Array.isArray(data.messages) ? data.messages : [];
      if (!messages.length) {
        return;
      }

      var $messages = $('.ai-assistant__messages');
      if (!$messages.length) {
        return;
      }

      $messages.find('.ai-assistant__row').slice(1).remove();
      $messages.find('.ai-assistant__transient-error').remove();
      $messages.find('.ai-assistant__inline-actions').remove();

      // Find the index of the last assistant message so we only show buttons there.
      var lastAssistantIdx = -1;
      messages.forEach(function (msg, i) {
        if (msg && String(msg.role || '').trim() === 'assistant') {
          lastAssistantIdx = i;
        }
      });

      messages.forEach(function (msg, i) {
        if (!msg || typeof msg !== 'object') {
          return;
        }
        var role = String(msg.role || '').trim();
        var content = String(msg.content || '');
        if (!content) {
          return;
        }
        if (role === 'assistant') {
          var messageMeta =
            msg && typeof msg.assistant_message_meta === 'object' && msg.assistant_message_meta
              ? msg.assistant_message_meta
              : null;
          // Only show buttons on the last assistant message (may still be actionable).
          var uiActions = i === lastAssistantIdx ? (Array.isArray(msg.ui_actions) ? msg.ui_actions : []) : [];
          renderAssistantReply(content, uiActions, messageMeta);
        } else {
          appendBubble('<p>' + userLinesToHtml(content) + '</p>', true);
        }
      });

      scrollToBottom();
    });
  }

  /** Sends a chat step to the server. */
  function sendChatStep(userMessage, options) {
    options = options || {};
    var cfg = window.raaAiAssistant || {};
    if (!cfg || !cfg.ajaxUrl) {
      return;
    }
    if (!cfg.assistantStepNonce) {
      appendBubble('<p>' + escapeHtml((cfg.i18n && cfg.i18n.errorGeneric) || 'Error') + '</p>', false);
      return;
    }
    if (assistantSendInFlight) {
      return;
    }
    var chatId = getChatId();
    if (!chatId) {
      appendBubble('<p>Missing chat session id in URL. Open this page from "Schedule with AI".</p>', false);
      return;
    }

    $('.ai-assistant__inline-actions').remove();

    if (options.showAsUser !== false) {
      appendBubble('<p>' + userLinesToHtml(userMessage) + '</p>', true);
    }

    assistantSendInFlight = true;
    appendBubble(
      '<p class="ai-assistant__loading">'
      + '<span class="ai-assistant__loading-dot"></span>'
      + '<span class="ai-assistant__loading-dot"></span>'
      + '<span class="ai-assistant__loading-dot"></span>'
      + '</p>',
      false
    );
    var $loading = $('.ai-assistant__loading').last();

    var postData = {
      action: cfg.actionChatStep || 'apexianlab_ai_assistant_chat_step',
      nonce: cfg.assistantStepNonce,
      chat_id: chatId,
      user_message: userMessage,
      client_timezone: detectBrowserTimezone(),
      client_locale: (navigator.language || navigator.userLanguage || '').trim(),
    };
    if (options.buttonPayload) {
      postData.button_payload = JSON.stringify(options.buttonPayload);
    }

    // Client-side timeout must be longer than the server's LLM CURL timeout
    // (180s). If the connection drops mid-flight but the server kept running,
    // the server will have persisted the reply to the DB and we will recover
    // it below via loadChatHistory() on .fail().
    $.ajax({
      url: cfg.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: postData,
      timeout: 200000,
    })
      .done(function (response) {
        $loading.closest('.ai-assistant__row').remove();
        if (!response || response.success !== true) {
          var msg =
            (response && response.data && response.data.message) ||
            ((cfg.i18n && cfg.i18n.errorGeneric) || 'Error');
          appendBubble('<p>' + escapeHtml(String(msg)) + '</p>', false);
          return;
        }
        var data = response.data || {};
        if (data && data.chat_id) {
          setChatId(data.chat_id);
        }
        var assistantMessage = typeof data.assistant_message === 'string' ? data.assistant_message : '';
        var assistantMessageMeta =
          data && typeof data.assistant_message_meta === 'object' && data.assistant_message_meta
            ? data.assistant_message_meta
            : null;
        var uiActions = Array.isArray(data.ui_actions) ? data.ui_actions : [];
        renderAssistantReply(assistantMessage, uiActions, assistantMessageMeta);
      })
      .fail(function (jqXHR, textStatus) {
        $loading.closest('.ai-assistant__row').remove();
        // The server may have completed and saved the reply to the DB even
        // though our socket was closed (slow LLM + proxy timeout is the
        // common case). Reload history so the user sees that reply instead
        // of a bare "Error". If nothing was saved, show a friendly retry
        // message.
        var fallbackText =
          textStatus === 'timeout'
            ? ((cfg.i18n && cfg.i18n.errorTimeout)
              || 'The AI is taking longer than usual. Checking for its reply…')
            : ((cfg.i18n && cfg.i18n.errorConnection)
              || 'Connection hiccup. Checking for the latest reply…');
        var $notice = $(
          '<div class="ai-assistant__transient-error">'
            + '<p>' + escapeHtml(fallbackText) + '</p>'
            + '</div>'
        );
        var $messages = $('.ai-assistant__messages');
        if ($messages.length) {
          $messages.append($notice);
          scrollToBottom();
        }
        // Refresh chat history. If the server did persist the reply, it will
        // be re-rendered by loadChatHistory(); if not, the transient notice
        // stays and the user can retry.
        loadChatHistory();
        setTimeout(function () {
          $notice.remove();
        }, 5000);
      })
      .always(function () {
        assistantSendInFlight = false;
      });
  }

  /** Handles the assistant send button click. */
  function handleAssistantSend() {
    var $ta = $('#ai-assistant-input');
    if (!$ta.length) {
      return;
    }
    var text = ($ta.val() || '').trim();
    if (!text) {
      return;
    }
    $ta.val('');
    $ta.trigger('input');
    sendChatStep(text);
  }

  $(document).on('click', '.ai-assistant__chip[data-ai-assistant-action]', function (e) {
    e.preventDefault();
    var text = String($(this).text() || '').trim();
    if (!text) {
      return;
    }
    sendChatStep(text);
  });

  $(document).on('click', '[data-ai-assistant-ui-action-id]', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var label = String($btn.attr('data-ai-assistant-ui-action-label') || $btn.attr('data-ai-assistant-ui-action-id') || '').trim();
    if (!label) {
      return;
    }
    var rawPayload = $btn.attr('data-ai-assistant-ui-action-payload') || '{}';
    var buttonPayload = {};
    try { buttonPayload = JSON.parse(decodeURIComponent(rawPayload)); } catch (ignored) {}
    $btn.closest('.ai-assistant__inline-actions').remove();
    sendChatStep(label, { buttonPayload: buttonPayload });
  });

  $(document).on('click', '.ai-assistant__send', function (e) {
    e.preventDefault();
    handleAssistantSend();
  });

  /** Composer textarea: one line by default, grows to max 4 lines then scrolls. */
  function lineHeightPx(el) {
    var cs = window.getComputedStyle(el);
    var raw = cs.lineHeight;
    var fs = parseFloat(cs.fontSize) || 15;
    var v = parseFloat(raw);
    if (raw === 'normal' || isNaN(v)) {
      return fs * 1.4;
    }
    if (String(raw).indexOf('px') !== -1) {
      return v;
    }
    return v * fs;
  }

  /** Binds the assistant composer autosize functionality. */
  function bindAssistantComposerAutosize() {
    var $ta = $('#ai-assistant-input');
    if (!$ta.length || String($ta.prop('tagName') || '').toLowerCase() !== 'textarea') {
      return;
    }
    var el = $ta[0];
    var $stack = $ta.closest('.ai-assistant__field-stack');

    function sync() {
      var lh = lineHeightPx(el);
      var cs = window.getComputedStyle(el);
      var pt = parseFloat(cs.paddingTop) || 0;
      var pb = parseFloat(cs.paddingBottom) || 0;
      var minH = lh + pt + pb;
      var maxH = lh * 4 + pt + pb;
      var lineBreak = /\r?\n/.test(el.value || '');

      el.style.overflowY = 'hidden';
      el.style.height = 'auto';

      if ($stack.length) {
        $stack.removeClass('ai-assistant__field-stack--multiline');
      }
      var shSingle = el.scrollHeight;
      var useMultiline = lineBreak || shSingle > minH + 4;

      if ($stack.length && useMultiline) {
        $stack.addClass('ai-assistant__field-stack--multiline');
        el.style.height = 'auto';
      }

      var sh = el.scrollHeight;
      var next = Math.min(Math.max(sh, minH), maxH);
      el.style.height = next + 'px';
      if (sh > maxH) {
        el.style.overflowY = 'auto';
      }

      if ($stack.length && $stack.hasClass('ai-assistant__field-stack--multiline')) {
        if (!lineBreak && next <= minH + 4) {
          $stack.removeClass('ai-assistant__field-stack--multiline');
          el.style.height = 'auto';
          sh = el.scrollHeight;
          next = Math.min(Math.max(sh, minH), maxH);
          el.style.height = next + 'px';
          el.style.overflowY = sh > maxH ? 'auto' : 'hidden';
        }
      }
    }

    $ta.on('input', sync);
    $(window).on('resize', sync);
    sync();

    $ta.off('keydown.raaAiAssistantSend').on('keydown.raaAiAssistantSend', function (e) {
      if (e.shiftKey) {
        return;
      }
      var isEnter = e.key === 'Enter' || e.which === 13 || e.keyCode === 13;
      if (!isEnter) {
        return;
      }
      if (e.isComposing || e.keyCode === 229) {
        return;
      }
      e.preventDefault();
      e.stopImmediatePropagation();
      handleAssistantSend();
    });
  }

  // ── Voice recording (click-to-toggle mic) ─────────────────────────
  //
  // Flow:
  //   click mic → startVoiceRecording()
  //     → getUserMedia + MediaRecorder + AnalyserNode
  //     → field switches to "recording mode" (textarea hides, waveform
  //       widget appears with timer and cancel button)
  //   click mic again → stopVoiceRecording(false)
  //     → blob is uploaded to /audio/transcriptions, transcript becomes
  //       a normal chat message via sendChatStep()
  //   click × (cancel) → stopVoiceRecording(true)
  //     → recorder is stopped and the blob is DISCARDED.
  //
  // Audio is never stored on the server — we only proxy the blob to
  // the STT endpoint and use the resulting text.
  var voiceRecorder    = null;
  var voiceChunks      = [];
  var voiceStream      = null;
  var voiceActive      = false;
  var voiceCancelled   = false;
  var voiceAudioCtx    = null;
  var voiceAnalyser    = null;
  var voiceAnimFrame   = null;
  var voiceTimerId     = null;
  var voiceStartedAt   = 0;

  function formatVoiceTime(ms) {
    var total = Math.max(0, Math.floor(ms / 1000));
    var m = Math.floor(total / 60);
    var s = total % 60;
    return m + ':' + (s < 10 ? '0' + s : s);
  }

  function setRecordingUi(active) {
    var $field = $('.ai-assistant__field').first();
    var $mic   = $('.ai-assistant__mic');
    $field.attr('data-ai-recording', active ? 'true' : 'false');
    $mic.toggleClass('ai-assistant__mic--recording', !!active);
    $mic.attr('aria-pressed', active ? 'true' : 'false');
    if (!active) {
      $('.ai-assistant__record-time').text('0:00');
      var $canvas = $('.ai-assistant__record-wave');
      if ($canvas.length) {
        var c = $canvas[0];
        var ctx = c.getContext('2d');
        if (ctx) ctx.clearRect(0, 0, c.width, c.height);
      }
    }
  }

  function stopAnalyserLoop() {
    if (voiceAnimFrame) {
      cancelAnimationFrame(voiceAnimFrame);
      voiceAnimFrame = null;
    }
    if (voiceAnalyser) {
      try { voiceAnalyser.disconnect(); } catch (e) {}
      voiceAnalyser = null;
    }
    if (voiceAudioCtx) {
      try { voiceAudioCtx.close(); } catch (e) {}
      voiceAudioCtx = null;
    }
  }

  function stopTimer() {
    if (voiceTimerId) {
      clearInterval(voiceTimerId);
      voiceTimerId = null;
    }
  }

  function startAnalyserLoop(stream) {
    var AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) {
      return;
    }
    try {
      voiceAudioCtx = new AudioCtx();
      var source    = voiceAudioCtx.createMediaStreamSource(stream);
      voiceAnalyser = voiceAudioCtx.createAnalyser();
      voiceAnalyser.fftSize = 256;
      source.connect(voiceAnalyser);
    } catch (e) {
      stopAnalyserLoop();
      return;
    }

    var $canvas = $('.ai-assistant__record-wave');
    if (!$canvas.length) return;
    var canvas = $canvas[0];
    var ctx    = canvas.getContext('2d');
    if (!ctx) return;

    // Size the canvas to its CSS layout size (HiDPI-aware) so the bars
    // look crisp and the available width is used fully.
    var dpr = window.devicePixelRatio || 1;
    function sizeCanvas() {
      var rect = canvas.getBoundingClientRect();
      canvas.width  = Math.max(1, Math.floor(rect.width  * dpr));
      canvas.height = Math.max(1, Math.floor(rect.height * dpr));
    }
    sizeCanvas();

    var bufferLen = voiceAnalyser.fftSize;
    var dataArr   = new Uint8Array(bufferLen);

    var draw = function () {
      voiceAnimFrame = requestAnimationFrame(draw);
      if (!voiceAnalyser) return;
      voiceAnalyser.getByteTimeDomainData(dataArr);

      // Render ~32 bars; each bar's height = peak amplitude in its slice
      // of the time-domain window. Quick and visually stable on speech.
      var w        = canvas.width;
      var h        = canvas.height;
      var barCount = 32;
      var gap      = 2 * dpr;
      var barW     = Math.max(1, Math.floor((w - gap * (barCount - 1)) / barCount));
      var slice    = Math.floor(bufferLen / barCount);

      ctx.clearRect(0, 0, w, h);
      ctx.fillStyle = 'rgba(24, 26, 32, 0.35)';

      for (var i = 0; i < barCount; i++) {
        var peak = 0;
        for (var j = 0; j < slice; j++) {
          var v = Math.abs(dataArr[i * slice + j] - 128);
          if (v > peak) peak = v;
        }
        // Normalize to canvas height; clamp minimum so idle silence
        // still shows a faint baseline rather than empty space.
        var amp    = peak / 128;
        var barH   = Math.max(2 * dpr, Math.floor(amp * h));
        var x      = i * (barW + gap);
        var y      = Math.floor((h - barH) / 2);
        ctx.fillRect(x, y, barW, barH);
      }
    };
    draw();
  }

  function startTimer() {
    voiceStartedAt = Date.now();
    var $label = $('.ai-assistant__record-time');
    $label.text('0:00');
    voiceTimerId = setInterval(function () {
      $label.text(formatVoiceTime(Date.now() - voiceStartedAt));
    }, 200);
  }

  function sendVoiceBlob(blob) {
    var cfg = window.raaAiAssistant || {};
    if (!cfg.ajaxUrl || !cfg.assistantStepNonce || !cfg.actionTranscribe) {
      return;
    }

    var $mic = $('.ai-assistant__mic');
    $mic.addClass('ai-assistant__mic--transcribing');

    // While the audio is being transcribed, show the same 3-dot "typing"
    // animation that the assistant uses — but as a USER-side bubble.
    // It communicates "your message is being prepared" and gets replaced
    // by the real user bubble (with the transcript) as soon as STT returns.
    appendBubble(
      '<p class="ai-assistant__loading">'
      + '<span class="ai-assistant__loading-dot"></span>'
      + '<span class="ai-assistant__loading-dot"></span>'
      + '<span class="ai-assistant__loading-dot"></span>'
      + '</p>',
      true
    );
    var $pending = $('.ai-assistant__loading').last().closest('.ai-assistant__row');

    var fd = new FormData();
    fd.append('action', cfg.actionTranscribe);
    fd.append('nonce', cfg.assistantStepNonce);
    fd.append('audio', blob, 'voice.webm');

    $.ajax({
      url: cfg.ajaxUrl,
      type: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json',
    })
      .done(function (response) {
        $pending.remove();
        if (response && response.success && response.data && response.data.transcript) {
          var text = String(response.data.transcript).trim();
          if (text) {
            sendChatStep(text);
            return;
          }
        }
        var msg = (response && response.data && response.data.message) || 'Transcription failed';
        appendBubble('<p>' + escapeHtml(msg) + '</p>', false);
      })
      .fail(function () {
        $pending.remove();
        appendBubble('<p>Voice transcription unavailable</p>', false);
      })
      .always(function () {
        $mic.removeClass('ai-assistant__mic--transcribing');
      });
  }

  function stopVoiceRecording(cancelled) {
    if (!voiceActive) {
      return;
    }
    voiceActive    = false;
    voiceCancelled = !!cancelled;

    stopAnalyserLoop();
    stopTimer();
    setRecordingUi(false);

    if (voiceRecorder && voiceRecorder.state !== 'inactive') {
      try { voiceRecorder.stop(); } catch (e) {}
    }
    if (voiceStream) {
      voiceStream.getTracks().forEach(function (t) { t.stop(); });
      voiceStream = null;
    }
  }

  function startVoiceRecording() {
    if (voiceActive || assistantSendInFlight) {
      return;
    }

    var cfg = window.raaAiAssistant || {};
    if (!cfg.sttEnabled) {
      return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      appendBubble('<p>Microphone is not supported in this browser</p>', false);
      return;
    }

    voiceActive    = true;
    voiceCancelled = false;
    voiceChunks    = [];
    setRecordingUi(true);

    navigator.mediaDevices.getUserMedia({ audio: true })
      .then(function (stream) {
        if (!voiceActive) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          return;
        }
        voiceStream = stream;

        var mimeType = 'audio/webm;codecs=opus';
        if (typeof MediaRecorder !== 'undefined' && !MediaRecorder.isTypeSupported(mimeType)) {
          mimeType = 'audio/webm';
          if (!MediaRecorder.isTypeSupported(mimeType)) {
            mimeType = '';
          }
        }

        var opts = mimeType ? { mimeType: mimeType } : {};
        voiceRecorder = new MediaRecorder(stream, opts);

        voiceRecorder.ondataavailable = function (e) {
          if (e.data && e.data.size > 0) {
            voiceChunks.push(e.data);
          }
        };

        voiceRecorder.onstop = function () {
          stream.getTracks().forEach(function (t) { t.stop(); });
          voiceStream = null;

          var shouldSend = !voiceCancelled && voiceChunks.length > 0;
          if (shouldSend) {
            var blob = new Blob(voiceChunks, { type: voiceRecorder.mimeType || 'audio/webm' });
            if (blob.size > 500) {
              sendVoiceBlob(blob);
            }
          }
          voiceChunks    = [];
          voiceCancelled = false;
        };

        voiceRecorder.start();
        startAnalyserLoop(stream);
        startTimer();
      })
      .catch(function () {
        voiceActive = false;
        setRecordingUi(false);
        appendBubble('<p>Microphone access denied</p>', false);
      });
  }

  $(document).on('click', '.ai-assistant__mic', function (e) {
    e.preventDefault();
    if (voiceActive) {
      stopVoiceRecording(false);
    } else {
      startVoiceRecording();
    }
  });

  $(document).on('click', '.ai-assistant__record-cancel', function (e) {
    e.preventDefault();
    stopVoiceRecording(true);
  });

  /**
   * The server renders the greeting's timestamp in the WordPress timezone as
   * a progressive-enhancement fallback. When JS is available we overwrite it
   * with the browser's local time so it matches the time shown on every other
   * message bubble (which is generated client-side via nowTimeLabel()).
   */
  function syncInitialGreetingTime() {
    var $initial = $('[data-ai-initial-time="true"]').first();
    if (!$initial.length) {
      return;
    }
    var label = nowTimeLabel();
    if (label) {
      $initial.text(label);
    }
    try {
      $initial.attr('datetime', new Date().toISOString());
    } catch (e) { /* ignore */ }
  }

  /**
   * Ask the server whether the current user has any upcoming meetings.
   * Only called when the user is authenticated (currentUser.email is set).
   * If the server says no meetings (or guest with unknown email → null),
   * disable the Cancel and Reschedule static chip buttons.
   */
  function checkAndUpdateChipStates() {
    var cfg    = window.raaAiAssistant || {};
    var chatId = getChatId();
    if (!cfg.ajaxUrl || !cfg.assistantStepNonce || !chatId) {
      return;
    }

    // Only check for users whose identity we know — for guests with no email
    // in context, the server returns null and we leave buttons enabled.
    $.ajax({
      url: cfg.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      timeout: 10000,
      data: {
        action: 'apexianlab_ai_assistant_has_meetings',
        nonce:  cfg.assistantStepNonce,
        chat_id: chatId,
      },
    }).done(function (response) {
      if (!response || response.success !== true) {
        return;
      }
      var hasMeetings = (response.data || {}).has_meetings;
      // null = unknown (guest without email) → keep enabled
      if (hasMeetings === false) {
        $('.ai-assistant__chip[data-ai-assistant-action="reschedule"]')
          .prop('disabled', true)
          .addClass('ai-assistant__chip--disabled')
          .attr('title', 'No upcoming meetings');
        $('.ai-assistant__chip[data-ai-assistant-action="cancel"]')
          .prop('disabled', true)
          .addClass('ai-assistant__chip--disabled')
          .attr('title', 'No upcoming meetings');
      }
    });
  }

  $(function () {
    bindAssistantComposerAutosize();
    syncInitialGreetingTime();
    loadChatHistory();
    checkAndUpdateChipStates();

    var cfg = window.raaAiAssistant || {};
    if (!cfg.sttEnabled) {
      $('.ai-assistant__mic').hide();
    }
  });
})(jQuery);
