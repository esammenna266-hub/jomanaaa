-- 009 Reference seed: currencies, roles, permissions, sequences, settings, lookups
-- No users are seeded: the first owner account is created by the app installer (needs a real password hash).

SET NAMES utf8mb4;

INSERT INTO currencies (code, name, name_ar) VALUES
  ('EGP','Egyptian Pound','جنيه مصري'),
  ('USD','US Dollar','دولار'),
  ('SDG','Sudanese Pound','جنيه سوداني');

INSERT INTO branches (name, country, timezone, is_main_warehouse) VALUES
  ('مخزن فيصل', 'EG', 'Africa/Cairo', 1);

INSERT INTO roles (code, name_ar) VALUES
  ('owner','المالك'),
  ('admin','موظف الأدمن'),
  ('branch_staff','موظف الفرع'),
  ('faisal_staff','موظف فيصل'),
  ('accountant','الحسابات'),
  ('agent','المكتب الخارجي');

INSERT INTO permissions (code, name_ar) VALUES
  ('branch.manage','إدارة الفروع'),
  ('user.manage','إدارة المستخدمين'),
  ('masterdata.view','عرض البيانات الأساسية'),
  ('masterdata.manage','إدارة البيانات الأساسية'),
  ('customer.create','تسجيل عميل'),
  ('customer.view','عرض العملاء'),
  ('shipment.create','استمارة شحن وطلب اعتماد سعر'),
  ('shipment.approve_price','اعتماد السعر أو خصم فوق الحد'),
  ('shipment.discount_under_limit','خصم تحت الحد'),
  ('shipment.view','عرض الشحنات'),
  ('shipment.receive','استلام البضاعة في الفرع'),
  ('shipment.cancel','إلغاء شحنة'),
  ('shipment.deliver','تسليم الشحنة للمستلم'),
  ('treasury.manage','إدارة الخزائن'),
  ('payment.reverse','عكس حركة فلوس'),
  ('payment.collect','تحصيل فلوس وجدولة أقساط'),
  ('transfer.create','استمارة خروج لمخزن فيصل'),
  ('transfer.confirm_receipt','تأكيد استلام في فيصل'),
  ('cargo_trip.manage','رحلة شحن وبوليصة وتعهد وبصمة'),
  ('tracking.update','تحديث مراحل التتبع'),
  ('expense.manage','تسجيل وتوزيع المصروفات والعطلات'),
  ('trip.manage','فتح رحلة أتوبيس/طيران/باخرة وكراسي الشحن'),
  ('trip.view','عرض الرحلات وخريطة الكراسي'),
  ('booking.view','عرض الحجوزات'),
  ('booking.create','حجز كرسي'),
  ('booking.view_booker','معرفة مين حجز كل كرسي'),
  ('booking.cancel_refund','إلغاء واسترداد'),
  ('remittance.send','إرسال توريد نقدية'),
  ('remittance.confirm','تأكيد توريد نقدية'),
  ('agent_price.manage','أسعار المكاتب الخارجية'),
  ('treasury.view','أرصدة الخزائن'),
  ('document.request_edit','طلب تعديل مستند'),
  ('document.approve_edit','اعتماد طلب تعديل مستند'),
  ('audit.view','سجل التدقيق'),
  ('report.view','التقارير'),
  ('backup.run','نسخة احتياطية يدوي');

-- Owner: everything, no filter
INSERT INTO role_permissions (role_id, permission_id, scope)
SELECT r.id, p.id, 'all' FROM roles r CROSS JOIN permissions p WHERE r.code = 'owner';

-- Starting point from the handover matrix (section 4). Editable later from the admin screen.
INSERT INTO role_permissions (role_id, permission_id, scope)
SELECT r.id, p.id, m.scope
FROM (
  SELECT 'admin' rc,'branch.manage' pc,'all' scope UNION ALL
  SELECT 'admin','user.manage','all' UNION ALL
  SELECT 'admin','masterdata.manage','all' UNION ALL
  SELECT 'admin','masterdata.view','all' UNION ALL
  SELECT 'admin','customer.create','all' UNION ALL
  SELECT 'admin','customer.view','all' UNION ALL
  SELECT 'branch_staff','customer.view','branch' UNION ALL
  SELECT 'faisal_staff','customer.view','all' UNION ALL
  SELECT 'accountant','customer.view','all' UNION ALL
  SELECT 'agent','customer.view','own' UNION ALL
  SELECT 'admin','report.view','all' UNION ALL
  SELECT 'admin','treasury.manage','all' UNION ALL
  SELECT 'branch_staff','trip.view','all' UNION ALL
  SELECT 'branch_staff','booking.view','branch' UNION ALL
  SELECT 'faisal_staff','trip.view','all' UNION ALL
  SELECT 'faisal_staff','booking.view','all' UNION ALL
  SELECT 'accountant','trip.view','all' UNION ALL
  SELECT 'accountant','booking.view','all' UNION ALL
  SELECT 'agent','trip.view','all' UNION ALL
  SELECT 'agent','booking.view','own' UNION ALL
  SELECT 'branch_staff','shipment.view','branch' UNION ALL
  SELECT 'branch_staff','shipment.receive','branch' UNION ALL
  SELECT 'branch_staff','shipment.cancel','branch' UNION ALL
  SELECT 'faisal_staff','shipment.view','all' UNION ALL
  SELECT 'faisal_staff','shipment.cancel','all' UNION ALL
  SELECT 'accountant','shipment.view','all' UNION ALL
  SELECT 'accountant','treasury.manage','all' UNION ALL
  SELECT 'accountant','payment.reverse','all' UNION ALL

  SELECT 'branch_staff','masterdata.view','all' UNION ALL
  SELECT 'branch_staff','customer.create','all' UNION ALL
  SELECT 'branch_staff','shipment.create','branch' UNION ALL
  SELECT 'branch_staff','shipment.discount_under_limit','all' UNION ALL
  SELECT 'branch_staff','shipment.deliver','all' UNION ALL
  SELECT 'branch_staff','payment.collect','branch' UNION ALL
  SELECT 'branch_staff','transfer.create','branch' UNION ALL
  SELECT 'branch_staff','booking.create','all' UNION ALL
  SELECT 'branch_staff','booking.view_booker','branch' UNION ALL
  SELECT 'branch_staff','booking.cancel_refund','branch' UNION ALL
  SELECT 'branch_staff','remittance.send','branch' UNION ALL
  SELECT 'branch_staff','treasury.view','branch' UNION ALL
  SELECT 'branch_staff','document.request_edit','branch' UNION ALL
  SELECT 'branch_staff','report.view','branch' UNION ALL

  SELECT 'faisal_staff','masterdata.view','all' UNION ALL
  SELECT 'faisal_staff','customer.create','all' UNION ALL
  SELECT 'faisal_staff','shipment.approve_price','all' UNION ALL
  SELECT 'faisal_staff','shipment.discount_under_limit','all' UNION ALL
  SELECT 'faisal_staff','transfer.confirm_receipt','all' UNION ALL
  SELECT 'faisal_staff','cargo_trip.manage','all' UNION ALL
  SELECT 'faisal_staff','tracking.update','all' UNION ALL
  SELECT 'faisal_staff','expense.manage','all' UNION ALL
  SELECT 'faisal_staff','trip.manage','all' UNION ALL
  SELECT 'faisal_staff','document.request_edit','all' UNION ALL

  SELECT 'accountant','masterdata.view','all' UNION ALL
  SELECT 'accountant','payment.collect','all' UNION ALL
  SELECT 'accountant','expense.manage','all' UNION ALL
  SELECT 'accountant','remittance.confirm','all' UNION ALL
  SELECT 'accountant','treasury.view','all' UNION ALL
  SELECT 'accountant','report.view','all' UNION ALL

  SELECT 'agent','customer.create','own' UNION ALL
  SELECT 'agent','shipment.create','own' UNION ALL
  SELECT 'agent','shipment.view','own' UNION ALL
  SELECT 'agent','booking.create','own' UNION ALL
  SELECT 'agent','booking.view_booker','own' UNION ALL
  SELECT 'agent','report.view','own'
) m
JOIN roles r ON r.code = m.rc
JOIN permissions p ON p.code = m.pc;

-- One sequence per printed document type (NF-05)
INSERT INTO document_sequences (doc_type, next_no) VALUES
  ('shipment_form',1),('receipt',1),('waybill',1),('manifest',1),('transfer_form',1),
  ('driver_undertaking',1),('trip_movement',1),('voucher',1),('bus_ticket',1),
  ('passenger_manifest',1),('refund_receipt',1),
  ('payment',1),('booking',1),('remittance',1),('service_booking',1),('cargo_trip',1);

INSERT INTO settings (`key`, value, description) VALUES
  ('discount_limit_amount','0','حد الخصم: فوقه يحتاج اعتماد المالك (يتحدد من العميل)'),
  ('late_alert_days_1','5','أول مدة تنبيه تأخير (يوم)'),
  ('late_alert_days_2','35','تاني مدة تنبيه تأخير (يوم)'),
  ('default_express_cutoff_hours','0','معاد قفل كراسي الشحن قبل الرحلة بالساعات (نقطة مفتوحة 3)');

INSERT INTO border_points (name) VALUES ('أرقين'),('حلفا'),('حدربة');
INSERT INTO destinations (name, country) VALUES ('أم درمان','SD'),('ود مدني','SD');
