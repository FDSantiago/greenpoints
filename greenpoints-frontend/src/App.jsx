import { Navigate, Route, Routes } from 'react-router-dom';
import RequireAuth from './auth/RequireAuth.jsx';
import Layout from './components/Layout.jsx';
import Login from './pages/Login.jsx';
import Register from './pages/Register.jsx';
import VerifyEmail from './pages/VerifyEmail.jsx';
import Home from './pages/Home.jsx';
import DropOff from './pages/DropOff.jsx';
import History from './pages/History.jsx';
import SubmissionDetail from './pages/SubmissionDetail.jsx';
import Wallet from './pages/Wallet.jsx';
import Rewards from './pages/Rewards.jsx';
import RewardDetail from './pages/RewardDetail.jsx';
import MyPickups from './pages/MyPickups.jsx';
import Leaderboard from './pages/Leaderboard.jsx';
import Impact from './pages/Impact.jsx';
import Alerts from './pages/Alerts.jsx';
import Review from './pages/staff/Review.jsx';
import Pickups from './pages/staff/Pickups.jsx';
import Overview from './pages/admin/Overview.jsx';
import Users from './pages/admin/Users.jsx';
import { Groups, Categories, AdminRewards } from './pages/admin/Crud.jsx';

export default function App() {
  return (
    <Routes>
      {/* public */}
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />
      {/* signed in, email not verified yet */}
      <Route path="/verify-email" element={<VerifyEmail />} />

      {/* any signed-in user */}
      <Route element={<RequireAuth />}>
        <Route element={<Layout />}>
          <Route path="/" element={<Home />} />
          <Route path="/drop-off" element={<DropOff />} />
          <Route path="/history" element={<History />} />
          <Route path="/history/:id" element={<SubmissionDetail />} />
          <Route path="/wallet" element={<Wallet />} />
          <Route path="/rewards" element={<Rewards />} />
          <Route path="/rewards/:id" element={<RewardDetail />} />
          <Route path="/pickups" element={<MyPickups />} />
          <Route path="/leaderboard" element={<Leaderboard />} />
          <Route path="/impact" element={<Impact />} />
          <Route path="/alerts" element={<Alerts />} />
        </Route>
      </Route>

      {/* staff + admin */}
      <Route element={<RequireAuth roles={['staff', 'admin']} />}>
        <Route element={<Layout />}>
          <Route path="/staff/review" element={<Review />} />
          <Route path="/staff/pickups" element={<Pickups />} />
        </Route>
      </Route>

      {/* admin only */}
      <Route element={<RequireAuth roles={['admin']} />}>
        <Route element={<Layout />}>
          <Route path="/admin" element={<Overview />} />
          <Route path="/admin/users" element={<Users />} />
          <Route path="/admin/groups" element={<Groups />} />
          <Route path="/admin/categories" element={<Categories />} />
          <Route path="/admin/rewards" element={<AdminRewards />} />
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
