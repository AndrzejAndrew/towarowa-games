-- Patch: Inicjalizacja user_levels (100 poziomów) + odznaki za poziomy 10/25/50/100 (bez XP)
-- Uwaga: wykonaj ręcznie w phpMyAdmin (zakładka SQL / Import)

-- 1) Wyczyść i zainicjalizuj poziomy
TRUNCATE TABLE user_levels;

INSERT INTO user_levels (level, xp_required)
SELECT n AS level,
       CASE WHEN n = 1 THEN 0 ELSE 50 * (n - 1) * n END AS xp_required
FROM (
    SELECT (t.i * 10 + o.i + 1) AS n
    FROM (SELECT 0 i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
          UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) o
    CROSS JOIN (SELECT 0 i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t
) nums
WHERE n <= 100
ORDER BY n;

-- 2) Odznaki poziomowe (bez dodatkowego XP)
INSERT IGNORE INTO achievements (code, name, description, icon, xp_reward) VALUES
('level_10',  'Poziom 10',  'Osiągnij poziom 10.',  NULL, 0),
('level_25',  'Poziom 25',  'Osiągnij poziom 25.',  NULL, 0),
('level_50',  'Poziom 50',  'Osiągnij poziom 50.',  NULL, 0),
('level_100', 'Poziom 100', 'Osiągnij poziom 100.', NULL, 0);

-- Kontrola
-- SELECT level, xp_required FROM user_levels WHERE level IN (1,2,10,25,50,100) ORDER BY level;
