(function () {
  const boardEl = document.getElementById('board');
  if (!boardEl) return;
  const code = boardEl.getAttribute('data-code');

  // Zwraca tablicę indeksów zwycięskich pól (np. [0, 4, 8]) albo null
  function getWinningCells(boardStr) {
    const brd = boardStr.split('');
    const patterns = [
      [0, 1, 2],
      [3, 4, 5],
      [6, 7, 8],
      [0, 3, 6],
      [1, 4, 7],
      [2, 5, 8],
      [0, 4, 8],
      [2, 4, 6],
    ];

    for (const cells of patterns) {
      const [i, j, k] = cells;
      if (
        brd[i] !== '_' &&
        brd[i] === brd[j] &&
        brd[j] === brd[k]
      ) {
        return cells;
      }
    }
    return null;
  }

  function render(state) {
    boardEl.innerHTML = '';
    const brd = state.board.split('');
    const winningCells = getWinningCells(state.board);

    brd.forEach(function (v, idx) {
      const cell = document.createElement('div');
      cell.className = 'ttt-cell';
      cell.dataset.pos = idx;
      cell.textContent = (v === '_' ? '' : v);

      const canClick =
        state.status === 'playing' &&
        state.my_symbol &&
        state.turn === state.my_symbol &&
        v === '_';

      if (!canClick) {
        cell.classList.add('disabled');
      }

      // Jeśli to pole należy do zwycięskiej trójki – zaznacz na zielono
      if (winningCells && winningCells.indexOf(idx) !== -1) {
        cell.classList.add('ttt-cell-win');
      }

      cell.addEventListener('click', function () {
        if (canClick) {
          move(idx);
        }
      });

      boardEl.appendChild(cell);
    });

    const statusEl = document.getElementById('status');
    const pX = document.getElementById('pX');
    const pO = document.getElementById('pO');

    if (statusEl) {
      if (state.status === 'finished') {
        if (state.winner_name) {
          statusEl.textContent = 'zakończona – wygrał: ' + state.winner_name;
        } else {
          statusEl.textContent = 'zakończona – remis';
        }
      } else if (state.status === 'playing') {
        statusEl.textContent = 'w toku – tura: ' + state.turn;
      } else {
        statusEl.textContent = state.status;
      }
    }
    if (pX) pX.textContent = state.player_x_name || '—';
    if (pO) pO.textContent = state.player_o_name || '—';
  }

  function poll() {
    fetch('state.php?code=' + encodeURIComponent(code) + '&_=' + Date.now())
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          render(data);
        }
      })
      .catch(function () {})
      .finally(function () {
        setTimeout(poll, 1000);
      });
  }

  function move(pos) {
    const fd = new FormData();
    fd.append('code', code);
    fd.append('pos', pos);
    fetch('move.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function () {
        // nic – odświeży się w poll()
      });
  }

  poll();
})();
