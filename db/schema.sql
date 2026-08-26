-- Nice-to-have for later (SPEC.md §7.1): not read from by the app yet.
-- The live path is the Google Sheet -> includes/attendance.php -> file cache.
-- These tables exist so a daily sync job can start writing attendance
-- history here later, for trends over time, without a schema change.

CREATE TABLE IF NOT EXISTS schools (
  id VARCHAR(20) PRIMARY KEY,
  name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO schools (id, name) VALUES
  ('eng', 'School of Engineering'),
  ('mgmt', 'School of Management'),
  ('law', 'School of Law'),
  ('design', 'School of Design'),
  ('science', 'School of Science'),
  ('arch', 'School of Architecture'),
  ('hosp', 'School of Hospitality'),
  ('lib', 'School of Liberal Arts'),
  ('film', 'School of Film & Media');

-- branch is '' for schools with no branch structure (only School of
-- Engineering has confirmed branches so far — see includes/attendance.php).
CREATE TABLE IF NOT EXISTS attendance_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id VARCHAR(20) NOT NULL,
  year_label VARCHAR(20) NOT NULL,
  branch VARCHAR(20) NOT NULL DEFAULT '',
  division VARCHAR(10) NOT NULL,
  record_date DATE NOT NULL,
  strength INT NOT NULL,
  present INT NOT NULL,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_record (school_id, year_label, branch, division, record_date),
  FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
