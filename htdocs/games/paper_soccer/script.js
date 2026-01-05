'''
// Pobieranie canvas i kontekstu 2D
const canvas = document.getElementById('ps-board');
const ctx = canvas.getContext('2d');

// Wymiary boiska i siatki
const boardWidth = 8;  // Szerokość siatki (liczba punktów)
const boardHeight = 10; // Wysokość siatki (liczba punktów)
const padding = 40;    // Odsunięcie od krawędzi canvas

// Obliczanie wymiarów komórek i canvas
const cellWidth = (canvas.width - 2 * padding) / boardWidth;
const cellHeight = (canvas.height - 2 * padding) / boardHeight;

// Kolory
const grassColor = '#53a653';
const lineColor = '#ffffff';
const dotColor = '#000000';

// Funkcja do rysowania boiska
function drawBoard() {
    // Tło - murawa
    ctx.fillStyle = grassColor;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // Linie boiska
    ctx.strokeStyle = lineColor;
    ctx.lineWidth = 2;

    // Zewnętrzne linie
    ctx.strokeRect(padding, padding, canvas.width - 2 * padding, canvas.height - 2 * padding);

    // Linia środkowa
    ctx.beginPath();
    ctx.moveTo(padding, canvas.height / 2);
    ctx.lineTo(canvas.width - padding, canvas.height / 2);
    ctx.stroke();

    // Koło na środku
    ctx.beginPath();
    ctx.arc(canvas.width / 2, canvas.height / 2, 60, 0, 2 * Math.PI);
    ctx.stroke();

    // Pola karne
    const penaltyBoxWidth = 4 * cellWidth;
    const penaltyBoxHeight = 1.5 * cellHeight;
    
    // Górne pole karne
    ctx.strokeRect(
        (canvas.width - penaltyBoxWidth) / 2,
        padding,
        penaltyBoxWidth,
        penaltyBoxHeight
    );

    // Dolne pole karne
    ctx.strokeRect(
        (canvas.width - penaltyBoxWidth) / 2,
        canvas.height - padding - penaltyBoxHeight,
        penaltyBoxWidth,
        penaltyBoxHeight
    );
}

// Funkcja do rysowania siatki punktów
function drawGrid() {
    ctx.fillStyle = dotColor;
    for (let y = 0; y <= boardHeight; y++) {
        for (let x = 0; x <= boardWidth; x++) {
            ctx.beginPath();
            ctx.arc(padding + x * cellWidth, padding + y * cellHeight, 3, 0, 2 * Math.PI);
            ctx.fill();
        }
    }
}

// Główna funkcja rysująca
function render() {
    drawBoard();
    drawGrid();
}

// Wywołanie funkcji rysującej
render();
'''