(function () {
  var el = document.getElementById('auction'); if (!el) return;
  var id = el.dataset.id, csrf = el.dataset.csrf, end = +el.dataset.end, skew = +el.dataset.now - Date.now() / 1000, over = false;
  var $ = function (i) { return document.getElementById(i); };
  function pad(n) { return String(n).padStart(2, '0'); }
  function tick() {
    var s = Math.max(0, Math.floor(end - (Date.now() / 1000 + skew)));
    $('d').textContent = pad(Math.floor(s / 86400)); $('h').textContent = pad(Math.floor(s % 86400 / 3600));
    $('m').textContent = pad(Math.floor(s % 3600 / 60)); $('s').textContent = pad(s % 60);
    if (s === 0 && !over) { over = true; $('stat').innerHTML = '<span class="st">Ended</span>'; var b = $('bid'); if (b) b.disabled = true; setTimeout(function () { location.reload(); }, 2000); }
  }
  function apply(st) {
    if (!st) return;
    skew = st.now - Date.now() / 1000;
    $('cur').textContent = st.current; $('nb').textContent = st.bids; if ($('min')) $('min').textContent = st.min;
    if (st.ended && !over) { over = true; location.reload(); }
  }
  function call(opts) { return fetch('/auctions/api.php' + (opts ? '' : '?id=' + id), opts).then(function (r) { return r.json(); }); }
  function poll() { if (document.hidden || over) return; call().then(function (r) { apply(r.state); }).catch(function () {}); }
  var btn = $('bid');
  if (btn) btn.addEventListener('click', function () {
    var msg = $('msg'), body = new URLSearchParams({ id: id, amount: $('amt').value, csrf: csrf });
    btn.disabled = true;
    call({ method: 'POST', body: body, headers: { 'X-CSRF-Token': csrf } }).then(function (r) {
      msg.className = 'alert ' + (r.ok ? 'ok' : 'err'); msg.textContent = r.ok ? r.message : r.error;
      if (r.ok) $('amt').value = ''; apply(r.state);
    }).catch(function () { msg.className = 'alert err'; msg.textContent = 'Network error. Please try again.'; }).finally(function () { btn.disabled = over; });
  });
  tick(); setInterval(tick, 1000); setInterval(poll, 5000);
})();
