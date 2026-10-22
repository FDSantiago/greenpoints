import { Link, Navigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext.jsx';
import { useApi } from '../hooks/useApi.js';
import { Async, Bars, Empty, Pill } from '../components/ui.jsx';
import SubmissionRow from '../components/SubmissionRow.jsx';
import { kg, pts } from '../lib/format.js';

export default function Home() {
  const { user } = useAuth();
  // Staff and admins have their own home screens.
  if (user.role === 'admin') return <Navigate to="/admin" replace />;
  if (user.role === 'staff') return <Navigate to="/staff/review" replace />;
  return <StudentHome user={user} />;
}

function StudentHome({ user }) {
  // Two requests at once: /dashboard (points, rank, recent) and the monthly summary (kg, by category).
  const dash = useApi('/dashboard');
  const summary = useApi('/waste-submissions/summary');

  return (
    <>
      <h1>Hello, {user.name.split(' ')[0]}</h1>
      <p className="muted" style={{ marginBottom: 18 }}>{user.group ? `Group ${user.group.name}` : 'Ask an admin to add you to a group'}</p>

      <Async state={dash}>
        {({ data: d }) => (
          <>
            <section className="hero">
              <div className="label">Your available recycle points</div>
              <div className="big">{pts(d.points.balance)}<small>pts</small></div>
              <div className="label">+{pts(d.points.earned_this_month)} earned this month</div>
              {d.points.next_reward ? (
                <>
                  <div className="meter" aria-label="Progress to next reward"><i style={{ width: `${d.points.next_reward.progress_percent}%` }} /></div>
                  <div className="label">{pts(d.points.next_reward.points_needed)} pts to {d.points.next_reward.name}</div>
                </>
              ) : <div className="label" style={{ marginTop: 14 }}>You can afford every reward right now.</div>}
              <div className="row-actions">
                <Link className="btn light" to="/drop-off">Drop off waste</Link>
                <Link className="btn ghost" style={{ background: 'transparent', color: '#fff', borderColor: 'rgba(255,255,255,.6)' }} to="/rewards">Browse rewards</Link>
              </div>
            </section>

            <div className="stats">
              <div className="card stat"><div className="num">{summary.data ? kg(summary.data.data.total_kg) : '…'}</div><div className="cap">Recycled this month</div></div>
              <div className="card stat">
                <div className="num">{d.rank.group ? `#${d.rank.group.rank}` : '–'}</div>
                <div className="cap">{d.rank.group ? `${d.rank.group.name} this week` : 'No group yet'}</div>
              </div>
              <div className="card stat"><div className="num">{d.rank.individual ? `#${d.rank.individual.rank}` : '–'}</div><div className="cap">Your rank this week</div></div>
              <div className="card stat"><div className="num">{d.pending_submissions}</div><div className="cap">Waiting for staff check</div></div>
            </div>

            <div className="cols two">
              <div className="card">
                <h2>Recent drop-offs</h2>
                {d.recent_submissions.length ? d.recent_submissions.map((s) => <SubmissionRow key={s.id} s={s} />) : <Empty>No drop-offs yet. Start with your first one.</Empty>}
                <Link to="/history" className="small" style={{ color: 'var(--green)', fontWeight: 800 }}>See all history</Link>
              </div>
              <div className="stack">
                <div className="card">
                  <h2>Waste by category</h2>
                  {summary.data && <Bars items={summary.data.data.by_category.map((c) => ({ label: c.category, value: c.total_kg }))} />}
                </div>
                {d.active_redemptions.length > 0 && (
                  <div className="card">
                    <h2>Reward pickups</h2>
                    {d.active_redemptions.map((r) => (
                      <Link key={r.id} to="/pickups" className="row">
                        <div className="grow"><b>{r.reward.name}</b><span className="muted small">Code {r.code}</span></div>
                        <Pill status={r.status} />
                      </Link>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </>
        )}
      </Async>
    </>
  );
}
