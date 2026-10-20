import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import { fakeUser, renderApp, type MockRoute } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const owner = fakeUser({ 'report.view': 'all' });
const office = fakeUser({ 'report.view': 'own', 'booking.view': 'own' }, { branch: null, agent: { id: 4, name: 'مكتب النور' } });

const debts = {
  data: {
    title: 'المديونيات',
    columns: [
      { key: 'customer', label: 'العميل' },
      { key: 'remaining', label: 'المتبقي' },
    ],
    rows: [
      { customer: 'سعد محمود', remaining: '1200.00' },
      { customer: 'منى علي', remaining: '300.00' },
    ],
  },
};

const treasuries = {
  data: { title: 'أرصدة الخزائن', columns: [{ key: 'branch', label: 'الفرع' }], rows: [{ branch: 'فرع القاهرة' }] },
};

const dashboard = {
  data: {
    treasury_totals: [{ currency: 'جنيه مصري', amount: '1250.00' }],
    collected_today: [{ currency: 'جنيه مصري', amount: '1000.00' }],
    open_shipments: { at_branch: 2, loaded: 1 },
    pending_approvals: 3,
    overdue_installments: 4,
    pending_remittances: 0,
    cargo_trips_on_the_road: 2,
    passenger_trips_open: 5,
    agents_owing: 1,
  },
};

const reportRoutes = (extra: MockRoute[] = []): MockRoute[] => [
  { path: '/reports/treasury-balances', body: treasuries },
  { path: '/reports/debts', body: debts },
  { path: '/reports/refunds', body: { data: { title: 'المسترد', columns: [{ key: 'serial', label: 'رقم الحركة' }], rows: [] } } },
  ...extra,
];

describe('reports page', () => {
  it('opens on the first report and switches to another one', async () => {
    const user = userEvent.setup();
    renderApp('/reports', { user: owner, routes: reportRoutes() });

    expect(await screen.findByRole('heading', { name: 'أرصدة الخزائن' })).toBeInTheDocument();
    expect(screen.getByText('فرع القاهرة')).toBeInTheDocument();

    await user.selectOptions(screen.getByRole('combobox', { name: ar.reports.report }), 'debts');
    expect(await screen.findByRole('heading', { name: 'المديونيات' })).toBeInTheDocument();
    expect(screen.getByText('سعد محمود')).toBeInTheDocument();
    expect(screen.getByText('1200.00')).toBeInTheDocument();
  });

  it('shows the date range only for reports about a period and sends it to the server', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/reports', { user: owner, routes: reportRoutes() });

    await screen.findByRole('heading', { name: 'أرصدة الخزائن' });
    expect(screen.queryByLabelText(ar.reports.from)).not.toBeInTheDocument();

    await user.selectOptions(screen.getByRole('combobox', { name: ar.reports.report }), 'refunds');
    await user.type(await screen.findByLabelText(ar.reports.from), '2026-10-01');
    await user.type(screen.getByLabelText(ar.reports.to), '2026-10-31');

    await waitFor(() => {
      const last = calls.filter((call) => call.path === '/reports/refunds').at(-1)?.query;
      expect(last?.get('from')).toBe('2026-10-01');
      expect(last?.get('to')).toBe('2026-10-31');
    });
    expect(await screen.findByText(ar.common.empty)).toBeInTheDocument();
  });

  it('saves the shown report as an Excel workbook, asking the server with the same filters', async () => {
    const user = userEvent.setup();
    const click = vi.fn();
    const createElement = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
      const element = createElement(tag);
      if (tag === 'a') {
        element.click = click;
      }

      return element;
    });
    URL.createObjectURL = vi.fn(() => 'blob:xlsx-url');
    URL.revokeObjectURL = vi.fn();
    const calls = renderApp('/reports', { user: owner, routes: reportRoutes() });

    await user.selectOptions(await screen.findByRole('combobox', { name: ar.reports.report }), 'refunds');
    await user.type(await screen.findByLabelText(ar.reports.from), '2026-10-01');
    await screen.findByText(ar.common.empty);
    await user.click(screen.getByRole('button', { name: ar.reports.excel }));

    await waitFor(() => expect(click).toHaveBeenCalled());
    const request = calls.filter((call) => call.path === '/reports/refunds').find((call) => call.query.get('format') === 'xlsx');
    expect(request?.query.get('from')).toBe('2026-10-01');
  });

  it('opens the report as an A4 PDF in a new tab', async () => {
    const user = userEvent.setup();
    const tab = { location: { href: '' }, close: vi.fn() };
    vi.spyOn(window, 'open').mockReturnValue(tab as unknown as Window);
    URL.createObjectURL = vi.fn(() => 'blob:pdf-url');
    URL.revokeObjectURL = vi.fn();
    const calls = renderApp('/reports', { user: owner, routes: reportRoutes() });

    await screen.findByRole('heading', { name: 'أرصدة الخزائن' });
    await user.click(screen.getByRole('button', { name: ar.reports.pdf }));

    await waitFor(() => expect(tab.location.href).toBe('blob:pdf-url'));
    expect(calls.some((call) => call.path === '/reports/treasury-balances' && call.query.get('format') === 'pdf')).toBe(true);
  });

  it('keeps users without the permission out', async () => {
    renderApp('/reports', { user: fakeUser({ 'shipment.view': 'all' }), routes: [] });

    expect(await screen.findByText(ar.home.intro)).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: ar.reports.title })).not.toBeInTheDocument();
  });

  it('hides the reports menu entry from external offices', async () => {
    renderApp('/', { user: office, routes: [] });

    const menu = await screen.findByRole('navigation', { name: ar.nav.menu });
    expect(within(menu).queryByRole('link', { name: ar.nav.reports })).not.toBeInTheDocument();
  });
});

describe('dashboard on the home page', () => {
  it('shows treasury totals, today and open work to a user who can see all reports', async () => {
    renderApp('/', { user: owner, routes: [{ path: '/reports/dashboard', body: dashboard }] });

    expect(await screen.findByText(ar.dashboard.title)).toBeInTheDocument();
    expect(await screen.findByText('1,250.00')).toBeInTheDocument();
    expect(screen.getByText('1,000.00')).toBeInTheDocument();
    expect(screen.getByText(`${ar.shipments.status.at_branch}:`, { exact: false })).toBeInTheDocument();
    const overdue = screen.getByText(ar.dashboard.overdueInstallments).closest('.card')!;
    expect(within(overdue as HTMLElement).getByText('4')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: new RegExp(ar.dashboard.openPassengerTrips) })).toHaveAttribute('href', '/passenger-trips');
  });

  it('leaves out the figures a branch user does not get', async () => {
    renderApp('/', {
      user: fakeUser({ 'report.view': 'branch' }),
      routes: [{ path: '/reports/dashboard', body: { data: { ...dashboard.data, pending_approvals: null, cargo_trips_on_the_road: null, agents_owing: null } } }],
    });

    await screen.findByText('1,250.00');
    expect(screen.queryByText(ar.dashboard.pendingApprovals)).not.toBeInTheDocument();
    expect(screen.queryByText(ar.dashboard.agentsOwing)).not.toBeInTheDocument();
  });

  it('is not requested for an external office', async () => {
    const calls = renderApp('/', { user: office, routes: [] });

    await screen.findByText(ar.home.intro);
    expect(calls.some((call) => call.path === '/reports/dashboard')).toBe(false);
  });
});
