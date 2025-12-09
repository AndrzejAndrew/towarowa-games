<?php
// Globalny handler błędów/wyjątków → Discord (przez relay)

require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/discord_config.php';

function discord_error_handler($errno, $errstr, $errfile, $errline) {
    global $DISCORD_META;

    // Jeśli chcesz mniej spamu, możesz zawęzić typy błędów:
    if (!($errno & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_WARNING | E_USER_WARNING))) {
        return false;
    }

    $msg = "⚠️ **PHP Error**\n"
         . "Kod: `$errno`\n"
         . "Opis: `$errstr`\n"
         . "Plik: `$errfile`\n"
         . "Linia: `$errline`\n"
         . "Czas: " . date('Y-m-d H:i:s');

    discord_send(
        'log_sys',
        $msg,
        $DISCORD_META['log_sys']['username'] ?? 'System',
        $DISCORD_META['log_sys']['color'] ?? 0x95A5A6
    );

    return false; // pozwala PHP dalej normalnie logować
}

function discord_exception_handler(Throwable $ex) {
    global $DISCORD_META;

    $msg = "💥 **PHP Exception**\n"
         . "Typ: `" . get_class($ex) . "`\n"
         . "Opis: `" . $ex->getMessage() . "`\n"
         . "Plik: `" . $ex->getFile() . "`\n"
         . "Linia: `" . $ex->getLine() . "`\n"
         . "Czas: " . date('Y-m-d H:i:s');

    discord_send(
        'log_sys',
        $msg,
        $DISCORD_META['log_sys']['username'] ?? 'System',
        $DISCORD_META['log_sys']['color'] ?? 0x95A5A6
    );
}

set_error_handler('discord_error_handler');
set_exception_handler('discord_exception_handler');
