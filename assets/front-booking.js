(function(){
  const root = document.querySelector(".pup-booking-root");
  if (!root) return;

  const apiGet = async (p) => {
    const r = await fetch(`${PUP_FRONT.restUrl}${p}`);
    const j = await r.json().catch(()=>({}));
    if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
    return j;
  };
  const apiPost = async (p, body) => {
    const r = await fetch(`${PUP_FRONT.restUrl}${p}`, {
      method:"POST",
      headers:{ "Content-Type":"application/json" },
      body: JSON.stringify(body || {})
    });
    const j = await r.json().catch(()=>({}));
    if (!r.ok || j.ok === false) throw new Error(j.error || `HTTP ${r.status}`);
    return j;
  };

  const esc = (s)=>String(s??"").replace(/[&<>"']/g,c=>({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;" }[c]));
  const el = (html)=>{ const t=document.createElement("template"); t.innerHTML=html.trim(); return t.content.firstElementChild; };

  const todayISO = () => new Date().toISOString().slice(0,10);

  const state = {
    step: 1,
    loading: false,
    error: "",
    categories: [],
    services: [],
    options: [],
    optionsServiceId: null,
    selected: {
      categoryId: null,
      serviceId: PUP_FRONT.defaultServiceId ? Number(PUP_FRONT.defaultServiceId) : null,
      optionIds: [],
      date: todayISO(),
      slot: null, // {employee_id,time,employee_name}
      customer: {
        first_name:"", last_name:"", email:"", phone:"",
        address:"", postal_code:"", city:"",
        birthday:"" // optionnel
      },
      payment: { method:"pay_later" }
    },
    done: null
  };

  const steps = [
    {id:1, label:"Soin"},
    {id:2, label:"Options"},
    {id:3, label:"Créneau"},
    {id:4, label:"Infos"},
    {id:5, label:"Paiement"},
    {id:6, label:"Confirmation"},
  ];

  const servicesForCategory = (categoryId) => {
    const cid = Number(categoryId||0);
    return (state.services || []).filter(s => Number(s.category_id||0) === cid);
  };

  const getService = () => state.services.find(s => Number(s.id) === Number(state.selected.serviceId));

  const loadCatalog = async () => {
    const out = await apiGet(`/public/catalog`);
    state.categories = out.categories || [];
    state.services = out.services || [];

    if (state.selected.serviceId && !state.selected.categoryId) {
      const svc = state.services.find(x => Number(x.id) === Number(state.selected.serviceId));
      if (svc) state.selected.categoryId = svc.category_id ? Number(svc.category_id) : null;
    }

    if (!state.selected.categoryId && state.categories.length) state.selected.categoryId = Number(state.categories[0].id);

    if (!state.selected.serviceId) {
      const list = servicesForCategory(state.selected.categoryId);
      if (list.length) state.selected.serviceId = Number(list[0].id);
    }
  };

    const ensureOptionsLoaded = async () => {
      const sid = Number(state.selected.serviceId || 0);
      if (!sid) return;

      // Recharge si aucune option ou si elles proviennent d'un autre service
      const alreadyForService = Number(state.optionsServiceId || 0) === sid;
      if (Array.isArray(state.options) && state.options.length > 0 && alreadyForService) return;

      await loadOptions();
    };

  const loadOptions = async () => {
    state.options = [];
    state.selected.optionIds = [];
    const sid = Number(state.selected.serviceId||0);
    if (!sid) return;
    const out = await apiGet(`/public/services/${sid}/options`);
    state.options = out.items || [];
    state.optionsServiceId = sid;
  };

  const loadSlots = async () => {
    state.selected.slot = null;
    const sid = Number(state.selected.serviceId||0);
    if (!sid) throw new Error("Choisis un service.");
    const out = await apiGet(`/slots?service_id=${encodeURIComponent(sid)}&date=${encodeURIComponent(state.selected.date)}&step_min=15`);
    return out.items || [];
  };

  const canNext = () => {
    const svc = getService();
    if (state.step === 1) return !!state.selected.serviceId;
    if (state.step === 2) return true;
    if (state.step === 3) return (svc && svc.booking_mode === 'product') ? true : !!state.selected.slot;

    if (state.step === 4) {
      const c = state.selected.customer;
      const okEmail = c.email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(c.email);
      return !!(c.first_name && c.last_name && okEmail && c.phone && c.address && c.postal_code && c.city);
    }

    if (state.step === 5) return true;
    return true;
  };

  const go = (n)=>{ state.step = Math.max(1, Math.min(6, n)); render(); };

  const next = async () => {
    const svc = getService();
    if (!canNext()) return;

    if (state.step === 1) {
      state.loading = true; state.error = ""; render();
      try {
        await ensureOptionsLoaded();   // <-- le fix
        state.step = 2;
      } catch (e) {
        state.error = e.message || String(e);
      } finally {
        state.loading = false;
        render();
      }
      return;
    }

    if (state.step === 2) {
      state.step = (svc && svc.booking_mode === 'product') ? 4 : 3;
      render(); return;
    }
    if (state.step === 3) { state.step = 4; render(); return; }
    if (state.step === 4) { state.step = 5; render(); return; }
    if (state.step === 5) { await finalize(); return; }
  };

  const back = () => {
    const svc = getService();
    if (state.step === 4 && svc && svc.booking_mode === 'product') { go(2); return; }
    go(state.step - 1);
  };

  const finalize = async () => {
    const svc = getService();
    state.loading = true; state.error=""; render();

    try {
      if (svc && svc.booking_mode === 'product') {
        state.done = { message: "Produit sans créneau : à brancher sur cartes cadeaux (étape suivante)." };
        go(6);
        return;
      }

      const c = state.selected.customer;

      const payloadHold = {
        service_id: Number(state.selected.serviceId),
        employee_id: Number(state.selected.slot.employee_id),
        date: state.selected.date,
        time: state.selected.slot.time,

        first_name: c.first_name,
        last_name: c.last_name,
        email: c.email,
        phone: c.phone,

        address: c.address,
        postal_code: c.postal_code,
        city: c.city,
        birthday: c.birthday || "",

        option_ids: (state.selected.optionIds || []).map(Number),
      };

      const holdOut = await apiPost(`/public/hold`, payloadHold);

      const token = holdOut?.hold?.token;
      if (!token) throw new Error("Hold token manquant.");

      const confOut = await apiPost(`/public/confirm`, { token });

      state.done = {
        appointment_id: confOut.appointment_id,
        service: svc?.name,
        date: state.selected.date,
        time: state.selected.slot.time,
        employee: state.selected.slot.employee_name
      };
      go(6);

    } catch(e) {
      state.error = e.message || String(e);
    } finally {
      state.loading = false;
      render();
    }
  };

  const renderHeader = () => {
    return `
      <div class="pup-b-row pup-b-space">
        <div>
          <h3 style="margin:0;">Réservation</h3>
          <div class="pup-b-muted">Parcours guidé en ${steps.length} étapes.</div>
        </div>
        <div class="pup-b-muted" style="font-weight:600;">Étape ${state.step}/${steps.length} — ${esc(steps[state.step-1].label)}</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
        ${steps.map(s => `
          <div style="padding:6px 10px;border-radius:999px;border:1px solid #e6e6e6;
                      background:${s.id===state.step?'#111':'#fff'};
                      color:${s.id===state.step?'#fff':'#111'};
                      font-size:12px;">
            ${esc(s.label)}
          </div>
        `).join("")}
      </div>
    `;
  };

  const renderStep = async () => {
    const svc = getService();

    // STEP 1
    if (state.step === 1) {
      const catOptions = (state.categories||[]).map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join("");
      const svcOptions = servicesForCategory(state.selected.categoryId).map(s => (
        `<option value="${s.id}">${esc(s.name)} (${esc(s.duration_min)} min)</option>`
      )).join("");

      return `
        <div class="pup-b-grid" style="margin-top:12px;">
          <div>
            <label>Catégorie</label>
            <select class="pup-b-select" id="cat">${catOptions}</select>
          </div>
          <div>
            <label>Prestation</label>
            <select class="pup-b-select" id="svc">${svcOptions}</select>
          </div>
        </div>
      `;
    }

    // STEP 2
    if (state.step === 2) {
      const items = (state.options||[]);
      if (!items.length) return `<p class="pup-b-muted" style="margin-top:12px;">Aucune option disponible pour ce soin.</p>`;

      return `
        <div style="margin-top:12px;">
          <div class="pup-b-muted">Coche les options souhaitées.</div>
          <div style="display:flex;flex-direction:column;gap:10px;margin-top:12px;">
            ${items.map(o => `
              <label style="display:flex;gap:10px;align-items:flex-start;border:1px solid #e6e6e6;border-radius:14px;padding:12px;background:#fff;">
                <input type="checkbox" data-id="${o.id}" ${state.selected.optionIds.includes(Number(o.id))?'checked':''} />
                <div>
                  <div style="font-weight:700;">${esc(o.name)}</div>
                  ${o.description ? `<div class="pup-b-muted">${esc(o.description)}</div>` : ""}
                  <div class="pup-b-muted" style="margin-top:4px;">+${esc(o.duration_add_min)} min • ${esc(o.price)} €</div>
                </div>
              </label>
            `).join("")}
          </div>
        </div>
      `;
    }

    // STEP 3
    if (state.step === 3) {
      if (svc && svc.booking_mode === 'product') return `<p class="pup-b-muted" style="margin-top:12px;">Cette prestation ne nécessite pas de créneau.</p>`;

      return `
        <div class="pup-b-grid" style="margin-top:12px;">
          <div>
            <label>Date</label>
            <input class="pup-b-input" id="date" type="date" value="${esc(state.selected.date)}" />
          </div>
          <div>
            <label>Créneaux</label>
            <div id="slots" class="pup-b-muted">Choisis une date puis un créneau…</div>
          </div>
        </div>
      `;
    }

    // STEP 4
    if (state.step === 4) {
      const c = state.selected.customer;
      return `
        <div class="pup-b-grid" style="margin-top:12px;">
          <div><label>Prénom *</label><input class="pup-b-input" id="first" value="${esc(c.first_name)}" /></div>
          <div><label>Nom *</label><input class="pup-b-input" id="last" value="${esc(c.last_name)}" /></div>

          <div><label>Email *</label><input class="pup-b-input" id="email" value="${esc(c.email)}" /></div>
          <div><label>Téléphone *</label><input class="pup-b-input" id="phone" value="${esc(c.phone)}" /></div>

          <div style="grid-column:1/-1;"><label>Adresse *</label><input class="pup-b-input" id="address" value="${esc(c.address)}" placeholder="N° + rue" /></div>
          <div><label>Code postal *</label><input class="pup-b-input" id="postal" value="${esc(c.postal_code)}" /></div>
          <div><label>Ville *</label><input class="pup-b-input" id="city" value="${esc(c.city)}" /></div>

          <div style="grid-column:1/-1;">
            <label>Date de naissance (optionnel, mais recommandé ðÂÂÂÂÂÂ)</label>
            <input class="pup-b-input" id="birthday" type="date" value="${esc(c.birthday)}" />
            <div class="pup-b-muted" style="margin-top:6px;">ðÂÂÂÂÂÂ Renseigne-la pour profiter d’une surprise/promo le mois de ton anniversaire.</div>
          </div>
        </div>
      `;
    }

    // STEP 5
    if (state.step === 5) {
      return `
        <div style="margin-top:12px;">
          <div class="pup-b-muted">MVP : paiement “au salon”. Stripe + cartes cadeaux ensuite.</div>
          <label style="display:flex;gap:10px;align-items:center;margin-top:10px;">
            <input type="radio" name="pay" value="pay_later" checked />
            <span>Je paie au salon</span>
          </label>
        </div>
      `;
    }

    // STEP 6
    if (state.step === 6) {
      if (!state.done) return `<p class="pup-b-muted" style="margin-top:12px;">…</p>`;
      if (state.done.message) {
        return `<div style="margin-top:12px;border:1px solid #e6e6e6;border-radius:18px;padding:14px;background:#fff;">
          <div style="font-weight:800;">OK</div>
          <div class="pup-b-muted">${esc(state.done.message)}</div>
        </div>`;
      }
      return `
        <div style="margin-top:12px;border:1px solid #e6e6e6;border-radius:18px;padding:14px;background:#fff;">
          <div style="font-weight:800;">Réservation confirmée âÂÂÂÂ</div>
          <div class="pup-b-muted" style="margin-top:6px;">
            <b>${esc(state.done.service||"")}</b><br/>
            ${esc(state.done.date)} à ${esc(state.done.time)}<br/>
            Praticien : ${esc(state.done.employee||"")}
          </div>
        </div>
      `;
    }

    return "";
  };

  const wire = async () => {
    // Step 1
    if (state.step === 1) {
      const cat = root.querySelector("#cat");
      const svc = root.querySelector("#svc");

      if (cat) {
        cat.value = String(state.selected.categoryId||"");
        cat.onchange = async () => {
          state.selected.categoryId = Number(cat.value||0);
          const list = servicesForCategory(state.selected.categoryId);
          state.selected.serviceId = list.length ? Number(list[0].id) : null;

          state.loading = true; state.error=""; render();
          try { await loadOptions(); }
          catch(e){ state.error = e.message || String(e); }
          finally { state.loading=false; render(); }
        };
      }

      if (svc) {
        svc.value = String(state.selected.serviceId||"");
        svc.onchange = async () => {
          state.selected.serviceId = Number(svc.value||0);
          state.loading = true; state.error=""; render();
        const refresh = async () => {
          if (!slotsBox) return;
          slotsBox.innerHTML = `<span class="pup-b-muted">Chargement des créneaux…</span>`;
          try {
            const items = await loadSlots();
            if (!flat.length) {
              slotsBox.innerHTML = `<span class="pup-b-muted">Aucun créneau disponible pour cette date.</span>`;
              return;
            }
            slotsBox.innerHTML = `
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                ${flat.map(s => {
                  const isSel = state.selected.slot &&
                                 Number(state.selected.slot.employee_id) === Number(s.employee_id) &&
                                 String(state.selected.slot.time) === String(s.time);

                  const style = isSel
                    ? 'background:#b00020; border-color:#b00020; color:#fff; font-weight:bold;'
                    : 'background:#fff; color:#111;';

                  return `
                    <button
                      type="button"
                      class="pup-b-btn secondary pup-slot-btn ${isSel ? 'active' : ''}"
                      data-e="${s.employee_id}"
                      data-n="${esc(s.employee_name)}"
                      data-t="${esc(s.time)}"
                      style="padding:8px 12px; min-width:80px; cursor:pointer; ${style}">
                      ${esc(s.time)}
                    </button>
                  `;
                }).join("")}
              </div>
            `;
      const dateInput = root.querySelector("#date");
      const slotsBox = root.querySelector("#slots");

      const refresh = async () => {
        if (!slotsBox) return;
        slotsBox.innerHTML = `<span class="pup-b-muted">Chargement des créneaux…</span>`;
        try {
          const items = await loadSlots();
          const flat = [];
          
          // On aplatit les slots car le nouveau contrôleur renvoie une structure groupée
          items.forEach(emp => {
            if (emp.slots) {
              emp.slots.forEach(t => {
                flat.push({
                  employee_id: emp.employee_id,
                  employee_name: emp.employee_name,
                  time: t
                });
              });
            }
          });

          if (!flat.length) {
            slotsBox.innerHTML = `<span class="pup-b-muted">Aucun créneau disponible pour cette date.</span>`;
            return;
          }

          slotsBox.innerHTML = `
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              ${flat.map(s => {
                // Comparaison stricte pour la coloration
                const isSel = state.selected.slot && 

              // On re-render pour mettre à jour la coloration et le bouton Continuer
              render();
                    ${esc(s.time)}
                  </button>
                `;
              }).join("")}
            </div>
          `;

          // Gestion du clic simplifiée
          slotsBox.querySelectorAll(".pup-slot-btn").forEach(b => {
            b.onclick = (e) => {
              e.preventDefault();
              
              // 1. Mise à jour de l'état
              state.selected.slot = {
                employee_id: Number(b.getAttribute("data-e")),
                employee_name: b.getAttribute("data-n") || "",
                time: b.getAttribute("data-t") || ""
              };

              // 2. Mise à jour visuelle immédiate sans tout re-render (plus fluide)
              slotsBox.querySelectorAll(".pup-slot-btn").forEach(btn => {
                btn.style.background = "#fff";
                btn.style.color = "#111";
                btn.style.borderColor = "#e6e6e6";
              });
              b.style.background = "#b00020";
              b.style.color = "#fff";
              b.style.borderColor = "#b00020";

              // 3. Activer le bouton Continuer en bas de page
              const nextBtn = root.querySelector("#next");
              if (nextBtn) nextBtn.disabled = false;
            };
          });

        } catch(e) {
          slotsBox.innerHTML = `<span class="pup-b-err"><b>Erreur:</b> ${esc(e.message||String(e))}</span>`;
        }
      };

      if (dateInput) {
        dateInput.onchange = () => { 
          state.selected.date = dateInput.value; 
          refresh(); 
        };
      }
      refresh();
    }

    // Step 4
    if (state.step === 4) {
      const c = state.selected.customer;
      const bind = (selector, key) => {
        const input = root.querySelector(selector);
        if (!input) return;
        // On met à jour le state SANS render() pour ne pas perdre le focus
        input.oninput = () => { 
            c[key] = input.value; 
            // On active/désactive le bouton suivant dynamiquement si on veut
            const nextBtn = root.querySelector("#next");
            if (nextBtn) nextBtn.disabled = !canNext();
        };
      };

      bind("#first","first_name");
      bind("#last","last_name");
      bind("#email","email");
      bind("#phone","phone");
      bind("#address","address");
      bind("#postal","postal_code");
      bind("#city","city");

      const bd = root.querySelector("#birthday");
      if (bd) bd.onchange = () => { c.birthday = bd.value; };
    }
  };

  const render = async () => {
    root.innerHTML = "";

    const svc = getService();
    const summary = `
      <div class="pup-b-muted" style="margin-top:10px;">
        ${svc ? `<b>${esc(svc.name)}</b>` : ""}
        ${state.selected.slot ? ` • ${esc(state.selected.date)} ${esc(state.selected.slot.time)}` : ""}
      </div>
    `;

    const card = el(`
      <div class="pup-b-card">
        ${renderHeader()}
        ${summary}
        ${state.error ? `<p class="pup-b-err" style="margin-top:12px;"><b>Erreur:</b> ${esc(state.error)}</p>` : ""}
        ${state.loading ? `<p class="pup-b-muted" style="margin-top:12px;">Chargement…</p>` : ""}
        <div id="step"></div>

        <div class="pup-b-row pup-b-space" style="margin-top:16px;">
          <button class="pup-b-btn secondary" id="back" ${state.step===1?'disabled':''}>Retour</button>
          <button class="pup-b-btn" id="next" ${(state.step===6 || !canNext() || state.loading)?'disabled':''}>
            ${state.step===5 ? "Confirmer" : (state.step===6 ? "Terminé" : "Continuer")}
          </button>
        </div>
      </div>
    `);

    root.appendChild(card);

    const stepBox = card.querySelector("#step");
    stepBox.innerHTML = await renderStep();

    card.querySelector("#back").onclick = () => back();
    const nextBtn = card.querySelector("#next");
    if (nextBtn) nextBtn.onclick = () => next();

    await wire();
  };

  (async () => {
    state.loading = true; render();
    try {
      await loadCatalog();
      await loadOptions(); // IMPORTANT : options dès le départ
    } catch(e) {
      state.error = e.message || String(e);
    } finally {
      state.loading = false;
      render();
    }
  })();

})();
