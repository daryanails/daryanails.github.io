// db.js — frontend on GitHub Pages, backend (api.php) on Sprinthost

const BACKEND = 'https://daryanails.xsph.ru';

// On github.io we talk to the Sprinthost server; on Sprinthost itself (or localhost) paths stay relative.
const REMOTE = /(^|\.)github\.io$/.test(location.hostname);
const BASE = REMOTE ? BACKEND : '';
const API = BASE + '/api.php';
const UP = '/uploads/';

// api.php stores photos as "/uploads/..." — on GitHub Pages they must point to the Sprinthost server.
// abs(): server -> page (add the host), rel(): page -> server (remove it again before saving).
function abs(v) {
  if (!BASE) return v;
  if (typeof v === 'string') return v.startsWith(UP) ? BASE + v : v;
  if (Array.isArray(v)) return v.map(abs);
  if (v && typeof v === 'object') { const o = {}; for (const k in v) o[k] = abs(v[k]); return o; }
  return v;
}
function rel(v) {
  if (!BASE) return v;
  const p = BASE + UP;
  if (typeof v === 'string') return v.startsWith(p) ? v.slice(BASE.length) : v;
  if (Array.isArray(v)) return v.map(rel);
  if (v && typeof v === 'object') { const o = {}; for (const k in v) o[k] = rel(v[k]); return o; }
  return v;
}

async function parse(res) {
  const json = await res.json().catch(() => ({}));
  if (!res.ok || json.ok === false) {
    const err = new Error(json.error || 'Network error');
    err.status = res.status;
    throw err;
  }
  return abs(json);
}

// POST (JSON, or FormData when isForm = true)
async function call(action, data = {}, isForm = false) {
  const headers = { 'X-DN': '1' };            // required by api.php (CSRF guard)
  let body;
  if (isForm) {
    body = data;                              // FormData: the browser sets the Content-Type itself
  } else {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(rel(data));
  }
  const res = await fetch(`${API}?a=${action}`, {
    method: 'POST',
    headers,
    body,
    credentials: 'include'                    // admin session cookie
  });
  return parse(res);
}

// GET
async function getCall(action) {
  const res = await fetch(`${API}?a=${action}`, {
    headers: { 'X-DN': '1' },
    credentials: 'include'
  });
  return parse(res);
}

// Dashboard data (login required)
export async function fetchAll() {
  try {
    const json = await getCall('all');
    return {
      clients: json.clients || [],
      bookings: json.bookings || [],
      items: json.items || [],
      works: json.works || [],
      site: json.site || {}
    };
  } catch (e) {
    console.error('fetchAll:', e);
    if (e.status === 401) throw e;
    return { clients: [], bookings: [], items: [], works: [], site: {} };
  }
}

// Public site data
export async function fetchPublic() {
  try {
    const json = await getCall('public');
    return {
      clients: [],
      bookings: [],
      items: json.items || [],
      works: json.works || [],
      site: json.site || {}
    };
  } catch (e) {
    console.error('fetchPublic:', e);
    return { clients: [], bookings: [], items: [], works: [], site: {} };
  }
}

// New booking from the website
export async function createBooking(p) {
  const json = await call('create_booking', {
    name: p.name,
    phone: p.phone,
    design: p.design,
    date: p.date,
    time: p.time,
    note: p.note || '',
    photos: p.photos || []
  });
  return json.id;
}

// Save / delete (clients, bookings, services, works)
export async function saveClient(c) { return call('save', { t: 'clients', row: c }).then(r => r.row); }
export const deleteClient = id => call('delete', { t: 'clients', id });

export async function saveBooking(b) { return call('save', { t: 'bookings', row: b }).then(r => r.row); }
export const deleteBooking = id => call('delete', { t: 'bookings', id });

export async function saveItem(i) { return call('save', { t: 'items', row: i }).then(r => r.row); }
export const deleteItem = id => call('delete', { t: 'items', id });

export async function saveWork(w) { return call('save', { t: 'works', row: w }).then(r => r.row); }
export const deleteWork = id => call('delete', { t: 'works', id });

export async function saveSite(data) {
  try {
    await call('site_save', { data });
    return { data: [{ id: 1 }] };
  } catch (error) {
    return { error };
  }
}

// Photo upload
export async function uploadPhoto(file, folder = 'misc') {
  try {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('folder', folder);
    const json = await call('upload', fd, true);
    return json.url;
  } catch (e) {
    console.error('uploadPhoto:', e);
    return '';
  }
}

// Admin sign-in & accounts
export const auth = {
  session: async () => {
    try { const json = await getCall('me'); return json.user; }
    catch (e) { return null; }
  },
  login: async (email, pass) => {
    const json = await call('login', { email, pass });
    return json.user;
  },
  logout: async () => { try { await call('logout'); } catch (e) { } },
  setup: async (email, pass) => {
    const json = await call('setup', { email, pass });
    return json.user;
  },
  changePassword: async pass => call('password', { pass }),
  admins: async () => {
    const json = await getCall('admins');
    return json.rows;
  },
  addAdmin: async (email, pass, role) => {
    await call('admin_add', { email, pass, role });
  },
  removeAdmin: async id => {
    await call('admin_remove', { id });
  },
  importAll: async () => { throw new Error('not needed'); }
};
