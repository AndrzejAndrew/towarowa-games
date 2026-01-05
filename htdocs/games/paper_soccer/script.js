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
let ball      = { x: 4, y: 6 }; // środek planszy
let usedLines = [];

// dane z atrybutów canvas
let ajaxGameID  = canvas ? parseInt(canvas.dataset.gameId, 10) : null;
let ajaxPlayer  = canvas ? parseInt(canvas.dataset.player, 10) : null;
let gameMode    = canvas ? canvas.dataset.mode : null;
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
        if (((x / stripeWidth) | 0) % 2 === 0) {
            ctx.fillStyle = "#262d3b";
        } else {
            ctx.fillStyle = "#161b26";
        }
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

    // linia środkowa
    ctx.moveTo(gx + 6,       gy + bh / 2);
    ctx.lineTo(gx + bw - 6, gy + bh / 2);


    // koło środkowe
    const center = gridToPx(4, 6);
    const circleR = cellSize * 1.3;
    ctx.moveTo(center.x + circleR, center.y);
    ctx.arc(center.x, center.y, circleR, 0, Math.PI * 2);

    // pola karne – górne
    const penaltyDepth   = cellSize * 3.2;
    const boxWidth       = bw * 0.6;
    const boxX           = gx + (bw - boxWidth) / 2;
    const topBoxY        = gy + 6;
    const bottomBoxY     = gy + bh - 6 - penaltyDepth;

    // prostokąty pól karnych
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

    // bramki – czerwone pola na liniach końcowych (nad i pod siatką punktów)
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

// piłka – cartoon (gruby kontur, łatwo widoczna)
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

    // kilka „łat” – komiksowo, bez dokładnej geometrii
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

    // piłka (jeśli nie trwa animacja, rysujemy w obecnej pozycji)
    if (!animating) {
        drawBall(ball.x, ball.y);
    }
}

// ----------------------------------------------------
// LOGIKA – linie, ruchy, odbicia
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

    const dx = Math.abs(x - ball.x);
    const dy = Math.abs(y - ball.y);

    // musi być sąsiednie pole i nie to samo
    if (dx > 1 || dy > 1 || (dx === 0 && dy === 0)) return false;

    // linia już była?
    if (isLineUsed(ball.x, ball.y, x, y)) return false;

    // zakaz ślizgania się po ścianie
    const onLeftWallSlide   = (ball.x === 0        && x === 0        && ball.y !== y);
    const onRightWallSlide  = (ball.x === cols - 1 && x === cols - 1 && ball.y !== y);
    const onTopWallSlide    = (ball.y === 0        && y === 0        && ball.x !== x);
    const onBottomWallSlide = (ball.y === rows - 1 && y === rows - 1 && ball.x !== x);

    if (onLeftWallSlide || onRightWallSlide || onTopWallSlide || onBottomWallSlide) {
        return false;
    }

    return true;
}

// odbicie
function hasBounce(x, y) {
    // 1) ŚCIANY
    if (x === 0 || x === cols - 1) return true;
    if (y === 0 || y === rows - 1) return true;

    // 2) SKRZYŻOWANIA – jeśli z punktu (x,y) wychodzi >= 2 odcinki
    let degree = 0;
    for (let line of usedLines) {
        if (
            (line.x1 === x && line.y1 === y) ||
            (line.x2 === x && line.y2 === y)
        ) {
            degree++;
            if (degree >= 2) {
                return true;
            }
        }
    }

    return false;
}

// dowolna bramka (numer nie jest używany, tylko boolean)
function isGoal(x, y) {
    if (y === goalBottom.y && x >= goalBottom.xStart && x <= goalBottom.xEnd) return 1;
    if (y === goalTop.y    && x >= goalTop.xStart    && x <= goalTop.xEnd)    return 2;
    return 0;
}

// ----------------------------------------------------
// ANIMACJA PIŁKI
// ----------------------------------------------------
function startBallAnimation(fromX, fromY, toX, toY) {
    animating     = true;
    animStartTime = performance.now();
    animFrom      = gridToPx(fromX, fromY);
    animTo        = gridToPx(toX,   toY);

    function step(now) {
        if (!animating) return;

        const t = Math.min(1, (now - animStartTime) / animDuration);
        const ease = t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t; // lekka krzywa

        const x = animFrom.x + (animTo.x - animFrom.x) * ease;
        const y = animFrom.y + (animTo.y - animFrom.y) * ease;

        drawBoard();
        drawBallAtPixel(x, y);

        if (t < 1) {
            requestAnimationFrame(step);
        } else {
            animating = false;
            drawBoard();
        }
    }

    requestAnimationFrame(step);
}

// ----------------------------------------------------
// WYKONANIE RUCHU + WYSŁANIE DO BACKENDU
// ----------------------------------------------------
function makeMove(x, y) {
    if (isSendingMove) return;

    const prev = { x: ball.x, y: ball.y };

    // GOL?
    const goal = isGoal(x, y);
    if (goal) {
        addLine(ball.x, ball.y, x, y);
        ball.x = x;
        ball.y = y;
        startBallAnimation(prev.x, prev.y, x, y);
        sendMove(prev.x, prev.y, x, y, 0, 1, 0);
        return;
    }

    // NORMALNY RUCH
    addLine(ball.x, ball.y, x, y);
    ball.x = x;
    ball.y = y;

    const extra = hasBounce(x, y) ? 1 : 0;
    startBallAnimation(prev.x, prev.y, x, y);
    sendMove(prev.x, prev.y, x, y, extra, 0, 0);
}

// ----------------------------------------------------
// KLIK NA PLANSZY
// ----------------------------------------------------
if (canvas && ctx) {
    canvas.addEventListener("click", function (e) {
        if (canvas.style.pointerEvents === "none") return;

        const rect = canvas.getBoundingClientRect();
        const mx = e.clientX - rect.left;
        const my = e.clientY - rect.top;

        // konwersja z pikseli na siatkę
        const x = Math.round((mx - boardOffsetX) / cellSize);
        const y = Math.round((my - boardOffsetY) / cellSize);

        if (!isValidMove(x, y)) return;

        makeMove(x, y);
    });
}

// ----------------------------------------------------
// WYŚLIJ RUCH DO BACKENDU
// ----------------------------------------------------
function sendMove(fromX, fromY, toX, toY, extra, goal, draw) {
    if (!ajaxGameID) return;

    isSendingMove = true;

    const data = new FormData();
    data.append("game_id", ajaxGameID);
    data.append("from_x", fromX);
    data.append("from_y", fromY);
    data.append("to_x", toX);
    data.append("to_y", toY);
    data.append("extra", extra ? 1 : 0);
    data.append("goal", goal ? 1 : 0);
    data.append("draw", draw ? 1 : 0);

    fetch("move.php", { method: "POST", body: data })
        .then(async (r) => {
            const raw = await r.text();
            console.log("move.php RAW RESPONSE:", raw);

            if (!r.ok) {
                alert(
                    "Serwer zwrócił błąd HTTP " +
                    r.status +
                    ". Odpowiedź:\n\n" +
                    raw.slice(0, 400)
                );
                throw new Error("HTTP " + r.status);
            }

            let resp;
            try {
                resp = JSON.parse(raw);
            } catch (e) {
                alert(
                    "Serwer zwrócił niepoprawny JSON.\n" +
                    "Początek odpowiedzi:\n\n" +
                    raw.slice(0, 400)
                );
                throw e;
            }

            if (resp && resp.error) {
                alert("Błąd z move.php: " + resp.error);
            }

            syncGame();
        })
        .catch(err => {
            console.error("Błąd podczas wysyłania ruchu move.php:", err);
            alert("Wystąpił błąd po stronie serwera przy wysyłaniu ruchu.");
        })
        .finally(() => {
            isSendingMove = false;
        });
}

// ----------------------------------------------------
// SYNC PVP/BOT – co 700 ms
// ----------------------------------------------------
function syncGame() {
    if (!ajaxGameID || !canvas || !ctx) return;

    fetch("state.php?game_id=" + encodeURIComponent(ajaxGameID))
        .then(r => r.json())
        .then(state => {
            const infoTurn    = document.getElementById("ps-turn-info");
            const p1NameEl    = document.getElementById("ps-p1-name");
            const p2NameEl    = document.getElementById("ps-p2-name");
            const p1GoalEl    = document.getElementById("ps-p1-goal");
            const p2GoalEl    = document.getElementById("ps-p2-goal");
            const scoreEl     = document.getElementById("ps-score");

            if (!state || !state.game) {
                if (infoTurn) infoTurn.innerHTML = "Błąd stanu gry.";
                return;
            }

            const p1Name = state.game.player1_name || "Gracz 1";
            const p2Name = state.game.player2_name || "Gracz 2";

            if (p1NameEl) p1NameEl.textContent = p1Name;
            if (p2NameEl) p2NameEl.textContent = p2Name;

            // opis bramek – P1 dół, P2 góra
            if (p1GoalEl) p1GoalEl.textContent = "Atakujesz bramkę na dole";
            if (p2GoalEl) p2GoalEl.textContent = "Atakujesz bramkę u góry";

            // ładowanie wyniku z localStorage (tylko raz)
            if (!scoresLoaded) {
                scoreKey = "ps_score_" + p1Name + "_" + p2Name;
                try {
                    const stored = localStorage.getItem(scoreKey);
                    if (stored) {
                        const parsed = JSON.parse(stored);
                        scoreP1 = parsed.p1 || 0;
                        scoreP2 = parsed.p2 || 0;
                    }
                } catch (e) {
                    console.warn("Nie udało się odczytać wyniku z localStorage:", e);
                }
                if (scoreEl) scoreEl.textContent = scoreP1 + " : " + scoreP2;
                scoresLoaded = true;
            }

            // OCZEKIWANIE
            if (state.game.status === "waiting") {
                if (infoTurn) infoTurn.innerHTML = "Oczekiwanie na drugiego gracza...";
                canvas.style.pointerEvents = "none";
                return;
            }

            // KONIEC GRY
            if (state.game.status === "finished") {

                // dorysuj wszystkie ruchy
                if (Array.isArray(state.moves) && state.moves.length !== movesLoaded) {
                    reloadMoves(state.moves);
                    movesLoaded = state.moves.length;
                }

                // ustalenie przyczyny
                let reason = "nomove";

                if (state.game.winner == 0) {
                    reason = "draw";
                }

                if (Array.isArray(state.moves) && state.moves.length > 0) {
                    const last = state.moves[state.moves.length - 1];
                    const lx = last.to_x;
                    const ly = last.to_y;

                    if (
                        (ly === goalTop.y    && lx >= goalTop.xStart    && lx <= goalTop.xEnd) ||
                        (ly === goalBottom.y && lx >= goalBottom.xStart && lx <= goalBottom.xEnd)
                    ) {
                        reason = "goal";
                    }
                }

                const winner = parseInt(state.game.winner, 10);
                const me     = ajaxPlayer;

                // aktualizacja wyniku (tylko raz dla danej gry)
                if (!scoreCountedForGame) {
                    if (winner === 1) scoreP1++;
                    else if (winner === 2) scoreP2++;

                    if (scoreEl) scoreEl.textContent = scoreP1 + " : " + scoreP2;

                    if (scoreKey) {
                        try {
                            localStorage.setItem(
                                scoreKey,
                                JSON.stringify({ p1: scoreP1, p2: scoreP2 })
                            );
                        } catch (e) {
                            console.warn("Nie udało się zapisać wyniku do localStorage:", e);
                        }
                    }
                    scoreCountedForGame = true;
                }

                if (infoTurn) {
                    let msg = "";

                    if (winner === 0 || reason === "draw") {
                        msg = "🤝 Gra zakończona remisem.";
                    } else if (winner === me) {
                        if (reason === "goal") {
                            msg = "🏆 Gratulacje, wygrałeś! Strzeliłeś gola!";
                        } else {
                            msg = "🏆 Gratulacje, wygrałeś! Przeciwnik nie ma ruchu!";
                        }
                    } else {
                        if (reason === "goal") {
                            msg = "❌ Niestety, przegrałeś! Straciłeś gola!";
                        } else {
                            msg = "❌ Niestety, przegrałeś! Nie masz ruchu!";
                        }
                    }

                    infoTurn.innerHTML = msg;
                }

                canvas.style.pointerEvents = "none";

                const rematchBtn = document.getElementById("ps-rematch");
                if (rematchBtn) rematchBtn.style.display = "inline-block";

                return;
            }

            // CZYJA KOLEJ?
            if (state.game.current_player == ajaxPlayer) {
                if (infoTurn) infoTurn.innerHTML = "👉 Twoja kolej";
                canvas.style.pointerEvents = "auto";
            } else {
                if (infoTurn) infoTurn.innerHTML = "⏳ Kolej przeciwnika";
                canvas.style.pointerEvents = "none";
            }

            // Nowe ruchy
            if (Array.isArray(state.moves) && state.moves.length !== movesLoaded) {
                reloadMoves(state.moves);
                movesLoaded = state.moves.length;
            }
        })
        .catch(err => {
            console.error("Błąd podczas pobierania state.php:", err);
        });
}

// ----------------------------------------------------
// ODTWARZANIE ruchów z bazy
// ----------------------------------------------------
function reloadMoves(moves) {
    usedLines = [];
    ball = { x: 4, y: 6 };

    for (let mv of moves) {
        addLine(mv.from_x, mv.from_y, mv.to_x, mv.to_y);
        ball.x = mv.to_x;
        ball.y = mv.to_y;
    }
    drawBoard();
}

// start + rewanż
if (canvas && ctx) {
    drawBoard();
    syncGame();
    setInterval(syncGame, 700);
}

const rematchBtn = document.getElementById("ps-rematch");
if (rematchBtn && canvas) {
    rematchBtn.addEventListener("click", function () {
        if (gameMode === "bot") {
            window.location.href =
                "create_game.php?mode=bot&bot_difficulty=" + encodeURIComponent(botDiff);
        } else {
            // w PvP wracamy do ekranu, gdzie można znów stworzyć grę lub dołączyć
            window.location.href = "pvp.php";
        }
    });
}
