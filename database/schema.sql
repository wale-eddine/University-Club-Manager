CREATE DATABASE IF NOT EXISTS university_clubs;
USE university_clubs;

CREATE TABLE USERS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    special_id VARCHAR(255) NULL,
    mot_de_passe VARCHAR(255) NULL,
    google_id VARCHAR(191) UNIQUE NULL,
    avatar_url VARCHAR(255) NULL,
    email_verified_at DATETIME NULL,
    role ENUM('admin', 'responsable', 'etudiant') DEFAULT 'etudiant',
    account_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    inactive_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE CLUBS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(150) NOT NULL,
    special_id VARCHAR(255) NULL,
    description TEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    responsable_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (responsable_id) REFERENCES USERS(id)
);

CREATE TABLE CLUB_RESPONSABLES (
    id INT PRIMARY KEY AUTO_INCREMENT,
    club_id INT NOT NULL,
    user_id INT NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (club_id) REFERENCES CLUBS(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE,
    UNIQUE KEY unique_club_responsable (club_id, user_id)
);

CREATE TABLE CLUB_MEMBERS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    club_id INT NOT NULL,
    user_id INT NOT NULL,
    date_adhesion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (club_id) REFERENCES CLUBS(id),
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE,
    UNIQUE KEY unique_membership (club_id, user_id)
);

CREATE TABLE MEMBERSHIP_REQUESTS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    club_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    requester_notified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (club_id) REFERENCES CLUBS(id),
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE,
    UNIQUE KEY unique_request (club_id, user_id)
);

CREATE TABLE MEMBERSHIP_REQUEST_COOLDOWNS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    club_id INT NOT NULL,
    user_id INT NOT NULL,
    blocked_until DATETIME NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (club_id) REFERENCES CLUBS(id),
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE,
    UNIQUE KEY unique_club_user_cooldown (club_id, user_id)
);

CREATE TABLE EVENTS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    club_id INT NOT NULL,
    titre VARCHAR(200) NOT NULL,
    special_id VARCHAR(255) NULL,
    description TEXT NOT NULL,
    image_path VARCHAR(255) NULL,
    date_debut DATETIME NOT NULL,
    date_fin DATETIME NOT NULL,
    lieu VARCHAR(200) NOT NULL,
    max_participants INT NULL,
    allow_non_members TINYINT(1) NOT NULL DEFAULT 0,
    is_paid_event TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (club_id) REFERENCES CLUBS(id)
);

CREATE TABLE EVENT_PARTICIPANTS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    date_inscription TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    payment_status TINYINT(1) NOT NULL DEFAULT 0,
    payment_date DATETIME NULL,
    FOREIGN KEY (event_id) REFERENCES EVENTS(id),
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participation (event_id, user_id)
);

CREATE TABLE USER_NOTIFICATIONS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE
);

CREATE TABLE ACTION_LOGS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    actor_user_id INT NULL,
    actor_role VARCHAR(30) NOT NULL,
    action_type VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id INT NULL,
    target_label VARCHAR(255) NULL,
    club_id INT NULL,
    event_id INT NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action_logs_created_at (created_at),
    INDEX idx_action_logs_actor_role (actor_role),
    INDEX idx_action_logs_actor_user_id (actor_user_id),
    INDEX idx_action_logs_club_id (club_id),
    INDEX idx_action_logs_event_id (event_id),
    CONSTRAINT fk_action_logs_actor_user FOREIGN KEY (actor_user_id) REFERENCES USERS(id) ON DELETE SET NULL
);

CREATE TABLE PASSWORD_RESET_TOKENS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE,
    UNIQUE KEY unique_token_hash (token_hash),
    INDEX idx_password_reset_email (email),
    INDEX idx_password_reset_user (user_id)
);

CREATE TABLE EMAIL_VERIFICATION_TOKENS (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    email VARCHAR(100) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES USERS(id) ON DELETE CASCADE,
    UNIQUE KEY unique_email_verification_token_hash (token_hash),
    INDEX idx_email_verification_email (email),
    INDEX idx_email_verification_user (user_id)
);
