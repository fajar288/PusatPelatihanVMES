/* PPTI - VMES Training Center : interaksi tema (port dari JS template Blogger) */
(function () {
  var d = document, b = d.body;
  var AV = 'https://az-icons.com/images/users/icon.svg';
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion:reduce)').matches;

  function $(s, r) { return (r || d).querySelector(s); }
  function $$(s, r) { return [].slice.call((r || d).querySelectorAll(s)); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* ---------- Header: mengecil saat scroll + menu responsif ---------- */
  var hd = $('#hd');
  if (hd) {
    var hw = hd.querySelector('.wrap');
    var fitNav = function () {
      hd.classList.remove('ns', 'cp');
      if (hw.scrollWidth <= hw.clientWidth + 1) return;
      hd.classList.add('ns');
      if (hw.scrollWidth <= hw.clientWidth + 1) return;
      hd.classList.add('cp');
    };
    var onSc = function () {
      var y = window.pageYOffset || d.documentElement.scrollTop;
      hd.classList.toggle('shr', y > 24);
    };
    window.addEventListener('scroll', onSc, { passive: true });
    window.addEventListener('resize', fitNav);
    window.addEventListener('load', fitNav);
    if (d.fonts && d.fonts.ready) d.fonts.ready.then(fitNav);
    fitNav(); onSc();
    var mb = $('#mb');
    if (mb) mb.onclick = function () { $('#nav').classList.toggle('open'); };
  }

  /* ---------- Hero slider ---------- */
  (function () {
    var el = $('#h-hero'); if (!el) return;
    var im = $$('img', el), dt = $('#h-dots'), cur = 0, tm;
    if (!im.length) return;
    dt.innerHTML = im.map(function (s, i) { return '<button type="button" aria-label="Slide ' + (i + 1) + '" class="' + (i ? '' : 'on') + '"></button>'; }).join('');
    var bs = $$('button', dt);
    function show(n) {
      cur = (n + im.length) % im.length;
      im.forEach(function (x, i) { x.className = i === cur ? 'on' : ''; });
      bs.forEach(function (x, i) { x.className = i === cur ? 'on' : ''; });
    }
    function play() {
      clearInterval(tm);
      if (im.length > 1 && !reduce) tm = setInterval(function () { show(cur + 1); }, 4500);
    }
    dt.onclick = function (e) {
      var bt = e.target.closest('button'); if (!bt) return;
      show(bs.indexOf(bt)); play();
    };
    play();
  })();

  /* ---------- Counter statistik ---------- */
  (function () {
    var els = $$('.sg b[data-n]');
    if (!els.length || reduce) return;
    function run(e) {
      var n = +e.getAttribute('data-n'), sf = e.getAttribute('data-s') || '', t0 = null;
      function st(ts) {
        if (t0 === null) t0 = ts;
        var r = Math.min(1, (ts - t0) / 1800), v = Math.round(n * (1 - Math.pow(1 - r, 3)));
        e.textContent = v.toLocaleString('id-ID') + sf;
        if (r < 1) requestAnimationFrame(st);
      }
      requestAnimationFrame(st);
    }
    els.forEach(function (e) { e.textContent = '0' + (e.getAttribute('data-s') || ''); });
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (en) {
        en.forEach(function (x) { if (x.isIntersecting) { run(x.target); io.unobserve(x.target); } });
      }, { threshold: .4 });
      els.forEach(function (e) { io.observe(e); });
    } else { els.forEach(run); }
  })();

  /* ---------- Slider kursus (panah + autoplay) ---------- */
  (function () {
    var sl = $('#h-kursus'); if (!sl) return;
    var paused = false, box = sl.parentNode;
    function go(dir) {
      var c = sl.firstElementChild; if (!c) return;
      var st = c.offsetWidth + 24, end = sl.scrollLeft + sl.clientWidth >= sl.scrollWidth - 8;
      if (dir > 0 && end) sl.scrollTo({ left: 0, behavior: 'smooth' });
      else if (dir < 0 && sl.scrollLeft <= 8) sl.scrollTo({ left: sl.scrollWidth, behavior: 'smooth' });
      else sl.scrollBy({ left: dir * st, behavior: 'smooth' });
    }
    $$('.ar', box).forEach(function (a) { a.onclick = function () { go(+a.getAttribute('data-d')); }; });
    ['mouseenter', 'focusin', 'touchstart'].forEach(function (e) { box.addEventListener(e, function () { paused = true; }, { passive: true }); });
    ['mouseleave', 'focusout'].forEach(function (e) { box.addEventListener(e, function () { paused = false; }, { passive: true }); });
    box.addEventListener('touchend', function () { setTimeout(function () { paused = false; }, 6000); }, { passive: true });
    if (!reduce) setInterval(function () { if (!paused && !d.hidden) go(1); }, 4000);
  })();

  /* ---------- Slider generik (partner & testimoni) ---------- */
  function slide(wrap, sel) {
    var s = $(sel, wrap); if (!s) return;
    $$('.ar', wrap).forEach(function (a) {
      a.onclick = function () { s.scrollBy({ left: +a.getAttribute('data-d') * s.clientWidth * .8, behavior: 'smooth' }); };
    });
  }
  $$('.pw').forEach(function (w) { slide(w, '.pt'); });

  /* ---------- Jadwal terdekat: paginasi ---------- */
  (function () {
    var box = $('#h-jadwal'), pg = $('#h-jpg'); if (!box || !pg) return;
    var rows = $$('.jr', box), per = 7, cur = 1, tot = Math.max(1, Math.ceil(rows.length / per));
    function draw() {
      rows.forEach(function (r, i) { r.hidden = !(i >= (cur - 1) * per && i < cur * per); });
      if (tot < 2) { pg.style.display = 'none'; return; }
      var h = '<button class="pn" type="button" ' + (cur < 2 ? 'disabled ' : '') + 'data-p="' + (cur - 1) + '">&#8592; Sebelumnya</button><div class="pnn">';
      for (var i = 1; i <= tot; i++) h += '<button class="pm' + (i === cur ? ' on' : '') + '" type="button" data-p="' + i + '" aria-label="Halaman ' + i + '">' + i + '</button>';
      h += '</div><button class="pn" type="button" ' + (cur >= tot ? 'disabled ' : '') + 'data-p="' + (cur + 1) + '">Berikutnya &#8594;</button>';
      pg.innerHTML = h;
    }
    pg.onclick = function (e) {
      var t = e.target.closest('button'); if (!t || t.disabled) return;
      cur = +t.getAttribute('data-p'); draw();
    };
    draw();
  })();

  /* ---------- Testimoni ---------- */
  (function () {
    var f = $('#h-tfeat'), l = $('#h-tlist'), src = $('#ppti-testi'); if (!f || !l || !src) return;
    var T = [];
    try { T = JSON.parse(src.textContent); } catch (e) { return; }
    if (!T.length) return;
    slide(l.parentNode, '.tl');
    function stars(n) {
      var h = '';
      for (var i = 0; i < n; i++) h += '<svg class="sr" viewBox="0 0 24 24"><use href="#i-star"/></svg>';
      return '<div class="stars">' + h + '</div>';
    }
    function timg(t, cl) {
      var u = t.i || AV;
      return '<img referrerpolicy="no-referrer" class="' + (u === AV ? 'dflt ' : '') + (cl || '') + '" alt="' + esc(t.n) + '" src="' + esc(u) + '"/>';
    }
    function tfeat(t) {
      return '<div class="ph">' + timg(t) + '</div><div class="tb"><div>' + (t.p ? '<span class="tp">' + esc(t.p) + '</span>' : '') + stars(t.s) +
        '<svg class="qm" viewBox="0 0 24 24"><use href="#i-quote"/></svg><p class="tq">“' + esc(t.q) + '”</p></div><div class="au">' + timg(t) +
        '<div><b>' + esc(t.n) + '</b><small>' + esc(t.r) + '</small></div></div></div>';
    }
    function tcard(t, i) {
      return '<div class="tc" data-i="' + i + '" tabindex="0">' + (t.p ? '<span class="tp">' + esc(t.p) + '</span>' : '') + stars(t.s) +
        '<p>“' + esc(t.q) + '”</p><div class="au">' + timg(t) + '<div><b>' + esc(t.n) + '</b><small>' + esc(t.r) + '</small></div></div></div>';
    }
    var cur = 0;
    function draw() {
      f.innerHTML = tfeat(T[cur]);
      l.innerHTML = T.map(function (t, i) { return i === cur ? '' : tcard(t, i); }).join('');
      l.parentNode.style.display = T.length > 1 ? '' : 'none';
    }
    function pick(e) {
      var c = e.target.closest('.tc'); if (!c) return;
      cur = +c.getAttribute('data-i'); draw(); l.scrollLeft = 0;
    }
    l.onclick = pick;
    l.onkeydown = function (e) { if (e.key === 'Enter') pick(e); };
    draw();
  })();

  /* ---------- Galeri + lightbox ---------- */
  (function () {
    var lb = $('#lb'); if (!lb) return;
    lb.onclick = function () { lb.className = 'lb'; };
    d.addEventListener('keydown', function (e) { if (e.key === 'Escape') lb.className = 'lb'; });
    var el = $('#h-gal'); if (!el) return;
    el.onclick = function (e) {
      var bt = e.target.closest('button'); if (!bt) return;
      lb.querySelector('img').src = bt.getAttribute('data-u');
      lb.className = 'lb on';
    };
  })();

  /* ---------- Katalog: cari, filter, urutkan ---------- */
  (function () {
    var L = $('#cat-list'), bar = $('#cat-bar'); if (!L || !bar) return;
    var cards = $$('[data-q]', L); if (!cards.length) return;
    var q = $('#cat-q'), sk = $('#cat-k'), sm = $('#cat-m'), so = $('#cat-o'), n = $('#cat-n');
    var label = L.getAttribute('data-label') || 'postingan';
    var em = d.createElement('p'); em.className = 'cem'; em.hidden = true;
    em.textContent = 'Tidak ada hasil yang cocok. Coba kata kunci atau filter lain.';
    L.appendChild(em);
    function draw() {
      var t = q.value.trim().toLowerCase(), kc = sk.value, mo = sm.value, m = so.value, vis = [];
      cards.forEach(function (c) {
        var ok = (!t || c.getAttribute('data-q').indexOf(t) > -1) &&
          (!kc || c.getAttribute('data-cat') === kc) &&
          (!mo || c.getAttribute('data-mode') === mo);
        c.hidden = !ok;
        if (ok) vis.push(c);
      });
      vis.sort(function (x, y) {
        var xd = +x.getAttribute('data-d'), yd = +y.getAttribute('data-d');
        var xp = +x.getAttribute('data-pr') || 1e12, yp = +y.getAttribute('data-pr') || 1e12;
        if (m === 'az') return x.getAttribute('data-t').localeCompare(y.getAttribute('data-t'));
        if (m === 'lo') return xp - yp;
        if (m === 'hi') return (+y.getAttribute('data-pr')) - (+x.getAttribute('data-pr'));
        if (m === 'pop') return (+y.getAttribute('data-pop') - +x.getAttribute('data-pop')) || (yd - xd);
        return yd - xd;
      });
      vis.forEach(function (c) { L.insertBefore(c, em); });
      em.hidden = vis.length > 0;
      n.textContent = 'Menampilkan ' + vis.length + ' ' + label;
    }
    ['input', 'change'].forEach(function (e) { bar.addEventListener(e, draw); });
    draw();
  })();
})();
