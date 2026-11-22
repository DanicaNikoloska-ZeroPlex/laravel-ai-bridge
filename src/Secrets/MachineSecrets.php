<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Secrets;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tetrix\AiBridge\Auth\TokenManager;
use Tetrix\AiBridge\Connections\ConnectionStatus;
use Tetrix\AiBridge\Events\MachineSecretChanged;
use Tetrix\AiBridge\Models\Connection;
use Tetrix\AiBridge\Upstreams\UpstreamClient;

/**
 * The secret store on a person's machine, managed through its bridge: list, put, describe and
 * remove (PROTOCOL.md, "Secrets on the machine"). Reached as `AiBridge::secrets()`.
 *
 * Off unless the machine's operator started the bridge with `--machine-secrets`: only then does
 * its hello carry `machine_secrets`, and a bridge without it is never sent a `secret_*` frame
 * (every call answers `machine_secrets_unsupported`). The bridge keeps the secrets in its own
 * store on the machine (`~/.ai-bridge/secrets`).
 *
 * A value goes in and never comes out: nothing here returns, logs or stores one. It travels as
 * JSON from this process to the serve process (POST /api/secrets) and from there in the
 * `secret_put` frame, so keep `AI_BRIDGE_RELAY_URL` local or https. The request goes through
 * Laravel's HTTP client, so a recorder of outgoing requests sees it: with Telescope, call
 * `Telescope::hideRequestParameters(['value'])`.
 *
 * Every method answers an array `{ok: bool, ...}` and never throws for a refusal. On failure
 * `code` is the machine's (`store_unavailable`, `not_found`, `invalid_name`, `invalid_request`,
 * `exists`, `timeout`, `failed`) or one decided on the way (`machine_secrets_unsupported`,
 * `machine_secrets_outdated`, `not_connected`, `unreachable`, `send_failed`). For a put, describe
 * or remove whose outcome cannot be known (`timeout`, `unreachable`, a bridge that dropped
 * after it was sent) the answer also carries `result_unknown: true`: list the secrets again
 * before retrying.
 *
 * Every put, describe and remove, whatever its outcome, fires MachineSecretChanged and writes
 * one info log line (operation, connection, name, outcome; never a value or a description), so
 * a host can keep an audit trail.
 *
 * Works from any process except the serve process itself, like AiBridge::upstreams(). Takes a
 * Connection the caller already fetched through its own scope; authorising that is the
 * caller's job.
 */
class MachineSecrets
{
    public function __construct(
        private readonly TokenManager $tokenManager,
        private readonly ConnectionStatus $status,
    ) {}

    /**
     * Whether this connection's secrets can be managed now: its bridge is connected and its
     * hello carried `machine_secrets` at revision 2 or newer (see ConnectionStatus::for()).
     * False while the machine is off or the serve process cannot be reached, even when the last
     * hello had it: a bridge may come back without `--machine-secrets`. A call made while it
     * is false is refused with the reason (`not_connected`, `machine_secrets_unsupported`,
     * `machine_secrets_outdated`), never sent.
     */
    public function supported(Connection $connection): bool
    {
        $status = $this->status->for($connection);

        return $status['connected'] === true
            && $status['machine_secrets'] === true
            && SecretFrames::refusalFor(['machine_secrets' => ['revision' => $status['machine_secrets_revision']]]) === null;
    }

    /**
     * The machine's secrets: names, descriptions and when each was last updated, never a value.
     *
     * @return array{ok: bool, secrets?: list<array{name: string, description: string|null, updated_at: string|null}>, code?: string, error?: string}
     */
    public function list(Connection $connection): array
    {
        $answer = $this->call($connection, ['op' => 'list']);
        if (($answer['ok'] ?? false) === true) {
            $answer['secrets'] ??= [];
        }

        return $answer;
    }

    /**
     * Add a secret. An existing name is refused with `exists` unless `$replace` is true; a
     * bridge older than revision 2 is sent nothing (`machine_secrets_outdated`). On
     * success `replaced` says whether an existing secret was overwritten.
     *
     * @return array{ok: bool, replaced?: bool, code?: string, error?: string, result_unknown?: true}
     */
    public function put(Connection $connection, string $name, #[\SensitiveParameter] string $value, ?string $description = null, bool $replace = false): array
    {
        $answer = $this->call($connection, array_filter([
            'op' => 'put',
            'name' => $name,
            'description' => $description,
            'value' => $value,
            'replace' => $replace ?: null,
        ], static fn ($v) => $v !== null));

        // Whatever the machine or anything on the way said, it does not repeat the value.
        if (is_string($answer['error'] ?? null) && $value !== '') {
            $answer['error'] = str_replace($value, '[value]', $answer['error']);
        }

        return $answer;
    }

    /**
     * Change a secret's description.
     *
     * @return array{ok: bool, code?: string, error?: string, result_unknown?: true}
     */
    public function describe(Connection $connection, string $name, string $description): array
    {
        return $this->call($connection, ['op' => 'describe', 'name' => $name, 'description' => $description]);
    }

    /**
     * Remove a secret.
     *
     * @return array{ok: bool, code?: string, error?: string, result_unknown?: true}
     */
    public function remove(Connection $connection, string $name): array
    {
        return $this->call($connection, ['op' => 'remove', 'name' => $name]);
    }

    /**
     * Check the request, POST it to the serve process, and shape the answer.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(Connection $connection, #[\SensitiveParameter] array $body): array
    {
        $started = microtime(true);
        $answer = $this->answer($connection, $body);

        if ($body['op'] !== 'list') {
            $this->audit($connection, $body, $answer, $started);
        }

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function answer(Connection $connection, #[\SensitiveParameter] array $body): array
    {
        $frame = SecretRequest::frameOf($body);
        if (! isset($frame['type'])) {
            return ['ok' => false] + $frame;
        }

        if (! $connection->isBridge() || ($connection->connection_key ?? '') === '') {
            return ['ok' => false, 'code' => 'invalid_request', 'error' => 'Only a CLI bridge connection has a machine.'];
        }

        $answer = $this->relay($connection->connection_key, $body, (int) config('ai-bridge.machine_secrets.timeout', 20) + 3);

        // A write that was sent and not answered may still have happened: the machine's command
        // was stopped (`timeout` from the machine), nobody answered in time (`timeout` from the
        // serve process), the bridge dropped after it was sent (marked by the serve process), or
        // the serve process's own answer never came back (`unreachable`).
        if ($body['op'] !== 'list' && ($answer['ok'] ?? false) !== true) {
            $code = $answer['code'] ?? null;
            if ($code === 'unreachable') {
                $answer['error'] = 'The bridge server could not be reached or did not answer, so the result is unknown: list the secrets again before retrying.';
            }
            if (in_array($code, ['timeout', 'unreachable'], true)) {
                $answer['result_unknown'] = true;
            }
        }

        return $answer;
    }

    /**
     * The audit trail of one change: MachineSecretChanged and an info log line, never the value.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $answer
     */
    private function audit(Connection $connection, #[\SensitiveParameter] array $body, array $answer, float $started): void
    {
        $ok = ($answer['ok'] ?? false) === true;
        $outcome = $ok ? 'ok' : (is_string($answer['code'] ?? null) ? $answer['code'] : 'failed');
        $name = is_string($body['name'] ?? null) ? mb_substr($body['name'], 0, 128) : '';
        $unknown = ($answer['result_unknown'] ?? null) === true;
        $replaced = $ok && ($answer['replaced'] ?? null) === true;
        $userId = self::authUserId();

        Log::info('AI Bridge: machine secret '.$body['op'], [
            'connection_id' => $connection->id,
            'user_id' => $userId,
            'name' => $name,
            'outcome' => $outcome,
            'result_unknown' => $unknown,
            'replaced' => $replaced,
        ]);

        try {
            MachineSecretChanged::dispatch(
                $userId,
                (int) $connection->id,
                (string) $body['op'],
                $name,
                $outcome,
                $unknown,
                $replaced,
                (int) round((microtime(true) - $started) * 1000),
            );
        } catch (\Throwable $e) {
            Log::warning('AI Bridge: a MachineSecretChanged listener failed', ['error' => $e::class]);
        }
    }

    private static function authUserId(): int|string|null
    {
        try {
            return auth()->id();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function relay(string $connectionKey, #[\SensitiveParameter] array $body, int $timeout): array
    {
        try {
            $relayToken = $this->tokenManager->generate($connectionKey, ['scope' => TokenManager::INTERNAL_RELAY_SCOPE], 60);

            /** @var Response $response */
            $response = Http::withToken($relayToken)
                ->timeout($timeout)
                ->acceptJson()
                ->post(UpstreamClient::internalApiBase().'/api/secrets', $body);
        } catch (\Throwable $e) {
            // The operation and the exception's class only: a message could quote the request.
            Log::info('AI Bridge: could not reach the serve process for a machine secret request', [
                'op' => $body['op'],
                'error' => $e::class,
            ]);

            return ['ok' => false, 'code' => 'unreachable', 'error' => 'The bridge server could not be reached.'];
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->successful() && is_bool($json['ok'] ?? null)) {
            return $json;
        }

        return [
            'ok' => false,
            'code' => is_string($json['code'] ?? null) ? $json['code'] : 'unreachable',
            'error' => is_string($json['error'] ?? null) ? $json['error'] : 'The bridge server answered '.$response->status().'.',
        ] + (($json['result_unknown'] ?? null) === true ? ['result_unknown' => true] : []);
    }
}
