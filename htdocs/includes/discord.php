<?php
// ---------------------------------------------------
//  Moduł Discord – wysyłanie przez relay na CBA
// ---------------------------------------------------

if (!function_exists('discord_send')) {
    function discord_send($type, $message, $username = "Centrum Rozrywki", $color = 0x5865F2) {

        if (!$type || !$message) {
            return false;
        }

        // Wczytujemy config z adresem relaya i tokenem
        require_once __DIR__ . '/auth.php';

        if (!defined('DISCORD_RELAY_URL') || !defined('DISCORD_RELAY_SECRET')) {
            return false;
        }

        // Dane, które wyślemy do relay.php na CBA
        $postData = [
            'token'    => DISCORD_RELAY_SECRET,
            'type'     => $type,
            'message'  => $message,
            'username' => $username,
            'color'    => $color,
        ];

        $ch = curl_init(DISCORD_RELAY_URL);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); // zwykły POST x-www-form-urlencoded
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);

        curl_close($ch);

        if ($http_code < 200 || $http_code >= 300) {
            return "RELAY_HTTP_$http_code: $curl_err $response";
        }

        return $response ?: true;
    }
}
