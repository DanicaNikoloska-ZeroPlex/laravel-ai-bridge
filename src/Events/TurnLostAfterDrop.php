<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A turn a `reliable_delivery` bridge was running when its connection dropped did not come
 * back, and has now been failed with `bridge_disconnected`.
 *
 * Such a turn is detached on the drop rather than failed (see PROTOCOL.md, "Short drops").
 * It ends here for one of two reasons:
 *
 *  - `not_listed`: the bridge reconnected and its `hello` did not list the turn in
 *    `running_turns`, so the machine no longer runs it;
 *  - `grace_expired`: the bridge did not come back within `reconnect_grace_seconds`.
 *
 * The turn's handler has already been sent the error (so its buffer and the conversation's
 * `streaming_request_id` are ended the usual way) when this fires. It is the one signal that a
 * detached turn is really lost: a turn that was re-attached never fires it. A bridge without
 * `reliable_delivery` never fires it either; its turns fail on the drop, as before.
 */
class TurnLostAfterDrop
{
    use Dispatchable;

    public const NOT_LISTED = 'not_listed';

    public const GRACE_EXPIRED = 'grace_expired';

    public function __construct(
        public readonly string $requestId,
        /** The user (machine) the turn belonged to. */
        public readonly string $userId,
        /** The bridge conversation id the turn ran on, '' when not tied to one. */
        public readonly string $conversationId,
        /** {@see NOT_LISTED} or {@see GRACE_EXPIRED}. */
        public readonly string $reason,
    ) {}
}
