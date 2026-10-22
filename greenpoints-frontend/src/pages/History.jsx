import { useState } from 'react';
import { useApi } from '../hooks/useApi.js';
import { Async, Chips, Empty, PageHead, Pager } from '../components/ui.jsx';
import SubmissionRow from '../components/SubmissionRow.jsx';

export default function History() {
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const state = useApi('/waste-submissions', { status, page, per_page: 10 });

  return (
    <>
      <PageHead title="Drop-off history" sub="Every drop-off and where it stands." />
      <Chips value={status} onChange={(v) => { setStatus(v); setPage(1); }}
        options={[['', 'All'], ['pending', 'Pending'], ['verified', 'Verified'], ['rejected', 'Rejected']]} />
      <Async state={state}>
        {(p) => (
          <div className="card">
            {p.data.length ? p.data.map((s) => <SubmissionRow key={s.id} s={s} />) : <Empty>Nothing here yet.</Empty>}
            <Pager data={p} onPage={setPage} />
          </div>
        )}
      </Async>
    </>
  );
}
