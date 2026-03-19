-- ============================================
-- ENABLE FOREIGN KEYS (VERY IMPORTANT)
-- ============================================
PRAGMA foreign_keys = ON;

-- ============================================
-- TABLE: years
-- ============================================
CREATE TABLE IF NOT EXISTS years (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL UNIQUE,
    description TEXT,
    is_active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE: funds
-- ============================================
CREATE TABLE IF NOT EXISTS funds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year_id INTEGER NOT NULL,
    fund_code TEXT NOT NULL,
    fund_name TEXT NOT NULL,
    fund_source TEXT DEFAULT 'GOP',
    allotment REAL DEFAULT 0,
    obligated REAL DEFAULT 0,
    disbursed REAL DEFAULT 0,
    balance REAL DEFAULT 0,
    status TEXT DEFAULT 'active',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (year_id) REFERENCES years(id) ON DELETE CASCADE,
    UNIQUE(year_id, fund_name)
);

-- ============================================
-- TABLE: components
-- ============================================
CREATE TABLE IF NOT EXISTS components (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    component_code TEXT NOT NULL UNIQUE,
    component_name TEXT NOT NULL,
    description TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE: account_titles
-- ============================================
CREATE TABLE IF NOT EXISTS account_titles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uacs_code TEXT NOT NULL UNIQUE,
    account_title TEXT NOT NULL,
    category TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- TABLE: transactions
-- ============================================
CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fund_id INTEGER NOT NULL,

    mo_no TEXT,
    month TEXT,
    date_transaction TEXT,

    asa_no TEXT,
    pap_code TEXT,
    rc_code TEXT,
    ors_no TEXT,
    fund_source TEXT,
    payee TEXT,

    component_id INTEGER,
    component_no TEXT,
    unit TEXT,
    category TEXT,

    account_title_id INTEGER,
    uacs_code TEXT,
    particulars TEXT,

    obligation REAL DEFAULT 0,
    adjustment REAL DEFAULT 0,
    adjusted_obligation REAL DEFAULT 0,

    gop_amount REAL DEFAULT 0,
    lp_amount REAL DEFAULT 0,

    iplan_11 REAL DEFAULT 0,
    iplan_12 REAL DEFAULT 0,
    ibuild_21 REAL DEFAULT 0,
    ibuild_22 REAL DEFAULT 0,
    ireap_31 REAL DEFAULT 0,
    ireap_32 REAL DEFAULT 0,
    isupport REAL DEFAULT 0,
    sre REAL DEFAULT 0,

    status TEXT DEFAULT 'pending',
    unpaid_obligation REAL DEFAULT 0,

    date_input_engas TEXT,
    box_d_of_dv TEXT,
    dv_received_for_lddap TEXT,
    lddap_no TEXT,

    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (fund_id) REFERENCES funds(id),
    FOREIGN KEY (component_id) REFERENCES components(id),
    FOREIGN KEY (account_title_id) REFERENCES account_titles(id)
);

-- ============================================
-- TABLE: user_session
-- ============================================
CREATE TABLE IF NOT EXISTS user_session (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    selected_year_id INTEGER,
    selected_fund_id INTEGER,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (selected_year_id) REFERENCES years(id),
    FOREIGN KEY (selected_fund_id) REFERENCES funds(id)
);

-- ============================================
-- TABLE: transaction_logs
-- ============================================
CREATE TABLE IF NOT EXISTS transaction_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_id INTEGER NOT NULL,
    changes TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);

-- ============================================
-- TABLE: fund_logs
-- ============================================
CREATE TABLE IF NOT EXISTS fund_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fund_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    changes TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE CASCADE
);

-- ============================================
-- INDEXES (VERY IMPORTANT FOR PERFORMANCE)
-- ============================================
CREATE INDEX IF NOT EXISTS idx_transactions_fund_id ON transactions(fund_id);
CREATE INDEX IF NOT EXISTS idx_transactions_component_id ON transactions(component_id);
CREATE INDEX IF NOT EXISTS idx_transactions_account_title_id ON transactions(account_title_id);
CREATE INDEX IF NOT EXISTS idx_funds_year_id ON funds(year_id);

-- ============================================
-- INSERT INITIAL DATA
-- ============================================

-- Insert components
INSERT OR IGNORE INTO components (component_code, component_name, description) VALUES 
('1.1', 'I-PLAN (1.1)', 'I-PLAN Component'),
('1.2', 'I-PLAN (1.2)', 'I-PLAN Component'),
('2.1', 'I-BUILD (2.1)', 'I-BUILD Component'),
('2.2', 'I-BUILD (2.2)', 'I-BUILD Component'),
('3.1', 'I-REAP (3.1)', 'I-REAP Component'),
('3.2', 'I-REAP (3.2)', 'I-REAP Component'),
('4.0', 'I-SUPPORT', 'I-SUPPORT Component'),
('SRE', 'SRE', 'Special RE Component');

-- Insert account titles
INSERT OR IGNORE INTO account_titles (uacs_code, account_title, category) VALUES 
('50202010-02', 'Training Expense', 'Training'),
('50201010-00', 'Traveling Expenses - Local', 'Travel'),
('50203010-00', 'Office Supplies Expense', 'Supplies'),
('50203090-00', 'Fuel, Oil and Lubricant', 'Fuel'),
('50205020-01', 'Telephone Expense - Mobile', 'Communication'),
('50213210-03', 'R&M - Semi-Expendable ICT Equipment', 'Maintenance'),
('50299020-00', 'Printing and Publication Expenses', 'Printing'),
('50203210-03', 'Semi-expendable ICT Equipment', 'Equipment'),
('50299990-99', 'Other Maintenance and Operating Expenses', 'Other'),
('50211990-00', 'Other Professional Services', 'Services');

-- Insert user session
INSERT OR IGNORE INTO user_session (id, selected_year_id, selected_fund_id) 
VALUES (1, NULL, NULL);

-- ============================================
-- INSERT YEAR AND FUND DATA (REQUIRED FOR TRANSACTIONS)
-- ============================================

-- Insert year 2026
INSERT INTO years (year, description, is_active) 
VALUES (2026, 'SCALE-UP 2026 - LP FUNDS', 1);

-- Insert fund for 2026 with the summary data from your spreadsheet
INSERT INTO funds (year_id, fund_code, fund_name, fund_source, allotment, obligated, disbursed, balance, status)
VALUES 
((SELECT id FROM years WHERE year = 2026), 'LP-2026-01', 'SCALE-UP 2026 LP FUNDS', 'LP', 33536184.00, 13417728.00, 13417728.00, 20118456.00, 'active');

-- ============================================
-- INSERT TRANSACTIONS (ORS No. 000001 to 000030)
-- ============================================

INSERT INTO transactions (
    fund_id, mo_no, month, date_transaction, asa_no, pap_code, rc_code, ors_no, fund_source, payee,
    component_id, component_no, unit, category, account_title_id, uacs_code, particulars,
    obligation, adjustment, adjusted_obligation, gop_amount, lp_amount,
    iplan_11, iplan_12, ibuild_21, ibuild_22, ireap_31, ireap_32, isupport, sre,
    status, unpaid_obligation, date_input_engas, box_d_of_dv, dv_received_for_lddap, lddap_no
) VALUES 
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000001', 'LP', 'ARNEL V. GAGUJAS',
    (SELECT id FROM components WHERE component_code = '1.1'), '1.1', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 536800.00,
    536800.00, 0, 0, 0, 0, 0, 0, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000002', 'LP', 'ISABEL B. TEJO',
    (SELECT id FROM components WHERE component_code = '1.1'), '1.1', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 536800.00,
    536800.00, 0, 0, 0, 0, 0, 0, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000003', 'LP', 'EDWIN B. CAMHIT',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 536800.00, 0, 0, 0, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000004', 'LP', 'MYRIC P. TICUALA',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 536800.00, 0, 0, 0, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000005', 'LP', 'GENESIS C. DELOEG',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 536800.00, 0, 0, 0, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000006', 'LP', 'JEFFREY T. BAS-ONG',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 536800.00, 0, 0, 0, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000007', 'LP', 'TIM S. CHATTOM',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    332480.00, 0, 332480.00, 0, 0,
    0, 0, 0, 332480.00, 0, 0, 0, 0,
    'UNPAID', 332480.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000008', 'LP', 'JOHNSON D. CEA',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    593824.00, 0, 593824.00, 0, 0,
    0, 0, 0, 0, 0, 593824.00, 0, 0,
    'UNPAID', 593824.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000009', 'LP', 'LORNA E. PANYO',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 0, 0, 536800.00, 0, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000010', 'LP', 'RODERICK A. ADCHOG',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 0, 0, 536800.00, 0, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000011', 'LP', 'STEPHER L. BANHAN',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 0, 0, 536800.00, 0, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000012', 'LP', 'DALOS S. EMOK',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    365728.00, 0, 365728.00, 0, 0,
    0, 0, 0, 0, 0, 365728.00, 0, 0,
    'UNPAID', 365728.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000013', 'LP', 'RIGI MAY COPATAN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 0, 0, 0, 536800.00, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000014', 'LP', 'RUDAN S. GARIN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    479776.00, 0, 479776.00, 0, 0,
    0, 0, 0, 0, 0, 0, 479776.00, 0,
    'UNPAID', 479776.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000015', 'LP', 'JOHNNY P. COMICHO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    479776.00, 0, 479776.00, 0, 0,
    0, 0, 0, 0, 0, 0, 479776.00, 0,
    'UNPAID', 479776.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000016', 'LP', 'LARENSTEIN D. BAKILAN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    479776.00, 0, 479776.00, 0, 0,
    0, 0, 0, 0, 0, 0, 479776.00, 0,
    'UNPAID', 479776.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000017', 'LP', 'ROISTON Z. CARAME',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 0, 0, 0, 536800.00, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000018', 'LP', 'AARON S. FAGYAN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 0, 0, 0, 536800.00, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000019', 'LP', 'CHRISTIAN ARIES M. SALGADO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    479776.00, 0, 479776.00, 0, 0,
    0, 0, 0, 0, 0, 0, 479776.00, 0,
    'UNPAID', 479776.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000020', 'LP', 'ELVY T. ESTACIO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    479776.00, 0, 479776.00, 0, 0,
    0, 0, 0, 0, 0, 0, 479776.00, 0,
    'UNPAID', 479776.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000021', 'LP', 'FRANCIS D. DUMAGAS',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 0, 0, 0, 536800.00, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000022', 'LP', 'DETLEEF B. CAMPOS',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    536800.00, 0, 536800.00, 0, 0,
    0, 0, 0, 0, 0, 0, 536800.00, 0,
    'UNPAID', 536800.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000023', 'LP', 'BLIZYLE MAE T. ATIMPAO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    280640.00, 0, 280640.00, 0, 0,
    0, 0, 0, 0, 0, 0, 280640.00, 0,
    'UNPAID', 280640.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000024', 'LP', 'RODEL MARK U. GARCIA',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    280640.00, 0, 280640.00, 0, 0,
    0, 0, 0, 0, 0, 0, 280640.00, 0,
    'UNPAID', 280640.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000025', 'LP', 'VANESSA A. BINNOY-PANGANIBAN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    280640.00, 0, 280640.00, 0, 0,
    0, 0, 0, 0, 0, 0, 280640.00, 0,
    'UNPAID', 280640.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000026', 'LP', 'NORBERTO O. CORRALES JR.',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    280640.00, 0, 280640.00, 0, 0,
    0, 0, 0, 0, 0, 0, 280640.00, 0,
    'UNPAID', 280640.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000027', 'LP', 'LEE LUBANGAS',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    280640.00, 0, 280640.00, 0, 0,
    0, 0, 0, 0, 0, 0, 280640.00, 0,
    'UNPAID', 280640.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000028', 'LP', 'DARIUS P. MALATEO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    280640.00, 0, 280640.00, 0, 0,
    0, 0, 0, 0, 0, 0, 280640.00, 0,
    'UNPAID', 280640.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000029', 'LP', 'HERDIE A. DAYSA',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    479776.00, 0, 479776.00, 0, 0,
    0, 0, 0, 0, 0, 0, 479776.00, 0,
    'UNPAID', 479776.00, NULL, NULL, NULL, NULL
),
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2026) AND fund_name = 'SCALE-UP 2026 LP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2026-000013', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101163-2026-02-000030', 'LP', 'BUREAU OF THE TREASURY',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50299990-99'), '50299990-99',
    'FIDELITY BOND - YOLANDA LINGBAWAN',
    48000.00, 0, 48000.00, 0, 0,
    0, 0, 0, 0, 0, 0, 48000.00, 0,
    'UNPAID', 48000.00, NULL, NULL, NULL, NULL
);

-- ============================================
-- INSERT YEAR 2025
-- ============================================
INSERT INTO years (year, description, is_active) 
VALUES (2025, 'SCALE-UP 2025 - GOP FUNDS', 1);

-- ============================================
-- INSERT FUND FOR 2025 GOP
-- ============================================
INSERT INTO funds (year_id, fund_code, fund_name, fund_source, allotment, obligated, disbursed, balance, status)
VALUES 
((SELECT id FROM years WHERE year = 2025), 'GOP-2025-01', 'SCALE-UP 2025 GOP FUNDS', 'GOP', 8384046.00, 3354432.00, 3354432.00, 5029614.00, 'active');

-- ============================================
-- INSERT TRANSACTIONS (ORS No. 000001 to 000027 with obligations)
-- ============================================
INSERT INTO transactions (
    fund_id, mo_no, month, date_transaction, asa_no, pap_code, rc_code, ors_no, fund_source, payee,
    component_id, component_no, unit, category, account_title_id, uacs_code, particulars,
    obligation, adjustment, adjusted_obligation, gop_amount, lp_amount,
    iplan_11, iplan_12, ibuild_21, ibuild_22, ireap_31, ireap_32, isupport, sre,
    status, unpaid_obligation, date_input_engas, box_d_of_dv, dv_received_for_lddap, lddap_no
) VALUES 
-- ORS No. 000001
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000001', 'GOP', 'ARNEL V. GAGUJAS',
    (SELECT id FROM components WHERE component_code = '1.1'), '1.1', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    134200.00, 0, 0, 0, 0, 0, 0, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000002
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000002', 'GOP', 'ISABEL B. TEJO',
    (SELECT id FROM components WHERE component_code = '1.1'), '1.1', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    134200.00, 0, 0, 0, 0, 0, 0, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000003
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000003', 'GOP', 'EDWIN B. CAMHIT',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 134200.00, 0, 0, 0, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000004
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000004', 'GOP', 'MYRIC P. TICUALA',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 134200.00, 0, 0, 0, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000005
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000005', 'GOP', 'GENESIS C. DELOEG',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 134200.00, 0, 0, 0, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000006
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000006', 'GOP', 'JEFFREY T. BAS-ONG',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 134200.00, 0, 0, 0, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000007
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000007', 'GOP', 'TIM S. CHATTOM',
    (SELECT id FROM components WHERE component_code = '2.2'), '2.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    83120.00, 0, 83120.00, 83120.00, 0,
    0, 0, 0, 83120.00, 0, 0, 0, 0,
    'UNPAID', 83120.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000008
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000008', 'GOP', 'JOHNSON D. CEA',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    148456.00, 0, 148456.00, 148456.00, 0,
    0, 0, 0, 0, 0, 148456.00, 0, 0,
    'UNPAID', 148456.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000009 (LORNA E. PANYO)
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000009', 'GOP', 'LORNA E. PANYO',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 0, 0, 134200.00, 0, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000009 (RODERICK A. ADCHOG) - Same ORS number as above but different payee
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000009', 'GOP', 'RODERICK A. ADCHOG',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 0, 0, 134200.00, 0, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000009 (STEPHER L. BANHAN) - Same ORS number
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000009', 'GOP', 'STEPHER L. BANHAN',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 0, 0, 134200.00, 0, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000009 (DALOS S. EMOK) - Same ORS number
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000009', 'GOP', 'DALOS S. EMOK',
    (SELECT id FROM components WHERE component_code = '3.2'), '3.2', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    91432.00, 0, 91432.00, 91432.00, 0,
    0, 0, 0, 0, 0, 91432.00, 0, 0,
    'UNPAID', 91432.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000010
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000010', 'GOP', 'RIGI MAY COPATAN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 0, 0, 0, 134200.00, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000011
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000011', 'GOP', 'RUDAN S. GARIN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    119944.00, 0, 119944.00, 119944.00, 0,
    0, 0, 0, 0, 0, 0, 119944.00, 0,
    'UNPAID', 119944.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000012
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-03-000012', 'GOP', 'JOHNNY P. COMICHO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    119944.00, 0, 119944.00, 119944.00, 0,
    0, 0, 0, 0, 0, 0, 119944.00, 0,
    'UNPAID', 119944.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000013
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000013', 'GOP', 'LARENSTEIN D. BAKILAN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    119944.00, 0, 119944.00, 119944.00, 0,
    0, 0, 0, 0, 0, 0, 119944.00, 0,
    'UNPAID', 119944.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000014
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000014', 'GOP', 'ROISTON Z. CARAME',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 0, 0, 0, 134200.00, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000015
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000015', 'GOP', 'AARON S. FAGYAN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 0, 0, 0, 134200.00, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000016
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000016', 'GOP', 'CHRISTIAN ARIES M. SALGADO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    119944.00, 0, 119944.00, 119944.00, 0,
    0, 0, 0, 0, 0, 0, 119944.00, 0,
    'UNPAID', 119944.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000017
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000089', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '06-02101151-2025-03-000017', 'GOP', 'ELVY T. ESTACIO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    119944.00, 0, 119944.00, 119944.00, 0,
    0, 0, 0, 0, 0, 0, 119944.00, 0,
    'UNPAID', 119944.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000018
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-04-000018', 'GOP', 'FRANCIS D. DUMAGAS',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 0, 0, 0, 134200.00, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000019
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-04-000019', 'GOP', 'DETLEEF B. CAMPOS',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    134200.00, 0, 134200.00, 134200.00, 0,
    0, 0, 0, 0, 0, 0, 134200.00, 0,
    'UNPAID', 134200.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000020
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000058', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-04-000020', 'GOP', 'BLIZYLE MAE T. ATIMPAO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    70160.00, 0, 70160.00, 70160.00, 0,
    0, 0, 0, 0, 0, 0, 70160.00, 0,
    'UNPAID', 70160.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000021
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-05-000021', 'GOP', 'RODEL MARK U. GARCIA',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    70160.00, 0, 70160.00, 70160.00, 0,
    0, 0, 0, 0, 0, 0, 70160.00, 0,
    'UNPAID', 70160.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000022
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-05-000022', 'GOP', 'VANESSA A. BINNOY-PANGANIBAN',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    70160.00, 0, 70160.00, 70160.00, 0,
    0, 0, 0, 0, 0, 0, 70160.00, 0,
    'UNPAID', 70160.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000023
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-06-000023', 'GOP', 'NORBERTO O. CORRALES JR.',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    70160.00, 0, 70160.00, 70160.00, 0,
    0, 0, 0, 0, 0, 0, 70160.00, 0,
    'UNPAID', 70160.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000024
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-06-000024', 'GOP', 'LEE LUBANGAS',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    70160.00, 0, 70160.00, 70160.00, 0,
    0, 0, 0, 0, 0, 0, 70160.00, 0,
    'UNPAID', 70160.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000025
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-06-000025', 'GOP', 'DARIUS P. MALATEO',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - MARCH 2026 TO DECEMBER 2026',
    70160.00, 0, 70160.00, 70160.00, 0,
    0, 0, 0, 0, 0, 0, 70160.00, 0,
    'UNPAID', 70160.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000026
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-06-000026', 'GOP', 'HERDIE A. DAYSA',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50211990-00'), '50211990-00',
    'OTHER PROFESSIONAL SERVICES - FEBRUARY 2026 TO DECEMBER 2026',
    119944.00, 0, 119944.00, 119944.00, 0,
    0, 0, 0, 0, 0, 0, 119944.00, 0,
    'UNPAID', 119944.00, NULL, NULL, NULL, NULL
),
-- ORS No. 000027
(
    (SELECT id FROM funds WHERE year_id = (SELECT id FROM years WHERE year = 2025) AND fund_name = 'SCALE-UP 2025 GOP FUNDS' LIMIT 1),
    '2', 'February', '02/20', 'ASA No. 2025-000017', '310500300010000 PRDP SCALE-UP', '05-001-03-00014-24-01 PRDP SU', 
    '02-02101151-2025-06-000027', 'GOP', 'BUREAU OF THE TREASURY',
    (SELECT id FROM components WHERE component_code = '4.0'), '4', NULL, 'IOC',
    (SELECT id FROM account_titles WHERE uacs_code = '50299990-99'), '50299990-99',
    'FIDELITY BOND - YOLANDA LINGBAWAN',
    12000.00, 0, 12000.00, 12000.00, 0,
    0, 0, 0, 0, 0, 0, 12000.00, 0,
    'UNPAID', 12000.00, NULL, NULL, NULL, NULL
);



