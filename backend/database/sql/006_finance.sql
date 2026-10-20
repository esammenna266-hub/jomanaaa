-- 006 Finance (AC, EX): treasuries, ledger, installments, remittances, approvals, expenses, delays
-- Rule: no stored balances. Everything is derived from `payments` (see views in 008).

SET NAMES utf8mb4;

-- One treasury per (branch x currency x method)
CREATE TABLE treasuries (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id   BIGINT UNSIGNED NOT NULL,
  currency_id TINYINT UNSIGNED NOT NULL,
  method      ENUM('cash','instapay','vodafone_cash') NOT NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_treasuries (branch_id, currency_id, method),
  UNIQUE KEY uq_treasuries_cur (id, currency_id),         -- target of the composite FK from payments
  CONSTRAINT fk_trs_branch FOREIGN KEY (branch_id)   REFERENCES branches(id),
  CONSTRAINT fk_trs_cur    FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE installments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shipment_id BIGINT UNSIGNED NULL,
  booking_id  BIGINT UNSIGNED NULL,
  due_date    DATE NOT NULL,
  amount      DECIMAL(14,2) NOT NULL,
  currency_id TINYINT UNSIGNED NOT NULL,
  status      ENUM('due','paid','overdue','cancelled') NOT NULL DEFAULT 'due',
  reminded_at DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by  BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_inst_due (status, due_date),
  KEY ix_inst_shipment (shipment_id),
  KEY ix_inst_booking (booking_id),
  CONSTRAINT fk_inst_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id),
  CONSTRAINT fk_inst_booking  FOREIGN KEY (booking_id)  REFERENCES bookings(id),
  CONSTRAINT fk_inst_cur      FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT ck_inst_amount   CHECK (amount > 0),
  CONSTRAINT ck_inst_subject  CHECK ((shipment_id IS NOT NULL) + (booking_id IS NOT NULL) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The only money ledger. Append-only: corrections are reversal rows (reversal_of_id).
CREATE TABLE payments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no      VARCHAR(20) NOT NULL,
  direction      ENUM('in','out') NOT NULL,
  kind           ENUM('collection','refund','expense','remittance','agent_settlement','manual','reversal') NOT NULL,
  treasury_id    BIGINT UNSIGNED NOT NULL,
  amount         DECIMAL(14,2) NOT NULL,
  currency_id    TINYINT UNSIGNED NOT NULL,        -- must equal the treasury's currency (composite FK)
  payer_type     ENUM('customer','agent','staff','other') NULL,
  payer_id       BIGINT UNSIGNED NULL,
  shipment_id    BIGINT UNSIGNED NULL,
  booking_id     BIGINT UNSIGNED NULL,
  installment_id BIGINT UNSIGNED NULL,
  remittance_id  BIGINT UNSIGNED NULL,
  reversal_of_id BIGINT UNSIGNED NULL,
  notes          VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_serial (serial_no),
  UNIQUE KEY uq_payments_reversal (reversal_of_id),    -- a payment can be reversed once
  KEY ix_payments_treasury_at (treasury_id, created_at),
  KEY ix_payments_shipment (shipment_id),
  KEY ix_payments_booking (booking_id),
  KEY ix_payments_payer (payer_type, payer_id),
  CONSTRAINT fk_pay_treasury FOREIGN KEY (treasury_id, currency_id) REFERENCES treasuries(id, currency_id),
  CONSTRAINT fk_pay_shipment FOREIGN KEY (shipment_id)    REFERENCES shipments(id),
  CONSTRAINT fk_pay_booking  FOREIGN KEY (booking_id)     REFERENCES bookings(id),
  CONSTRAINT fk_pay_inst     FOREIGN KEY (installment_id) REFERENCES installments(id),
  CONSTRAINT fk_pay_reversal FOREIGN KEY (reversal_of_id) REFERENCES payments(id),
  CONSTRAINT fk_pay_user     FOREIGN KEY (created_by)     REFERENCES users(id),
  CONSTRAINT ck_pay_amount   CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- AC-06: cash handover branch -> main treasury; receiving treasury only grows once confirmed
CREATE TABLE remittances (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  serial_no        VARCHAR(20) NOT NULL,
  from_treasury_id BIGINT UNSIGNED NOT NULL,
  to_treasury_id   BIGINT UNSIGNED NOT NULL,
  amount           DECIMAL(14,2) NOT NULL,
  currency_id      TINYINT UNSIGNED NOT NULL,
  status           ENUM('sent','confirmed','rejected') NOT NULL DEFAULT 'sent',
  sent_by          BIGINT UNSIGNED NOT NULL,
  confirmed_by     BIGINT UNSIGNED NULL,
  sent_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at     DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_remit_serial (serial_no),
  CONSTRAINT fk_rm_from FOREIGN KEY (from_treasury_id, currency_id) REFERENCES treasuries(id, currency_id),
  CONSTRAINT fk_rm_to   FOREIGN KEY (to_treasury_id, currency_id)   REFERENCES treasuries(id, currency_id),
  CONSTRAINT fk_rm_sent FOREIGN KEY (sent_by)      REFERENCES users(id),
  CONSTRAINT fk_rm_conf FOREIGN KEY (confirmed_by) REFERENCES users(id),
  CONSTRAINT ck_rm_amount CHECK (amount > 0 AND from_treasury_id <> to_treasury_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE payments
  ADD CONSTRAINT fk_pay_remit FOREIGN KEY (remittance_id) REFERENCES remittances(id);

-- All approvals in one table (price / discount over the limit / document edit)
CREATE TABLE approvals (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type         ENUM('price','discount','edit') NOT NULL,
  subject_type VARCHAR(40) NOT NULL,
  subject_id   BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  value        DECIMAL(14,2) NULL,
  status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by   BIGINT UNSIGNED NULL,
  decided_at   DATETIME NULL,
  reason       VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_appr_status (status, type),
  KEY ix_appr_subject (subject_type, subject_id),
  CONSTRAINT fk_appr_req FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT fk_appr_dec FOREIGN KEY (decided_by)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- EX: total spent on a truck, allocated across its shipments
CREATE TABLE trip_expenses (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cargo_trip_id BIGINT UNSIGNED NOT NULL,
  category      VARCHAR(60) NOT NULL,
  point         VARCHAR(100) NULL,
  amount        DECIMAL(14,2) NOT NULL,
  currency_id   TINYINT UNSIGNED NOT NULL,
  treasury_id   BIGINT UNSIGNED NULL,
  payment_id    BIGINT UNSIGNED NULL,
  voided_at     DATETIME NULL,                  -- a mistaken expense is voided (its payment is reversed), never deleted
  voided_by     BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by    BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_te_trip (cargo_trip_id),
  CONSTRAINT fk_te_voider   FOREIGN KEY (voided_by)     REFERENCES users(id),
  CONSTRAINT fk_te_trip     FOREIGN KEY (cargo_trip_id) REFERENCES cargo_trips(id),
  CONSTRAINT fk_te_treasury FOREIGN KEY (treasury_id, currency_id) REFERENCES treasuries(id, currency_id),
  CONSTRAINT fk_te_payment  FOREIGN KEY (payment_id)    REFERENCES payments(id),
  CONSTRAINT ck_te_amount   CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- EX-02: sum of allocations must equal the expense total (enforced by the app before save + checked in 008)
CREATE TABLE expense_allocations (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trip_expense_id BIGINT UNSIGNED NOT NULL,
  shipment_id     BIGINT UNSIGNED NOT NULL,
  amount          DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ea (trip_expense_id, shipment_id),
  CONSTRAINT fk_ea_expense  FOREIGN KEY (trip_expense_id) REFERENCES trip_expenses(id),
  CONSTRAINT fk_ea_shipment FOREIGN KEY (shipment_id)     REFERENCES shipments(id),
  CONSTRAINT ck_ea_amount   CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- SH-09: delays (open point 7: recorded only; allocated like an expense when the customer is responsible)
CREATE TABLE trip_delays (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cargo_trip_id BIGINT UNSIGNED NOT NULL,
  days          SMALLINT UNSIGNED NOT NULL,
  cost          DECIMAL(14,2) NOT NULL DEFAULT 0,
  currency_id   TINYINT UNSIGNED NULL,
  responsible   ENUM('customer','driver','company') NOT NULL,
  notes         VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by    BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_td_trip (cargo_trip_id),
  CONSTRAINT fk_td_trip FOREIGN KEY (cargo_trip_id) REFERENCES cargo_trips(id),
  CONSTRAINT fk_td_cur  FOREIGN KEY (currency_id)   REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
