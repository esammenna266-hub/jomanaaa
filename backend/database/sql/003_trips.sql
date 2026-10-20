-- 003 Trips: cargo trips (truck), passenger trips (bus/flight/ship), stage events

SET NAMES utf8mb4;

-- Truck shipping trip (رحلة الشحن بالعربية). Manifest = shipments.cargo_trip_id
CREATE TABLE cargo_trips (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no       VARCHAR(20) NOT NULL,
  vehicle_id      BIGINT UNSIGNED NOT NULL,
  driver_id       BIGINT UNSIGNED NOT NULL,
  border_point_id BIGINT UNSIGNED NULL,
  clearing_agent_id BIGINT UNSIGNED NULL,
  stage           ENUM('loaded','departed_cairo','at_aswan','crossing_border','in_sudan','arrived','completed','cancelled')
                  NOT NULL DEFAULT 'loaded',
  departed_at     DATETIME NULL,
  arrived_at      DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by      BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cargo_trips_serial (serial_no),
  KEY ix_cargo_trips_stage (stage),
  CONSTRAINT fk_ct_vehicle  FOREIGN KEY (vehicle_id)        REFERENCES vehicles(id),
  CONSTRAINT fk_ct_driver   FOREIGN KEY (driver_id)         REFERENCES drivers(id),
  CONSTRAINT fk_ct_border   FOREIGN KEY (border_point_id)   REFERENCES border_points(id),
  CONSTRAINT fk_ct_clearing FOREIGN KEY (clearing_agent_id) REFERENCES clearing_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Bus / flight / ship (flight & ship use it as a seat quota)
CREATE TABLE trips (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind              ENUM('bus','flight','ship') NOT NULL,
  route             VARCHAR(150) NOT NULL,
  depart_at         DATETIME NOT NULL,
  checkin_at        DATETIME NULL,
  vehicle_id        BIGINT UNSIGNED NULL,
  driver_id         BIGINT UNSIGNED NULL,
  capacity          SMALLINT UNSIGNED NOT NULL,
  passenger_seats   SMALLINT UNSIGNED NOT NULL,
  express_seats     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  express_cutoff_at DATETIME NULL,                       -- BS-09 (open point 3)
  price             DECIMAL(14,2) NOT NULL,
  currency_id       TINYINT UNSIGNED NOT NULL,
  status            ENUM('open','express_locked','departed','completed','cancelled') NOT NULL DEFAULT 'open',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by        BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_trips_depart (depart_at),
  KEY ix_trips_kind_status (kind, status),
  CONSTRAINT fk_trips_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CONSTRAINT fk_trips_driver  FOREIGN KEY (driver_id)  REFERENCES drivers(id),
  CONSTRAINT fk_trips_cur     FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT ck_trips_seats   CHECK (passenger_seats + express_seats <= capacity),
  CONSTRAINT ck_trips_price   CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- SH-10 / TK-03: date of every stage change
CREATE TABLE trip_stage_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trip_type     ENUM('cargo','passenger') NOT NULL,
  trip_id       BIGINT UNSIGNED NOT NULL,                -- cargo_trips.id or trips.id by trip_type
  stage         VARCHAR(40) NOT NULL,
  happened_at   DATETIME NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
  notes         VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_tse_trip (trip_type, trip_id, happened_at),
  CONSTRAINT fk_tse_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
