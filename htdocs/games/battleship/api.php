<?php
// games/battleship/api.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
define('BATTLESHIP_INCLUDED', true);
require_once __DIR__ . '/battleship_logic.php';
require_once __DIR__ . '/../../includes/stats.php'; // ← globalne statystyki / XP

if (session_status() === PHP_SESSION_NONE) session_start();

// ------------------------------
// INPUT
// ------------------------------
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

$action  = $input['action']  ?? null;
$game_id = isset($input['game_id']) ? (int)$input['game_id'] : 0;

function api_error($msg) {
    echo json_encode(['ok'=>false,'error'=>$msg]);
    exit;
}

if (!$action || $game_id <= 0) api_error("Brak akcji lub game_id.");


// ------------------------------
// POBIERANIE GRY
// ------------------------------
$stmt = mysqli_prepare($conn, "SELECT * FROM battleship_games WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $game_id);
mysqli_stmt_execute($stmt);
$res  = mysqli_stmt_get_result($stmt);
$game = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$game) api_error("Nie znaleziono gry.");


// ------------------------------
// KIM JESTEM
// ------------------------------
$sessionKey = "battleship_player_" . $game_id;
$myPlayer   = $_SESSION[$sessionKey] ?? "spectator";


// ------------------------------
// PUBLICZNY STAN DLA FRONTU
// ------------------------------
function battleship_public_state($game, $myPlayer) {

    $state1 = json_decode($game['player1_state'] ?? '', true);
    $state2 = json_decode($game['player2_state'] ?? '', true);

    $mask = function($state, $isOwn) {
        if (!is_array($state) || !isset($state['board'])) {
            return [
                'board'=>array_fill(0,10,array_fill(0,10,0)),
                'remaining'=>0
            ];
        }

        $board = $state['board'];
        $out   = [];

        for ($y=0;$y<10;$y++) {
            $row=[];
            for ($x=0;$x<10;$x++) {
                $c = $board[$y][$x];
                if ($isOwn) {
                    $row[] = $c;
                } else {
                    if ($c==='H' || $c==='M' || $c==='S') $row[]=$c;
                    else $row[]=0;
                }
            }
            $out[] = $row;
        }

        return [
            'board'=>$out,
            'remaining'=>$state['remaining'] ?? 0
        ];
    };

    if ((int)$myPlayer === 1) {
        $my    = $mask($state1,true);
        $enemy = $mask($state2,false);
    }
    elseif ((int)$myPlayer === 2) {
        $my    = $mask($state2,true);
        $enemy = $mask($state1,false);
    }
    else {
        $my    = $mask($state1,false);
        $enemy = $mask($state2,false);
    }

    return [
        'id'           => (int)$game['id'],
        'mode'         => $game['mode'],
        'difficulty'   => $game['difficulty'],
        'player1_name' => $game['player1_name'],
        'player2_name' => $game['player2_name'],
        'current_turn' => (int)$game['current_turn'],
        'status'       => $game['status'],
        'winner'       => $game['winner'],
        'me'           => $myPlayer,
        'my_board'     => $my,
        'enemy_board'  => $enemy,
        'ships_p1'     => battleship_ships_summary($state1),
        'ships_p2'     => battleship_ships_summary($state2)
    ];
}


// ------------------------------
// GLOBALNE STATYSTYKI BATTLESHIP
// ------------------------------
function battleship_register_stats_for_game(array $game) {
    // winner = 1 (player1), 2 (player2); w statkach nie mamy remisu
    $winner = isset($game['winner']) ? (int)$game['winner'] : 0;
    if ($winner !== 1 && $winner !== 2) {
        return;
    }

    // w bazie są player1_id / player2_id → to user_id graczy
    $u1 = isset($game['player1_id']) ? (int)$game['player1_id'] : 0;
    $u2 = isset($game['player2_id']) ? (int)$game['player2_id'] : 0;

    $vsBot = ($game['mode'] === 'bot'); // w bot mode player2 to bot, user_id może być NULL

    if ($winner === 1) {
        if ($u1 > 0) {
            stats_register_result($u1, 'battleship', 'win');
        }
        if (!$vsBot && $u2 > 0) {
            stats_register_result($u2, 'battleship', 'loss');
        }
    } elseif ($winner === 2) {
        if (!$vsBot && $u2 > 0) {
            stats_register_result($u2, 'battleship', 'win');
        }
        if ($u1 > 0) {
            stats_register_result($u1, 'battleship', 'loss');
        }
    }
}


// ------------------------------
// GET_STATE
// ------------------------------
if ($action === 'get_state') {
    echo json_encode([
        'ok'=>true,
        'game'=>battleship_public_state($game,$myPlayer)
    ]);
    exit;
}


// ------------------------------
// FIRE
// ------------------------------
if ($action === 'fire') {

    if ($game['status'] !== 'in_progress')
        api_error("Gra nie jest w toku.");

    if (!in_array($myPlayer,[1,2,'1','2']))
        api_error("Nie jesteś graczem.");

    $myPlayer = (int)$myPlayer;

    if ((int)$game['current_turn'] !== $myPlayer)
        api_error("Nie Twoja kolej.");

    $x = (int)($input['x'] ?? -1);
    $y = (int)($input['y'] ?? -1);

    if ($x<0 || $x>9 || $y<0 || $y>9)
        api_error("Zły ruch.");

    $enemyKey   = ($myPlayer===1 ? 'player2_state' : 'player1_state');
    $enemyState = json_decode($game[$enemyKey],true);

    if (!$enemyState)
        api_error("Plansza przeciwnika jest pusta — błąd.");

    // zapamiętujemy stary status, żeby wiedzieć czy ta akcja zakończyła grę
    $oldStatus = $game['status'];

    // RUCH GRACZA
    $shot       = battleship_shot($enemyState,$x,$y);
    $enemyState = $shot['state'];

    $game[$enemyKey] = json_encode($enemyState);

    if ($shot['result']==='miss') {
        $game['current_turn'] = ($myPlayer===1 ? 2 : 1);
    }

    if ($shot['finished']) {
        $game['status'] = 'finished';
        $game['winner'] = $myPlayer;
    }

    // zapisujemy stan po ruchu gracza
    $stmt = mysqli_prepare($conn,
        "UPDATE battleship_games
         SET player1_state=?, player2_state=?, current_turn=?, status=?, winner=?
         WHERE id=?"
    );
    mysqli_stmt_bind_param(
        $stmt,"ssisii",
        $game['player1_state'],
        $game['player2_state'],
        $game['current_turn'],
        $game['status'],
        $game['winner'],
        $game['id']
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);


    // BOT MOVE (tylko dla gier vs bot),
    // tylko jeśli po ruchu gracza gra nadal trwa
    if ($game['mode']==='bot' &&
        $myPlayer===1 &&
        $game['status']==='in_progress') {

        $difficulty = $game['difficulty'] ?? 'easy';
        $p1 = json_decode($game['player1_state'],true);

        $bot    = battleship_bot_move($difficulty,$p1);
        $shotBot = battleship_shot($p1,$bot[0],$bot[1]);
        $p1      = $shotBot['state'];

        $game['player1_state'] = json_encode($p1);
        $game['current_turn']  = 1;

        if ($shotBot['finished']) {
            $game['status'] = 'finished';
            $game['winner'] = 2; // bot jako gracz 2
        }

        $stmt = mysqli_prepare($conn,
            "UPDATE battleship_games
             SET player1_state=?, current_turn=?, status=?, winner=?
             WHERE id=?"
        );
        mysqli_stmt_bind_param(
            $stmt,"sisii",
            $game['player1_state'],
            $game['current_turn'],
            $game['status'],
            $game['winner'],
            $game['id']
        );
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // Jeśli w tej akcji status zmienił się z in_progress na finished → zapisujemy globalne statystyki
    if ($oldStatus === 'in_progress' && $game['status'] === 'finished') {
        battleship_register_stats_for_game($game);
    }

    echo json_encode([
        'ok'    => true,
        'result'=> $shot['result'],
        'game'  => battleship_public_state($game,$myPlayer)
    ]);
    exit;
}


// ------------------------------
// SAVE_SETUP — ręczne rozstawianie
// ------------------------------
if ($action === 'save_setup') {

    if (!in_array($game['status'],['prepare_p1','prepare_p2','prepare_both']))
        api_error("Gra nie jest w trybie ustawiania.");

    if (!in_array($myPlayer,[1,2,'1','2']))
        api_error("Nie jesteś graczem.");

    $myPlayer = (int)$myPlayer;

    $board = $input['board'] ?? null;
    if (!$board) api_error("Brak planszy.");

    // walidacja rozmiaru
    if (count($board)!==10) api_error("Zła plansza.");
    foreach ($board as $r) {
        if (!is_array($r) || count($r)!==10) api_error("Zła plansza.");
    }

    // SERWER LICZY STATKI
    $ships=[];
    $remaining=0;

    $visited=array_fill(0,10,array_fill(0,10,false));

    for ($y=0;$y<10;$y++){
        for ($x=0;$x<10;$x++){
            $v=$board[$y][$x];
            if (!is_int($v)) $v=(int)$v;

            if ($v<=0 || $visited[$y][$x]) continue;

            $id=$v;
            $size=0;

            $stack=[[$x,$y]];
            $visited[$y][$x]=true;

            while($stack){
                [$cx,$cy]=array_pop($stack);
                $size++;

                $dirs=[[1,0],[-1,0],[0,1],[0,-1]];
                foreach($dirs as $d){
                    $nx=$cx+$d[0]; $ny=$cy+$d[1];
                    if ($nx<0||$nx>9||$ny<0||$ny>9) continue;
                    if ($visited[$ny][$nx]) continue;

                    $vv=$board[$ny][$nx];
                    if (!is_int($vv)) $vv=(int)$vv;

                    if ($vv===$id){
                        $visited[$ny][$nx]=true;
                        $stack[] = [$nx,$ny];
                    }
                }
            }

            $ships[$id]=['size'=>$size,'hits'=>0];
            $remaining++;
        }
    }

    if ($remaining===0) api_error("Nie wykryto statków.");

    $json = json_encode([
        'board'=>$board,
        'ships'=>$ships,
        'remaining'=>$remaining
    ]);

    if ($myPlayer===1){
        $game['player1_state']=$json;
    } else {
        $game['player2_state']=$json;
    }

    // PRZELICZAMY STATUS:
    $has1 = !empty($game['player1_state']);
    $has2 = !empty($game['player2_state']);

    if ($has1 && $has2) {
        $game['status']       = 'in_progress';
        $game['current_turn'] = 1;
    } else {
        // jeszcze ktoś nie ustawił — czekamy
        $game['status']       = 'prepare_both';
    }

    // zapis do bazy
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE battleship_games
         SET player1_state=?, player2_state=?, status=?, current_turn=?
         WHERE id=?"
    );
    mysqli_stmt_bind_param(
        $stmt,
        "sssii",
        $game['player1_state'],
        $game['player2_state'],
        $game['status'],
        $game['current_turn'],
        $game['id']
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode([
        'ok'=>true,
        'game'=>battleship_public_state($game,$myPlayer)
    ]);
    exit;
}


// ------------------------------
// UNKNOWN ACTION
// ------------------------------
api_error("Nieznana akcja.");
