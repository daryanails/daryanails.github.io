/* ==========================================================
   bg3d.js — 3D beauty-studio background (sits BEHIND the whole site) · v3
   • Nail-salon world: polish bottles, nail-art brush, nail file, glossy gel nails (solid / ombré / chrome),
     pearls, rhinestones, hearts
   • Star-glitter falls top → bottom, faster while you scroll
   • Scroll choreography: objects glide down the page in depth layers, wrap endlessly,
     spin with scroll speed; camera sways; light colour travels pink → lilac → rose-gold
   • NO pointer glow: the mouse never paints pink light (only a very light depth parallax)
   • Fly-in intro, "warp" pulse on link clicks; follows data-theme; pauses when hidden;
     still frame for reduced-motion; silently does nothing without WebGL / in data-saver
   ========================================================== */
const THREE_URL = 'https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js';

const PAL = {
  light: ['#E4558A', '#D63E78', '#A678D8', '#EE8DB0', '#F2A9C4', '#B8326A'],
  dark:  ['#D2688F', '#EE7FA1', '#A276CC', '#8A3C62', '#F0C9D8', '#E3A2BE']
};
const CHROME = { light: ['#D98AA6', '#A98DDD'], dark: ['#E9B1C4', '#C7B0EC'] };
const POLISH = { light: ['#D8366F', '#9B2C63', '#8C63C9', '#F08FB0'], dark: ['#E9558A', '#B8487F', '#A57BDA', '#F4A0BD'] };
const METAL = { light: '#E8B4A8', dark: '#EBC0B4' };           // rose-gold
const PEARL = { light: '#EBCDD9', dark: '#EBCFDA' };
const GEM = { light: '#E9A6C6', dark: '#F6D3E4' };
const HEART = { light: '#DE4A7E', dark: '#F08AAE' };
const FOG = { light: '#F6E3EA', dark: '#150E13' };
const STOPS = ['#ff9ec4', '#c9a0ff', '#ffc0b0', '#ff8fb8'];   // rim-light colour down the page

const seeded = a => () => { a |= 0; a = a + 0x6D2B79F5 | 0; let t = Math.imul(a ^ a >>> 15, 1 | a); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296 };
const clamp = (x, a = 0, b = 1) => Math.min(Math.max(x, a), b);
const easeOut = x => 1 - Math.pow(1 - clamp(x), 3);
const smooth = x => { x = clamp(x); return x * x * (3 - 2 * x) };
const mod = (a, n) => ((a % n) + n) % n;

async function start() {
  if (navigator.connection && navigator.connection.saveData) return;
  const reduce = matchMedia('(prefers-reduced-motion:reduce)').matches;
  const small = innerWidth < 700;

  let THREE;
  try { THREE = await import(THREE_URL); } catch (e) { return; }
  let renderer;
  try { renderer = new THREE.WebGLRenderer({ alpha: true, antialias: !small, powerPreference: 'low-power' }); } catch (e) { return; }

  const cv = renderer.domElement;
  cv.id = 'bg3d'; cv.setAttribute('aria-hidden', 'true');
  document.body.prepend(cv);
  renderer.setClearColor(0x000000, 0);
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  const dpr = () => Math.min(devicePixelRatio || 1, small ? 1.25 : 1.75);
  renderer.setPixelRatio(dpr());

  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog(FOG.light, 8, 28);
  const FOV = 35, CAMZ = 12, tanH = Math.tan(FOV * Math.PI / 360);
  const cam = new THREE.PerspectiveCamera(FOV, 1, 0.1, 80);
  cam.position.set(0, 0, CAMZ);

  /* ---------- lights (none of them follows the mouse) ---------- */
  const key = new THREE.DirectionalLight(0xffffff, 0.9); key.position.set(-4, 6, 8); scene.add(key);   // sweeps → travelling highlights
  scene.add(new THREE.AmbientLight(0xffffff, 0.35));
  const fill = new THREE.PointLight(0xffffff, 30, 0, 2); fill.position.set(0, 2, 5); scene.add(fill);  // neutral soft fill
  const rim = new THREE.PointLight(0xc9a0ff, 24, 0, 2); rim.position.set(-6, -2, 3); scene.add(rim);   // colour follows scroll

  /* ---------- soft-box reflections ---------- */
  const pmrem = new THREE.PMREMGenerator(renderer);
  let envTex = null;
  function buildEnv(dark) {
    const s = new THREE.Scene();
    s.add(new THREE.Mesh(new THREE.SphereGeometry(50, 32, 16),
      new THREE.MeshBasicMaterial({ color: new THREE.Color(dark ? '#3a1f2e' : '#e6bfd0').multiplyScalar(dark ? .8 : .95), side: THREE.BackSide })));
    const panel = (c, k, w, h, p) => {
      const m = new THREE.Mesh(new THREE.PlaneGeometry(w, h), new THREE.MeshBasicMaterial({ color: new THREE.Color(c).multiplyScalar(k), side: THREE.DoubleSide }));
      m.position.set(p[0], p[1], p[2]); m.lookAt(0, 0, 0); s.add(m);
    };
    panel('#ffffff', dark ? 3.5 : 3.6, 18, 10, [-14, 16, 12]);
    panel('#ffb3cf', dark ? 3 : 3.5, 10, 16, [22, 4, 6]);
    panel('#d9c2f5', 2.2, 20, 6, [0, -18, 8]);
    panel('#ffffff', dark ? 1.8 : 2.0, 8, 8, [6, 10, -20]);
    panel('#ffd9c9', 1.6, 6, 14, [-24, -2, 4]);
    if (envTex) envTex.dispose();
    envTex = pmrem.fromScene(s, 0.04).texture;
    scene.environment = envTex;
  }

  /* ---------- geometries ---------- */
  const pts = [], N = 40;
  for (let i = 0; i <= N; i++) {
    const t = i / N, w = Math.sin(Math.PI * Math.pow(t, 0.78));
    pts.push(new THREE.Vector2(Math.max(0.52 * Math.pow(w, 0.75) * (1 - 0.18 * t), 0.001), (t - 0.5) * 2));
  }
  const paint = (geo, f) => {
    const p = geo.attributes.position, c = new Float32Array(p.count * 3);
    for (let i = 0; i < p.count; i++) { const v = f(p.getX(i), p.getY(i), p.getZ(i)); c[i * 3] = c[i * 3 + 1] = c[i * 3 + 2] = v }
    geo.setAttribute('color', new THREE.BufferAttribute(c, 3)); return geo;
  };
  const seg = small ? 40 : 64, cs = small ? 24 : 36;
  const nailGeo = paint(new THREE.LatheGeometry(pts, seg), () => 1);
  const ombreGeo = paint(new THREE.LatheGeometry(pts, seg), (x, y) => 0.5 + 0.5 * smooth((y + 1) / 2 * 1.15));
  const pearlGeo = new THREE.SphereGeometry(0.5, small ? 32 : 48, small ? 20 : 32);
  const gemGeo = new THREE.OctahedronGeometry(0.55, 0);
  const hs = new THREE.Shape();
  hs.moveTo(0, -0.5); hs.bezierCurveTo(-0.95, 0.1, -0.45, 0.8, 0, 0.3); hs.bezierCurveTo(0.45, 0.8, 0.95, 0.1, 0, -0.5);
  const heartGeo = new THREE.ExtrudeGeometry(hs, { depth: 0.28, bevelEnabled: true, bevelSize: 0.09, bevelThickness: 0.12, bevelSegments: 5, curveSegments: 18 });
  heartGeo.center();
  const cyl = (rt, rb, h, s = cs) => new THREE.CylinderGeometry(rt, rb, h, s);

  /* ---------- materials ---------- */
  const phys = o => new THREE.MeshPhysicalMaterial(o);
  // Every material uses clearcoat + iridescence (>0) and no sheen → only 3 shader programs compile
  // (vertex-coloured nails, flat-shaded gem, everything else) = no freeze when a page opens.
  const base = { clearcoat: 1, clearcoatRoughness: 0.04, iridescence: 0.05, iridescenceIOR: 1.35 };
  const nailMats = PAL.light.map(c => phys({ ...base, color: c, vertexColors: true, roughness: 0.1, iridescence: 0.6 }));
  const chromeMats = CHROME.light.map(c => phys({ ...base, color: c, vertexColors: true, roughness: 0.14, metalness: 1, iridescence: 0.4, iridescenceIOR: 1.5 }));
  const pearlMat = phys({ ...base, color: PEARL.light, roughness: 0.16, metalness: 0.05, iridescence: 1, iridescenceIOR: 1.6 });
  const gemMat = phys({ ...base, color: GEM.light, roughness: 0.04, metalness: 0.9, clearcoatRoughness: 0, flatShading: true, iridescence: 0.8, iridescenceIOR: 1.9 });
  const heartMat = phys({ ...base, color: HEART.light, roughness: 0.1, iridescence: 0.5, iridescenceIOR: 1.4 });
  const polishMats = POLISH.light.map(c => phys({ ...base, color: c, roughness: 0.05, iridescence: 0.35 }));
  const metalMat = phys({ ...base, color: METAL.light, roughness: 0.18, metalness: 1 });
  const blackMat = phys({ ...base, color: '#1b1217', roughness: 0.12, metalness: 0.2 });
  const silverMat = phys({ ...base, color: '#EDEAF3', roughness: 0.12, metalness: 1, clearcoat: 0.6 });
  const bristleMat = phys({ ...base, color: '#F6D8E3', roughness: 0.85, clearcoat: 0.01, iridescence: 0.01 });
  const powderMat = phys({ ...base, color: '#EBA3BB', roughness: 0.95, clearcoat: 0.01, iridescence: 0.01 });

  /* ---------- nail-salon objects ---------- */
  const part = (geo, mat, x = 0, y = 0, z = 0) => { const m = new THREE.Mesh(geo, mat); m.position.set(x, y, z); return m };
  const G = {
    polish: cyl(0.34, 0.34, 0.62, 32), band: cyl(0.345, 0.345, 0.16, 32), shoulder: cyl(0.15, 0.34, 0.1, 32), neck: cyl(0.13, 0.13, 0.08, 24),
    cap: cyl(0.17, 0.17, 0.52, 24), capRing: cyl(0.19, 0.19, 0.06, 24),
    handle: cyl(0.03, 0.055, 1.3, 16), ferrule: cyl(0.062, 0.062, 0.26, 20), bristle: new THREE.SphereGeometry(0.075, 20, 14),
    fileBase: new THREE.BoxGeometry(0.3, 1.6, 0.06), fileGrit: new THREE.BoxGeometry(0.26, 1.54, 0.075)
  };
  const mkPolish = i => {
    const g = new THREE.Group(), c = polishMats[i % polishMats.length], o = -0.37;
    g.add(part(G.polish, c, 0, o), part(G.band, pearlMat, 0, o - 0.1), part(G.shoulder, c, 0, o + 0.36), part(G.neck, c, 0, o + 0.45),
      part(G.capRing, metalMat, 0, o + 0.5), part(G.cap, blackMat, 0, o + 0.76));
    return g;
  };
  const mkNailBrush = () => {                         // thin nail-art brush
    const g = new THREE.Group(), b = part(G.bristle, bristleMat, 0, 0.71);
    b.scale.set(1, 3.4, 1);
    g.add(part(G.handle, metalMat, 0, -0.4), part(G.ferrule, silverMat, 0, 0.38), b);
    return g;
  };
  const mkFile = () => {                              // nail file
    const g = new THREE.Group();
    g.add(part(G.fileBase, blackMat), part(G.fileGrit, powderMat, 0, 0, 0.004));
    return g;
  };

  /* ---------- scatter (seeded → same layout on every page) ---------- */
  const rnd = seeded(20261015);
  const items = [];
  const add = (obj, base, kind) => {
    obj.rotation.order = 'ZXY';
    const z = -9 + rnd() * 6.5, par = (z + 9) / 6.5;          // far back → soft, small, misty (like depth-of-field)
    const side = rnd() < 0.5 ? -1 : 1;                           // only at the left / right edges: the middle stays clean for content
    const mk = kind === 'makeup';
    const o = {
      m: obj, kind, z, par,
      nx: side * (0.82 + rnd() * 0.25),
      ny: rnd() * 2 - 1,
      s: base * 0.72 * (0.55 + par * 0.55),
      a: (rnd() - .5) * (mk ? .6 : .9), b: (rnd() - .5) * .9, c: (rnd() - .5) * (mk ? 1.0 : 1.7),
      sx: 0, sy: 0, xk: mk ? 0.12 : 1,
      ph: rnd() * 6.28, sp: 0.3 + rnd() * 0.35, delay: items.length * 0.04
    };
    items.push(o); scene.add(obj);
  };
  const cnt = small
    ? { nail: 5, polish: 2, brush: 1, pearl: 3, gem: 3, heart: 2 }
    : { nail: 7, polish: 4, brush: 2, pearl: 5, gem: 5, heart: 3 };
  const mesh = (g, m) => new THREE.Mesh(g, m);
  for (let i = 0; i < cnt.nail; i++) {
    const chrome = i % 4 === 3;
    add(mesh(i % 3 === 1 ? ombreGeo : nailGeo, chrome ? chromeMats[i % 2] : nailMats[i % nailMats.length]), 1.15, 'nail');
  }
  for (let i = 0; i < cnt.polish; i++) add(mkPolish(i), 1.0, 'makeup');
  for (let i = 0; i < cnt.brush; i++) add(mkNailBrush(), 0.9, 'makeup');
  for (let i = 0; i < cnt.pearl; i++) add(mesh(pearlGeo, pearlMat), 0.5, 'pearl');
  for (let i = 0; i < cnt.gem; i++) add(mesh(gemGeo, gemMat), 0.5, 'gem');
  for (let i = 0; i < cnt.heart; i++) add(mesh(heartGeo, heartMat), 0.6, 'heart');

  /* ---------- HERO SHOWCASE (right side of the home hero): polishes, file, brush, floating nails + orbiting nails / pearls / crystals ---------- */
  const heroEl = document.getElementById('hero');
  const HERO_D = 6.6;
  const hero = new THREE.Group(); hero.visible = false; scene.add(hero);
  const core = new THREE.Group(), orbit = new THREE.Group(); hero.add(core, orbit);
  const glowMat = new THREE.MeshBasicMaterial({ color: '#ff9ec4', transparent: true, opacity: .85, fog: false, depthWrite: false });
  const hoverNails = [];
  {
    // pearl podium with a rose-gold rim and two glowing rings
    const podium = new THREE.Mesh(new THREE.CylinderGeometry(1.75, 1.95, 0.3, 64), pearlMat);
    const rim = new THREE.Mesh(new THREE.TorusGeometry(1.76, 0.05, 16, 96), metalMat); rim.rotation.x = Math.PI / 2; rim.position.y = 0.15;
    const g1 = new THREE.Mesh(new THREE.TorusGeometry(2.15, 0.022, 8, 128), glowMat); g1.rotation.x = Math.PI / 2; g1.position.y = -0.12;
    const g2 = new THREE.Mesh(new THREE.TorusGeometry(2.6, 0.012, 8, 128), glowMat); g2.rotation.x = Math.PI / 2; g2.position.y = -0.2;
    core.add(podium, rim, g1, g2);
    // three nail polishes (different heights/colours), nail file, nail-art brush, two floating press-on nails, rhinestones + pearls
    const pol = (i, sc, x, z, ry) => { const m = mkPolish(i); m.scale.setScalar(sc); m.position.set(x, 0.15 + 0.68 * sc, z); m.rotation.y = ry; core.add(m) };
    pol(0, 1.6, 0, -0.25, 0.3); pol(2, 1.15, -1.2, 0.2, 0.6); pol(1, 1.0, 1.15, 0.3, -0.6);
    const fl = mkFile(); fl.scale.setScalar(0.95); fl.position.set(0.95, 0.91, -0.75); fl.rotation.set(0, 0.35, -0.18); core.add(fl);
    const br = mkNailBrush(); br.scale.setScalar(1.05); br.position.set(0.05, 0.24, 1.15); br.rotation.set(0, -0.3, Math.PI / 2); core.add(br);
    [[ombreGeo, nailMats[0], -0.6, 0.3], [nailGeo, chromeMats[0], 0.75, -0.3]].forEach(([geo, mat, x, rz], i) => {
      const n = new THREE.Mesh(geo, mat); n.scale.set(0.5, 0.5, 0.17); n.position.set(x, 0.92, 1.22); n.rotation.z = rz; n.userData.y = 0.92;
      core.add(n); hoverNails.push(n);
    });
    [[-1.45, 0.8, 0.14], [1.5, 0.75, 0.12], [0.3, -1.0, 0.13], [-0.4, 1.25, 0.1]].forEach(([x, z, r], i) => {
      const m = new THREE.Mesh(i % 2 ? pearlGeo : gemGeo, i % 2 ? pearlMat : gemMat); m.scale.setScalar(r * 2); m.position.set(x, 0.15 + r, z); core.add(m);
    });
  }
  const orbitItems = [];
  [[ombreGeo, nailMats[0], .55, 1], [pearlGeo, pearlMat, .3, 0], [heartGeo, heartMat, .42, 0], [nailGeo, chromeMats[0], .55, 1],
   [pearlGeo, pearlMat, .22, 0], [nailGeo, nailMats[2], .55, 1], [gemGeo, gemMat, .3, 0]].forEach(([geo, mat, sz, nail], i, a) => {
    const m = new THREE.Mesh(geo, mat), ang = i / a.length * Math.PI * 2;
    m.position.set(Math.cos(ang) * 3.0, Math.sin(i * 1.7) * 0.25, Math.sin(ang) * 3.0);
    m.scale.set(sz, sz, nail ? sz * 0.34 : sz); m.rotation.z = (i % 2 ? 1 : -1) * 0.35;
    orbit.add(m); orbitItems.push({ m, sp: 0.4 + (i % 3) * 0.25 });
  });
  let heroSpin = 0, orbitAng = 0;

  /* ---------- star glitter (falls top → bottom) ---------- */
  const tc = document.createElement('canvas'); tc.width = tc.height = 96;
  const cx = tc.getContext('2d');
  const g = cx.createRadialGradient(48, 48, 0, 48, 48, 48);
  g.addColorStop(0, 'rgba(255,255,255,1)'); g.addColorStop(.18, 'rgba(255,255,255,.7)'); g.addColorStop(1, 'rgba(255,255,255,0)');
  cx.fillStyle = g; cx.fillRect(0, 0, 96, 96);
  cx.fillStyle = '#fff';
  cx.beginPath(); cx.moveTo(48, 2); cx.quadraticCurveTo(51, 45, 94, 48); cx.quadraticCurveTo(51, 51, 48, 94); cx.quadraticCurveTo(45, 51, 2, 48); cx.quadraticCurveTo(45, 45, 48, 2); cx.fill();
  const sprite = new THREE.CanvasTexture(tc);
  const sparks = [];
  const mkSparks = (n, size, v, k, off) => {
    const pos = new Float32Array(n * 3);
    for (let i = 0; i < n; i++) { pos[i * 3] = (rnd() * 2 - 1) * 11; pos[i * 3 + 1] = (rnd() * 2 - 1) * 7; pos[i * 3 + 2] = -5 + rnd() * 8.5 }
    const geo = new THREE.BufferGeometry(); geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    const mat = new THREE.PointsMaterial({ map: sprite, size, transparent: true, depthWrite: false, opacity: .7 });
    const p = new THREE.Points(geo, mat); scene.add(p); sparks.push({ p, v, k, off });
  };
  mkSparks(small ? 26 : 50, 0.34, 0.22, 1.5, 0);
  mkSparks(small ? 26 : 50, 0.24, 0.14, 1.0, 5);
  mkSparks(small ? 18 : 36, 0.46, 0.32, 2.2, 9);

  /* ---------- theme ---------- */
  function applyTheme() {
    const dark = document.documentElement.dataset.theme === 'dark', T = dark ? 'dark' : 'light', e = dark ? .85 : .85;
    nailMats.forEach((m, i) => { m.color.set(PAL[T][i]); m.envMapIntensity = e });
    chromeMats.forEach((m, i) => { m.color.set(CHROME[T][i]); m.envMapIntensity = dark ? 1.1 : 1.3 });
    polishMats.forEach((m, i) => { m.color.set(POLISH[T][i]); m.envMapIntensity = e });
    metalMat.color.set(METAL[T]); metalMat.envMapIntensity = dark ? 1.2 : 1.4;
    blackMat.envMapIntensity = dark ? 1.4 : 1.1; silverMat.envMapIntensity = 1.3;
    bristleMat.envMapIntensity = e; powderMat.envMapIntensity = e;
    pearlMat.color.set(PEARL[T]); pearlMat.envMapIntensity = e;
    gemMat.color.set(GEM[T]); gemMat.envMapIntensity = dark ? 1.4 : 1.7;
    heartMat.color.set(HEART[T]); heartMat.envMapIntensity = e;
    glowMat.color.set(dark ? '#ff9ec4' : '#d4528a'); glowMat.blending = dark ? THREE.AdditiveBlending : THREE.NormalBlending; glowMat.needsUpdate = true;
    scene.fog.color.set(FOG[T]);
    renderer.toneMappingExposure = dark ? 0.95 : 0.98;
    scene.fog.near = dark ? 8 : 14; scene.fog.far = dark ? 28 : 64;   // light page: far objects keep their colour instead of washing out to white
    fill.intensity = dark ? 38 : 24;
    rim.intensity = dark ? 46 : 22;
    sparks.forEach(s => {
      s.p.material.color.set(dark ? '#F6C4D6' : '#E4739B');
      s.p.material.blending = dark ? THREE.AdditiveBlending : THREE.NormalBlending; s.p.material.needsUpdate = true;
    });
    cv.style.setProperty('--bg3d-o', String((dark ? .92 : .95) * (small ? .7 : 1)));
    buildEnv(dark);
    if (reduce) draw(0);
  }
  new MutationObserver(applyTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  /* ---------- layout ---------- */
  let W = 1, H = 1, k = 1;
  function resize() {
    W = innerWidth; H = innerHeight;
    renderer.setPixelRatio(dpr()); renderer.setSize(W, H, false);
    cam.aspect = W / H; cam.updateProjectionMatrix();
    k = Math.min(1, Math.max(0.6, (W / H) / 1.6));
    if (reduce) draw(0);
  }
  addEventListener('resize', resize, { passive: true });

  /* ---------- interaction (parallax only — no light under the pointer) ---------- */
  const ptr = { x: 0, y: 0, tx: 0, ty: 0, used: false };
  addEventListener('pointermove', e => { ptr.used = true; ptr.tx = e.clientX / W * 2 - 1; ptr.ty = e.clientY / H * 2 - 1 }, { passive: true });
  let pulse = 0;
  document.addEventListener('click', e => {
    const a = e.target.closest && e.target.closest('a[href]'); if (!a || a.target === '_blank') return;
    if (a.origin === location.origin) pulse = a.pathname === location.pathname ? 1 : 1.8;
  }, true);
  addEventListener('hashchange', () => { pulse = Math.max(pulse, 1) });

  const stops = STOPS.map(c => new THREE.Color(c));
  const tmp = new THREE.Color();
  const stopColor = (p, out) => {
    p = clamp(p) * (stops.length - 1); const i = Math.min(Math.floor(p), stops.length - 2);
    return out.copy(stops[i]).lerp(stops[i + 1], p - i);
  };

  /* ---------- frame ---------- */
  let sc = 0, time = 0, lastY = scrollY, vel = 0;
  function draw(dt) {
    time += dt;
    pulse *= Math.exp(-dt * 2.4);

    const maxS = Math.max(1, document.documentElement.scrollHeight - H);
    const sy = Math.min(scrollY, maxS);
    sc += (sy / H - sc) * 0.08;
    const prog = sy / maxS;
    if (dt > 0) { const v = (sy - lastY) / H / dt; vel += (clamp(v, -6, 6) - vel) * Math.min(1, dt * 5) }
    lastY = sy;
    const av = Math.min(Math.abs(vel), 3);

    if (!ptr.used) { ptr.tx = Math.sin(time * .25) * .5; ptr.ty = Math.cos(time * .19) * .35 }
    ptr.x += (ptr.tx - ptr.x) * 0.05; ptr.y += (ptr.ty - ptr.y) * 0.05;
    const aspect = W / H;

    stopColor(mod(prog + 0.5, 1), tmp); rim.color.copy(tmp);
    key.position.set(-4 + Math.sin(time * 0.35 + sc * 1.3) * 6, 6 + Math.cos(time * 0.27) * 1.5, 8);
    fill.position.set(Math.sin(time * 0.2) * 3, 2 + Math.cos(time * 0.17), 5);
    rim.position.set(-6 + Math.sin(sc * 0.9) * 3, -2 + Math.cos(time * .3) * 1.5, 3);

    cam.position.set(ptr.x * 0.4 + Math.sin(sc * 0.75) * 0.8, -ptr.y * 0.25 + Math.cos(sc * 0.5) * 0.2, CAMZ + pulse * 1.4);
    cam.lookAt(0, 0, 0);

    for (const o of items) {
      const e = reduce ? 1 : easeOut((time - 0.2 - o.delay) / 1.7);
      const hh = tanH * (CAMZ - o.z), hw = hh * aspect;
      const span = hh * 2.7;
      const raw = o.ny * hh + sc * hh * 2 * (0.3 + o.par * 0.95) + Math.sin(time * o.sp + o.ph) * 0.22 - ptr.y * 0.2 * o.par;
      const y = mod(raw + span / 2, span) - span / 2;
      const edge = smooth((span / 2 - Math.abs(y)) / (hh * 0.3));
      o.m.position.set(
        o.nx * hw * 1.02 + ptr.x * 0.3 * o.par + Math.sin(sc * 1.1 + o.ph) * 0.25 * o.par,
        y,
        o.z - (1 - e) * 10 + pulse * 2.6 * o.par
      );
      if (dt > 0) { o.sy += vel * dt * (0.5 + o.par) * 1.7; o.sx += vel * dt * 0.55 * o.xk }
      o.m.rotation.set(
        o.a + Math.sin(time * o.sp + o.ph) * 0.3 * (o.xk > .5 ? 1.15 : 0.6) + pulse * 0.8 * o.xk + o.sx,
        o.b + Math.cos(time * o.sp * .8 + o.ph) * 0.45 + pulse * 2.2 * (o.par + .3) + o.sy,
        o.c + Math.sin(time * o.sp * .6 + o.ph) * 0.15 + (o.kind === 'gem' ? time * 0.3 : 0)
      );
      const s = o.s * k * (0.2 + 0.8 * e) * (0.15 + 0.85 * edge);
      o.m.scale.set(s, s, o.kind === 'nail' ? s * 0.34 : s);
    }

    // hero showcase: glued to the #hero block, so it scrolls away with the page
    let hv = false;
    if (heroEl && W >= 900) {
      const r = heroEl.getBoundingClientRect();
      if (r.bottom > -120 && r.top < H + 120) {
        hv = true;
        const he = reduce ? 1 : easeOut((time - 0.5) / 1.7);
        const D = Math.min(r.height * 0.88, W * 0.36), hz = 1.5, hhh = tanH * (CAMZ - hz), hww = hhh * aspect;
        const px = r.left + r.width * 0.74, py = r.top + r.height * 0.5;
        hero.position.set(((px / W) * 2 - 1) * hww, -((py / H) * 2 - 1) * hhh, hz);
        hero.scale.setScalar(Math.max(0.001, D * (2 * hhh / H) / HERO_D * he));
        if (dt > 0) { heroSpin += vel * dt * 0.9; heroSpin *= Math.exp(-dt * 0.9); orbitAng += dt * 0.35 + vel * dt * 0.5 }
        core.position.y = -1.1 + Math.sin(time * 0.8) * 0.1;
        core.rotation.set(Math.sin(time * 0.37) * 0.08 + ptr.y * 0.12, Math.sin(time * 0.5) * 0.5 + ptr.x * 0.35 + heroSpin, Math.sin(time * 0.3) * 0.04);
        orbit.rotation.set(0.55, orbitAng, -0.1);
        hoverNails.forEach((n, i) => { n.position.y = n.userData.y + Math.sin(time * 1.1 + i * 2.3) * 0.07; n.rotation.y = Math.sin(time * 0.7 + i) * 0.5 });
        if (dt > 0) orbitItems.forEach(it => { it.m.rotation.y += dt * it.sp });
      }
    }
    hero.visible = hv;

    sparks.forEach((s, i) => {
      s.p.position.y = -mod(time * s.v + sc * s.k + s.off, 14);
      s.p.rotation.y = ptr.x * (0.1 + i * 0.04) * (i % 2 ? -1 : 1);
      s.p.material.opacity = clamp(0.5 + 0.35 * Math.sin(time * 1.5 + i * 2.1) + av * 0.12, 0, 1);
    });
    renderer.render(scene, cam);
  }

  let raf = 0, last = 0;
  const frame = t => { raf = requestAnimationFrame(frame); draw(Math.min((t - last) / 1000, 0.05)); last = t };
  const run = () => { if (!raf && !reduce) { last = performance.now(); raf = requestAnimationFrame(frame) } };
  const stop = () => { cancelAnimationFrame(raf); raf = 0 };
  document.addEventListener('visibilitychange', () => document.hidden ? stop() : run());

  resize(); applyTheme();
  try { if (renderer.compileAsync) await renderer.compileAsync(scene, cam); } catch (e) { }   // compile shaders without blocking the page
  draw(0); run();
  requestAnimationFrame(() => cv.classList.add('on'));
}

/* start only after the page itself is shown (body.ready) so the 3D set-up never competes with page load / navigation */
const idle = f => (window.requestIdleCallback || (g => setTimeout(g, 200)))(f, { timeout: 1200 });
let started = false;
const boot = () => { if (started) return; started = true; idle(start) };
const whenReady = () => {
  if (document.body.classList.contains('ready')) return boot();
  const mo = new MutationObserver(() => { if (document.body.classList.contains('ready')) { mo.disconnect(); boot() } });
  mo.observe(document.body, { attributes: true, attributeFilter: ['class'] });
  setTimeout(() => { mo.disconnect(); boot() }, 6000);          // safety net
};
document.readyState === 'complete' ? whenReady() : addEventListener('load', whenReady, { once: true });
