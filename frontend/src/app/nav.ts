import { ar } from '../i18n/ar';

export interface NavItem {
  to: string;
  label: string;
  /** Hidden unless the user holds this permission (or any of them, when a list is given). */
  permission?: string | string[];
  end?: boolean;
  /** Shown only to external-office accounts. */
  officeOnly?: boolean;
  /** Not shown to external-office accounts. */
  hideForOffice?: boolean;
}

/** One entry per screen. The same permission codes guard the routes in routes.tsx. */
export const navItems: NavItem[] = [
  { to: '/', label: ar.nav.home, end: true },
  { to: '/shipments', label: ar.nav.shipments, permission: 'shipment.view' },
  { to: '/transfers', label: ar.nav.transfers, permission: ['transfer.create', 'transfer.confirm_receipt'] },
  { to: '/trips', label: ar.nav.trips, permission: ['cargo_trip.manage', 'tracking.update'] },
  { to: '/passenger-trips', label: ar.nav.passengerTrips, permission: 'trip.view' },
  { to: '/bookings', label: ar.nav.bookings, permission: 'booking.view' },
  { to: '/service-bookings', label: ar.nav.services, permission: 'booking.view' },
  { to: '/agents', label: ar.nav.agents, permission: 'agent_price.manage' },
  { to: '/treasuries', label: ar.nav.treasuries, permission: 'treasury.view' },
  { to: '/remittances', label: ar.nav.remittances, permission: ['remittance.send', 'remittance.confirm'] },
  { to: '/customers', label: ar.nav.customers, permission: 'customer.view' },
  { to: '/reports', label: ar.nav.reports, permission: 'report.view', hideForOffice: true },
  { to: '/basic-data', label: ar.nav.basicData, permission: 'masterdata.view', hideForOffice: true },
  { to: '/my-account', label: ar.nav.myAccount, officeOnly: true },
  { to: '/branches', label: ar.nav.branches, hideForOffice: true },
  { to: '/users', label: ar.nav.users, permission: 'user.manage' },
];
