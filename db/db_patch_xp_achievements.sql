-- ============================================================
-- XP / Achievements / Daily Login (Podejście B)
-- ============================================================
-- UWAGA: wykonaj backup bazy przed zmianami.

-- 1) Kolumny do logowania/streaków (Podejście B)
ALTER TABLE users
  ADD COLUMN last_login_date DATE NULL,
  ADD COLUMN login_streak INT NOT NULL DEFAULT 0,
  ADD COLUMN max_login_streak INT NOT NULL DEFAULT 0;

-- 2) (Zalecane) Ujednolicenie kodowania tabel achievements, aby uniknąć problemów z kolacją.
ALTER TABLE achievements CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE user_achievements CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- 3) Seed odznak (nie duplikuje, jeśli już istnieją)
INSERT IGNORE INTO achievements (code, name, description, icon, xp_reward) VALUES
('first_game',        'Pierwsza gra',        'Rozegraj pierwszą grę na portalu.',                                  NULL, 20),
('first_win',         'Pierwsza wygrana',    'Wygraj swoją pierwszą grę.',                                           NULL, 50),

('veteran_25',        'Weteran (25)',        'Rozegraj 25 gier na portalu.',                                         NULL, 150),
('veteran_100',       'Weteran (100)',       'Rozegraj 100 gier na portalu.',                                        NULL, 800),

('all_rounder_3',     'Wielobój (3 gry)',    'Zagraj w 3 różne gry.',                                                NULL, 100),
('all_rounder_6',     'Wielobój (6 gier)',   'Zagraj w 6 różnych gier.',                                             NULL, 300),

('win_streak_3',      'Seria wygranych (3)', 'Osiągnij 3 wygrane z rzędu. (bonus XP jest przyznawany za próg)',       NULL, 0),
('win_streak_5',      'Seria wygranych (5)', 'Osiągnij 5 wygranych z rzędu. (bonus XP jest przyznawany za próg)',       NULL, 0),
('win_streak_10',     'Seria wygranych (10)','Osiągnij 10 wygranych z rzędu. (bonus XP jest przyznawany za próg)',      NULL, 0),

('login_streak_7',    'Stały bywalec (7)',   'Zaloguj się 7 dni z rzędu.',                                           NULL, 150),
('login_streak_30',   'Legenda (30)',        'Zaloguj się 30 dni z rzędu.',                                          NULL, 800);
