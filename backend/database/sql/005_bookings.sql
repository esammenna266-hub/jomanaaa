-- 005 Passenger bookings (BS, TR) and hotel/car service bookings

SET NAMES utf8mb4;

CREATE TABLE bookings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no      VARCHAR(20) NOT NULL,
  trip_id        BIGINT UNSIGNED NOT NULL,
  seat_no        SMALLINT UNSIGNED NOT NULL,
  passenger_name VARCHAR(150) NOT NULL,
  passport_no    VARCHAR(40)  NULL,
  destination    VARCHAR(100) NULL,
  customer_id    BIGINT UNSIGNED NULL,
  branch_id      BIGINT UNSIGNED NULL,
  agent_id       BIGINT UNSIGNED NULL,
  agent_price    DECIMAL(14,2) NULL,            -- cost to the agent (internal)
  sale_price     DECIMAL(14,2) NOT NULL,        -- what the passenger pays
  currency_id    TINYINT UNSIGNED NOT NULL,
  status         ENUM('reserved','paid','travelled','cancelled') NOT NULL DEFAULT 'reserved',
  cancel_reason  VARCHAR(255) NULL,
  -- Seat uniqueness only applies to live bookings; cancelled rows free the seat (NF-02)
  active_seat    SMALLINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('reserved','paid','travelled'), seat_no, NULL)) VIRTUAL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_serial (serial_no),
  UNIQUE KEY uq_bookings_active_seat (trip_id, active_seat),
  KEY ix_bookings_customer (customer_id),
  KEY ix_bookings_agent (agent_id),
  KEY ix_bookings_branch (branch_id),
  CONSTRAINT fk_bk_trip     FOREIGN KEY (trip_id)     REFERENCES trips(id),
  CONSTRAINT fk_bk_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_bk_branch   FOREIGN KEY (branch_id)   REFERENCES branches(id),
  CONSTRAINT fk_bk_agent    FOREIGN KEY (agent_id)    REFERENCES agents(id),
  CONSTRAINT fk_bk_cur      FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT ck_bk_price    CHECK (sale_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Hotels & cars: free-form details + price (open point 14)
CREATE TABLE service_bookings (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no   VARCHAR(20) NOT NULL,
  type        ENUM('hotel','car') NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  branch_id   BIGINT UNSIGNED NULL,
  agent_id    BIGINT UNSIGNED NULL,
  details     TEXT NOT NULL,
  start_date  DATE NULL,
  end_date    DATE NULL,
  price       DECIMAL(14,2) NOT NULL,
  currency_id TINYINT UNSIGNED NOT NULL,
  status      ENUM('reserved','paid','completed','cancelled') NOT NULL DEFAULT 'reserved',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sb_serial (serial_no),
  CONSTRAINT fk_sb_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_sb_branch   FOREIGN KEY (branch_id)   REFERENCES branches(id),
  CONSTRAINT fk_sb_agent    FOREIGN KEY (agent_id)    REFERENCES agents(id),
  CONSTRAINT fk_sb_cur      FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT ck_sb_dates    CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
