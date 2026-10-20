-- 007 Documents archive (AU-02/03) and WhatsApp log

SET NAMES utf8mb4;

CREATE TABLE documents (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type             ENUM('shipment_form','receipt','waybill','manifest','transfer_form','driver_undertaking',
                        'trip_movement','voucher','bus_ticket','passenger_manifest','refund_receipt') NOT NULL,
  serial_no        VARCHAR(20) NOT NULL,
  subject_type     VARCHAR(40) NOT NULL,
  subject_id       BIGINT UNSIGNED NOT NULL,
  version          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status           ENUM('issued','locked','edit_requested','superseded') NOT NULL DEFAULT 'issued',
  pdf_path         VARCHAR(255) NOT NULL,         -- generated once, reprints serve this file
  locked_at        DATETIME NULL,
  whatsapp_sent_at DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by       BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_documents_serial_version (type, serial_no, version),
  KEY ix_documents_subject (subject_type, subject_id),
  KEY ix_documents_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE edit_requests (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id BIGINT UNSIGNED NOT NULL,
  reason      VARCHAR(255) NOT NULL,
  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_by BIGINT UNSIGNED NOT NULL,
  decided_by  BIGINT UNSIGNED NULL,
  decided_at  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_er_status (status),
  CONSTRAINT fk_er_doc FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_er_req FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT fk_er_dec FOREIGN KEY (decided_by)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE whatsapp_messages (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_phone     VARCHAR(20) NOT NULL,
  template     VARCHAR(60) NOT NULL,
  payload      JSON NULL,
  document_id  BIGINT UNSIGNED NULL,
  status       ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'queued',
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  provider_message_id VARCHAR(100) NULL,
  error        VARCHAR(255) NULL,
  sent_at      DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_wa_status (status, created_at),
  KEY ix_wa_phone (to_phone),
  CONSTRAINT fk_wa_doc FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
