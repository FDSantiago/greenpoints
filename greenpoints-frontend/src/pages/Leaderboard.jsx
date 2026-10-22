import { useState } from 'react';
import { useApi } from '../hooks/useApi.js';
import { Async, Chips, Empty, PageHead } from '../components/ui.jsx';

export default function Leaderboard() {
  const [scope, setScope] = useState('group');
  const [period, setPeriod] = useState('weekly');
  const [metric, setMetric] = useState('total');
  const state = useApi('/leaderboard', { scope, period, metric: scope === 'group' ? metric : undefined, limit: 20 });

  return (
    <>
      <PageHead title="Leaderboard" sub="Spending points never lowers your rank." />
      <Chips value={scope} onChange={setScope} options={[['group', 'Groups'], ['individual', 'Students']]} />
      <Chips value={period} onChange={setPeriod} options={[['weekly', 'This week'], ['monthly', 'This month'], ['all', 'All time']]} />
      {scope === 'group' && <Chips value={metric} onChange={setMetric} options={[['total', 'Total points'], ['per_member', 'Per student']]} />}
      <Async state={state}>
        {({ data }) => {
          const max = Math.max(...data.entries.map((e) => e.score), 1);
          const inList = data.me && data.entries.some((e) => e.id === data.me.id);
          const Row = ({ e, me }) => (
            <div className={`lbrow ${me ? 'me' : ''}`}>
              <span className={`rank ${e.rank === 1 ? 'r1' : ''}`}>{e.rank}</span>
              <div className="grow" style={{ flex: 1 }}>
                <b>{e.name}{me ? ' (you)' : ''}</b>
                <div className="meter" style={{ height: 6, marginTop: 6 }}><i style={{ width: `${(e.score / max) * 100}%` }} /></div>
              </div>
              <b>{e.score} pts</b>
            </div>
          );
          return (
            <div className="card" style={{ maxWidth: 720 }}>
              {data.entries.length ? data.entries.map((e) => <Row key={e.id} e={e} me={data.me?.id === e.id} />) : <Empty>No points earned in this period yet.</Empty>}
              {data.me && !inList && <><p className="muted small" style={{ margin: '10px 12px' }}>Your position</p><Row e={data.me} me /></>}
            </div>
          );
        }}
      </Async>
    </>
  );
}
