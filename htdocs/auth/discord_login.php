<?php
// /auth/discord_login.php

// WAŻNE: wklej tu swoje CLIENT_ID z Discord Developer Portal
$DISCORD_CLIENT_ID = '1446492877248004289';

// Ten sam redirect, który ustawiłeś w panelu Discord:
$DISCORD_REDIRECT_URI = 'https://towarowa35.great-site.net/auth/discord_callback.php';

// Zakres – wystarczy identify (id, username, avatar)
$scope = 'identify';

// Start sesji (auth.php pewnie też to robi, ale tu działamy niezależnie)
session_start();

// Losujemy state do ochrony przed podrobionym callbackiem
$state = bin2hex(random_bytes(16));
$_SESSION['discord_oauth_state'] = $state;

// Budujemy URL autoryzacji
$params = [
    'client_id'     => $DISCORD_CLIENT_ID,
    'response_type' => 'code',
    'redirect_uri'  => $DISCORD_REDIRECT_URI,
    'scope'         => $scope,
    'state'         => $state,
    'prompt'        => 'consent'
];

$auth_url = 'https://discord.com/oauth2/authorize?' . http_build_query($params);

// Przekierowanie na stronę Discorda
header("Location: $auth_url");
exit;
