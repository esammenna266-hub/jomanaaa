import { Route, Routes } from 'react-router-dom';
import { BookingsPage } from '../modules/booking/BookingsPage';
import { ServiceBookingsPage } from '../modules/booking/ServiceBookingsPage';
import { PassengerTripFilePage } from '../modules/booking/PassengerTripFilePage';
import { PassengerTripNewPage } from '../modules/booking/PassengerTripNewPage';
import { PassengerTripsPage } from '../modules/booking/PassengerTripsPage';
import { AgentFilePage } from '../modules/core/AgentFilePage';
import { AgentsPage } from '../modules/core/AgentsPage';
import { BranchesPage } from '../modules/core/BranchesPage';
import { MyAccountPage } from '../modules/core/MyAccountPage';
import { HomePage } from '../modules/home/HomePage';
import { ChangePasswordPage } from '../modules/identity/ChangePasswordPage';
import { LoginPage } from '../modules/identity/LoginPage';
import { UsersPage } from '../modules/identity/UsersPage';
import { CustomersPage } from '../modules/master-data/CustomersPage';
import { BasicDataPage } from '../modules/master-data/BasicDataPage';
import { RemittancesPage } from '../modules/finance/RemittancesPage';
import { TreasuriesPage } from '../modules/finance/TreasuriesPage';
import { ShipmentFilePage } from '../modules/shipping/ShipmentFilePage';
import { ShipmentFormPage } from '../modules/shipping/ShipmentFormPage';
import { ShipmentsPage } from '../modules/shipping/ShipmentsPage';
import { TransferFilePage } from '../modules/shipping/TransferFilePage';
import { TransferNewPage } from '../modules/shipping/TransferNewPage';
import { TransfersPage } from '../modules/shipping/TransfersPage';
import { TripFilePage } from '../modules/trips/TripFilePage';
import { TripNewPage } from '../modules/trips/TripNewPage';
import { TripsPage } from '../modules/trips/TripsPage';
import { ReportsPage } from '../modules/reports/ReportsPage';
import { RequireAuth, RequirePermission } from './auth/guards';
import { Layout } from './Layout';

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route element={<RequireAuth />}>
        <Route path="/change-password" element={<ChangePasswordPage />} />

        <Route element={<Layout />}>
          <Route index element={<HomePage />} />
          <Route path="branches" element={<BranchesPage />} />
          <Route path="my-account" element={<MyAccountPage />} />

          <Route element={<RequirePermission permission="shipment.view" />}>
            <Route path="shipments" element={<ShipmentsPage />} />
            <Route path="shipments/:id" element={<ShipmentFilePage />} />
          </Route>
          <Route element={<RequirePermission permission="shipment.create" />}>
            <Route path="shipments/new" element={<ShipmentFormPage />} />
            <Route path="shipments/:id/edit" element={<ShipmentFormPage />} />
          </Route>

          <Route element={<RequirePermission permission={['transfer.create', 'transfer.confirm_receipt']} />}>
            <Route path="transfers" element={<TransfersPage />} />
            <Route path="transfers/:id" element={<TransferFilePage />} />
          </Route>
          <Route element={<RequirePermission permission="transfer.create" />}>
            <Route path="transfers/new" element={<TransferNewPage />} />
          </Route>

          <Route element={<RequirePermission permission={['cargo_trip.manage', 'tracking.update']} />}>
            <Route path="trips" element={<TripsPage />} />
            <Route path="trips/:id" element={<TripFilePage />} />
          </Route>
          <Route element={<RequirePermission permission="cargo_trip.manage" />}>
            <Route path="trips/new" element={<TripNewPage />} />
          </Route>

          <Route element={<RequirePermission permission="trip.view" />}>
            <Route path="passenger-trips" element={<PassengerTripsPage />} />
            <Route path="passenger-trips/:id" element={<PassengerTripFilePage />} />
          </Route>
          <Route element={<RequirePermission permission="trip.manage" />}>
            <Route path="passenger-trips/new" element={<PassengerTripNewPage />} />
          </Route>
          <Route element={<RequirePermission permission="booking.view" />}>
            <Route path="bookings" element={<BookingsPage />} />
            <Route path="service-bookings" element={<ServiceBookingsPage />} />
          </Route>

          <Route element={<RequirePermission permission="masterdata.view" />}>
            <Route path="basic-data" element={<BasicDataPage />} />
          </Route>

          <Route element={<RequirePermission permission="report.view" />}>
            <Route path="reports" element={<ReportsPage />} />
          </Route>

          <Route element={<RequirePermission permission="agent_price.manage" />}>
            <Route path="agents" element={<AgentsPage />} />
            <Route path="agents/:id" element={<AgentFilePage />} />
          </Route>

          <Route element={<RequirePermission permission="treasury.view" />}>
            <Route path="treasuries" element={<TreasuriesPage />} />
          </Route>
          <Route element={<RequirePermission permission={['remittance.send', 'remittance.confirm']} />}>
            <Route path="remittances" element={<RemittancesPage />} />
          </Route>

          <Route element={<RequirePermission permission="customer.view" />}>
            <Route path="customers" element={<CustomersPage />} />
          </Route>

          <Route element={<RequirePermission permission="user.manage" />}>
            <Route path="users" element={<UsersPage />} />
          </Route>
        </Route>
      </Route>

      <Route path="*" element={<HomePage />} />
    </Routes>
  );
}
