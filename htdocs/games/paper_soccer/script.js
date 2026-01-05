// ----------------------------------------------------
// KONFIGURACJA PLANSZY
// ----------------------------------------------------

const cellSize    = 40;
const pointRadius = 3;
const cols        = 9;   // 0..8
const rows        = 13;  // 0..12

// wymiary boiska w pikselach (siatka 0..8, 0..12 to 8 i 12 odcinków)
const boardWidth  = (cols - 1) * cellSize;
const boardHeight = (rows - 1) * cellSize;

// marginesy + trybuny
const marginOutside = 20;   // odstęp od krawędzi canvas do trybun
const standWidth    = 40;   // szerokość trybun z każdej strony

// rozmiar canvas
const canvasWidth  = boardWidth  + marginOutside * 2 + standWidth * 2;
const canvasHeight = boardHeight + marginOutside * 2 + standWidth * 2;

// przesunięcie lewego górnego rogu boiska w canvas
const boardOffsetX = marginOutside + standWidth;
const boardOffsetY = marginOutside + standWidth;

// bramki – logiczne (zgodne z backendem)
const goalTop = {
    y: 0,
    xStart: 3,
    xEnd: 5
};

const goalBottom = {
    y: 12,
    xStart: 3,
    xEnd: 5
};

// stan gry po stronie frontu
let ball      = { x: 4, y: 6 };
let usedLines = [];
let gameId    = null;
let myPlayer  = null;
let currentPlayer = 1;
let gameOver = false;
let winner = 0;
let status = "waiting";

// DOM
const canvas = document.getElementById("ps-board");
const ctx = canvas ? canvas.getContext("2d") : null;

if (canvas) {
    canvas.width  = canvasWidth;
    canvas.height = canvasHeight;
}

const statusEl  = document.getElementById("ps-status");
const playersEl = document.getElementById("ps-players");
const messageEl = document.getElementById("ps-message");

// ----------------------------------------------------
// HELPERY
// ----------------------------------------------------

function gridToPx(x, y) {
    return {
        x: boardOffsetX + x * cellSize,
        y: boardOffsetY + y * cellSize
    };
}

function setMessage(txt) {
    if (messageEl) messageEl.textContent = txt;
}

function setStatus(txt) {
    if (statusEl) statusEl.textContent = txt;
}

function setPlayers(p1, p2) {
    if (playersEl) playersEl.textContent = `Gracz 1: ${p1} | Gracz 2: ${p2}`;
}

function isInsideBoard(px, py) {
    return (
        px >= boardOffsetX - cellSize / 2 &&
        px <= boardOffsetX + boardWidth + cellSize / 2 &&
        py >= boardOffsetY - cellSize / 2 &&
        py <= boardOffsetY + boardHeight + cellSize / 2
    );
}

// ----------------------------------------------------
// RYSOWANIE – TŁO, TRYBUNY, BOISKO
// ----------------------------------------------------

function drawStands() {
    // trybuny – ciemne
    ctx.fillStyle = "#151515";

    // lewa
    ctx.fillRect(marginOutside, marginOutside, standWidth, canvasHeight - marginOutside * 2);
    // prawa
    ctx.fillRect(canvasWidth - marginOutside - standWidth, marginOutside, standWidth, canvasHeight - marginOutside * 2);
    // góra
    ctx.fillRect(marginOutside, marginOutside, canvasWidth - marginOutside * 2, standWidth);
    // dół
    ctx.fillRect(marginOutside, canvasHeight - marginOutside - standWidth, canvasWidth - marginOutside * 2, standWidth);

    // drobne „kropki tłumu”
    ctx.fillStyle = "rgba(255,255,255,0.06)";
    for (let i = 0; i < 900; i++) {
        const x = marginOutside + Math.random() * (canvasWidth - marginOutside * 2);
        const y = marginOutside + Math.random() * (canvasHeight - marginOutside * 2);

        // pomijamy obszar boiska
        if (
            x > boardOffsetX && x < boardOffsetX + boardWidth &&
            y > boardOffsetY && y < boardOffsetY + boardHeight
        ) continue;

        ctx.fillRect(x, y, 1, 1);
    }
}

function drawPitch() {
    // murawa – jednolita, jasna z lekkim gradientem
    const bw = boardWidth;
    const bh = boardHeight;

    const gx = boardOffsetX;
    const gy = boardOffsetY;

    const grad = ctx.createLinearGradient(gx, gy, gx, gy + bh);
    grad.addColorStop(0, "#2fbf5b");
    grad.addColorStop(1, "#24a049");

    ctx.fillStyle = grad;
    ctx.fillRect(gx, gy, bw, bh);

    // jaśniejsze pasy murawy – subtelne, poziome
    const stripeH = cellSize * 1;
    for (let y = 0; y < bh; y += stripeH * 2) {
        ctx.fillStyle = "rgba(255,255,255,0.05)";
        ctx.fillRect(gx, gy + y, bw, stripeH);
    }

    // linie boiska – białe, grube, cartoon
    ctx.strokeStyle = "#ffffff";
    ctx.lineWidth   = 3;
    ctx.lineJoin    = "round";

    ctx.beginPath();
    // linia zewnętrzna
    ctx.rect(gx + 6, gy + 6, bw - 12, bh - 12);

    // bramki (góra/dół)
    // górna
    const goalW = cellSize * 2; // 2 kratki
    const topGoalX = gx + (4 * cellSize) - goalW / 2;
    ctx.rect(topGoalX, gy - 8, goalW, 14);

    // dolna
    const bottomGoalX = gx + (4 * cellSize) - goalW / 2;
    ctx.rect(bottomGoalX, gy + bh - 6, goalW, 14);

    // linia środkowa (w poprzek boiska)
    ctx.moveTo(gx + 6,       gy + bh / 2);
    ctx.lineTo(gx + bw - 6,  gy + bh / 2);

    // koło środkowe
    const center = gridToPx(4, 6);
    const circleR = cellSize * 1.3;
    ctx.moveTo(center.x + circleR, center.y);
    ctx.arc(center.x, center.y, circleR, 0, Math.PI * 2);

    // pola karne – górne
    const penaltyDepth   = cellSize * 3.2;
    const boxWidth       = bw * 0.6;
    const boxX           = gx + (bw - boxWidth) / 2;
    ctx.rect(boxX, gy + 6, boxWidth, penaltyDepth);

    // pola karne – dolne
    ctx.rect(boxX, gy + bh - 6 - penaltyDepth, boxWidth, penaltyDepth);

    ctx.stroke();
}

function drawGrid() {
    // punkty + siatka
    ctx.fillStyle = "rgba(255,255,255,0.6)";
    for (let y = 0; y < rows; y++) {
        for (let x = 0; x < cols; x++) {
            const p = gridToPx(x, y);
            ctx.beginPath();
            ctx.arc(p.x, p.y, pointRadius, 0, Math.PI * 2);
            ctx.fill();
        }
    }
}

function drawLines() {
    // narysuj wykonane linie
    ctx.strokeStyle = "#ffd200";
    ctx.lineWidth   = 4;
    ctx.lineCap     = "round";

    for (let l of usedLines) {
        const p1 = gridToPx(l.x1, l.y1);
        const p2 = gridToPx(l.x2, l.y2);
        ctx.beginPath();
        ctx.moveTo(p1.x, p1.y);
        ctx.lineTo(p2.x, p2.y);
        ctx.stroke();
    }
}

function drawBall() {
    const p = gridToPx(ball.x, ball.y);

    // cień
    ctx.fillStyle = "rgba(0,0,0,0.25)";
    ctx.beginPath();
    ctx.arc(p.x + 2, p.y + 2, 10, 0, Math.PI * 2);
    ctx.fill();

    // piłka
    ctx.fillStyle = "#ffffff";
    ctx.beginPath();
    ctx.arc(p.x, p.y, 10, 0, Math.PI * 2);
    ctx.fill();

    // „szwy”
    ctx.strokeStyle = "#000";
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.arc(p.x, p.y, 8, 0, Math.PI * 2);
    ctx.stroke();
}

function drawBoard() {
    if (!ctx) return;

    // tło całego canvas
    ctx.clearRect(0, 0, canvasWidth, canvasHeight);
    ctx.fillStyle = "#0b0b0b";
    ctx.fillRect(0, 0, canvasWidth, canvasHeight);

    drawStands();
    drawPitch();
    drawGrid();
    drawLines();
    drawBall();

    // overlay dla końca gry
    if (gameOver) {
        ctx.fillStyle = "rgba(0,0,0,0.55)";
        ctx.fillRect(0, 0, canvasWidth, canvasHeight);

        ctx.fillStyle = "#fff";
        ctx.font = "bold 36px Arial";
        ctx.textAlign = "center";
        ctx.fillText("KONIEC", canvasWidth / 2, canvasHeight / 2 - 10);

        ctx.font = "20px Arial";
        const msg = winner === 0 ? "Remis!" : (winner === myPlayer ? "Wygrałeś!" : "Przegrałeś!");
        ctx.fillText(msg, canvasWidth / 2, canvasHeight / 2 + 30);
    }
}

// ----------------------------------------------------
// LOGIKA RUCHU – WALIDACJA
// ----------------------------------------------------

function isLineUsed(x1, y1, x2, y2) {
    return usedLines.some(l =>
        (l.x1 === x1 && l.y1 === y1 && l.x2 === x2 && l.y2 === y2) ||
        (l.x1 === x2 && l.y1 === y2 && l.x2 === x1 && l.y2 === y1)
    );
}

function addLine(x1, y1, x2, y2) {
    usedLines.push({ x1, y1, x2, y2 });
}

// walidacja ruchu – z zakazem jazdy wzdłuż ściany
function isValidMove(x, y) {
    // poza planszą
    if (x < 0 || x >= cols || y < 0 || y >= rows) return false;

    // dozwolone 8 kierunków, ale tylko o 1
    const dx = Math.abs(x - ball.x);
    const dy = Math.abs(y - ball.y);
    if (dx > 1 || dy > 1 || (dx === 0 && dy === 0)) return false;

    // linia nie może się powtórzyć
    if (isLineUsed(ball.x, ball.y, x, y)) return false;

    // zakaz jazdy wzdłuż ściany
    const leftSlide   = (ball.x === 0       && x === 0       && ball.y !== y);
    const rightSlide  = (ball.x === cols-1  && x === cols-1  && ball.y !== y);
    const topSlide    = (ball.y === 0       && y === 0       && ball.x !== x);
    const bottomSlide = (ball.y === rows-1  && y === rows-1  && ball.x !== x);

    if (leftSlide || rightSlide || topSlide || bottomSlide) return false;

    return true;
}

function hasBounce(x, y) {
    // odbicie od ściany
    if (x === 0 || x === cols - 1) return true;
    if (y === 0 || y === rows - 1) return true;

    // odbicie na skrzyżowaniu (gdy punkt jest już częścią >=2 linii)
    let deg = 0;
    for (let l of usedLines) {
        if ((l.x1 === x && l.y1 === y) || (l.x2 === x && l.y2 === y)) {
            deg++;
            if (deg >= 2) return true;
        }
    }
    return false;
}

function isGoal(x, y) {
    // gol dla gracza 2 (u góry)
    if (y === goalTop.y && x >= goalTop.xStart && x <= goalTop.xEnd) return 2;
    // gol dla gracza 1 (na dole)
    if (y === goalBottom.y && x >= goalBottom.xStart && x <= goalBottom.xEnd) return 1;
    return 0;
}

function getPointFromMouse(evt) {
    const rect = canvas.getBoundingClientRect();
    const mx = evt.clientX - rect.left;
    const my = evt.clientY - rect.top;

    if (!isInsideBoard(mx, my)) return null;

    // znajdź najbliższy punkt siatki
    const gx = Math.round((mx - boardOffsetX) / cellSize);
    const gy = Math.round((my - boardOffsetY) / cellSize);

    return { x: gx, y: gy };
}

// ----------------------------------------------------
// RUCH GRACZA
// ----------------------------------------------------

async function sendMove(toX, toY) {
    if (gameOver) return;

    const extra = hasBounce(toX, toY) ? 1 : 0;
    const goal = isGoal(toX, toY);
    const draw = 0; // backend może ustawić, tu zostawiamy 0

    const payload = new URLSearchParams();
    payload.set("game_id", gameId);
    payload.set("from_x", ball.x);
    payload.set("from_y", ball.y);
    payload.set("to_x", toX);
    payload.set("to_y", toY);
    payload.set("extra", extra);
    payload.set("goal", goal ? 1 : 0);
    payload.set("draw", draw);

    const res = await fetch("move.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: payload.toString()
    });

    const data = await res.json();
    if (data.error) {
        setMessage(data.error);
        return;
    }

    // lokalna aktualizacja (opcjonalna)
    addLine(ball.x, ball.y, toX, toY);
    ball.x = toX;
    ball.y = toY;

    // odśwież stan z serwera
    await syncGame();
}

if (canvas) {
    canvas.addEventListener("click", async (evt) => {
        if (!gameId) return;
        if (gameOver) return;

        if (currentPlayer !== myPlayer) {
            setMessage("Nie Twoja tura.");
            return;
        }

        const p = getPointFromMouse(evt);
        if (!p) return;

        if (!isValidMove(p.x, p.y)) {
            setMessage("Nielegalny ruch.");
            return;
        }

        setMessage("");
        await sendMove(p.x, p.y);
    });
}

// ----------------------------------------------------
// SYNC STANU Z SERWERA
// ----------------------------------------------------

async function syncGame() {
    if (!gameId) return;

    const res = await fetch(`state.php?game_id=${gameId}`, { cache: "no-store" });
    const data = await res.json();
    if (data.error) {
        setMessage(data.error);
        return;
    }

    const g = data.game;
    status = g.status;
    winner = g.winner;
    currentPlayer = g.current_player;

    setStatus(`Status: ${status} | Tura: Gracz ${currentPlayer}`);

    setPlayers(g.player1_name, g.player2_name);

    // odtworzenie linii / piłki od zera
    usedLines = [];
    ball = { x: 4, y: 6 };

    const moves = data.moves || [];
    for (let mv of moves) {
        // POPRAWKA: DB/PHP zwraca liczby jako stringi — ujednolicamy typy
        addLine(Number(mv.from_x), Number(mv.from_y), Number(mv.to_x), Number(mv.to_y));
        ball.x = Number(mv.to_x);
        ball.y = Number(mv.to_y);
    }

    // koniec gry?
    gameOver = (status === "finished");
    drawBoard();
}

// start + rewanż
if (canvas && ctx) {
    drawBoard();
    syncGame();

    // auto-sync co 1s
    setInterval(syncGame, 1000);
}

// ----------------------------------------------------
// INICJALIZACJA Z DANYCH WSTRZYKNIĘTYCH Z PHP
// ----------------------------------------------------
if (window.PS_GAME_ID) gameId = window.PS_GAME_ID;
if (window.PS_MY_PLAYER) myPlayer = window.PS_MY_PLAYER;
