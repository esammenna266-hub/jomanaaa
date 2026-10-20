// Fills a fresh Gomana database with a believable story for a demo, through the public API (so every rule is checked).
//
//   node database/demo/demo-data.mjs <api url> <owner username> <owner password>
//   node database/demo/demo-data.mjs http://127.0.0.1:8000 owner "the owner password"
//
// It refuses to run twice (it stops when the demo branches already exist). New logins are written to
// DEMO_ACCOUNTS.local.txt next to this project's backend; that file is ignored by git.
import { randomBytes } from 'node:crypto';
import { appendFileSync } from 'node:fs';

const [base = 'http://127.0.0.1:8000', ownerName = 'owner', ownerPassword] = process.argv.slice(2);
if (!ownerPassword) {
  console.error('usage: node database/demo/demo-data.mjs <api url> <owner username> <owner password>');
  process.exit(1);
}

const api = `${base.replace(/\/$/, '')}/api/v1`;
const accountsFile = new URL('../../DEMO_ACCOUNTS.local.txt', import.meta.url);

async function call(token, method, path, body) {
  const response = await fetch(`${api}${path}`, {
    method,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    throw new Error(`${method} ${path} -> ${response.status} ${JSON.stringify(payload)}`);
  }
  return payload;
}

const login = async (username, password) => (await call(null, 'POST', '/auth/login', { username, password })).token;
const password = () => randomBytes(9).toString('base64url');
const inDays = (days, hour = 8) => {
  const date = new Date(Date.now() + days * 86_400_000);
  date.setHours(hour, 0, 0, 0);
  return date.toISOString();
};

const owner = await login(ownerName, ownerPassword);
const O = (method, path, body) => call(owner, method, path, body);

if ((await O('GET', '/branches?per_page=100')).data.some((branch) => branch.name === 'فرع القاهرة')) {
  console.error('The demo data is already there (branch "فرع القاهرة" exists). Nothing was changed.');
  process.exit(1);
}

console.log('branches and treasuries');
const cairo = (await O('POST', '/branches', { name: 'فرع القاهرة', country: 'EG', phone: '+20225551234', address: 'العباسية، القاهرة', is_main_warehouse: false })).data;
const aswan = (await O('POST', '/branches', { name: 'فرع أسوان', country: 'EG', phone: '+20972551234', address: 'كورنيش النيل، أسوان', is_main_warehouse: false })).data;
// The main warehouse (there can be only one) comes with the base data; create it only on a database that lacks it.
const existing = (await O('GET', '/branches?per_page=100')).data.find((branch) => branch.is_main_warehouse);
const faisal = existing ?? (await O('POST', '/branches', { name: 'مخزن فيصل', country: 'EG', phone: '+20237771234', address: 'شارع فيصل، الجيزة', is_main_warehouse: true })).data;
const treasuries = {};
for (const [key, branch, currency, method] of [
  ['cairoEgp', cairo, 1, 'cash'], ['cairoUsd', cairo, 2, 'cash'], ['cairoInsta', cairo, 1, 'instapay'],
  ['aswanEgp', aswan, 1, 'cash'], ['faisalEgp', faisal, 1, 'cash'], ['faisalUsd', faisal, 2, 'cash'],
]) {
  treasuries[key] = (await O('POST', '/treasuries', { branch_id: branch.id, currency_id: currency, method })).data;
}

console.log('people and logins');
const roles = Object.fromEntries((await O('GET', '/roles')).data.map((role) => [role.code, role.id]));
const office = (await O('POST', '/agents', { name: 'مكتب النور للسفريات', country: 'SD', phone: '+249912340000' })).data;
const logins = [];
const makeUser = async (username, name, role, extra) => {
  const secret = password();
  await O('POST', '/users', { name, username, password: secret, role_id: roles[role], ...extra });
  logins.push(`${username} : ${secret}   (${name}، ${role})`);
  return login(username, secret);
};
const sara = await makeUser('sara', 'سارة أحمد', 'branch_staff', { branch_id: cairo.id });
await makeUser('omar', 'عمر حسن', 'branch_staff', { branch_id: aswan.id });
await makeUser('faisal', 'محمود فيصل', 'faisal_staff', { branch_id: faisal.id });
await makeUser('hesabat', 'منى الحسابات', 'accountant', {});
const alnoor = await makeUser('alnoor', 'مكتب النور', 'agent', { agent_id: office.id });

console.log('vehicles, drivers, customers');
const bus = (await O('POST', '/vehicles', { plate_no: 'أ ب ج 4521', category: 'bus', seats: 49, owner_name: 'الجومانا', ownership: 'owned' })).data;
await O('POST', '/vehicles', { plate_no: 'ن ص ع 1234', category: 'jumbo', body: 'closed', max_ton: 22, owner_name: 'الجومانا', ownership: 'owned' });
await O('POST', '/vehicles', { plate_no: 'س ك ل 8870', category: 'trailer', body: 'open', max_ton: 30, owner_name: 'شركة النيل للنقل', ownership: 'rented' });
const driver = (await O('POST', '/drivers', { name: 'حسن إبراهيم', phone: '+201098765432', license_no: 'L-778812', id_type: 'national_id', id_no: '27501011234567', is_international: true })).data;
await O('POST', '/drivers', { name: 'عبد الله سليمان', phone: '+201112223344', license_no: 'L-445590', id_type: 'passport', id_no: 'A1234567', is_international: true });
await O('POST', '/clearing-agents', { name: 'مكتب الأمين للتخليص الجمركي', phone: '+201005556677' });

const customers = [];
for (const [name, phone, country, passport] of [
  ['سعد محمود', '+201012345671', 'EG', null], ['منى علي', '+201012345672', 'EG', null], ['هبة إبراهيم', '+201012345673', 'EG', null],
  ['أحمد عثمان', '+249912345674', 'SD', 'P0049912'], ['ياسر الطيب', '+249912345675', 'SD', 'P0051877'], ['خالد عبد الله', '+249912345676', 'SD', null],
]) {
  customers.push((await call(sara, 'POST', '/customers', { name, phone, country, passport_no: passport })).data);
}

console.log('office price list');
await O('POST', `/agents/${office.id}/prices`, { service: 'bus', route: 'القاهرة - الخرطوم', price: '2300', currency_id: 1 });

console.log('bus trip, seats and express cargo');
const destinations = Object.fromEntries((await O('GET', '/destinations?per_page=100')).data.map((d) => [d.name, d.id]));
const trip = (await O('POST', '/trips', {
  kind: 'bus', route: 'القاهرة - الخرطوم', depart_at: inDays(4, 6), checkin_at: inDays(4, 3), vehicle_id: bus.id, driver_id: driver.id,
  passenger_seats: 45, express_seats: 4, express_cutoff_at: inDays(3, 18), price: '2500', currency_id: 1,
})).data;
const book = (token, seat, name, passport, destination, extra) =>
  call(token, 'POST', '/bookings', { trip_id: trip.id, seat_no: seat, passenger_name: name, passport_no: passport, destination, ...extra });
await book(sara, 5, 'أحمد عثمان محمد', 'P0049912', 'الخرطوم', { customer_id: customers[3].id, treasury_id: treasuries.cairoEgp.id });
await book(sara, 6, 'منى الطيب', 'P0066120', 'أم درمان', { treasury_id: treasuries.cairoEgp.id });
await book(sara, 12, 'ياسر الطيب', 'P0051877', 'ود مدني', { customer_id: customers[4].id, treasury_id: treasuries.cairoEgp.id });
await book(alnoor, 20, 'عثمان إدريس', 'P0071234', 'الخرطوم', { sale_price: '2700' });
await book(alnoor, 21, 'زينب آدم', 'P0071235', 'الخرطوم', { sale_price: '2700' });

console.log('shipments');
const shipmentBody = (customer, consignee, phone, destination, price, items, extra = {}) => ({
  customer_id: customer.id, consignee_name: consignee, consignee_phone: phone, destination_id: destinations[destination],
  currency_id: 1, price: String(price), items, ...extra,
});
const decide = async (shipment) => {
  const pending = (await O('GET', '/approvals?status=pending&per_page=100')).data.find((a) => a.subject_id === shipment.id && a.status === 'pending');
  if (pending) await O('POST', `/approvals/${pending.id}/decide`, { decision: 'approve' });
};

// 1. a draft the clerk has not finished
await call(sara, 'POST', '/shipments', shipmentBody(customers[0], 'عمر الطيب', '+249912000001', 'أم درمان', 4200, [{ name: 'ملابس', qty: 12, unit: 'carton' }]));

// 2. approved, half paid, received in the branch
const s2 = (await call(sara, 'POST', '/shipments', shipmentBody(customers[1], 'ياسر عثمان', '+249912000002', 'ود مدني', 8600, [{ name: 'أدوات منزلية', qty: 20, unit: 'carton' }, { name: 'أحذية', qty: 6, unit: 'carton' }]))).data;
await call(sara, 'POST', `/shipments/${s2.id}/submit`);
await decide(s2);
await call(sara, 'POST', '/payments', { shipment_id: s2.id, treasury_id: treasuries.cairoEgp.id, amount: '4000' });
await call(sara, 'POST', `/shipments/${s2.id}/receive`);

// 3. approved and paid in full
const s3 = (await call(sara, 'POST', '/shipments', shipmentBody(customers[2], 'سلمى يوسف', '+249912000003', 'الخرطوم', 5300, [{ name: 'مستحضرات تجميل', qty: 8, unit: 'carton' }]))).data;
await call(sara, 'POST', `/shipments/${s3.id}/submit`);
await decide(s3);
await call(sara, 'POST', '/payments', { shipment_id: s3.id, treasury_id: treasuries.cairoEgp.id, amount: '5300' });
await call(sara, 'POST', `/shipments/${s3.id}/receive`);

// 4. waiting for the owner's approval
const s4 = (await call(sara, 'POST', '/shipments', shipmentBody(customers[3], 'مصطفى أحمد', '+249912000004', 'بورتسودان', 12400, [{ name: 'قطع غيار سيارات', qty: 40, unit: 'carton' }]))).data;
await call(sara, 'POST', `/shipments/${s4.id}/submit`);

// 5. an express parcel riding the bus trip
const s5 = (await call(sara, 'POST', '/shipments', shipmentBody(customers[4], 'هالة الطيب', '+249912000005', 'الخرطوم', 900, [{ name: 'مستندات وهدايا', qty: 1, unit: 'pack' }], { type: 'express', bus_trip_id: trip.id }))).data;
await call(sara, 'POST', `/shipments/${s5.id}/submit`);
await decide(s5);
await call(sara, 'POST', '/payments', { shipment_id: s5.id, treasury_id: treasuries.cairoEgp.id, amount: '900' });

console.log('hotel booking');
await call(sara, 'POST', '/service-bookings', {
  type: 'hotel', customer_id: customers[5].id, details: 'فندق النيل، القاهرة: غرفتين لمدة 3 ليالي، إفطار مشمول',
  start_date: inDays(6).slice(0, 10), end_date: inDays(9).slice(0, 10), price: '6500', currency_id: 1, treasury_id: treasuries.cairoEgp.id,
});

appendFileSync(accountsFile, `\n${logins.join('\n')}\n`);
console.log(`\ndone. ${logins.length} logins were added to DEMO_ACCOUNTS.local.txt`);
