import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ar } from '../../i18n/ar';
import type { Customer } from '../../shared/api/types';
import { fakeUser, page, renderApp } from '../../test/utils';

afterEach(() => vi.unstubAllGlobals());

const customer = (overrides: Partial<Customer> = {}): Customer => ({
  id: 1,
  name: 'سعد محمود',
  phone: '+201012345678',
  passport_no: null,
  country: 'EG',
  whatsapp_verified_at: null,
  active: true,
  ...overrides,
});

const staff = fakeUser({ 'customer.view': 'branch', 'customer.create': 'all' });

describe('customers screen', () => {
  it('lists customers with phone numbers shown left-to-right', async () => {
    renderApp('/customers', { user: staff, routes: [{ path: '/customers', body: page([customer()]) }] });

    const phone = await screen.findByText('+201012345678');
    expect(phone).toHaveClass('ltr');
    expect(screen.getByText('سعد محمود')).toBeInTheDocument();
    expect(screen.getByText(ar.customers.notVerified)).toBeInTheDocument();
  });

  it('searches by what is typed, after a short pause', async () => {
    const user = userEvent.setup();
    const calls = renderApp('/customers', {
      user: staff,
      routes: [
        {
          path: '/customers',
          body: ({ query }: { query: URLSearchParams }) => page(query.get('q') ? [customer({ name: 'نتيجة البحث' })] : [customer()]),
        },
      ],
    });

    await user.type(await screen.findByRole('searchbox'), '0101');

    expect(await screen.findByText('نتيجة البحث')).toBeInTheDocument();
    const searches = calls.filter((call) => call.path === '/customers').map((call) => call.query.get('q'));
    expect(searches.at(-1)).toBe('0101');
    expect(searches.filter((term) => term !== null && term !== '').length).toBe(1);
  });

  it('shows the server validation message next to the phone field and keeps the form open', async () => {
    const user = userEvent.setup();
    renderApp('/customers', {
      user: staff,
      routes: [
        { path: '/customers', body: page([]) },
        {
          method: 'POST',
          path: '/customers',
          status: 422,
          body: { message: 'x', errors: { phone: ['العميل ده متسجل قبل كده بنفس رقم التليفون'] } },
        },
      ],
    });

    await user.click(await screen.findByRole('button', { name: ar.customers.add }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(ar.customers.name), 'عمر');
    await user.type(within(dialog).getByLabelText(ar.customers.phone), '01012345678');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    expect(await within(dialog).findByText('العميل ده متسجل قبل كده بنفس رقم التليفون')).toBeInTheDocument();
    expect(within(dialog).getByLabelText(ar.customers.phone)).toHaveAttribute('aria-invalid', 'true');
  });

  it('creates a customer, closes the form and reloads the list', async () => {
    const user = userEvent.setup();
    let created = false;
    const calls = renderApp('/customers', {
      user: staff,
      routes: [
        { path: '/customers', body: () => page(created ? [customer({ name: 'عميل جديد' })] : []) },
        {
          method: 'POST',
          path: '/customers',
          status: 201,
          body: () => {
            created = true;

            return { data: customer({ name: 'عميل جديد' }) };
          },
        },
      ],
    });

    await user.click(await screen.findByRole('button', { name: ar.customers.add }));
    const dialog = screen.getByRole('dialog');
    await user.type(within(dialog).getByLabelText(ar.customers.name), '  عميل جديد ');
    await user.type(within(dialog).getByLabelText(ar.customers.phone), '01012345678');
    await user.selectOptions(within(dialog).getByLabelText(new RegExp(ar.customers.country)), 'EG');
    await user.click(within(dialog).getByRole('button', { name: ar.common.save }));

    expect(await screen.findByText('عميل جديد')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(calls.find((call) => call.method === 'POST')?.body).toEqual({
      name: 'عميل جديد',
      phone: '01012345678',
      passport_no: null,
      country: 'EG',
    });
  });

  it('hides add and edit for users who can only view', async () => {
    renderApp('/customers', {
      user: fakeUser({ 'customer.view': 'branch' }),
      routes: [{ path: '/customers', body: page([customer()]) }],
    });

    await screen.findByText('سعد محمود');
    expect(screen.queryByRole('button', { name: ar.customers.add })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: ar.common.edit })).not.toBeInTheDocument();
  });

  it('closes the form with Escape', async () => {
    const user = userEvent.setup();
    renderApp('/customers', { user: staff, routes: [{ path: '/customers', body: page([]) }] });

    await user.click(await screen.findByRole('button', { name: ar.customers.add }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await user.keyboard('{Escape}');

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});
