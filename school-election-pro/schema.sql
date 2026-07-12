CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'superadmin' CHECK (role IN ('superadmin','manager','observer')),
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_code TEXT NOT NULL UNIQUE,
    full_name TEXT NOT NULL,
    class_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    session_token TEXT NULL,
    failed_login_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS elections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    start_at TEXT NULL,
    end_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','scheduled','open','closed','archived')),
    results_public INTEGER NOT NULL DEFAULT 0 CHECK (results_public IN (0,1)),
    candidates_randomized INTEGER NOT NULL DEFAULT 1 CHECK (candidates_randomized IN (0,1)),
    terminal_mode INTEGER NOT NULL DEFAULT 1 CHECK (terminal_mode IN (0,1)),
    second_round_enabled INTEGER NOT NULL DEFAULT 1 CHECK (second_round_enabled IN (0,1)),
    second_round_threshold REAL NOT NULL DEFAULT 50.0,
    locked_at TEXT NULL,
    closed_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS candidates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    election_id INTEGER NOT NULL,
    full_name TEXT NOT NULL,
    class_name TEXT NOT NULL,
    slogan TEXT NOT NULL DEFAULT '',
    program_text TEXT NOT NULL DEFAULT '',
    bio TEXT NOT NULL DEFAULT '',
    achievements TEXT NOT NULL DEFAULT '',
    resources_text TEXT NOT NULL DEFAULT '',
    video_url TEXT NOT NULL DEFAULT '',
    website_url TEXT NOT NULL DEFAULT '',
    photo_path TEXT NULL,
    color TEXT NOT NULL DEFAULT '#665df5',
    ballot_number INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS election_eligibility (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    election_id INTEGER NOT NULL,
    student_id INTEGER NULL,
    student_code TEXT NOT NULL,
    full_name TEXT NOT NULL,
    class_name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (election_id, student_id),
    FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE RESTRICT,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS participation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    election_id INTEGER NOT NULL,
    student_id INTEGER NULL,
    student_code TEXT NOT NULL DEFAULT '',
    full_name TEXT NOT NULL DEFAULT '',
    class_name TEXT NOT NULL DEFAULT '',
    voted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (election_id, student_id),
    FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE RESTRICT,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    election_id INTEGER NOT NULL,
    candidate_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (election_id) REFERENCES elections(id) ON DELETE RESTRICT,
    FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS election_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NULL,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL DEFAULT '',
    entity_id INTEGER NULL,
    details TEXT NOT NULL DEFAULT '',
    ip_address TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope TEXT NOT NULL,
    identifier TEXT NOT NULL DEFAULT '',
    ip_address TEXT NOT NULL DEFAULT '',
    success INTEGER NOT NULL DEFAULT 0 CHECK (success IN (0,1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_candidates_election ON candidates(election_id, is_active);
CREATE INDEX IF NOT EXISTS idx_votes_election_candidate ON votes(election_id, candidate_id);
CREATE INDEX IF NOT EXISTS idx_eligibility_election ON election_eligibility(election_id, student_id);
CREATE INDEX IF NOT EXISTS idx_participation_election ON participation(election_id, student_id);
CREATE INDEX IF NOT EXISTS idx_students_class_name ON students(class_name);
CREATE INDEX IF NOT EXISTS idx_students_list_sort ON students(class_name, full_name, id);
CREATE INDEX IF NOT EXISTS idx_students_active ON students(is_active, class_name, full_name);
CREATE INDEX IF NOT EXISTS idx_participation_student_election ON participation(student_id, election_id);
CREATE INDEX IF NOT EXISTS idx_eligibility_student_election ON election_eligibility(student_id, election_id);
CREATE INDEX IF NOT EXISTS idx_participation_snapshot ON participation(election_id, student_code);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts(scope, identifier, ip_address, created_at);
