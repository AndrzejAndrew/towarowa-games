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

// pozycja boiska (lewygórny róg)
const boardOffsetX = marginOutside + standWidth;
const boardOffsetY = marginOutside + standWidth;

// potrzebny canvas
const canvas = document.getElementById("ps-board");
let   ctx    = null;

if (canvas) {
    canvas.width  = boardWidth  + (marginOutside + standWidth) * 2;
    canvas.height = boardHeight + (marginOutside + standWidth) * 2;
    ctx = canvas.getContext("2d");
}

// bramki – logiczne (zgodne z backendem)
const goalTop = { y: 0,  xStart: 3, xEnd: 5 };
const goalBottom = { y: 12, xStart: 3, xEnd: 5 };

// stan gry po stronie frontu
let ball      = { x: 4, y: 6 }; // środek planszy
let usedLines = [];

// dane z atrybutów canvas (ZGODNE z play.php)
let ajaxGameID  = canvas ? parseInt(canvas.dataset.gameId, 10) : null;
let ajaxPlayer  = canvas ? parseInt(canvas.dataset.player, 10) : null;
let gameMode    = canvas ? (canvas.dataset.mode || null) : null;
let botDiff     = canvas ? parseInt(canvas.dataset.botDiff || "1", 10) : 1;

let movesLoaded   = 0;
let isSendingMove = false;

// Wyniki meczów (lokalnie, na podstawie winner)
let scoreP1 = 0;
let scoreP2 = 0;
let scoresLoaded = false;
let scoreKey = null;
let scoreCountedForGame = false;

// do animacji piłki
let animating     = false;
let animStartTime = 0;
let animDuration  = 180; // ms
let animFrom      = null;
let animTo        = null;

// ----------------------------------------------------
// POMOCNICZE – konwersja z siatki na piksele
// ----------------------------------------------------
function gridToPx(x, y) {
    return {
        x: boardOffsetX + x * cellSize,
        y: boardOffsetY + y * cellSize
    };
}

// ----------------------------------------------------
// RYSOWANIE – STADION + BOISKO
// ----------------------------------------------------
function drawStands() {
    // tło całego canvas
    ctx.fillStyle = "#050814";
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // prostokąt trybun
    const sx = marginOutside;
    const sy = marginOutside;
    const sw = canvas.width  - marginOutside * 2;
    const sh = canvas.height - marginOutside * 2;

    ctx.fillStyle = "#1e2430";
    ctx.fillRect(sx, sy, sw, sh);

    // paski trybun (pionowe)
    ctx.save();
    ctx.beginPath();
    ctx.rect(sx, sy, sw, sh);
    ctx.clip();

    const stripeWidth = 16;
    for (let x = sx; x < sx + sw; x += stripeWidth) {
        ctx.fillStyle = ((((x / stripeWidth) | 0) % 2) === 0) ? "#262d3b" : "#161b26";
        ctx.fillRect(x, sy, stripeWidth, sh);
    }

    ctx.restore();

    // delikatna ramka stadionu
    ctx.strokeStyle = "#3b4251";
    ctx.lineWidth   = 2;
    ctx.strokeRect(sx, sy, sw, sh);
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

    // LINIA ŚRODKOWA (POPRAWKA: w poprzek boiska)
    ctx.moveTo(gx + 6,      gy + bh / 2);
    ctx.lineTo(gx + bw - 6, gy + bh / 2);

    // koło środkowe
    const center = gridToPx(4, 6);
    const circleR = cellSize * 1.3;
    ctx.moveTo(center.x + circleR, center.y);
    ctx.arc(center.x, center.y, circleR, 0, Math.PI * 2);

    // pola karne – górne/dolne
    const penaltyDepth   = cellSize * 3.2;
    const boxWidth       = bw * 0.6;
    const boxX           = gx + (bw - boxWidth) / 2;
    const topBoxY        = gy + 6;
    const bottomBoxY     = gy + bh - 6 - penaltyDepth;

    ctx.rect(boxX, topBoxY, boxWidth, penaltyDepth);
    ctx.rect(boxX, bottomBoxY, boxWidth, penaltyDepth);

    // małe pola bramkowe
    const smallDepth = cellSize * 1.6;
    const smallWidth = bw * 0.35;
    const smallX     = gx + (bw - smallWidth) / 2;
    const topSmallY  = topBoxY;
    const botSmallY  = gy + bh - 6 - smallDepth;

    ctx.rect(smallX, topSmallY, smallWidth, smallDepth);
    ctx.rect(smallX, botSmallY, smallWidth, smallDepth);

    // punkty karne
    const topPenaltySpot = { x: center.x, y: topBoxY + penaltyDepth - cellSize * 0.8 };
    const botPenaltySpot = { x: center.x, y: bottomBoxY + cellSize * 0.8 };

    ctx.moveTo(topPenaltySpot.x + 1.5, topPenaltySpot.y);
    ctx.arc(topPenaltySpot.x, topPenaltySpot.y, 1.5, 0, Math.PI * 2);

    ctx.moveTo(botPenaltySpot.x + 1.5, botPenaltySpot.y);
    ctx.arc(botPenaltySpot.x, botPenaltySpot.y, 1.5, 0, Math.PI * 2);

    ctx.stroke();

    // bramki – czerwone pola na liniach końcowych
    ctx.strokeStyle = "#ff5555";
    ctx.lineWidth   = 6;
    ctx.beginPath();

    // górna bramka
    let pA = gridToPx(goalTop.xStart, goalTop.y);
    let pB = gridToPx(goalTop.xEnd,   goalTop.y);
    ctx.moveTo(pA.x, pA.y - cellSize * 0.35);
    ctx.lineTo(pB.x, pB.y - cellSize * 0.35);

    // dolna bramka
    pA = gridToPx(goalBottom.xStart, goalBottom.y);
    pB = gridToPx(goalBottom.xEnd,   goalBottom.y);
    ctx.moveTo(pA.x, pA.y + cellSize * 0.35);
    ctx.lineTo(pB.x, pB.y + cellSize * 0.35);

    ctx.stroke();
}

// ----------------------------------------------------
// PUNKTY, LINIE, PIŁKA
// ----------------------------------------------------
function drawPoint(x, y) {
    const p = gridToPx(x, y);

    ctx.beginPath();
    ctx.arc(p.x, p.y, pointRadius, 0, Math.PI * 2);
    ctx.fillStyle = "#0b1f46";
    ctx.fill();
}

function drawLine(x1, y1, x2, y2) {
    const p1 = gridToPx(x1, y1);
    const p2 = gridToPx(x2, y2);

    ctx.strokeStyle = "#000000";
    ctx.lineWidth   = 2.2;
    ctx.lineCap     = "round";

    ctx.beginPath();
    ctx.moveTo(p1.x, p1.y);
    ctx.lineTo(p2.x, p2.y);
    ctx.stroke();
}

// piłka – cartoon
function drawBallAtPixel(px, py) {
    const r = cellSize * 0.33;

    // cień
    ctx.save();
    ctx.globalAlpha = 0.35;
    ctx.fillStyle = "#000000";
    ctx.beginPath();
    ctx.ellipse(px + r * 0.25, py + r * 0.35, r * 0.8, r * 0.5, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    // kula
    ctx.beginPath();
    ctx.arc(px, py, r, 0, Math.PI * 2);
    ctx.fillStyle = "#fdfdfd";
    ctx.fill();

    ctx.lineWidth = 3;
    ctx.strokeStyle = "#222222";
    ctx.stroke();

    // „łaty”
    ctx.lineWidth = 2;
    ctx.strokeStyle = "#555555";

    ctx.beginPath();
    ctx.moveTo(px, py - r);
    ctx.lineTo(px - r * 0.45, py - r * 0.2);
    ctx.lineTo(px, py);
    ctx.lineTo(px + r * 0.45, py - r * 0.2);
    ctx.closePath();
    ctx.stroke();

    ctx.beginPath();
    ctx.moveTo(px - r * 0.15, py + r * 0.1);
    ctx.lineTo(px - r * 0.45, py + r * 0.55);
    ctx.lineTo(px + r * 0.1,  py + r * 0.4);
    ctx.closePath();
    ctx.stroke();

    // highlight
    ctx.beginPath();
    ctx.arc(px - r * 0.35, py - r * 0.35, r * 0.18, 0, Math.PI * 2);
    ctx.fillStyle = "rgba(255,255,255,0.9)";
    ctx.fill();
}

function drawBall(x, y) {
    const p = gridToPx(x, y);
    drawBallAtPixel(p.x, p.y);
}

// ----------------------------------------------------
// RYSOWANIE CAŁOŚCI
// ----------------------------------------------------
function drawBoard() {
    if (!ctx || !canvas) return;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    drawStands();
    drawPitch();

    // siatka punktów
    for (let y = 0; y < rows; y++) {
        for (let x = 0; x < cols; x++) {
            drawPoint(x, y);
        }
    }

    // linie ruchów
    for (let line of usedLines) {
        drawLine(line.x1, line.y1, line.x2, line.y2);
    }

    // piłka (jeśli nie trwa animacja)
    if (!animating) {
        drawBall(ball.x, ball.y);
    }
}

// ----------------------------------------------------
// LOGIKA – linie, ruchy, odbicia
// ----------------------------------------------------
function isLineUsed(x1, y1, x2, y2) {
    return usedLines.some(l =>
        (l.x1 === x1 && l.y1 === y1 &
