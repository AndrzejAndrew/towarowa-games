
-- Struktura bazy dla portalu gier + quizu

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(100),
  question TEXT NOT NULL,
  a VARCHAR(255),
  b VARCHAR(255),
  c VARCHAR(255),
  d VARCHAR(255),
  correct CHAR(1) NOT NULL
);

CREATE TABLE IF NOT EXISTS games (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(8) UNIQUE NOT NULL,
  owner_player_id INT DEFAULT NULL,
  total_rounds INT NOT NULL,
  time_per_question INT NOT NULL,
  current_round INT DEFAULT 1,
  status ENUM('lobby','running','finished') DEFAULT 'lobby',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  game_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  nickname VARCHAR(50) NOT NULL,
  score INT DEFAULT 0,
  is_guest TINYINT(1) DEFAULT 0
);

CREATE TABLE IF NOT EXISTS game_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  game_id INT NOT NULL,
  question_id INT NOT NULL,
  round_number INT NOT NULL
);

CREATE TABLE IF NOT EXISTS answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  game_id INT NOT NULL,
  player_id INT NOT NULL,
  question_id INT NOT NULL,
  answer CHAR(1),
  is_correct TINYINT(1),
  answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS game_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  game_type VARCHAR(50) NOT NULL,
  result VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
