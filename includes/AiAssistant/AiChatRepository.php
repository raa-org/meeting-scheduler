<?php
/**
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */


declare(strict_types=1);

namespace Apexianlab\Calendar\AiAssistant;

use PDO;

final class AiChatRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * Same source as PostgreSQL DEFAULT gen_random_uuid() for ai_chats.id.
     */
    public function generateUuid(): string
    {
        $stmt = $this->pdo->query('SELECT gen_random_uuid()::text');
        $value = $stmt->fetchColumn();

        return \is_string($value) ? $value : '';
    }

    /**
     * Generate a cryptographically random session token and its SHA-256 hash.
     *
     * @return array{token:string, hash:string}
     */
    public static function generateSessionToken(): array
    {
        $token = bin2hex(random_bytes(32));
        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    /**
     * @return string|null New chat UUID or null on failure
     */
    public function create(
        ?string $userId,
        ?string $calendarScheduleId = null,
        ?string $calendarEmail = null,
        ?string $sessionTokenHash = null
    ): ?string {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ai_chats (user_id, calendar_schedule_id, calendar_email, session_token_hash)
                 VALUES (:user_id, :calendar_schedule_id, :calendar_email, :session_token_hash)
                 RETURNING id'
            );
            if ($userId !== null && $userId !== '') {
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
            }
            if ($calendarScheduleId !== null && $calendarScheduleId !== '') {
                $stmt->bindValue(':calendar_schedule_id', $calendarScheduleId, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':calendar_schedule_id', null, PDO::PARAM_NULL);
            }
            if ($calendarEmail !== null && $calendarEmail !== '') {
                $stmt->bindValue(':calendar_email', $calendarEmail, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':calendar_email', null, PDO::PARAM_NULL);
            }
            if ($sessionTokenHash !== null && $sessionTokenHash !== '') {
                $stmt->bindValue(':session_token_hash', $sessionTokenHash, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':session_token_hash', null, PDO::PARAM_NULL);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return isset($row['id']) ? (string) $row['id'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Returns true when the plain token matches the stored hash, or when the
     * chat has no token set (legacy rows created before this feature).
     */
    public function verifySessionToken(string $chatId, string $plainToken): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT session_token_hash FROM ai_chats WHERE id = :id AND deleted_at IS NULL LIMIT 1'
            );
            $stmt->bindValue(':id', $chatId, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return false;
            }

            $storedHash = isset($row['session_token_hash']) && $row['session_token_hash'] !== ''
                ? (string) $row['session_token_hash']
                : null;

            // Legacy chats without a token are still accessible.
            if ($storedHash === null) {
                return true;
            }

            return hash_equals($storedHash, hash('sha256', $plainToken));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function exists(string $chatId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ai_chats WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $chatId, PDO::PARAM_STR);
        $stmt->execute();

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array{id:string,user_id:?string,calendar_schedule_id:?string,calendar_email:?string}|null
     */
    public function getById(string $chatId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, calendar_schedule_id, calendar_email
             FROM ai_chats
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->bindValue(':id', $chatId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id'                   => (string) ($row['id'] ?? ''),
            'user_id'              => isset($row['user_id']) && $row['user_id'] !== '' ? (string) $row['user_id'] : null,
            'calendar_schedule_id' => isset($row['calendar_schedule_id']) && $row['calendar_schedule_id'] !== '' ? (string) $row['calendar_schedule_id'] : null,
            'calendar_email'       => isset($row['calendar_email']) && $row['calendar_email'] !== '' ? (string) $row['calendar_email'] : null,
        ];
    }

    /**
     * @return array{chat_id:string,state:string,pending_action:?string,active_conversation_id:?string,context:array<string,mixed>}|null
     */
    public function getState(string $chatId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT chat_id, state, pending_action, active_conversation_id, context_json
             FROM ai_chat_state
             WHERE chat_id = :chat_id'
        );
        $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $ctx = [];
        if (!empty($row['context_json'])) {
            $decoded = json_decode((string) $row['context_json'], true);
            if (is_array($decoded)) {
                $ctx = $decoded;
            }
        }

        return [
            'chat_id'               => (string) $row['chat_id'],
            'state'                 => (string) $row['state'],
            'pending_action'        => isset($row['pending_action']) && $row['pending_action'] !== '' ? (string) $row['pending_action'] : null,
            'active_conversation_id' => isset($row['active_conversation_id']) && $row['active_conversation_id'] !== '' ? (string) $row['active_conversation_id'] : null,
            'context'               => $ctx,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    public function upsertState(string $chatId, string $state, ?string $pendingAction, array $context = [], ?string $activeConversationId = null): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_chat_state (chat_id, state, pending_action, active_conversation_id, context_json)
             VALUES (:chat_id, :state, :pending_action, :active_conversation_id, :context_json::jsonb)
             ON CONFLICT (chat_id) DO UPDATE SET
                state = EXCLUDED.state,
                pending_action = EXCLUDED.pending_action,
                active_conversation_id = EXCLUDED.active_conversation_id,
                context_json = EXCLUDED.context_json'
        );
        $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_STR);
        $stmt->bindValue(':state', $state, PDO::PARAM_STR);
        if ($pendingAction !== null && $pendingAction !== '') {
            $stmt->bindValue(':pending_action', $pendingAction, PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':pending_action', null, PDO::PARAM_NULL);
        }
        if ($activeConversationId !== null && $activeConversationId !== '') {
            $stmt->bindValue(':active_conversation_id', $activeConversationId, PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':active_conversation_id', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':context_json', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);

        return (bool) $stmt->execute();
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $response
     */
    public function addActionLog(
        string $chatId,
        string $actionName,
        string $status = 'ok',
        array $request = [],
        array $response = [],
        ?string $errorMessage = null
    ): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_chat_action_log (chat_id, action_name, status, request_json, response_json, error_message)
             VALUES (:chat_id, :action_name, :status, :request_json::jsonb, :response_json::jsonb, :error_message)'
        );
        $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_STR);
        $stmt->bindValue(':action_name', $actionName, PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':request_json', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);
        $stmt->bindValue(':response_json', json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);
        if ($errorMessage !== null && $errorMessage !== '') {
            $stmt->bindValue(':error_message', $errorMessage, PDO::PARAM_STR);
        } else {
            $stmt->bindValue(':error_message', null, PDO::PARAM_NULL);
        }

        return (bool) $stmt->execute();
    }

    /**
     * @param array<string,mixed> $assistantMessageMeta
     * @param array<int,array<string,mixed>> $uiActions
     * @param array<string,mixed> $userAction
     */
    public function addMessage(
        string $chatId,
        string $conversationId,
        string $role,
        string $content,
        array $userAction = [],
        array $assistantMessageMeta = [],
        array $uiActions = []
    ): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_chat_messages (chat_id, conversation_id, role, content, user_action, assistant_message_meta, ui_actions)
             VALUES (:chat_id, :conversation_id, :role, :content, :user_action::jsonb, :assistant_message_meta::jsonb, :ui_actions::jsonb)'
        );
        $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_STR);
        $stmt->bindValue(':conversation_id', $conversationId, PDO::PARAM_STR);
        $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        $stmt->bindValue(':content', $content, PDO::PARAM_STR);
        $stmt->bindValue(':user_action', json_encode($userAction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);
        $stmt->bindValue(':assistant_message_meta', json_encode($assistantMessageMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);
        $stmt->bindValue(':ui_actions', json_encode($uiActions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);

        return (bool) $stmt->execute();
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    public function getRecentMessages(string $chatId, int $limit = 20, ?string $conversationId = null): array
    {
        $lim = max(1, min(200, $limit));
        $conversationSql = '';
        if ($conversationId !== null && $conversationId !== '') {
            $conversationSql = ' AND conversation_id = :conversation_id';
        }
        $stmt = $this->pdo->prepare(
            'SELECT role, content
             FROM ai_chat_messages
             WHERE chat_id = :chat_id AND deleted_at IS NULL' . $conversationSql . '
             ORDER BY created_at DESC
             LIMIT ' . $lim
        );
        $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_STR);
        if ($conversationSql !== '') {
            $stmt->bindValue(':conversation_id', $conversationId, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }
        $rows = array_reverse($rows);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role    = isset($row['role']) ? (string) $row['role'] : '';
            $content = isset($row['content']) ? (string) $row['content'] : '';
            if ($role === '' || $content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $content];
        }

        return $out;
    }

    /**
     * @return list<array{role:string,content:string,user_action:array<string,mixed>,assistant_message_meta:array<string,mixed>,ui_actions:array<int,array<string,mixed>>}>
     */
    public function getRecentMessagesUi(string $chatId, int $limit = 200, ?string $conversationId = null): array
    {
        $lim = max(1, min(500, $limit));
        $conversationSql = '';
        if ($conversationId !== null && $conversationId !== '') {
            $conversationSql = ' AND conversation_id = :conversation_id';
        }
        $stmt = $this->pdo->prepare(
            'SELECT role, content, user_action, assistant_message_meta, ui_actions
             FROM ai_chat_messages
             WHERE chat_id = :chat_id AND deleted_at IS NULL' . $conversationSql . '
             ORDER BY created_at DESC
             LIMIT ' . $lim
        );
        $stmt->bindValue(':chat_id', $chatId, PDO::PARAM_STR);
        if ($conversationSql !== '') {
            $stmt->bindValue(':conversation_id', $conversationId, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        $rows = array_reverse($rows);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role    = isset($row['role']) ? (string) $row['role'] : '';
            $content = isset($row['content']) ? (string) $row['content'] : '';
            if ($role === '' || $content === '') {
                continue;
            }
            $metaRaw = $row['assistant_message_meta'] ?? [];
            if (is_string($metaRaw)) {
                $decoded = json_decode($metaRaw, true);
                $metaRaw = is_array($decoded) ? $decoded : [];
            }
            $actionsRaw = $row['ui_actions'] ?? [];
            if (is_string($actionsRaw)) {
                $decoded = json_decode($actionsRaw, true);
                $actionsRaw = is_array($decoded) ? $decoded : [];
            }
            $userActionRaw = $row['user_action'] ?? [];
            if (is_string($userActionRaw)) {
                $decoded = json_decode($userActionRaw, true);
                $userActionRaw = is_array($decoded) ? $decoded : [];
            }

            $out[] = [
                'role'                   => $role,
                'content'                => $content,
                'user_action'            => is_array($userActionRaw) ? $userActionRaw : [],
                'assistant_message_meta' => is_array($metaRaw) ? $metaRaw : [],
                'ui_actions'             => is_array($actionsRaw) ? $actionsRaw : [],
            ];
        }

        return $out;
    }
}
