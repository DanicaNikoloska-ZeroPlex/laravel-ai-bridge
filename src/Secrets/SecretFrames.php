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
    public const CODES = ['store_unavailable', 'not_found', 'invalid_name', 'invalid_request', 'exists', 'timeout', 'failed'];

    /** The most rows one list answer is read for. */
    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly BridgeConnectionManager $connections,
    ) {}

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
