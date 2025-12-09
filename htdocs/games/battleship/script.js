// ==========================================
//  Battleship Front-End — PEŁNA WERSJA
// ==========================================

document.addEventListener("DOMContentLoaded", () => {

    const root = document.getElementById("battleship-root");
    if (!root) return;

    const gameId = parseInt(root.dataset.gameId, 10);
    const myPlayer = root.dataset.myPlayer;

    // ==========================================
    //  GLOBALNE LOGI
    // ==========================================

    let moveLog = [];

    function addLog(entry) {
        moveLog.push(entry);
        renderMoveLog();
    }

    // ==========================================
    //  BUILDER UI
    // ==========================================

    root.innerHTML = `
        <div class="battleship-container">

            <div class="battle-left">

                <div class="board-wrapper">
                    <div class="board-title">Twoja plansza</div>
                    <div id="my-board" class="board"></div>
                </div>

                <div class="board-wrapper">
                    <div class="board-title">Plansza przeciwnika</div>
                    <div id="enemy-board" class="board"></div>
                </div>

                <div id="status-line" class="status-line"></div>
            </div>

            <div class="battle-right">

                <div class="move-log" id="move-log">
                    <h3>Ruchy</h3>
                    <div id="move-entries"></div>
                </div>

                <div class="ship-status" id="ship-status">
                    <h3>Statki</h3>
                    <div id="ship-list"></div>
                </div>

                <div class="prepare-panel" id="prepare-panel" style="display:none;">
                    <h3>Rozstaw swoje statki</h3>
                    <div class="prepare-info">
                        Kliknij na planszę, aby ustawić statki.
                    </div>
                    <div class="prepare-controls">
                        <button id="rotate-btn">Obróć</button>
                        <button id="confirm-btn" disabled>Gotowe</button>
                    </div>
                </div>

            </div>

        </div>
    `;

    // ELEMENTY UI
    const myBoardEl = document.getElementById("my-board");
    const enemyBoardEl = document.getElementById("enemy-board");
    const statusLine = document.getElementById("status-line");
    const moveEntries = document.getElementById("move-entries");
    const shipListEl = document.getElementById("ship-list");

    // ==========================================
    //  PREPARE MODE ZMIENNE
    // ==========================================

    let prepareMode = false;
    let prepareBoard = [];
    let currentShipId = 1;
    let shipsToPlace = [4,3,3,2,2,2,1,1,1,1];
    let placeDirection = "H";

    let lastState = null;
    let isFiring = false;

    // ==========================================
    //  PUSTA PLANSZA
    // ==========================================

    function createEmptyBoard() {
        const b = [];
        for (let y = 0; y < 10; y++) {
            const row = [];
            for (let x = 0; x < 10; x++) row.push(0);
            b.push(row);
        }
        return b;
    }

    // ==========================================
    //  CAN PLACE SHIP
    // ==========================================

    function canPlaceShip(board, x, y, size, dir) {
        for (let i = 0; i < size; i++) {
            const cx = x + (dir === "H" ? i : 0);
            const cy = y + (dir === "V" ? i : 0);

            if (cx < 0 || cx > 9 || cy < 0 || cy > 9) return false;
            if (board[cy][cx] !== 0) return false;

            // brak dotykania
            for (let ny = cy - 1; ny <= cy + 1; ny++) {
                for (let nx = cx - 1; nx <= cx + 1; nx++) {
                    if (nx < 0 || nx > 9 || ny < 0 || ny > 9) continue;
                    if (board[ny][nx] !== 0) return false;
                }
            }
        }
        return true;
    }

    // ==========================================
    //   PLACE SHIP
    // ==========================================

    function placeShip(board, x, y, size, dir, shipId) {
        for (let i = 0; i < size; i++) {
            const cx = x + (dir === "H" ? i : 0);
            const cy = y + (dir === "V" ? i : 0);
            board[cy][cx] = shipId;
        }
    }

    // ==========================================
    //  PREPARE CLICK
    // ==========================================

    function prepareClick(x, y) {
        if (shipsToPlace.length === 0) return;

        const size = shipsToPlace[0];

        if (canPlaceShip(prepareBoard, x, y, size, placeDirection)) {
            placeShip(prepareBoard, x, y, size, placeDirection, currentShipId);

            currentShipId++;
            shipsToPlace.shift();

            renderBoard(myBoardEl, prepareBoard, true);
            renderStatusPrepare();

            if (shipsToPlace.length === 0) {
                document.getElementById("confirm-btn").disabled = false;
                statusLine.textContent = "Wszystkie statki ustawione — kliknij 'Gotowe'.";
            }
        }
    }

    // ==========================================
    //   ACTIVATE PREPARE MODE
    // ==========================================

    function activatePrepareMode() {
        prepareMode = true;
        prepareBoard = createEmptyBoard();
        currentShipId = 1;
        shipsToPlace = [4,3,3,2,2,2,1,1,1,1];
        placeDirection = "H";

        document.getElementById("prepare-panel").style.display = "block";
        document.getElementById("confirm-btn").disabled = true;

        renderBoard(myBoardEl, prepareBoard, true);
        renderStatusPrepare();
    }

    function renderStatusPrepare() {
        if (shipsToPlace.length === 0) {
            statusLine.textContent = "Statki ustawione — kliknij Gotowe.";
            return;
        }
        statusLine.textContent =
            "Ustaw " + shipsToPlace[0] + "-masztowiec (" +
            (placeDirection === "H" ? "poziomo" : "pionowo") + ")";
    }

    // ==========================================
    //  KOLOR POLA
    // ==========================================

    function getCellClass(value) {
        switch (value) {
            case 'M': return "miss";
            case 'H': return "hit";
            case 'S': return "sunk";
        }
        if (typeof value === "number" && value > 0) return "ship";
        return "empty";
    }

    // ==========================================
    //   RENDER BOARD
    // ==========================================

    function renderBoard(container, board, clickable = false) {
        container.innerHTML = "";
        if (!board) return;

        for (let y = 0; y < 10; y++) {
            const rowEl = document.createElement("div");
            rowEl.className = "row";

            for (let x = 0; x < 10; x++) {
                const cellEl = document.createElement("div");
                const val = board[y][x];

                if (prepareMode) {
                    cellEl.className = "cell " + (val === 0 ? "empty" : "ship");
                } else {
                    cellEl.className = "cell " + getCellClass(val);
                }

                cellEl.dataset.x = x;
                cellEl.dataset.y = y;

                if (clickable) {
                    cellEl.classList.add("clickable");
                    cellEl.addEventListener("click", () => {
                        if (prepareMode)
                            prepareClick(x, y);
                        else
                            fireShot(x, y);
                    });
                }

                rowEl.appendChild(cellEl);
            }

            container.appendChild(rowEl);
        }
    }

    // ==========================================
    //  LISTA STATKÓW — MOJE + PRZECIWNIKA
    // ==========================================

    function renderShips(state, me) {
        shipListEl.innerHTML = "";

        const myShips    = (me == 1 ? state.ships_p1 : state.ships_p2);
        const enemyShips = (me == 1 ? state.ships_p2 : state.ships_p1);

        const makeRow = (ship, label) => {
            const sunk = (ship.hits >= ship.size);
            const div = document.createElement("div");
            div.className = "ship-row" + (sunk ? " sunk" : "");
            div.innerHTML = `
                <span>${label} ${ship.size}-maszt.</span>
                <span>${ship.hits}/${ship.size}</span>
            `;
            return div;
        };

        const headerMy = document.createElement("h4");
        headerMy.textContent = "Twoje statki";
        shipListEl.appendChild(headerMy);
        myShips.forEach(s => shipListEl.appendChild(makeRow(s, "Twój")));

        const headerEnemy = document.createElement("h4");
        headerEnemy.textContent = "Statki przeciwnika";
        headerEnemy.style.marginTop = "10px";
        shipListEl.appendChild(headerEnemy);
        enemyShips.forEach(s => shipListEl.appendChild(makeRow(s, "Wróg")));
    }

    // ==========================================
    //   LOG
    // ==========================================

    function renderMoveLog() {
        moveEntries.innerHTML = "";

        moveLog.forEach(entry => {
            const div = document.createElement("div");
            div.className = "move-entry";
            div.textContent = entry;
            moveEntries.appendChild(div);
        });

        moveEntries.scrollTop = moveEntries.scrollHeight;
    }

    // ==========================================
    //   STATUS
    // ==========================================

    function updateStatus(state) {

        if (state.status === "finished") {
            if (state.winner == state.me) {
                statusLine.textContent = "🎉 Wygrałeś!";
            } else {
                statusLine.textContent = "❌ Przegrałeś.";
            }
            return;
        }

        if (state.status.startsWith("prepare")) return;

        if (parseInt(state.current_turn) === parseInt(state.me)) {
            statusLine.textContent = "Twoja kolej — wybierz pole.";
        } else {
            statusLine.textContent = "Czekaj na ruch przeciwnika…";
        }
    }

    // ==========================================
    //  FIRE SHOT
    // ==========================================

    function fireShot(x, y) {
        if (isFiring) return;
        if (!lastState) return;
        if (lastState.status !== "in_progress") return;
        if (parseInt(lastState.current_turn) !== parseInt(lastState.me)) return;

        isFiring = true;

        apiCall({
            action: "fire",
            game_id: gameId,
            x, y
        }).then(res => {
            isFiring = false;

            if (!res.ok) {
                statusLine.textContent = res.error;
                return;
            }

            const result = res.result;
            const p = `(${x},${y})`;

            if (result === "miss") addLog(`Ty → ${p}: PUDŁO`);
            if (result === "hit") addLog(`Ty → ${p}: TRAFIONY`);
            if (result === "sunk") addLog(`Ty → ${p}: ZATOPIONY`);

            updateUI(res.game);

        }).catch(() => {
            isFiring = false;
            statusLine.textContent = "Błąd komunikacji.";
        });
    }

    // ==========================================
    //  API CALL
    // ==========================================

    function apiCall(data) {
        return fetch("api.php", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify(data)
        }).then(res => res.json());
    }

    // ==========================================
    //  UPDATE UI — POPRAWIONA WERSJA
    // ==========================================

    function updateUI(state) {
        lastState = state;

        // ------------------------------
        // *LOGIKA PREPARE OPARTA NA BACKENDZIE*
        // ------------------------------

        const myShipsFromBackend =
            (state.me == 1 && state.ships_p1.length > 0) ||
            (state.me == 2 && state.ships_p2.length > 0);

        const shouldPrepare =
            state.status.startsWith("prepare") &&
            !myShipsFromBackend;

        if (shouldPrepare) {
            if (!prepareMode) activatePrepareMode();
            return;
        }

        // ------------------------------
        // KONIEC PREPARE MODE
        // ------------------------------

        prepareMode = false;
        document.getElementById("prepare-panel").style.display = "none";

        // BOARD RENDER
        renderBoard(myBoardEl, state.my_board.board, false);
        renderBoard(
            enemyBoardEl,
            state.enemy_board.board,
            state.status === "in_progress" &&
            parseInt(state.current_turn) === parseInt(state.me)
        );

        // SHIPS + LOG + STATUS
        renderShips(state, state.me);
        renderMoveLog();
        updateStatus(state);
    }

    // ==========================================
    //  ROTATE + CONFIRM USTAWIENIA
    // ==========================================

    document.getElementById("rotate-btn").addEventListener("click", () => {
        placeDirection = (placeDirection === "H" ? "V" : "H");
        renderStatusPrepare();
    });

    document.getElementById("confirm-btn").addEventListener("click", () => {
        if (shipsToPlace.length > 0) return;

        const ships = {};
        let shipCount = 0;

        for (let id = 1; id < currentShipId; id++) {
            let size = 0;
            for (let y = 0; y < 10; y++) {
                for (let x = 0; x < 10; x++) {
                    if (prepareBoard[y][x] === id) size++;
                }
            }
            if (size > 0) {
                ships[id] = { size, hits: 0 };
                shipCount++;
            }
        }

        apiCall({
            action: "save_setup",
            game_id: gameId,
            board: prepareBoard,
            ships: ships,
            remaining: shipCount
        }).then(res => {
            if (!res.ok) {
                statusLine.textContent = res.error;
                return;
            }
            prepareMode = false;
            updateUI(res.game);
        });
    });

    // ==========================================
    //  START / LOOP
    // ==========================================

    refresh();
    setInterval(() => {
        if (!prepareMode) refresh();
    }, 1500);

});
