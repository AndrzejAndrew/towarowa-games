(() => {
  const canvas = document.getElementById("ps-board");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");

  const gameId = Number(canvas.dataset.gameId || 0);
  const myPlayer = Number(canvas.dataset.player || 0);
  const mode = String(canvas.dataset.mode || "pvp");
  const botDiff = Number(canvas.dataset.botDiff || 1);

  const cols = 9;
  const rows = 13;

  // UI
  const infoTurn = document.getElementById("ps-info-turn");
  const p1NameEl = document.getElementById("ps-player1-name");
  const p2NameEl = document.getElementById("ps-player2-name");
  const scoreEl  = document.getElementById("ps-score");
  const statusEl = document.getElementById("ps-status");

  // State
  let usedLines = [];
  let ball = { x: 4, y: 6 };
  let currentPlayer = 1;
  let status = "loading";
  let winner = 0;

  let selected = null;
  let syncing = false;

  function normalizeLines(lines) {
    if (!Array.isArray(lines)) return [];
    return lines.map(l => ({
      x1: Number(l.x1 ?? l.from_x ?? l.fromX ?? l.fx ?? l[0] ?? 0),
      y1: Number(l.y1 ?? l.from_y ?? l.fromY ?? l.fy ?? l[1] ?? 0),
      x2: Number(l.x2 ?? l.to_x   ?? l.toX   ?? l.tx ?? l[2] ?? 0),
      y2: Number(l.y2 ?? l.to_y   ?? l.toY   ?? l.ty ?? l[3] ?? 0),
    }));
  }

  function lineKey(x1, y1, x2, y2) {
    // canonical (bez kierunku)
    if (x1 < x2 || (x1 === x2 && y1 <= y2)) return `${x1},${y1}-${x2},${y2}`;
    return `${x2},${y2}-${x1},${y1}`;
  }

  function isLineUsed(x1, y1, x2, y2) {
    const k = lineKey(x1, y1, x2, y2);
    for (const l of usedLines) {
      if (lineKey(l.x1, l.y1, l.x2, l.y2) === k) return true;
    }
    return false;
  }

  // Geometria
  function layout() {
    const pad = 42;
    const w = canvas.width;
    const h = canvas.height;
    const gx = (w - 2 * pad) / (cols - 1);
    const gy = (h - 2 * pad) / (rows - 1);
    return { pad, w, h, gx, gy };
  }

  function gridToPx(x, y) {
    const { pad, gx, gy } = layout();
    return { px: pad + x * gx, py: pad + y * gy };
  }

  function findNearestPoint(px, py) {
    const { pad, w, h, gx, gy } = layout();
    if (px < pad - 20 || px > w - pad + 20 || py < pad - 20 || py > h - pad + 20) return null;

    // przybliżone zaokrąglenie do siatki
    const x = Math.round((px - pad) / gx);
    const y = Math.round((py - pad) / gy);

    if (x < 0 || x >= cols || y < 0 || y >= rows) return null;

    const p = gridToPx(x, y);
    const dist = Math.hypot(p.px - px, p.py - py);
    if (dist > 18) return null;
    return { x, y };
  }

  function hasBounce(x, y) {
    // Odbicie od ścian
    if (x === 0 || x === cols - 1 || y === 0 || y === rows - 1) return true;

    // Skrzyżowania (>=2 linii wychodzące z punktu)
    let deg = 0;
    for (const l of usedLines) {
      if ((l.x1 === x && l.y1 === y) || (l.x2 === x && l.y2 === y)) {
        deg++;
        if (deg >= 2) return true;
      }
    }
    return false;
  }

  function isGoal(x, y) {
    // bramki: górna y=0 i dolna y=12, środek x=3..5
    return (x >= 3 && x <= 5) && (y === 0 || y === rows - 1);
  }

  function isValidMove(from, to) {
    const dx = Math.abs(to.x - from.x);
    const dy = Math.abs(to.y - from.y);
    if (dx > 1 || dy > 1 || (dx === 0 && dy === 0)) return false;

    if (to.x < 0 || to.x >= cols || to.y < 0 || to.y >= rows) return false;

    if (isLineUsed(from.x, from.y, to.x, to.y)) return false;

    // Zgodnie z Twoim backendem (bot.php): zakaz "ślizgania" po skrajnych liniach
    const leftSlide   = (from.x === 0 && to.x === 0 && from.y !== to.y);
    const rightSlide  = (from.x === cols - 1 && to.x === cols - 1 && from.y !== to.y);
    const topSlide    = (from.y === 0 && to.y === 0 && from.x !== to.x);
    const bottomSlide = (from.y === rows - 1 && to.y === rows - 1 && from.x !== to.x);
    if (leftSlide || rightSlide || topSlide || bottomSlide) return false;

    return true;
  }

  // Rysowanie
  function drawPitch() {
    const { pad, w, h, gx, gy } = layout();

    ctx.clearRect(0, 0, w, h);

    // tło (ciemne)
    ctx.fillStyle = "#111";
    ctx.fillRect(0, 0, w, h);

    // murawa
    const fx = pad;
    const fy = pad;
    const fw = w - 2 * pad;
    const fh = h - 2 * pad;

    ctx.fillStyle = "#1b7f3a";
    ctx.fillRect(fx, fy, fw, fh);

    // PASY W POPRZEK BOISKA (poziome pasy, zmieniają się po Y)
    const stripeH = gy * 2; // co ~2 kratki
    for (let y = fy; y < fy + fh; y += stripeH) {
      ctx.fillStyle = "rgba(255,255,255,0.05)";
      ctx.fillRect(fx, y, fw, stripeH * 0.5);
    }

    // linie boiska
    ctx.strokeStyle = "rgba(255,255,255,0.95)";
    ctx.lineWidth = 3;
    ctx.strokeRect(fx, fy, fw, fh);

    // linia środkowa
    const midY = fy + (rows - 1) * gy / 2;
    ctx.beginPath();
    ctx.moveTo(fx, midY);
    ctx.lineTo(fx + fw, midY);
    ctx.stroke();

    // koło środkowe
    const midX = fx + (cols - 1) * gx / 2;
    ctx.beginPath();
    ctx.arc(midX, midY, Math.min(gx, gy) * 1.5, 0, Math.PI * 2);
    ctx.stroke();

    // bramki (proste)
    const goalW = gx * 2;
    // górna
    ctx.strokeRect(midX - goalW / 2, fy - gy * 0.6, goalW, gy * 0.6);
    // dolna
    ctx.strokeRect(midX - goalW / 2, fy + fh, goalW, gy * 0.6);
  }

  function drawLinesAndNodes() {
    // linie ruchów
    ctx.strokeStyle = "rgba(255,255,255,0.95)";
    ctx.lineWidth = 2;

    for (const l of usedLines) {
      const a = gridToPx(l.x1, l.y1);
      const b = gridToPx(l.x2, l.y2);
      ctx.beginPath();
      ctx.moveTo(a.px, a.py);
      ctx.lineTo(b.px, b.py);
      ctx.stroke();
    }

    // węzły
    ctx.fillStyle = "rgba(255,255,255,0.65)";
    for (let y = 0; y < rows; y++) {
      for (let x = 0; x < cols; x++) {
        const p = gridToPx(x, y);
        ctx.beginPath();
        ctx.arc(p.px, p.py, 3, 0, Math.PI * 2);
        ctx.fill();
      }
    }

    // zaznaczenie
    if (selected) {
      const p = gridToPx(selected.x, selected.y);
      ctx.strokeStyle = "rgba(255,255,0,0.9)";
      ctx.lineWidth = 3;
      ctx.beginPath();
      ctx.arc(p.px, p.py, 10, 0, Math.PI * 2);
      ctx.stroke();
    }

    // piłka
    const bp = gridToPx(ball.x, ball.y);
    ctx.fillStyle = "#fff";
    ctx.beginPath();
    ctx.arc(bp.px, bp.py, 7, 0, Math.PI * 2);
    ctx.fill();

    ctx.strokeStyle = "#000";
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.arc(bp.px, bp.py, 7, 0, Math.PI * 2);
    ctx.stroke();
  }

  function render() {
    drawPitch();
    drawLinesAndNodes();

    if (statusEl) {
      if (status === "waiting") statusEl.textContent = "Oczekiwanie na 2 gracza…";
      else if (status === "playing") statusEl.textContent = "Gra trwa";
      else if (status === "finished") statusEl.textContent = "Koniec gry";
      else statusEl.textContent = "Trwa ładowanie gry…";
    }

    if (infoTurn) {
      if (status === "playing") {
        infoTurn.textContent = (currentPlayer === myPlayer) ? "Twoja tura" : "Tura przeciwnika";
      } else {
        infoTurn.textContent = "";
      }
    }
  }

  async function syncState() {
    if (syncing) return;
    syncing = true;

    try {
      const r = await fetch("state.php?game_id=" + encodeURIComponent(gameId), { cache: "no-store" });
      const raw = await r.text();

      if (!r.ok) {
        console.error("state.php HTTP", r.status, raw);
        status = "loading";
        if (statusEl) statusEl.textContent = "Błąd state.php: HTTP " + r.status;
        return;
      }

      const data = JSON.parse(raw);
      if (!data || data.ok !== true || !data.game) {
        console.error("state.php bad JSON payload:", data);
        return;
      }

      const g = data.game;

      // nazwy graczy
      if (p1NameEl) p1NameEl.textContent = g.player1_name ?? "Gracz 1";
      if (p2NameEl) p2NameEl.textContent = g.player2_name ?? (mode === "bot" ? "BOT" : "Gracz 2");

      status = String(g.status ?? "playing");
      currentPlayer = Number(g.current_player ?? 1);
      winner = Number(g.winner ?? 0);

      // Linie i piłka
      if (Array.isArray(g.used_lines)) {
        usedLines = normalizeLines(g.used_lines);
      } else if (Array.isArray(g.moves)) {
        // kompatybilność: gdyby kiedyś backend zwracał moves
        usedLines = normalizeLines(g.moves);
      }

      ball.x = Number(g.ball_x ?? 4);
      ball.y = Number(g.ball_y ?? 6);

      render();

    } catch (e) {
      console.error("syncState error", e);
    } finally {
      syncing = false;
    }
  }

  async function sendMove(from, to) {
    // lokalnie dopisz linię i przesuń piłkę (optymistycznie)
    usedLines.push({ x1: from.x, y1: from.y, x2: to.x, y2: to.y });
    ball.x = to.x; ball.y = to.y;

    const goal = isGoal(to.x, to.y) ? 1 : 0;
    const extra = goal ? 0 : (hasBounce(to.x, to.y) ? 1 : 0);

    render();

    const body = new URLSearchParams();
    body.set("game_id", String(gameId));
    body.set("from_x", String(from.x));
    body.set("from_y", String(from.y));
    body.set("to_x", String(to.x));
    body.set("to_y", String(to.y));
    body.set("extra", String(extra));
    body.set("goal", String(goal));
    body.set("draw", "0");

    try {
      const r = await fetch("move.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body
      });

      const raw = await r.text();

      if (!r.ok) {
        console.error("move.php HTTP", r.status, raw);
        alert("Serwer zwrócił błąd HTTP " + r.status + ". Odpowiedź:\n" + raw);
        await syncState(); // odśwież po błędzie
        return;
      }

      const data = JSON.parse(raw);
      if (!data || data.ok !== true) {
        alert(data?.error ? String(data.error) : "Nieznany błąd ruchu.");
        await syncState();
        return;
      }

      // po udanym ruchu dociągnij pełny stan (w tym bot / winner / itp.)
      await syncState();

    } catch (e) {
      console.error("sendMove error", e);
      alert("Wystąpił błąd po stronie serwera przy wysyłaniu ruchu.");
      await syncState();
    }
  }

  canvas.addEventListener("click", async (ev) => {
    if (status !== "playing") return;
    if (currentPlayer !== myPlayer) return;

    const rect = canvas.getBoundingClientRect();
    const px = ev.clientX - rect.left;
    const py = ev.clientY - rect.top;

    const p = findNearestPoint(px, py);
    if (!p) return;

    if (!selected) {
      // start tylko z piłki
      if (p.x === ball.x && p.y === ball.y) {
        selected = p;
        render();
      }
      return;
    }

    // drugi klik = cel
    const from = selected;
    const to = p;

    // reset selection
    selected = null;

    if (!isValidMove(from, to)) {
      render();
      return;
    }

    await sendMove(from, to);
  });

  // Start
  render();
  syncState();
  setInterval(syncState, 900);
})();
