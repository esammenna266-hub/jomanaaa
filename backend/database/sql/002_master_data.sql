-- 002 Master data (MD): customers, seat layouts, vehicles, drivers, lookups, agent prices

SET NAMES utf8mb4;

CREATE TABLE customers (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                 VARCHAR(150) NOT NULL,
  phone                VARCHAR(20)  NOT NULL,          -- international format: +20..., +249...
  passport_no          VARCHAR(40)  NULL,
  country              CHAR(2)      NULL,
  whatsapp_verified_at DATETIME NULL,                  -- MD-05
  agent_id             BIGINT UNSIGNED NULL,           -- owned by an external office (scope)
  -- NULL agent_id would defeat a plain UNIQUE(phone, agent_id); 0 = company-owned customer
  agent_key            BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(agent_id, 0)) VIRTUAL,
  branch_id            BIGINT UNSIGNED NULL,           -- registered at branch
  active               TINYINT(1) NOT NULL DEFAULT 1,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by           BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_phone_agent (phone, agent_key),
  KEY ix_customers_phone (phone),
  KEY ix_customers_name (name),
  CONSTRAINT fk_customers_agent  FOREIGN KEY (agent_id)  REFERENCES agents(id),
  CONSTRAINT fk_customers_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE seat_layouts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80) NOT NULL,            -- 49 / 51 / 52 / 57 / 70
  seats_count SMALLINT UNSIGNED NOT NULL,
  layout_json JSON NOT NULL,                   -- rows, aisle, door position, seat numbers
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Trucks and buses share one table
CREATE TABLE vehicles (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plate_no       VARCHAR(30) NOT NULL,
  category       ENUM('jumbo','single','trailer','bus') NOT NULL,
  body           ENUM('closed','open') NULL,                 -- trucks only
  min_ton        DECIMAL(6,2) NULL,
  max_ton        DECIMAL(6,2) NULL,
  seats          SMALLINT UNSIGNED NULL,                     -- buses only
  seat_layout_id BIGINT UNSIGNED NULL,
  owner_name     VARCHAR(120) NULL,
  ownership      ENUM('owned','rented') NOT NULL DEFAULT 'owned',
  active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vehicles_plate (plate_no),
  CONSTRAINT fk_vehicles_layout FOREIGN KEY (seat_layout_id) REFERENCES seat_layouts(id),
  CONSTRAINT ck_vehicles_tons CHECK (min_ton IS NULL OR max_ton IS NULL OR min_ton <= max_ton),
  CONSTRAINT ck_vehicles_bus  CHECK (category <> 'bus' OR seats IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Fingerprints are NOT stored here: they are captured per trip (see driver_undertakings)
CREATE TABLE drivers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(120) NOT NULL,
  phone           VARCHAR(20)  NULL,
  license_no      VARCHAR(40)  NULL,
  id_type         ENUM('national_id','passport','other') NULL,
  id_no           VARCHAR(40)  NULL,
  id_issue_date   DATE NULL,
  id_expiry_date  DATE NULL,
  is_international TINYINT(1) NOT NULL DEFAULT 0,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by      BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_drivers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE border_points (            -- أرقين، حلفا، حدربة
  id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name    VARCHAR(100) NOT NULL,
  active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_border_points_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE destinations (             -- أم درمان، ود مدني
  id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name     VARCHAR(100) NOT NULL,
  country  CHAR(2) NULL,
  active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_destinations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE clearing_agents (          -- المخلصين الجمركيين
  id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name    VARCHAR(120) NOT NULL,
  phone   VARCHAR(20) NULL,
  active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- AG-03: agent cost price per service/route. Never printed on customer documents.
CREATE TABLE agent_prices (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_id    BIGINT UNSIGNED NOT NULL,
  service     ENUM('bus','flight','ship','express','cargo') NOT NULL,
  route       VARCHAR(120) NOT NULL,
  price       DECIMAL(14,2) NOT NULL,
  currency_id TINYINT UNSIGNED NOT NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_prices (agent_id, service, route, currency_id),
  CONSTRAINT fk_ap_agent FOREIGN KEY (agent_id)    REFERENCES agents(id),
  CONSTRAINT fk_ap_cur   FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT ck_ap_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
