// db.js — Local PHP version (All data lives in files on the hosting via api.php)

const API = '/api.php';

// دالة مساعدة لإرسال الطلبات إلى api.php
async function call(action, data = {}, isForm = false) {
  const headers = { 'X-DN': '1' }; // مطلوب في api.php لمنع هجمات CSRF
  
  let body;
  if (isForm) {
    body = data; // FormData
  } else {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(data);
  }

  const res = await fetch(`${API}?a=${action}`, {
    method: 'POST',
    headers: headers,
    body: body
  });

  const json = await res.json().catch(() => ({}));
  if (!res.ok || json.ok === false) {
    const err = new Error(json.error || 'Network error');
    err.status = res.status;
    throw err;
  }
  return json;
}

// دالة مساعدة للطلبات من نوع GET
async function getCall(action) {
  const res = await fetch(`${API}?a=${action}`, {
    headers: { 'X-DN': '1' }
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok || json.ok === false) {
    const err = new Error(json.error || 'Network error');
    err.status = res.status;
    throw err;
  }
  return json;
}

// جلب البيانات للوحة التحكم (يتطلب تسجيل دخول)
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

// جلب البيانات للزوار (الموقع العام)
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

// إنشاء حجز جديد من الموقع
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

// حفظ البيانات (عملاء، حجوزات، خدمات، أعمال)
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

// رفع الصور
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

// نظام تسجيل الدخول والإدارة
export const auth = {
  session: async () => {
    try { const json = await getCall('me'); return json.user; } 
    catch (e) { return null; }
  },
  login: async (email, pass) => {
    try {
      const json = await call('login', { email, pass });
      return json.user;
    } catch (e) {
      throw e;
    }
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