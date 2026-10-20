-- 008 Integrity: append-only guards and derived-balance views

SET NAMES utf8mb4;

-- Money and audit trail can never be edited or removed (AU-01, AC rule: fix with a reversal row)
CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
CREATE TRIGGER trg_payments_no_update BEFORE UPDATE ON payments FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments is append-only: post a reversal instead';
CREATE TRIGGER trg_payments_no_delete BEFORE DELETE ON payments FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payments is append-only: post a reversal instead';

-- Treasury balance per (treasury) = sum(in) - sum(out)
CREATE VIEW v_treasury_balances AS
SELECT t.id            AS treasury_id,
       t.branch_id,
       t.currency_id,
       t.method,
       COALESCE(SUM(CASE p.direction WHEN 'in' THEN p.amount ELSE -p.amount END), 0) AS balance
FROM treasuries t
LEFT JOIN payments p ON p.treasury_id = t.id
GROUP BY t.id, t.branch_id, t.currency_id, t.method;

-- Net money collected per shipment per payment currency (collections minus refunds, reversals net out)
CREATE VIEW v_shipment_collected AS
SELECT p.shipment_id,
       p.currency_id,
       SUM(CASE p.direction WHEN 'in' THEN p.amount ELSE -p.amount END) AS net_collected
FROM payments p
WHERE p.shipment_id IS NOT NULL
GROUP BY p.shipment_id, p.currency_id;

-- Open point 1 (assumption): remaining is tracked in the shipment's own currency.
-- Payments in another currency show up in v_shipment_collected; the app lets staff pick which remaining they reduce.
CREATE VIEW v_shipment_balances AS
SELECT s.id AS shipment_id,
       s.serial_no,
       s.branch_id,
       s.agent_id,
       s.customer_id,
       s.currency_id,
       (s.price - s.discount)                                   AS total_due,
       COALESCE(c.net_collected, 0)                             AS paid_same_currency,
       (s.price - s.discount) - COALESCE(c.net_collected, 0)    AS remaining
FROM shipments s
LEFT JOIN v_shipment_collected c ON c.shipment_id = s.id AND c.currency_id = s.currency_id
WHERE s.status NOT IN ('draft','cancelled');

CREATE VIEW v_booking_balances AS
SELECT b.id AS booking_id,
       b.serial_no,
       b.trip_id,
       b.branch_id,
       b.agent_id,
       b.currency_id,
       b.sale_price AS total_due,
       COALESCE(SUM(CASE p.direction WHEN 'in' THEN p.amount ELSE -p.amount END), 0) AS paid,
       b.sale_price - COALESCE(SUM(CASE p.direction WHEN 'in' THEN p.amount ELSE -p.amount END), 0) AS remaining
FROM bookings b
LEFT JOIN payments p ON p.booking_id = b.id AND p.currency_id = b.currency_id
GROUP BY b.id, b.serial_no, b.trip_id, b.branch_id, b.agent_id, b.currency_id, b.sale_price;

-- EX-02 guard: every row returned here is an expense whose allocations do not add up to its total
CREATE VIEW v_expense_allocation_gaps AS
SELECT e.id AS trip_expense_id, e.cargo_trip_id, e.amount AS expense_total,
       COALESCE(SUM(a.amount), 0) AS allocated,
       e.amount - COALESCE(SUM(a.amount), 0) AS gap
FROM trip_expenses e
LEFT JOIN expense_allocations a ON a.trip_expense_id = e.id
GROUP BY e.id, e.cargo_trip_id, e.amount
HAVING gap <> 0;

-- Seat map: seats still free on a trip (numbers 1..passenger_seats minus live bookings)
CREATE VIEW v_trip_seat_usage AS
SELECT t.id AS trip_id, t.kind, t.route, t.depart_at, t.status,
       t.passenger_seats, t.express_seats,
       COUNT(b.id) AS booked_seats,
       t.passenger_seats - COUNT(b.id) AS free_passenger_seats
FROM trips t
LEFT JOIN bookings b ON b.trip_id = t.id AND b.status IN ('reserved','paid','travelled')
GROUP BY t.id, t.kind, t.route, t.depart_at, t.status, t.passenger_seats, t.express_seats;
