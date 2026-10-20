-- 001 Foundation: currencies, branches, agents, users, RBAC, audit, sequences, settings
-- Conventions: InnoDB, utf8mb4, BIGINT UNSIGNED ids, money = DECIMAL(14,2), all timestamps stored in UTC.

SET NAMES utf8mb4;

CREATE TABLE currencies (
  id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        CHAR(3)      NOT NULL,            -- EGP / USD / SDG
  name        VARCHAR(60)  NOT NULL,
  name_ar     VARCHAR(60)  NOT NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_currencies_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE branches (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(120) NOT NULL,
  country           CHAR(2)      NOT NULL,       -- EG / SD
  timezone          VARCHAR(40)  NOT NULL DEFAULT 'Africa/Cairo',
  is_main_warehouse TINYINT(1)   NOT NULL DEFAULT 0,
  -- generated: lets us enforce "only one main warehouse" with a unique index
  main_warehouse_key TINYINT GENERATED ALWAYS AS (IF(is_main_warehouse = 1, 1, NULL)) VIRTUAL,
  phone             VARCHAR(30)  NULL,
  address           VARCHAR(255) NULL,
  active            TINYINT(1)   NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by        BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branches_main_warehouse (main_warehouse_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- External offices (AG): they sell on behalf of the company and see only their own data
CREATE TABLE agents (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  phone       VARCHAR(30)  NULL,
  country     CHAR(2)      NULL,
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  BIGINT UNSIGNED NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE roles (
  id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(30) NOT NULL,             -- owner, admin, branch_staff, faisal_staff, accountant, agent
  name_ar     VARCHAR(60) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE permissions (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(60)  NOT NULL,            -- e.g. shipment.approve_price
  name_ar     VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE role_permissions (
  role_id       TINYINT UNSIGNED  NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  scope         ENUM('all','branch','own') NOT NULL DEFAULT 'all',  -- all = no filter, branch = "فرعه", own = "بتاعه"
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  username      VARCHAR(60)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_id       TINYINT UNSIGNED NOT NULL,
  branch_id     BIGINT UNSIGNED NULL,           -- branch staff
  agent_id      BIGINT UNSIGNED NULL,           -- external office account
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by    BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_branch (branch_id),
  KEY ix_users_agent (agent_id),
  CONSTRAINT fk_users_role   FOREIGN KEY (role_id)   REFERENCES roles(id),
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
  CONSTRAINT fk_users_agent  FOREIGN KEY (agent_id)  REFERENCES agents(id),
  CONSTRAINT ck_users_scope CHECK (NOT (branch_id IS NOT NULL AND agent_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Per-user grants on top of the role (open point 6: "approve" permission can be given to anyone)
CREATE TABLE user_permissions (
  user_id       BIGINT UNSIGNED  NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  scope         ENUM('all','branch','own') NOT NULL DEFAULT 'all',
  PRIMARY KEY (user_id, permission_id),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_up_perm FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- AU-01: append-only (enforced by triggers in 008)
CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,
  branch_id   BIGINT UNSIGNED NULL,
  action      VARCHAR(60)  NOT NULL,
  entity      VARCHAR(60)  NOT NULL,
  entity_id   VARCHAR(64) NULL,                 -- numeric ids and text keys (e.g. settings) alike
  before_json JSON NULL,
  after_json  JSON NULL,
  ip          VARCHAR(45) NULL,
  at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_audit_entity (entity, entity_id),
  KEY ix_audit_user_at (user_id, at),
  KEY ix_audit_branch_at (branch_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- NF-05: gap-free serials. Always SELECT ... FOR UPDATE inside the issuing transaction.
CREATE TABLE document_sequences (
  doc_type    VARCHAR(40) NOT NULL,
  next_no     BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE settings (
  `key`       VARCHAR(60) NOT NULL,
  value       VARCHAR(255) NOT NULL,
  description VARCHAR(255) NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
