-- 004 Shipping (SH, WH, TK, FP): shipments, items, files, warehouse transfers, driver undertakings

SET NAMES utf8mb4;

CREATE TABLE shipments (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no          VARCHAR(20) NOT NULL,
  tracking_code      CHAR(10)    NOT NULL,          -- random, used by QR + public page (never the serial)
  type               ENUM('normal','express') NOT NULL DEFAULT 'normal',
  branch_id          BIGINT UNSIGNED NOT NULL,
  agent_id           BIGINT UNSIGNED NULL,          -- set when created by an external office
  customer_id        BIGINT UNSIGNED NOT NULL,
  consignee_name     VARCHAR(150) NOT NULL,
  consignee_phone    VARCHAR(20)  NOT NULL,
  border_point_id    BIGINT UNSIGNED NULL,
  destination_id     BIGINT UNSIGNED NOT NULL,
  clearing_agent_id  BIGINT UNSIGNED NULL,
  proposed_category  ENUM('jumbo','single','trailer','bus') NULL,   -- proposed vehicle
  proposed_body      ENUM('closed','open') NULL,
  proposed_tons      DECIMAL(6,2) NULL,
  expected_date      DATE NULL,
  currency_id        TINYINT UNSIGNED NOT NULL,     -- single currency per shipment; paid/remaining come from payments
  price              DECIMAL(14,2) NOT NULL DEFAULT 0,
  agent_sale_price   DECIMAL(14,2) NULL,            -- what the agent's customer pays; agent cost = price
  discount           DECIMAL(14,2) NOT NULL DEFAULT 0,
  status             ENUM('draft','pending_approval','approved','at_branch','to_main_warehouse','at_main_warehouse',
                          'loaded','in_transit','arrived','delivered','cancelled','lost','damaged')
                     NOT NULL DEFAULT 'draft',
  cargo_trip_id      BIGINT UNSIGNED NULL,
  bus_trip_id        BIGINT UNSIGNED NULL,          -- express cargo rides a bus trip
  delivered_at       DATETIME NULL,
  delivered_to       VARCHAR(150) NULL,             -- who actually took the goods
  cancel_reason      VARCHAR(255) NULL,
  notes              TEXT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by         BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shipments_serial (serial_no),
  UNIQUE KEY uq_shipments_tracking (tracking_code),
  KEY ix_shipments_branch_status (branch_id, status),
  KEY ix_shipments_agent (agent_id),
  KEY ix_shipments_customer (customer_id),
  KEY ix_shipments_cargo_trip (cargo_trip_id),
  KEY ix_shipments_bus_trip (bus_trip_id),
  CONSTRAINT fk_sh_branch   FOREIGN KEY (branch_id)         REFERENCES branches(id),
  CONSTRAINT fk_sh_agent    FOREIGN KEY (agent_id)          REFERENCES agents(id),
  CONSTRAINT fk_sh_customer FOREIGN KEY (customer_id)       REFERENCES customers(id),
  CONSTRAINT fk_sh_border   FOREIGN KEY (border_point_id)   REFERENCES border_points(id),
  CONSTRAINT fk_sh_dest     FOREIGN KEY (destination_id)    REFERENCES destinations(id),
  CONSTRAINT fk_sh_clearing FOREIGN KEY (clearing_agent_id) REFERENCES clearing_agents(id),
  CONSTRAINT fk_sh_cur      FOREIGN KEY (currency_id)       REFERENCES currencies(id),
  CONSTRAINT fk_sh_ctrip    FOREIGN KEY (cargo_trip_id)     REFERENCES cargo_trips(id),
  CONSTRAINT fk_sh_btrip    FOREIGN KEY (bus_trip_id)       REFERENCES trips(id),
  CONSTRAINT ck_sh_money    CHECK (price >= 0 AND discount >= 0 AND discount <= price),
  CONSTRAINT ck_sh_one_trip CHECK (NOT (cargo_trip_id IS NOT NULL AND bus_trip_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Totals per unit are computed in queries, never stored
CREATE TABLE shipment_items (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(150) NOT NULL,
  qty         DECIMAL(10,2) NOT NULL,
  unit        ENUM('piece','carton','sack','barrel','pack') NOT NULL,
  per_pack    DECIMAL(10,2) NULL,
  weight_kg   DECIMAL(10,2) NULL,
  notes       VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_si_shipment (shipment_id),
  CONSTRAINT fk_si_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id),
  CONSTRAINT ck_si_qty CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE shipment_files (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NOT NULL,
  path        VARCHAR(255) NOT NULL,                -- object storage key, served via signed URL
  kind        ENUM('passport','invoice','packing_list','other') NOT NULL DEFAULT 'other',
  uploaded_by BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_sf_shipment (shipment_id),
  CONSTRAINT fk_sf_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Branch -> main warehouse (Faisal) exit form
CREATE TABLE transfers (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no      VARCHAR(20) NOT NULL,
  from_branch_id BIGINT UNSIGNED NOT NULL,
  to_branch_id   BIGINT UNSIGNED NOT NULL,
  vehicle_text   VARCHAR(80) NULL,
  driver_text    VARCHAR(120) NULL,
  driver_phone   VARCHAR(20) NULL,
  status         ENUM('draft','sent','received','received_with_diff') NOT NULL DEFAULT 'draft',
  sent_at        DATETIME NULL,
  received_at    DATETIME NULL,
  received_by    BIGINT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transfers_serial (serial_no),
  KEY ix_transfers_status (status),
  CONSTRAINT fk_tr_from FOREIGN KEY (from_branch_id) REFERENCES branches(id),
  CONSTRAINT fk_tr_to   FOREIGN KEY (to_branch_id)   REFERENCES branches(id),
  CONSTRAINT fk_tr_recv FOREIGN KEY (received_by)    REFERENCES users(id),
  CONSTRAINT ck_tr_diff CHECK (from_branch_id <> to_branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE transfer_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id    BIGINT UNSIGNED NOT NULL,
  shipment_id    BIGINT UNSIGNED NOT NULL,
  sent_count     DECIMAL(10,2) NOT NULL,
  received_count DECIMAL(10,2) NULL,                -- WH-04: mismatch raises an alert
  PRIMARY KEY (id),
  UNIQUE KEY uq_ti (transfer_id, shipment_id),
  CONSTRAINT fk_ti_transfer FOREIGN KEY (transfer_id) REFERENCES transfers(id),
  CONSTRAINT fk_ti_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- FP-02: a fresh fingerprint image for every trip
CREATE TABLE driver_undertakings (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no        VARCHAR(20) NOT NULL,
  cargo_trip_id    BIGINT UNSIGNED NOT NULL,
  driver_id        BIGINT UNSIGNED NOT NULL,
  pickup_point     VARCHAR(150) NULL,
  delivery_point   VARCHAR(150) NULL,
  delivery_days    SMALLINT UNSIGNED NULL,
  daily_penalty    DECIMAL(14,2) NULL,
  currency_id      TINYINT UNSIGNED NULL,
  fingerprint_path VARCHAR(255) NULL,
  signed_at        DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by       BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_du_serial (serial_no),
  UNIQUE KEY uq_du_trip_driver (cargo_trip_id, driver_id),
  CONSTRAINT fk_du_trip   FOREIGN KEY (cargo_trip_id) REFERENCES cargo_trips(id),
  CONSTRAINT fk_du_driver FOREIGN KEY (driver_id)     REFERENCES drivers(id),
  CONSTRAINT fk_du_cur    FOREIGN KEY (currency_id)   REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
