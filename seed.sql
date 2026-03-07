-- ============================================================
-- Expense Manager — Demo Seed Data
-- Run AFTER schema.sql to populate with sample data for testing.
--   mysql -u root -p ExpMgWEB < seed.sql
-- ============================================================

USE ExpMgWEB;

-- ============================================================
-- Demo Users (passwords are bcrypt hashes of 'password123')
-- ============================================================
INSERT INTO users (username, email, password) VALUES
  ('alice',  'alice@example.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
  ('bob',    'bob@example.com',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
  ('carol',  'carol@example.com',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE username = username;

-- ============================================================
-- Demo Group
-- ============================================================
INSERT INTO `groups` (id, name, join_code, created_by) VALUES
  (1, 'Roommates', 'ROOM2026', (SELECT id FROM users WHERE username = 'alice'))
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO group_members (group_id, user_id, role) VALUES
  (1, (SELECT id FROM users WHERE username = 'alice'), 'admin'),
  (1, (SELECT id FROM users WHERE username = 'bob'),   'member'),
  (1, (SELECT id FROM users WHERE username = 'carol'), 'member')
ON DUPLICATE KEY UPDATE role = role;

-- ============================================================
-- Demo Personal Expenses — Alice (current month: March 2026)
-- ============================================================
SET @alice  = (SELECT id FROM users WHERE username = 'alice');
SET @bob    = (SELECT id FROM users WHERE username = 'bob');
SET @carol  = (SELECT id FROM users WHERE username = 'carol');

SET @food      = (SELECT id FROM categories WHERE name = 'Food/Groceries');
SET @transport = (SELECT id FROM categories WHERE name = 'Transport');
SET @utils     = (SELECT id FROM categories WHERE name = 'Utilities');
SET @bills     = (SELECT id FROM categories WHERE name = 'Bills');
SET @shopping  = (SELECT id FROM categories WHERE name = 'Shopping');
SET @education = (SELECT id FROM categories WHERE name = 'Education');
SET @health    = (SELECT id FROM categories WHERE name = 'Health');
SET @others    = (SELECT id FROM categories WHERE name = 'Others');

-- Alice's personal expenses
INSERT INTO expenses (user_id, amount, category_id, note, expense_date, type) VALUES
  (@alice, 450.00,  @food,      'Weekly groceries',           '2026-03-01', 'personal'),
  (@alice, 120.00,  @transport, 'Uber to office',             '2026-03-02', 'personal'),
  (@alice, 1500.00, @bills,     'Internet bill',              '2026-03-03', 'personal'),
  (@alice, 80.00,   @food,      'Lunch with friend',          '2026-03-04', 'personal'),
  (@alice, 200.00,  @health,    'Pharmacy',                   '2026-03-05', 'personal'),
  (@alice, 350.00,  @shopping,  'New headphones',             '2026-03-05', 'personal'),
  (@alice, 60.00,   @transport, 'Metro pass top-up',          '2026-03-06', 'personal'),
  (@alice, 250.00,  @education, 'Online course subscription', '2026-03-07', 'personal');

-- Bob's personal expenses
INSERT INTO expenses (user_id, amount, category_id, note, expense_date, type) VALUES
  (@bob, 300.00,  @food,      'Groceries',              '2026-03-01', 'personal'),
  (@bob, 90.00,   @transport, 'Bus pass',               '2026-03-02', 'personal'),
  (@bob, 500.00,  @shopping,  'New shoes',              '2026-03-03', 'personal'),
  (@bob, 150.00,  @others,    'Gift for friend',        '2026-03-04', 'personal'),
  (@bob, 75.00,   @food,      'Coffee and snacks',      '2026-03-06', 'personal');

-- Carol's personal expenses
INSERT INTO expenses (user_id, amount, category_id, note, expense_date, type) VALUES
  (@carol, 600.00,  @food,      'Weekly meal prep',    '2026-03-01', 'personal'),
  (@carol, 2000.00, @bills,     'Electricity bill',    '2026-03-02', 'personal'),
  (@carol, 180.00,  @health,    'Gym membership',      '2026-03-03', 'personal'),
  (@carol, 400.00,  @education, 'Books',               '2026-03-05', 'personal');

-- ============================================================
-- Demo Group Expenses — Roommates (shared costs)
-- ============================================================
INSERT INTO expenses (user_id, group_id, amount, category_id, note, expense_date, type) VALUES
  (@alice, 1, 3000.00, @bills,  'Rent - March share',      '2026-03-01', 'group'),
  (@bob,   1, 800.00,  @food,   'House groceries',         '2026-03-02', 'group'),
  (@carol, 1, 500.00,  @utils,  'Water bill',              '2026-03-03', 'group'),
  (@alice, 1, 250.00,  @others, 'Cleaning supplies',       '2026-03-05', 'group'),
  (@bob,   1, 150.00,  @food,   'Snacks for movie night',  '2026-03-06', 'group');

-- ============================================================
-- Demo Budget — Alice, March 2026
-- ============================================================
INSERT INTO budgets (user_id, budget_month, amount_limit) VALUES
  (@alice, '2026-03', 8000.00),
  (@bob,   '2026-03', 5000.00)
ON DUPLICATE KEY UPDATE amount_limit = VALUES(amount_limit);

-- ============================================================
-- Demo List — Shared grocery list
-- ============================================================
INSERT INTO lists (id, name, user_id, group_id) VALUES
  (1, 'Grocery Run', @alice, 1)
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO list_items (list_id, description, priority, is_checked, added_by) VALUES
  (1, 'Milk (2 litres)',    'high',     0, @alice),
  (1, 'Bread — whole wheat','high',     0, @bob),
  (1, 'Eggs (12 pack)',     'high',     1, @carol),
  (1, 'Olive oil',          'moderate', 0, @alice),
  (1, 'Dish soap',          'moderate', 0, @bob),
  (1, 'Trash bags',         'low',      0, @alice),
  (1, 'Chips',              'low',      1, @carol);

-- Alice's personal list
INSERT INTO lists (id, name, user_id, group_id) VALUES
  (2, 'Study Materials', @alice, NULL)
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO list_items (list_id, description, priority, is_checked, added_by) VALUES
  (2, 'Highlighter set',   'moderate', 0, @alice),
  (2, 'A4 notebooks (x3)', 'high',     0, @alice),
  (2, 'USB-C cable',       'low',      0, @alice);

-- ============================================================
-- Demo Notifications
-- ============================================================
INSERT INTO notifications (user_id, message, type, reference_id) VALUES
  (@alice, 'bob joined Roommates',                        'group_join',           1),
  (@alice, 'carol joined Roommates',                      'group_join',           1),
  (@bob,   'alice added a group expense: Rent - March share (₹3,000.00)', 'group_expense_add', 1),
  (@carol, 'alice added a group expense: Rent - March share (₹3,000.00)', 'group_expense_add', 1),
  (@alice, 'bob added a group expense: House groceries (₹800.00)',        'group_expense_add', 1),
  (@carol, 'bob added a group expense: House groceries (₹800.00)',        'group_expense_add', 1);
