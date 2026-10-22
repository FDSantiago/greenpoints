export const pts = (n) => (n ?? 0).toLocaleString('en-PH');
export const kg = (n) => `${Number(n ?? 0).toLocaleString('en-PH', { maximumFractionDigits: 2 })} kg`;
export const date = (iso) =>
  iso ? new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
export const dateTime = (iso) =>
  iso ? new Date(iso).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';
export const thisMonth = () => new Date().toISOString().slice(0, 7); // "2026-10"
export const cap = (s = '') => s.charAt(0).toUpperCase() + s.slice(1);

export const ICONS = { Plastic: '🧴', Glass: '🫙', 'Organic waste': '🍂', Paper: '📄', Metal: '🥫' };
export const iconFor = (name) => ICONS[name] || '♻️';

// "Plastic 1.2 kg · Paper 0.5 kg"
export const summarizeItems = (items = []) =>
  items.map((i) => `${i.category.name} ${i.weight_kg} kg`).join(' · ');
