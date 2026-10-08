-- Least-privilege MySQL account for the application (run once as an administrator).
-- The app never needs DELETE (the schema forbids hard deletes anyway), except to clear a
-- user's failed login attempts after a successful login. Replace the password first.
CREATE USER IF NOT EXISTS 'capitally_app'@'localhost' IDENTIFIED BY 'REPLACE-WITH-A-LONG-RANDOM-PASSWORD';
GRANT SELECT, INSERT, UPDATE ON capitally.* TO 'capitally_app'@'localhost';
GRANT DELETE ON capitally.login_attempts TO 'capitally_app'@'localhost';
FLUSH PRIVILEGES;
