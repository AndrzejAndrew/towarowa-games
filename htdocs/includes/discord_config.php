<?php
/**
 * Ten plik NIE zawiera sekretów.
 * Sekrety są wstrzykiwane w secrets_runtime.php przez GitHub Actions.
 */

if (!defined('DISCORD_RELAY_SECRET')) {
    http_response_code(500);
    die('DISCORD_RELAY_SECRET missing (deploy error)');
}

if (!defined('DISCORD_OAUTH_RELAY_SECRET')) {
    http_response_code(500);
    die('DISCORD_OAUTH_RELAY_SECRET missing (deploy error)');
}
