-- HelpDeskPro Database Schema
-- IT Helpdesk & Ticket Management System
-- Matches the ERD in Chapter 6 of the proposal (Users, Tickets, TicketLogs)

CREATE DATABASE IF NOT EXISTS helpdeskpro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE helpdeskpro;

-- ---------------------------------------------------------
-- Departments (multi-team support)
-- Lets one installation serve several support teams - IT, Facilities,
-- HR, etc. - each with its own technicians and ticket queue, instead of
-- one flat organization-wide pile.
-- ---------------------------------------------------------
CREATE TABLE departments (
    department_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------
-- Users table
-- ---------------------------------------------------------
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    staff_id VARCHAR(20) NOT NULL UNIQUE,   -- auto-issued from role (ADM-/TEC-/EMP-); can also be used to sign in
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'technician', 'employee') NOT NULL DEFAULT 'employee',
    department_id INT NULL,                   -- technician's team; NULL for admins (global) and employees
    two_factor_secret VARCHAR(32) NULL,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    password_changed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0, -- set when an admin chooses the password; cleared once the user picks their own
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL
);

-- ---------------------------------------------------------
-- Tickets table
-- ---------------------------------------------------------
CREATE TABLE tickets (
    ticket_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,                     -- employee who submitted the ticket
    assigned_to INT NULL,                     -- technician assigned (nullable until assigned)
    department_id INT NULL,                   -- which team handles this ticket
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    category ENUM('Hardware', 'Software', 'Network', 'Other') DEFAULT 'Other',
    priority ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
    status ENUM('Open', 'In Progress', 'Closed') DEFAULT 'Open',
    escalated TINYINT(1) NOT NULL DEFAULT 0,  -- set to 1 once auto-escalated due to SLA breach
    sla_due_at DATETIME NULL,                 -- deadline in BUSINESS hours (see includes/business_hours.php)
    attachment VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL
);

-- ---------------------------------------------------------
-- TicketLogs table (comments / status history / notifications)
-- ---------------------------------------------------------
CREATE TABLE ticket_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,                     -- who made this update
    status VARCHAR(30) NULL,                  -- status at time of update (nullable for plain comments)
    message TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(ticket_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ---------------------------------------------------------
-- Login attempts (for rate-limiting / lockout after repeated failures)
-- ---------------------------------------------------------
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email, attempted_at)
);

-- ---------------------------------------------------------
-- Audit log (security-relevant events: logins, user/role changes, etc.)
-- ---------------------------------------------------------
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,               -- nullable: e.g. failed login before we know who
    actor_name VARCHAR(150) NULL,   -- denormalised so the trail survives user deletion
    actor_role VARCHAR(20) NULL,    -- role held AT THE TIME of the action
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,   -- what was acted on: ticket / user / department / kb_article ...
    entity_id INT NULL,             -- which record
    old_value TEXT NULL,            -- state BEFORE the change
    new_value TEXT NULL,            -- state AFTER the change
    details TEXT NULL,              -- human-readable summary
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_time (created_at),
    INDEX idx_audit_action (action)
);

-- ---------------------------------------------------------
-- Password resets (secure token-based "forgot password" flow)
-- ---------------------------------------------------------
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,   -- sha256 hash of the token (never store the raw token)
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash)
);

-- ---------------------------------------------------------
-- Canned responses (shared reusable reply templates for technicians/admins)
-- ---------------------------------------------------------
CREATE TABLE canned_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_by INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ---------------------------------------------------------
-- Knowledge Base articles (self-service help before filing a ticket)
-- ---------------------------------------------------------
CREATE TABLE kb_articles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    category ENUM('Hardware', 'Software', 'Network', 'Other') NOT NULL DEFAULT 'Other',
    body TEXT NOT NULL,
    created_by INT NOT NULL,
    views INT NOT NULL DEFAULT 0,
    helpful_yes INT NOT NULL DEFAULT 0,
    helpful_no INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ---------------------------------------------------------
-- In-app notifications (bell icon) - independent of email, so this
-- always works even if PHPMailer/SMTP isn't configured.
-- ---------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,          -- recipient
    ticket_id INT NULL,            -- related ticket, if any
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (ticket_id) REFERENCES tickets(ticket_id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read)
);

-- ---------------------------------------------------------
-- Staff ID sequence
-- Remembers the highest number issued for each role prefix so an ID is
-- never reused after an account is deleted - historical tickets and audit
-- entries record staff IDs, so a recycled ID would make them ambiguous.
-- ---------------------------------------------------------
CREATE TABLE staff_id_sequence (
    prefix VARCHAR(8) NOT NULL PRIMARY KEY,
    last_number INT NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------
-- Sample seed data
-- Password for ALL sample accounts is: password123
-- (hash generated with PHP password_hash, bcrypt)
-- ---------------------------------------------------------
-- Default departments
INSERT INTO departments (name, description) VALUES
('IT Support', 'Hardware, software, network and account issues'),
('Facilities', 'Building, furniture, power and physical workspace issues'),
('HR', 'Payroll, leave, onboarding and people-related requests');

INSERT INTO users (name, email, staff_id, password, role, department_id) VALUES
('System Admin', 'admin@helpdeskpro.com', 'ADM-0001', '$2y$10$jEbQrBOgGcvpWca1ogGiBeoiDE9j7ytYpkK5UW/odykp6UxYXYD4i', 'admin', NULL),
('Ahmed Technician', 'tech1@helpdeskpro.com', 'TEC-0001', '$2y$10$jEbQrBOgGcvpWca1ogGiBeoiDE9j7ytYpkK5UW/odykp6UxYXYD4i', 'technician', 1),
('Fathmath Employee', 'employee1@helpdeskpro.com', 'EMP-0001', '$2y$10$jEbQrBOgGcvpWca1ogGiBeoiDE9j7ytYpkK5UW/odykp6UxYXYD4i', 'employee', NULL);

-- Start the ID counters level with the seeded accounts
INSERT INTO staff_id_sequence (prefix, last_number) VALUES ('ADM', 1), ('TEC', 1), ('EMP', 1);

-- Sample Knowledge Base articles (created_by = 1, System Admin)
INSERT INTO kb_articles (title, category, body, created_by, views, helpful_yes, helpful_no) VALUES
('How to connect to the office Wi-Fi', 'Network',
'1. Open your Wi-Fi settings and select the network "OfficeNet".\n2. Enter the password provided by IT (see the printed card at reception, or ask your manager).\n3. If the connection fails, forget the network and try again - sometimes a stale saved password causes this.\n4. Still stuck? Restart your device''s Wi-Fi adapter (turn Wi-Fi off and back on).\n\nIf none of this works, please submit a ticket under the Network category.',
1, 42, 18, 2),

('Printer not printing - basic checks', 'Hardware',
'Before submitting a ticket, please check the following:\n1. Is the printer powered on and showing no error lights?\n2. Is there paper in the tray and no visible paper jam?\n3. Is the printer selected as your default printer in your computer''s print settings?\n4. Try printing a test page from the printer''s own control panel.\n5. If the printer shows "offline", try restarting both the printer and your computer.\n\nIf the issue persists after these steps, please submit a ticket with the printer''s location and model.',
1, 65, 29, 4),

('Resetting your account password', 'Other',
'You can reset your own password without waiting for IT:\n1. Go to the login page and click "Forgot password?"\n2. Enter your work email address.\n3. Check your email for a reset link (valid for 30 minutes).\n4. Choose a new password with at least 8 characters.\n\nIf you don''t receive the email within a few minutes, check your spam folder. If you still can''t get in, submit a ticket.',
1, 88, 40, 1),

('Installing approved software', 'Software',
'Most standard business software (Office, Zoom, Slack) is available through the company software portal without needing a ticket.\n1. Open the Software Center from your Start menu.\n2. Search for the application you need.\n3. Click Install and wait for it to complete - no admin password required.\n\nIf the software you need isn''t listed in the portal, submit a ticket under Software with the name and purpose of the application, and your manager''s approval if it''s a paid license.',
1, 51, 22, 3),

('Setting up email on your phone', 'Other',
'To add your work email to your phone:\n1. Open your phone''s Mail app and choose "Add Account" > "Exchange" (or "Corporate").\n2. Enter your full work email address and password.\n3. When prompted for a server, enter mail.techsolutions.mv (or leave blank for auto-detect).\n4. Accept the security policy prompt if asked - this allows remote wipe if the phone is lost, which is a company security requirement.\n\nIf setup fails after a few tries, submit a ticket so IT can check your account''s mobile access settings.',
1, 37, 15, 5);
