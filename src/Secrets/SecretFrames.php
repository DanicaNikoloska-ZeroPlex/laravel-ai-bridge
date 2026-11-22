<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Secrets;

use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\WebSocket\BridgeConnectionManager;

/**
 * The serve process's handling of `secret_result`, the bridge's one answer to a `secret_*`
 * frame. It goes to whoever waits for that id (BridgeWebSocketServer::apiSecrets()).
 *
 * Runs inside the ReactPHP event loop, where an uncaught TypeError drops every connected
 * bridge, so every field is type-checked and reduced to what PROTOCOL.md documents.
 */
final class SecretFrames
{
    /** The codes a failed `secret_result` may carry; anything else becomes `failed`. */
    public const CODES = ['machine_secrets_unsupported', 'store_unavailable', 'not_found', 'invalid_name', 'invalid_request', 'exists', 'timeout', 'failed'];

    /** The most rows one list answer is read for. */
    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly BridgeConnectionManager $connections,
    ) {}

    /** The lowest `machine_secrets.revision` sent any `secret_*` frame (revision 1 overwrote on a plain put). */
    public const MIN_REVISION = 2;

    /**
     * Why a bridge that said this at hello must not be sent a `secret_*` frame, or null when it
     * may: 409 `machine_secrets_unsupported` without `machine_secrets` (its operator did not opt
     * in), 409 `machine_secrets_outdated` below revision 2 or without a revision. Checked before
     * sending, and again for a frame held through a short drop against the bridge that came
     * back, which may be an older one.
     *
     * @param  array<string, mixed>  $bridgeInfo  What the bridge said at hello (BridgeConnectionManager).
     * @return array{ok: false, code: string, error: string, _status: int}|null
     */
    public static function refusalFor(array $bridgeInfo): ?array
    {
        $field = $bridgeInfo['machine_secrets'] ?? null;
        if (! is_array($field)) {
            return [
                'ok' => false,
                'code' => 'machine_secrets_unsupported',
                'error' => 'This bridge does not announce machine_secrets; it needs updating, or its operator has not started it with --machine-secrets.',
                '_status' => 409,
            ];
        }

        $revision = $field['revision'] ?? null;
        if (! is_int($revision) || $revision < self::MIN_REVISION) {
            return [
                'ok' => false,
                'code' => 'machine_secrets_outdated',
                'error' => 'The bridge on this machine manages secrets in an older way; update it (ai-bridge 0.46.0 or newer).',
                '_status' => 409,
            ];
        }

        return null;
    }

    /** The key a `secret_*` exchange waits under: per bridge, so ids cannot cross users. */
    public static function exchangeKey(string $userId, string $id): string
    {
        return 'secret:'.$userId.':'.$id;
    }

    /** @param  array<string, mixed>  $message */
    public function result(string $connectionId, array $message): ?array
    {
        $userId = $this->connections->getUserIdByConnectionId($connectionId);
        $id = $message['id'] ?? null;

        if ($userId === null) {
            Log::warning('AI Bridge: secret_result from unauthenticated connection', ['connection_id' => $connectionId]);

            return null;
        }
        if (! is_string($id) || $id === '' || strlen($id) > 128) {
            return null;
        }

        $this->connections->resolvePendingExchange(self::exchangeKey($userId, $id), $userId, self::answerOf($message));

        return null;
    }

    /**
     * A `secret_result` reduced to `{ok: true, secrets?, replaced?}` or `{ok: false, code, error}`.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public static function answerOf(array $message): array
    {
        if (($message['ok'] ?? null) === true) {
            $answer = ['ok' => true];
            if (array_key_exists('secrets', $message)) {
                $answer['secrets'] = self::rowsOf($message['secrets']);
            }
            if (is_bool($message['replaced'] ?? null)) {
                $answer['replaced'] = $message['replaced'];
            }

            return $answer;
        }

        $code = $message['code'] ?? null;
        $code = is_string($code) && in_array($code, self::CODES, true) ? $code : 'failed';
        $error = $message['error'] ?? null;

        return [
            'ok' => false,
            'code' => $code,
            'error' => match (true) {
                // Stopped before it finished: a put, describe or remove may have happened.
                $code === 'timeout' => 'The machine\'s secret store did not finish in time, so the result is unknown: list the secrets again before retrying.',
                is_string($error) && $error !== '' && mb_check_encoding($error, 'UTF-8') => mb_substr($error, 0, 500),
                default => 'The machine could not do this.',
            },
        ];
    }

    /**
     * The rows of a list answer: `{name, description, updated_at}`, a row whose name breaks the
     * naming rule dropped.
     *
     * @return list<array{name: string, description: string|null, updated_at: string|null}>
     */
    public static function rowsOf(mixed $value): array
    {
        $rows = [];

        foreach (is_array($value) && array_is_list($value) ? array_slice($value, 0, self::MAX_ROWS) : [] as $row) {
            if (! is_array($row) || SecretRequest::nameRefusal($row['name'] ?? null) !== null) {
                continue;
            }
            $description = $row['description'] ?? null;
            $updatedAt = $row['updated_at'] ?? null;

            $rows[] = [
                'name' => $row['name'],
                'description' => is_string($description) && mb_check_encoding($description, 'UTF-8')
                    ? mb_substr($description, 0, SecretRequest::MAX_DESCRIPTION) : null,
                'updated_at' => is_string($updatedAt) && $updatedAt !== '' && strlen($updatedAt) <= 64 ? $updatedAt : null,
            ];
        }

        return $rows;
    }
}
