(function(){
  const root = document.querySelector(".pup-manage-root");
  if (!root) return;

  const token = new URLSearchParams(location.search).get("token") || "";
  if (!token) { root.innerHTML = "<p>Token manquant.</p>"; return; }

  const apiGet = async (p) => {
    const r = await fetch(`${PUP_MANAGE.restUrl}${p}`);
    const j = await r.json().catch(()=>({}));
    if (!r.ok || j.ok===false) throw new Error(j.error || `HTTP ${r.status}`);
    return j;
  };
  const apiPost = async (p, body) => {
    const r = await fetch(`${PUP_MANAGE.restUrl}${p}`, {
      method:"POST", headers:{ "Content-Type":"application/json" },
      body: JSON.stringify(body)
    });
    const j = await r.json().catch(()=>({}));
    if (!r.ok || j.ok===false) throw new Error(j.error || `HTTP ${r.status}`);
    return j;
  };

  const esc = (s)=>String(s??"").replace(/[&<>"']/g,c=>({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;" }[c]));
  const render = (html)=> root.innerHTML = html;

  let appt = null;

  const load = async () => {
    const out = await apiGet(`/public/manage?token=${encodeURIComponent(token)}`);
    appt = out.appointment;
  };

  const show = () => {
    render(`
      <div style="background:#fff;border:1px solid #e6e6e6;border-radius:18px;padding:16px;max-width:760px;">
        <h3 style="margin:0 0 8px 0;">Gérer mon rendez-vous</h3>
        <p style="margin:0 0 12px 0;">
          <b>${esc(appt.service)}</b><br>
          ${esc(appt.start_dt)}<br>
          Statut : <b>${esc(appt.status)}</b>
        </p>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button id="cancel" style="padding:10px 14px;border-radius:12px;border:0;background:#111;color:#fff;cursor:pointer;">Annuler</button>
          <button id="resched" style="padding:10px 14px;border-radius:12px;border:1px solid #111;background:#fff;color:#111;cursor:pointer;">Reporter</button>
        </div>

        <div id="panel" style="margin-top:14px;"></div>
      </div>
    `);

    document.getElementById("cancel").onclick = async () => {
      await apiPost("/public/manage/cancel", { token });
      location.reload();
    };

    document.getElementById("resched").onclick = () => rescheduleUI();
  };

  const rescheduleUI = async () => {
    const panel = document.getElementById("panel");
    const today = new Date().toISOString().slice(0,10);

    panel.innerHTML = `
      <div style="border-top:1px solid #eee; margin-top:14px; padding-top:14px;">
        <h4 style="margin:0 0 8px 0;">Choisir un nouveau créneau</h4>
        <label>Date</label><br>
        <input id="d" type="date" value="${esc(today)}" style="padding:10px;border:1px solid #ddd;border-radius:12px;width:220px;">
        <div id="slots" style="margin-top:12px;"></div>
      </div>
    `;
    let chosen = { date: null, time: null };
    const loadSlots = async () => {
      const d = document.getElementById("d").value;
      const out = await apiGet(`/slots?service_id=${encodeURIComponent(appt.service_id)}&date=${encodeURIComponent(d)}&step_min=15`);
      const items = out.items || [];
    
      const wrap = document.getElementById("slots");
      if (!items.length) {
        wrap.innerHTML = "<p>Aucun créneau.</p>";
        return;
      }
    
      wrap.innerHTML = `
        ${items.map(emp => `
          <div style="border:1px solid #eee;border-radius:14px;padding:12px;margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <b>${esc(emp.employee_name)}</b>
              <span style="color:#666">${(emp.slots||[]).length} créneaux</span>
            </div>
    
            <div class="pup-slots" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
              ${(emp.slots||[]).map(t => `
                <button class="slot"
                  data-date="${esc(d)}"
                  data-time="${esc(t)}"
                  style="padding:8px 10px;border-radius:999px;border:1px solid #ddd;background:#fff;cursor:pointer;">
                  ${esc(t)}
                </button>
              `).join("")}
            </div>
    
            <div style="display:flex;justify-content:flex-end;margin-top:12px;gap:10px;align-items:center;">
              <span id="chosenLabel" style="color:#666;"></span>
              <button id="confirmResched"
                disabled
                style="padding:10px 14px;border-radius:12px;border:0;background:#111;color:#fff;cursor:pointer;opacity:.45;">
                Valider le report
              </button>
            </div>
          </div>
        `).join("")}
      `;
    
      // handlers
      const updateConfirmBtn = () => {
        const btn = document.getElementById("confirmResched");
        const lbl = document.getElementById("chosenLabel");
        if (!btn || !lbl) return;
    
        if (chosen.date && chosen.time) {
          lbl.textContent = `Sélection : ${chosen.date} ${chosen.time}`;
          btn.disabled = false;
          btn.style.opacity = "1";
        } else {
          lbl.textContent = "";
          btn.disabled = true;
          btn.style.opacity = ".45";
        }
      };
    
      wrap.querySelectorAll("button.slot").forEach(btn => {
        btn.onclick = () => {
          // deselect all
          wrap.querySelectorAll("button.slot").forEach(b => {
            b.style.borderColor = "#ddd";
            b.style.background = "#fff";
          });
    
          // select this
          btn.style.borderColor = "#111";
          btn.style.background = "#f3f3f3";
    
          chosen.date = btn.getAttribute("data-date");
          chosen.time = btn.getAttribute("data-time");
          updateConfirmBtn();
        };
      });
    
      document.getElementById("confirmResched").onclick = async () => {
        const btn = document.getElementById("confirmResched");
        btn.disabled = true;
        btn.textContent = "Report en cours…";
        await apiPost("/public/manage/reschedule", { token, date: chosen.date, time: chosen.time });
        location.reload();
      };
    
      updateConfirmBtn();
    };

    document.getElementById("d").onchange = loadSlots;
    await loadSlots();
  };

  (async () => {
    try { await load(); show(); }
    catch(e){ render(`<p style="color:#b00020;"><b>Erreur :</b> ${esc(e.message)}</p>`); }
  })();
})();
