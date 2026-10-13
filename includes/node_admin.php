<?php
declare(strict_types=1);

function node_action_key_is_configured(): bool
{
    return NODE_ADMIN_ACTION_KEY_HASH !== '';
}

function verify_node_action_key(string $actionKey): bool
{
    if (!node_action_key_is_configured() || $actionKey === '') {
        return false;
    }

    return password_verify($actionKey, NODE_ADMIN_ACTION_KEY_HASH);
}

function require_node_action_key(?array $user, ?string $actionKey): void
{
    require_superadmin($user);

    if (!verify_node_action_key((string) $actionKey)) {
        http_response_code(403);
        exit('Invalid node action key.');
    }
}
