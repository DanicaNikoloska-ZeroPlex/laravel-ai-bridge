<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Secrets;

use Tetrix\AiBridge\Protocol\MessageTypes;

/**
 * The rules a request about the machine's secrets must meet, and the `secret_*` frame it
 * becomes (PROTOCOL.md, "Secrets on the machine").
 *
 * Checked twice with the same code: in the web process, so a bad request never leaves it, and
 * in the serve process, so a relay token alone cannot get a malformed frame to a machine. The
 * bridge checks names again before its secret command runs.
 *
 * A refusal's text never contains the value.
 */
final class SecretRequest
{
    /**
     * A secret's name: a letter or digit, then letters, digits, `.`, `_` or `-`, 64 at most.
     * `\z`, not `$`: `$` also matches before a trailing newline.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    /** The longest description, in characters (code points), as the bridge counts them. */
    public const MAX_DESCRIPTION = 1000;

    /** The largest value, in bytes. */
    public const MAX_VALUE_BYTES = 65536;

    public const OPERATIONS = ['list', 'put', 'describe', 'remove'];

    /** Why `$name` is not a secret's name, or null when it is. */
    public static function nameRefusal(mixed $name): ?string
    {
        if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1 || str_contains($name, '..')) {
            return 'A secret name is 1 to 64 characters: letters, digits, ".", "_" or "-", starting with a letter or digit, never "..".';
        }

        return null;
    }

    /** Why `$description` cannot be sent, or null when it can. */
    public static function descriptionRefusal(mixed $description): ?string
    {
        if (! is_string($description) || ! mb_check_encoding($description, 'UTF-8')) {
            return 'A description must be UTF-8 text.';
        }
        if (mb_strlen($description, 'UTF-8') > self::MAX_DESCRIPTION) {
            return 'A description is at most '.self::MAX_DESCRIPTION.' characters.';
        }
        if (str_contains($description, "\0")) {
            return 'A description must not contain a NUL character.';
        }
        // The machine's secret command takes the description as an argument, where a leading
        // "-" could be read as an option.
        if (str_starts_with($description, '-')) {
            return 'A description must not start with "-".';
        }

        return null;
    }

    /** Why `$value` cannot be sent, or null when it can. Never quotes the value. */
    public static function valueRefusal(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return 'A secret value must be a non-empty string.';
        }
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            return 'A secret value is at most '.self::MAX_VALUE_BYTES.' bytes.';
        }
        if (! mb_check_encoding($value, 'UTF-8')) {
            return 'A secret value must be UTF-8 text.';
        }

        return null;
    }

    /**
     * The `secret_*` frame (without its `id`) for a request body
     * `{op: list|put|describe|remove, name?, description?, value?, replace?}`; or, when the
     * request breaks a rule, `{code: invalid_name|invalid_request, error}` (no `type` key).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function frameOf(array $body): array
    {
        $refused = static fn (string $error, string $code = 'invalid_request'): array => ['code' => $code, 'error' => $error];

        $op = $body['op'] ?? null;
        if (! is_string($op) || ! in_array($op, self::OPERATIONS, true)) {
            return $refused('op must be one of '.implode(', ', self::OPERATIONS).'.');
        }

        if ($op === 'list') {
            return ['type' => MessageTypes::SECRET_LIST];
        }

        $name = $body['name'] ?? null;
        if (($refusal = self::nameRefusal($name)) !== null) {
            return $refused($refusal, 'invalid_name');
        }

        if ($op === 'remove') {
            return ['type' => MessageTypes::SECRET_REMOVE, 'name' => $name];
        }

        $description = $body['description'] ?? null;

        if ($op === 'describe') {
            if (($refusal = self::descriptionRefusal($description)) !== null) {
                return $refused($refusal);
            }

            return ['type' => MessageTypes::SECRET_DESCRIBE, 'name' => $name, 'description' => $description];
        }

        // put
        if ($description !== null && ($refusal = self::descriptionRefusal($description)) !== null) {
            return $refused($refusal);
        }
        if (($refusal = self::valueRefusal($body['value'] ?? null)) !== null) {
            return $refused($refusal);
        }
        $replace = $body['replace'] ?? false;
        if (! is_bool($replace)) {
            return $refused('replace must be true or false.');
        }

        $frame = ['type' => MessageTypes::SECRET_PUT, 'name' => $name];
        if ($description !== null && $description !== '') {
            $frame['description'] = $description;
        }
        $frame['value'] = $body['value'];
        if ($replace) {
            $frame['replace'] = true;
        }

        return $frame;
    }
}
