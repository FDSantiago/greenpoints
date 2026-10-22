import { Link } from 'react-router-dom';
import { Pill } from './ui.jsx';
import { date, iconFor, summarizeItems } from '../lib/format.js';

export default function SubmissionRow({ s }) {
  return (
    <Link to={`/history/${s.id}`} className="row">
      <span className="ic">{iconFor(s.items[0]?.category.name)}</span>
      <div className="grow">
        <b>{summarizeItems(s.items)}</b>
        <span className="muted small">{date(s.submitted_at)}</span>
      </div>
      <div className="right">
        <Pill status={s.status} />
        {s.points_awarded != null && <div className="small muted">+{s.points_awarded} pts</div>}
      </div>
    </Link>
  );
}
