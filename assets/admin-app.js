(function () {
  const root = document.getElementById("pup-admin-root");
  if (!root) return;

  const api = async (path, opts = {}) => {
    const res = await fetch(`${PUP_ADMIN.restUrl}${path}`, {
      ...opts,
      headers: {
        "Content-Type": "application/json",
        "X-WP-Nonce": PUP_ADMIN.nonce,
        ...(opts.headers || {})
      }
    });

    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.ok === false) {
      const msg = json.error || `HTTP ${res.status}`;
      throw new Error(msg);
    }
    return json;
  };

  const state = {
    tab: "services",
    route: "tabs", // "tabs" | "planning" | "serviceOptions"
    planning: {
      employeeId: null,
      employeeName: "",
      schedules: [],
      exceptions: [],
      loading: false,
      error: "",
      dirty: false
    },
    serviceOptions: {
      serviceId: null,
      serviceName: "",
      items: [],
      loading: false,
      error: "",
      dirty: false
    },
    services: { items: [], modal: null, loading: false, error: "" },
    employees: { items: [], modal: null, loading: false, error: "" },
    categories: { items: [], modal: null, loading: false, error: "" },
    visibility: { categories: [], customerCategories: [], rules: {}, loading: false, error: "" },
    options: { items: [], modal: null, loading: false, error: "" },
  };

  // NOTE: h() retourne un Node (firstChild). Ne pas l'utiliser pour <tr> si tu veux querySelector sur le résultat.
  const h = (html) => {
    const d = document.createElement("div");
    d.innerHTML = String(html).trim();
    return d.firstChild;
  };

  function htmlToElement(html) {
    const tpl = document.createElement("template");
    tpl.innerHTML = String(html).trim();
    return tpl.content.firstElementChild;
  }

  const escapeHtml = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#039;"
  }[c]));
  const escapeAttr = (s) => escapeHtml(s).replace(/"/g, "&quot;");

  /* -----------------------------
     LOADERS
  ------------------------------*/
  const loadServices = async () => {
    const S = state.services;
    S.loading = true; S.error = ""; render();
    try {
      const out = await api(`/admin/services`);
      S.items = out.items || [];
    } catch (e) {
      S.error = e.message || String(e);
    } finally {
      S.loading = false; render();
    }
  };

  const loadEmployees = async () => {
    const E = state.employees;
    E.loading = true; E.error = ""; render();
    try {
      const out = await api(`/admin/employees`);
      E.items = out.items || [];
    } catch (e) {
      E.error = e.message || String(e);
    } finally {
      E.loading = false; render();
    }
  };

  const loadCategories = async () => {
    const C = state.categories;
    C.loading = true; C.error = ""; render();
    try {
      const out = await api(`/admin/categories`);
      C.items = out.items || [];
    } catch (e) {
      C.error = e.message || String(e);
    } finally {
      C.loading = false; render();
    }
  };

  const loadOptions = async () => {
    const O = state.options;
    O.loading = true; O.error = ""; render();
    try {
      const out = await api(`/admin/options`);
      O.items = out.items || [];
    } catch (e) {
      O.error = e.message || String(e);
    } finally {
      O.loading = false; render();
    }
  };

  /* -----------------------------
     CATEGORY CRUD
  ------------------------------*/
  const openCategoryModal = (item = null) => {
    state.categories.modal = item ? { ...item } : {
      id: null,
      name: "",
      slug: "",
      sort_order: 0,
      is_active: 1
    };
    render();
  };

  const saveCategoryModal = async () => {
    const C = state.categories;
    const m = C.modal;

    const payload = {
      name: m.name,
      slug: m.slug,
      sort_order: Number(m.sort_order || 0),
      is_active: Number(m.is_active) ? 1 : 0
    };

    C.error = ""; render();

    try {
      if (m.id) {
        await api(`/admin/categories/${m.id}`, { method: "PUT", body: JSON.stringify(payload) });
      } else {
        await api(`/admin/categories`, { method: "POST", body: JSON.stringify(payload) });
      }
      C.modal = null;
      await loadCategories();
    } catch (e) {
      C.error = e.message || String(e);
      render();
    }
  };

  const disableCategory = async (id) => {
    if (!confirm("Désactiver cette catégorie ?")) return;
    try {
      await api(`/admin/categories/${id}`, { method: "DELETE" });
      await loadCategories();
    } catch (e) {
      alert(e.message || String(e));
    }
  };

  /* -----------------------------
     VISIBILITY MATRIX
  ------------------------------*/
  const loadVisibilityMatrix = async () => {
    const V = state.visibility;
    V.loading = true; V.error = ""; render();
    try {
      const out = await api(`/admin/category-visibility`);
      V.categories = out.categories || [];
      V.customerCategories = out.customer_categories || [];
      V.rules = out.rules || {};
    } catch (e) {
      V.error = e.message || String(e);
    } finally {
      V.loading = false; render();
    }
  };

  const saveVisibilityRule = async (categoryId, customerCategoryId, isVisible) => {
    const V = state.visibility;
    try {
      await api(`/admin/category-visibility`, {
        method: "POST",
        body: JSON.stringify({
          category_id: Number(categoryId),
          customer_category_id: Number(customerCategoryId),
          is_visible: isVisible ? 1 : 0
        })
      });

      if (!V.rules[categoryId]) V.rules[categoryId] = {};
      V.rules[categoryId][customerCategoryId] = isVisible ? 1 : 0;
      render();
    } catch (e) {
      alert(e.message || String(e));
    }
  };

  /* -----------------------------
     OPTIONS CRUD
  ------------------------------*/
  const openOptionModal = (item = null) => {
    state.options.modal = item ? { ...item } : {
      id: null,
      name: "",
      description: "",
      price: "0.00",
      duration_add_min: 0,
      is_active: 1
    };
    render();
  };

  const saveOptionModal = async () => {
    const O = state.options;
    const m = O.modal;

    const payload = {
      name: m.name,
      description: m.description,
      price: m.price,
      duration_add_min: Number(m.duration_add_min || 0),
      is_active: Number(m.is_active) ? 1 : 0
    };

    O.error = ""; render();

    try {
      if (m.id) {
        await api(`/admin/options/${m.id}`, { method: "PUT", body: JSON.stringify(payload) });
      } else {
        await api(`/admin/options`, { method: "POST", body: JSON.stringify(payload) });
      }
      O.modal = null;
      await loadOptions();
    } catch (e) {
      O.error = e.message || String(e);
      render();
    }
  };

  const disableOption = async (id) => {
    if (!confirm("Désactiver cette option ?")) return;
    try {
      await api(`/admin/options/${id}`, { method: "DELETE" });
      await loadOptions();
    } catch (e) {
      alert(e.message || String(e));
    }
  };

  /* -----------------------------
     SERVICE -> OPTIONS screen
  ------------------------------*/
  const openServiceOptions = async (serviceId) => {
    const S = state.services.items.find(x => Number(x.id) === Number(serviceId));
    state.route = "serviceOptions";
    state.serviceOptions.serviceId = Number(serviceId);
    state.serviceOptions.serviceName = S?.name || `Service #${serviceId}`;
    await loadServiceOptions();
  };

  const loadServiceOptions = async () => {
    const P = state.serviceOptions;
    P.loading = true; P.error = ""; P.dirty = false; render();
    try {
      const out = await api(`/admin/services/${P.serviceId}/options`);
      P.items = out.items || [];
    } catch (e) {
      P.error = e.message || String(e);
    } finally {
      P.loading = false; render();
    }
  };

  const saveServiceOptions = async () => {
    const P = state.serviceOptions;
    P.loading = true; P.error = ""; render();

    const items = (P.items || [])
      .filter(r => Number(r._linked || r.linked) === 1)
      .map(r => ({
        option_id: Number(r.id),
        sort_order: Number(r.sort_order || 0),
        price_override: (r.price_override === "" || r.price_override === null) ? null : String(r.price_override),
        duration_override_min: (r.duration_override_min === "" || r.duration_override_min === null) ? null : Number(r.duration_override_min),
        is_active: Number(r.link_is_active ?? 1) ? 1 : 0
      }));

    try {
      const out = await api(`/admin/services/${P.serviceId}/options`, {
        method: "PUT",
        body: JSON.stringify({ items })
      });
      P.items = out.items || [];
      P.dirty = false;
    } catch (e) {
      P.error = e.message || String(e);
    } finally {
      P.loading = false; render();
    }
  };

  /* -----------------------------
     SERVICE -> EMPLOYEES (resources)
     Endpoints attendus:
       GET /admin/services/{id}/employees  -> { ok:true, items:[{id,...}] }
       PUT /admin/services/{id}/employees  -> { ok:true, items:[...] } (ou ok true)
  ------------------------------*/
  const loadServiceEmployees = async (serviceId) => {
    if (!serviceId) return [];
    try {
      const out = await api(`/admin/services/${serviceId}/employees`);
      const items = out.items || [];
      return items.map(x => Number(x.id));
    } catch (e) {
      // On ne bloque pas l'UI si endpoint pas encore installé
      console.warn("loadServiceEmployees failed:", e);
      return [];
    }
  };

  const saveServiceEmployees = async (serviceId, employeeIds) => {
    if (!serviceId) return;
    try {
      await api(`/admin/services/${serviceId}/employees`, {
        method: "PUT",
        body: JSON.stringify({ employee_ids: employeeIds || [] })
      });
    } catch (e) {
      alert(e.message || String(e));
    }
  };

  /* -----------------------------
     SERVICES MODAL ACTIONS
     -> multi employee_ids
  ------------------------------*/
  const openServiceModal = async (item = null) => {
    if (state.categories.items.length === 0) await loadCategories();
    if (state.employees.items.length === 0) await loadEmployees();

    const base = item ? { ...item } : {
      id: null, name: "", description: "",
      category_id: null,
      booking_mode: "slot",
      duration_min: 60, buffer_before_min: 0, buffer_after_min: 10,
      type: "individual", capacity_max: 6,
      min_notice_min: 0, cancel_limit_min: 1440,
      is_active: 1
    };

    // Nouveau: employee_ids = ressources nécessaires
    base.employee_ids = [];
    if (base.id) {
      base.employee_ids = await loadServiceEmployees(Number(base.id));
    }

    state.services.modal = base;
    render();
  };

  const saveServiceModal = async () => {
    const S = state.services;
    const m = S.modal;

    const payload = {
      name: m.name,
      category_id: (m.category_id === "" || m.category_id === null) ? null : Number(m.category_id),
      booking_mode: m.booking_mode || "slot",
      description: m.description,
      duration_min: Number(m.duration_min),
      buffer_before_min: Number(m.buffer_before_min),
      buffer_after_min: Number(m.buffer_after_min),
      type: m.type,
      capacity_max: (m.type === "capacity") ? Number(m.capacity_max || 1) : null,
      min_notice_min: Number(m.min_notice_min),
      cancel_limit_min: Number(m.cancel_limit_min),
      is_active: Number(m.is_active) ? 1 : 0
    };

    S.error = ""; render();

    try {
      let serviceId = m.id;

      if (m.id) {
        await api(`/admin/services/${m.id}`, { method: "PUT", body: JSON.stringify(payload) });
        serviceId = m.id;
      } else {
        // IMPORTANT: ton endpoint POST doit renvoyer l'id créé: {ok:true, id:123}
        const out = await api(`/admin/services`, { method: "POST", body: JSON.stringify(payload) });
        serviceId = out.id || out.service_id || null;
        if (!serviceId) throw new Error("Création service: id manquant dans la réponse API");
      }

      // Sauvegarde ressources nécessaires
      await saveServiceEmployees(Number(serviceId), m.employee_ids || []);

      S.modal = null;
      await loadServices();

    } catch (e) {
      S.error = e.message || String(e);
      render();
    }
  };

  const disableService = async (id) => {
    if (!confirm("Désactiver ce service ?")) return;
    try {
      await api(`/admin/services/${id}`, { method: "DELETE" });
      await loadServices();
    } catch (e) { alert(e.message || String(e)); }
  };

  const seedServices = async () => {
    if (!confirm("Créer 3 services de démo (si la table est vide) ?")) return;
    try {
      await api(`/admin/services/seed`, { method: "POST", body: "{}" });
      await loadServices();
    } catch (e) { alert(e.message || String(e)); }
  };

  /* -----------------------------
     EMPLOYEES MODAL ACTIONS
     -> kind + capacity
  ------------------------------*/
  const openEmployeeModal = (item = null) => {
    state.employees.modal = item ? { ...item } : {
      id: null,
      wp_user_id: "",
      display_name: "",
      email: "",
      timezone: "Europe/Paris",
      kind: "human",     // NEW
      capacity: 1,       // NEW
      is_active: 1,
      google_sync_enabled: 0
    };

    if (state.employees.modal.kind == null) state.employees.modal.kind = "human";
    if (state.employees.modal.capacity == null) state.employees.modal.capacity = 1;

    render();
  };

  const saveEmployeeModal = async () => {
    const E = state.employees;
    const m = E.modal;

    const payload = {
      wp_user_id: (m.wp_user_id === "" || m.wp_user_id === null) ? null : Number(m.wp_user_id),
      display_name: m.display_name,
      email: m.email,
      timezone: m.timezone || "Europe/Paris",
      kind: (m.kind === "resource") ? "resource" : "human",
      capacity: Math.max(1, Number(m.capacity || 1)),
      is_active: Number(m.is_active) ? 1 : 0,
      google_sync_enabled: Number(m.google_sync_enabled) ? 1 : 0
    };

    E.error = ""; render();

    try {
      if (m.id) {
        await api(`/admin/employees/${m.id}`, { method: "PUT", body: JSON.stringify(payload) });
      } else {
        await api(`/admin/employees`, { method: "POST", body: JSON.stringify(payload) });
      }
      E.modal = null;
      await loadEmployees();
    } catch (e) {
      E.error = e.message || String(e);
      render();
    }
  };

  const disableEmployee = async (id) => {
    if (!confirm("Désactiver cet employé ?")) return;
    try {
      await api(`/admin/employees/${id}`, { method: "DELETE" });
      await loadEmployees();
    } catch (e) { alert(e.message || String(e)); }
  };

  /* -----------------------------
     PLANNING
  ------------------------------*/
  const dayNames = {
    1: "Lundi", 2: "Mardi", 3: "Mercredi", 4: "Jeudi", 5: "Vendredi", 6: "Samedi", 7: "Dimanche"
  };

  const openPlanning = async (employeeId) => {
    const E = state.employees.items.find(x => Number(x.id) === Number(employeeId));
    state.route = "planning";
    state.planning.employeeId = Number(employeeId);
    state.planning.employeeName = E?.display_name || `Employé #${employeeId}`;
    await loadPlanning();
  };

  const loadPlanning = async () => {
    const P = state.planning;
    P.loading = true; P.error = ""; P.dirty = false; render();

    try {
      const out = await api(`/admin/employees/${P.employeeId}/planning`);
      P.schedules = out.schedules || [];
      P.exceptions = out.exceptions || [];
    } catch (e) {
      P.error = e.message || String(e);
    } finally {
      P.loading = false;
      render();
    }
  };

  const savePlanning = async () => {
    const P = state.planning;
    P.error = ""; P.loading = true; render();

    const payload = {
      schedules: P.schedules.map(s => ({
        weekday: Number(s.weekday),
        start_time: String(s.start_time || "").slice(0, 8),
        end_time: String(s.end_time || "").slice(0, 8),
      })),
      exceptions: P.exceptions.map(ex => ({
        date: ex.date,
        type: ex.type || "closed",
        start_time: ex.start_time ? String(ex.start_time).slice(0, 8) : null,
        end_time: ex.end_time ? String(ex.end_time).slice(0, 8) : null,
        note: ex.note || ""
      }))
    };

    try {
      const out = await api(`/admin/employees/${P.employeeId}/planning`, {
        method: "PUT",
        body: JSON.stringify(payload)
      });
      P.schedules = out.schedules || [];
      P.exceptions = out.exceptions || [];
      P.dirty = false;
    } catch (e) {
      P.error = e.message || String(e);
    } finally {
      P.loading = false;
      render();
    }
  };

  /* -----------------------------
     HELPERS TIME (arrondi 15 min)
  ------------------------------*/
  const roundToQuarter = (hhmm) => {
    const [h, m] = String(hhmm || "").split(":").map(Number);
    if (!Number.isFinite(h) || !Number.isFinite(m)) return hhmm;
    const total = h * 60 + m;
    const rounded = Math.round(total / 15) * 15;
    const hh = String(Math.floor(rounded / 60) % 24).padStart(2, "0");
    const mm = String(rounded % 60).padStart(2, "0");
    return `${hh}:${mm}`;
  };

  const normalizeTimeValue = (t) => {
    const hhmm = String(t || "").slice(0, 5);
    return roundToQuarter(hhmm);
  };

  /* -----------------------------
     RENDER VIEWS
  ------------------------------*/
  const renderHeader = () => h(`
    <div class="pup-card">
      <div class="pup-row pup-space">
        <div>
          <h1 style="margin:0;">PUP Booking</h1>
          <div class="pup-muted">Admin — services & employés (UI JS)</div>
        </div>
        <div class="pup-tabs">
          <div class="pup-tab" data-tab="services" data-active="${state.tab === 'services' ? 1 : 0}">Services</div>
          <div class="pup-tab" data-tab="employees" data-active="${state.tab === 'employees' ? 1 : 0}">Employés</div>
          <div class="pup-tab" data-tab="categories" data-active="${state.tab === 'categories' ? 1 : 0}">Catégories</div>
          <div class="pup-tab" data-tab="visibility" data-active="${state.tab === 'visibility' ? 1 : 0}">Visibilité</div>
          <div class="pup-tab" data-tab="options" data-active="${state.tab === 'options' ? 1 : 0}">Options</div>
        </div>
      </div>
    </div>
  `);

  const renderServices = () => {
    const S = state.services;

    const catById = new Map((state.categories.items || []).map(c => [Number(c.id), c]));

    const view = h(`
      <div class="pup-card">
        <div class="pup-row pup-space">
          <div>
            <h2 style="margin:0;">Services</h2>
            <div class="pup-muted">Durée, type, catégorie, ressources nécessaires, options.</div>
          </div>
          <div class="pup-row">
            <button class="pup-btn secondary" id="seed">Seed démo</button>
            <button class="pup-btn" id="add">+ Ajouter</button>
          </div>
        </div>

        ${S.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(S.error)}</p>` : ""}
        ${S.loading ? `<p class="pup-muted">Chargement…</p>` : ""}

        <table class="pup-table">
          <thead>
            <tr>
              <th>Nom</th><th>Type</th><th>Catégorie</th><th>Durée</th><th>Buffers</th><th>Capacité</th><th>Actif</th><th></th>
            </tr>
          </thead>
          <tbody>
            ${(S.items || []).map(it => {
              const cat = catById.get(Number(it.category_id));
              const catName = cat ? cat.name : "-";
              return `
                <tr>
                  <td><b>${escapeHtml(it.name)}</b></td>
                  <td><span class="pup-badge">${escapeHtml(it.type)}</span></td>
                  <td>${escapeHtml(catName)}</td>
                  <td>${Number(it.duration_min)} min</td>
                  <td>${Number(it.buffer_before_min)}/${Number(it.buffer_after_min)}</td>
                  <td>${it.capacity_max ?? "-"}</td>
                  <td>${Number(it.is_active) ? "✅" : "—"}</td>
                  <td>
                    <button class="pup-btn secondary" data-opt="${it.id}">Options</button>
                    <button class="pup-btn secondary" data-edit="${it.id}">Éditer</button>
                    <button class="pup-btn danger" data-del="${it.id}">Désactiver</button>
                  </td>
                </tr>
              `;
            }).join("")}
          </tbody>
        </table>
      </div>
    `);

    view.querySelector("#add").onclick = async () => openServiceModal(null);
    view.querySelector("#seed").onclick = () => seedServices();

    view.querySelectorAll("[data-edit]").forEach(btn => {
      btn.onclick = () => {
        const id = Number(btn.getAttribute("data-edit"));
        const item = S.items.find(x => Number(x.id) === id);
        openServiceModal(item);
      };
    });

    view.querySelectorAll("[data-del]").forEach(btn => {
      btn.onclick = () => disableService(Number(btn.getAttribute("data-del")));
    });

    view.querySelectorAll("[data-opt]").forEach(btn => {
      btn.onclick = () => openServiceOptions(Number(btn.getAttribute("data-opt")))
        .catch(e => { alert(e.message || String(e)); });
    });

    return view;
  };

  const renderEmployees = () => {
    const E = state.employees;

    const view = h(`
      <div class="pup-card">
        <div class="pup-row pup-space">
          <div>
            <h2 style="margin:0;">Employés / Ressources</h2>
            <div class="pup-muted">Humains + ressources (sauna, hammam…) partagent planning/exceptions.</div>
          </div>
          <div class="pup-row">
            <button class="pup-btn" id="add">+ Ajouter</button>
          </div>
        </div>

        ${E.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(E.error)}</p>` : ""}
        ${E.loading ? `<p class="pup-muted">Chargement…</p>` : ""}

        <table class="pup-table">
          <thead>
            <tr>
              <th>Nom</th><th>Type</th><th>Capacité</th><th>Email</th><th>Timezone</th><th>Google</th><th>Actif</th><th></th>
            </tr>
          </thead>
          <tbody>
            ${(E.items || []).map(it => {
              const kind = (it.kind === "resource") ? "Ressource" : "Humain";
              return `
                <tr>
                  <td><b>${escapeHtml(it.display_name)}</b></td>
                  <td><span class="pup-badge">${escapeHtml(kind)}</span></td>
                  <td>${Number(it.capacity || 1)}</td>
                  <td>${escapeHtml(it.email || "-")}</td>
                  <td><span class="pup-badge">${escapeHtml(it.timezone || "Europe/Paris")}</span></td>
                  <td>${Number(it.google_sync_enabled) ? "✅" : "—"}</td>
                  <td>${Number(it.is_active) ? "✅" : "—"}</td>
                  <td>
                    <button class="pup-btn secondary" data-plan="${it.id}">Planning</button>
                    <button class="pup-btn secondary" data-edit="${it.id}">Éditer</button>
                    <button class="pup-btn danger" data-del="${it.id}">Désactiver</button>
                  </td>
                </tr>
              `;
            }).join("")}
          </tbody>
        </table>
      </div>
    `);

    view.querySelector("#add").onclick = () => openEmployeeModal(null);

    view.querySelectorAll("[data-edit]").forEach(btn => {
      btn.onclick = () => {
        const id = Number(btn.getAttribute("data-edit"));
        const item = E.items.find(x => Number(x.id) === id);
        openEmployeeModal(item);
      };
    });

    view.querySelectorAll("[data-del]").forEach(btn => {
      btn.onclick = () => disableEmployee(Number(btn.getAttribute("data-del")));
    });

    view.querySelectorAll("[data-plan]").forEach(btn => {
      btn.onclick = () => openPlanning(Number(btn.getAttribute("data-plan")));
    });

    return view;
  };

  const renderCategories = () => {
    const C = state.categories;

    const view = h(`
      <div class="pup-card">
        <div class="pup-row pup-space">
          <div>
            <h2 style="margin:0;">Catégories</h2>
            <div class="pup-muted">Ex : Massage, Soirées/Événements, Carte Cadeau…</div>
          </div>
          <div class="pup-row">
            <button class="pup-btn" id="add">+ Ajouter</button>
          </div>
        </div>

        ${C.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(C.error)}</p>` : ""}
        ${C.loading ? `<p class="pup-muted">Chargement…</p>` : ""}

        <table class="pup-table">
          <thead>
            <tr>
              <th>Nom</th><th>Slug</th><th>Ordre</th><th>Actif</th><th></th>
            </tr>
          </thead>
          <tbody>
            ${(C.items || []).map(it => `
              <tr>
                <td><b>${escapeHtml(it.name)}</b></td>
                <td><span class="pup-badge">${escapeHtml(it.slug || "")}</span></td>
                <td>${Number(it.sort_order || 0)}</td>
                <td>${Number(it.is_active) ? "✅" : "—"}</td>
                <td>
                  <button class="pup-btn secondary" data-edit="${it.id}">Éditer</button>
                  <button class="pup-btn danger" data-del="${it.id}">Désactiver</button>
                </td>
              </tr>
            `).join("")}
          </tbody>
        </table>
      </div>
    `);

    view.querySelector("#add").onclick = () => openCategoryModal(null);

    view.querySelectorAll("[data-edit]").forEach(btn => {
      btn.onclick = () => {
        const id = Number(btn.getAttribute("data-edit"));
        const item = C.items.find(x => Number(x.id) === id);
        openCategoryModal(item);
      };
    });

    view.querySelectorAll("[data-del]").forEach(btn => {
      btn.onclick = () => disableCategory(Number(btn.getAttribute("data-del")));
    });

    return view;
  };

  const renderVisibility = () => {
    const V = state.visibility;

    const view = h(`
      <div class="pup-card">
        <div class="pup-row pup-space">
          <div>
            <h2 style="margin:0;">Visibilité des catégories</h2>
            <div class="pup-muted">Masquer/afficher une catégorie selon la catégorie client.</div>
          </div>
          <div class="pup-row">
            <button class="pup-btn secondary" id="reload">Recharger</button>
          </div>
        </div>

        ${V.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(V.error)}</p>` : ""}
        ${V.loading ? `<p class="pup-muted">Chargement…</p>` : ""}

        <div class="pup-muted" style="margin:10px 0;">
          Astuce : si aucune règle n’existe, on considère "visible par défaut" (MVP).
        </div>

        <div style="overflow:auto;">
          <table class="pup-table" style="min-width:800px;">
            <thead>
              <tr>
                <th>Catégorie \\ Catégorie client</th>
                ${(V.customerCategories || []).map(cc => `<th>${escapeHtml(cc.name)}</th>`).join("")}
              </tr>
            </thead>
            <tbody>
              ${(V.categories || []).map(cat => `
                <tr>
                  <td><b>${escapeHtml(cat.name)}</b></td>
                  ${(V.customerCategories || []).map(cc => {
                    const cur = (V.rules?.[cat.id]?.[cc.id]);
                    const checked = (cur === undefined) ? true : (Number(cur) === 1);
                    return `
                      <td style="text-align:center;">
                        <input type="checkbox" data-vis data-cat="${cat.id}" data-cc="${cc.id}" ${checked ? "checked" : ""}>
                      </td>
                    `;
                  }).join("")}
                </tr>
              `).join("")}
            </tbody>
          </table>
        </div>
      </div>
    `);

    view.querySelector("#reload").onclick = () => loadVisibilityMatrix();

    view.querySelectorAll("[data-vis]").forEach(chk => {
      chk.onchange = () => {
        const catId = Number(chk.getAttribute("data-cat"));
        const ccId = Number(chk.getAttribute("data-cc"));
        saveVisibilityRule(catId, ccId, chk.checked);
      };
    });

    return view;
  };

  const renderOptions = () => {
    const O = state.options;

    const view = h(`
      <div class="pup-card">
        <div class="pup-row pup-space">
          <div>
            <h2 style="margin:0;">Options</h2>
            <div class="pup-muted">Ex : Sauna +15 min, Huiles chaudes, Hammam…</div>
          </div>
          <div class="pup-row">
            <button class="pup-btn" id="add">+ Ajouter</button>
          </div>
        </div>

        ${O.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(O.error)}</p>` : ""}
        ${O.loading ? `<p class="pup-muted">Chargement…</p>` : ""}

        <table class="pup-table">
          <thead>
            <tr>
              <th>Nom</th><th>Prix</th><th>+ Durée</th><th>Actif</th><th></th>
            </tr>
          </thead>
          <tbody>
            ${(O.items || []).map(it => `
              <tr>
                <td><b>${escapeHtml(it.name)}</b></td>
                <td>${Number(it.price || 0).toFixed(2)} €</td>
                <td>+${Number(it.duration_add_min || 0)} min</td>
                <td>${Number(it.is_active) ? "✅" : "—"}</td>
                <td>
                  <button class="pup-btn secondary" data-edit="${it.id}">Éditer</button>
                  <button class="pup-btn danger" data-del="${it.id}">Désactiver</button>
                </td>
              </tr>
            `).join("")}
          </tbody>
        </table>
      </div>
    `);

    view.querySelector("#add").onclick = () => openOptionModal(null);

    view.querySelectorAll("[data-edit]").forEach(btn => {
      btn.onclick = () => {
        const id = Number(btn.getAttribute("data-edit"));
        const item = O.items.find(x => Number(x.id) === id);
        openOptionModal(item);
      };
    });

    view.querySelectorAll("[data-del]").forEach(btn => {
      btn.onclick = () => disableOption(Number(btn.getAttribute("data-del")));
    });

    return view;
  };

  const renderServiceOptions = () => {
    const P = state.serviceOptions;

    const view = h(`
      <div class="pup-card">
        <div class="pup-row pup-space">
          <div>
            <div class="pup-link" id="back">← Retour</div>
            <h2 style="margin:6px 0 0 0;">Options — ${escapeHtml(P.serviceName)}</h2>
            <div class="pup-muted">Choisis les options proposées pour ce service + overrides.</div>
          </div>

          <div class="pup-row">
            <button class="pup-btn secondary" id="reload">Recharger</button>
            <button class="pup-btn" id="save" ${P.dirty ? "" : "disabled"}>Enregistrer</button>
          </div>
        </div>

        ${P.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(P.error)}</p>` : ""}
        ${P.loading ? `<p class="pup-muted">Chargement…</p>` : ""}

        <table class="pup-table">
          <thead>
            <tr>
              <th>Activer</th><th>Option</th><th>Prix</th><th>+Durée</th><th>Ordre</th><th>Override prix</th><th>Override durée</th><th>Lien actif</th>
            </tr>
          </thead>
          <tbody id="rows"></tbody>
        </table>
      </div>
    `);

    view.querySelector("#back").onclick = () => {
      state.route = "tabs";
      render();
    };
    view.querySelector("#reload").onclick = () => loadServiceOptions();
    view.querySelector("#save").onclick = () => saveServiceOptions();

    const tbody = view.querySelector("#rows");

    (P.items || []).forEach((r) => {
      if (typeof r._linked === "undefined") {
        r._linked = Number(r.linked) === 1 ? 1 : 0;
      }

      const linked = Number(r._linked) === 1;

      const tr = htmlToElement(`
        <tr>
          <td style="text-align:center;">
            <input type="checkbox" data-linked ${linked ? "checked" : ""}>
          </td>
          <td><b>${escapeHtml(r.name)}</b></td>
          <td>${Number(r.price || 0).toFixed(2)} €</td>
          <td>+${Number(r.duration_add_min || 0)} min</td>
          <td><input class="pup-input" style="max-width:90px;" type="number" data-sort value="${Number(r.sort_order || 0)}"></td>
          <td><input class="pup-input" style="max-width:120px;" type="text" data-po value="${escapeAttr(r.price_override ?? "")}" placeholder="ex: 10.00"></td>
          <td><input class="pup-input" style="max-width:120px;" type="number" data-do value="${escapeAttr(r.duration_override_min ?? "")}" placeholder="ex: 15"></td>
          <td style="text-align:center;">
            <input type="checkbox" data-active ${(Number(r.link_is_active ?? 1) ? "checked" : "")} ${linked ? "" : "disabled"}>
          </td>
        </tr>
      `);

      const chkLinked = tr.querySelector("[data-linked]");
      const chkActive = tr.querySelector("[data-active]");
      const inpSort = tr.querySelector("[data-sort]");
      const inpPO = tr.querySelector("[data-po]");
      const inpDO = tr.querySelector("[data-do]");

      chkLinked.onchange = () => {
        r._linked = chkLinked.checked ? 1 : 0;
        chkActive.disabled = !chkLinked.checked;
        state.serviceOptions.dirty = true;
        view.querySelector("#save").disabled = false;
      };

      inpSort.oninput = () => { r.sort_order = Number(inpSort.value || 0); state.serviceOptions.dirty = true; };
      inpPO.oninput = () => { r.price_override = inpPO.value; state.serviceOptions.dirty = true; };
      inpDO.oninput = () => { r.duration_override_min = inpDO.value; state.serviceOptions.dirty = true; };
      chkActive.onchange = () => { r.link_is_active = chkActive.checked ? 1 : 0; state.serviceOptions.dirty = true; };

      tbody.appendChild(tr);
    });

    return view;
  };

  const renderPlanning = () => {
    const P = state.planning;

    const view = h(`
      <div class="pup-card">
        <div class="pup-row pup-space">
          <div>
            <div class="pup-link" id="back">← Retour</div>
            <h2 style="margin:6px 0 0 0;">Planning — ${escapeHtml(P.employeeName)}</h2>
            <div class="pup-muted">Horaires hebdo + exceptions (congés / ouvertures spéciales).</div>
          </div>

          <div class="pup-row">
            <button class="pup-btn secondary" id="reload">Recharger</button>
            <button class="pup-btn" id="save" ${P.dirty ? "" : "disabled"}>Enregistrer</button>
          </div>
        </div>

        ${P.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(P.error)}</p>` : ""}
        ${P.loading ? `<p class="pup-muted">Chargement…</p>` : ""}

        <div class="pup-grid" style="margin-top:12px;">
          <div>
            <h3 style="margin:0 0 8px 0;">Horaires hebdo</h3>
            <div class="pup-muted">Ajoute une ou plusieurs plages par jour.</div>
            <div id="week" class="pup-grid-1" style="margin-top:10px;"></div>
          </div>

          <div>
            <div class="pup-row pup-space">
              <div>
                <h3 style="margin:0 0 8px 0;">Exceptions</h3>
                <div class="pup-muted">Ex : congés (closed) ou ouverture spéciale (open).</div>
              </div>
              <button class="pup-btn secondary pup-small" id="addEx">+ Exception</button>
            </div>

            <div id="exList" class="pup-grid-1" style="margin-top:10px;"></div>
          </div>
        </div>
      </div>
    `);

    view.querySelector("#back").onclick = () => { state.route = "tabs"; render(); };
    view.querySelector("#reload").onclick = () => loadPlanning();
    view.querySelector("#save").onclick = () => savePlanning();

    const week = view.querySelector("#week");
    for (let d = 1; d <= 7; d++) {
      const daySlots = P.schedules.filter(s => Number(s.weekday) === d);

      const block = h(`
        <div class="pup-day">
          <div class="pup-row pup-space">
            <b>${dayNames[d]}</b>
            <button class="pup-btn secondary pup-small" data-add-slot="${d}">+ Plage</button>
          </div>
          <div class="pup-grid-1" data-day="${d}" style="margin-top:10px;"></div>
        </div>
      `);

      const list = block.querySelector(`[data-day="${d}"]`);
      daySlots.forEach((s) => {
        const row = h(`
          <div class="pup-inline">
            <input class="pup-input" style="max-width:160px;" type="time" step="900" min="06:00" max="23:45" data-st value="${escapeAttr((s.start_time||"").slice(0,5))}">
            <span>→</span>
            <input class="pup-input" style="max-width:160px;" type="time" step="900" min="06:00" max="23:45" data-et value="${escapeAttr((s.end_time||"").slice(0,5))}">
            <button class="pup-btn danger pup-small" data-rm>Suppr</button>
          </div>
        `);

        row.querySelector("[data-st]").onchange = (e) => {
          const v = normalizeTimeValue(e.target.value);
          e.target.value = v;
          s.start_time = v ? v + ":00" : "00:00:00";
          P.dirty = true; render();
        };
        row.querySelector("[data-et]").onchange = (e) => {
          const v = normalizeTimeValue(e.target.value);
          e.target.value = v;
          s.end_time = v ? v + ":00" : "00:00:00";
          P.dirty = true; render();
        };

        row.querySelector("[data-rm]").onclick = () => {
          P.schedules = P.schedules.filter(x => x !== s);
          P.dirty = true; render();
        };

        list.appendChild(row);
      });

      block.querySelector(`[data-add-slot="${d}"]`).onclick = () => {
        P.schedules.push({ weekday: d, start_time: "10:00:00", end_time: "12:00:00" });
        P.dirty = true;
        render();
      };

      week.appendChild(block);
    }

    const exList = view.querySelector("#exList");
    view.querySelector("#addEx").onclick = () => {
      const today = new Date().toISOString().slice(0, 10);
      P.exceptions.unshift({ date: today, type: "closed", start_time: null, end_time: null, note: "" });
      P.dirty = true;
      render();
    };

    (P.exceptions || []).forEach((ex) => {
      const row = h(`
        <div class="pup-day">
          <div class="pup-grid-1">
            <div class="pup-grid">
              <div>
                <label>Date</label>
                <input class="pup-input" type="date" data-date value="${escapeAttr(ex.date || "")}">
              </div>
              <div>
                <label>Type</label>
                <select class="pup-select" data-type>
                  <option value="closed">closed</option>
                  <option value="open">open</option>
                  <option value="busy">busy</option>
                </select>
              </div>
            </div>

            <div class="pup-grid">
              <div>
                <label>Début (optionnel)</label>
                <input class="pup-input" type="time" step="900" min="06:00" max="23:45" data-st value="${escapeAttr(ex.start_time ? ex.start_time.slice(0,5) : "")}">
              </div>
              <div>
                <label>Fin (optionnel)</label>
                <input class="pup-input" type="time" step="900" min="06:00" max="23:45" data-et value="${escapeAttr(ex.end_time ? ex.end_time.slice(0,5) : "")}">
              </div>
            </div>

            <div>
              <label>Note</label>
              <input class="pup-input" data-note value="${escapeAttr(ex.note || "")}">
            </div>

            <div class="pup-row" style="justify-content:flex-end;">
              <button class="pup-btn danger pup-small" data-rm>Supprimer</button>
            </div>
          </div>
        </div>
      `);

      row.querySelector("[data-type]").value = ex.type || "closed";

      row.querySelector("[data-date]").onchange = (e) => { ex.date = e.target.value; P.dirty = true; render(); };
      row.querySelector("[data-type]").onchange = (e) => { ex.type = e.target.value; P.dirty = true; render(); };

      row.querySelector("[data-st]").onchange = (e) => {
        const v = e.target.value ? normalizeTimeValue(e.target.value) : "";
        e.target.value = v;
        ex.start_time = v ? v + ":00" : null;
        P.dirty = true; render();
      };
      row.querySelector("[data-et]").onchange = (e) => {
        const v = e.target.value ? normalizeTimeValue(e.target.value) : "";
        e.target.value = v;
        ex.end_time = v ? v + ":00" : null;
        P.dirty = true; render();
      };

      row.querySelector("[data-note]").oninput = (e) => { ex.note = e.target.value; P.dirty = true; };

      row.querySelector("[data-rm]").onclick = () => {
        P.exceptions = P.exceptions.filter(x => x !== ex);
        P.dirty = true; render();
      };

      exList.appendChild(row);
    });

    return view;
  };

  /* -----------------------------
     MODALS
  ------------------------------*/
  const renderModal = () => {
    // Category modal
    if (state.categories.modal) {
      const m = state.categories.modal;
      const modal = h(`
        <div class="pup-modal-backdrop">
          <div class="pup-modal">
            <div class="pup-row pup-space">
              <h2 style="margin:0;">${m.id ? "Éditer la catégorie" : "Nouvelle catégorie"}</h2>
              <button class="pup-btn secondary" id="close">Fermer</button>
            </div>

            ${state.categories.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(state.categories.error)}</p>` : ""}

            <div class="pup-grid-1" style="margin-top:12px;">
              <div>
                <label>Nom</label>
                <input class="pup-input" id="name" value="${escapeAttr(m.name || "")}">
              </div>

              <div class="pup-grid">
                <div>
                  <label>Slug</label>
                  <input class="pup-input" id="slug" value="${escapeAttr(m.slug || "")}" placeholder="ex: massage">
                </div>
                <div>
                  <label>Ordre</label>
                  <input class="pup-input" id="sort_order" type="number" value="${Number(m.sort_order || 0)}">
                </div>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Actif</label>
                  <select class="pup-select" id="is_active">
                    <option value="1">Oui</option>
                    <option value="0">Non</option>
                  </select>
                </div>
              </div>

              <div class="pup-actions">
                <button class="pup-btn secondary" id="cancel">Annuler</button>
                <button class="pup-btn" id="save">Enregistrer</button>
              </div>
            </div>
          </div>
        </div>
      `);

      modal.querySelector("#is_active").value = String(Number(m.is_active) ? 1 : 0);

      const close = () => { state.categories.modal = null; render(); };
      modal.querySelector("#close").onclick = close;
      modal.querySelector("#cancel").onclick = close;

      modal.querySelector("#save").onclick = () => {
        state.categories.modal.name = modal.querySelector("#name").value;
        state.categories.modal.slug = modal.querySelector("#slug").value;
        state.categories.modal.sort_order = modal.querySelector("#sort_order").value;
        state.categories.modal.is_active = modal.querySelector("#is_active").value;
        saveCategoryModal();
      };

      return modal;
    }

    // Option modal
    if (state.options.modal) {
      const m = state.options.modal;
      const modal = h(`
        <div class="pup-modal-backdrop">
          <div class="pup-modal">
            <div class="pup-row pup-space">
              <h2 style="margin:0;">${m.id ? "Éditer l’option" : "Nouvelle option"}</h2>
              <button class="pup-btn secondary" id="close">Fermer</button>
            </div>

            ${state.options.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(state.options.error)}</p>` : ""}

            <div class="pup-grid-1" style="margin-top:12px;">
              <div>
                <label>Nom</label>
                <input class="pup-input" id="name" value="${escapeAttr(m.name || "")}">
              </div>

              <div>
                <label>Description</label>
                <textarea class="pup-textarea" id="description" rows="3">${escapeHtml(m.description || "")}</textarea>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Prix (€)</label>
                  <input class="pup-input" id="price" value="${escapeAttr(m.price ?? "0.00")}" placeholder="ex: 10.00">
                </div>
                <div>
                  <label>Durée ajoutée (min)</label>
                  <input class="pup-input" id="duration_add_min" type="number" min="0" value="${Number(m.duration_add_min || 0)}">
                </div>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Actif</label>
                  <select class="pup-select" id="is_active">
                    <option value="1">Oui</option>
                    <option value="0">Non</option>
                  </select>
                </div>
              </div>

              <div class="pup-actions">
                <button class="pup-btn secondary" id="cancel">Annuler</button>
                <button class="pup-btn" id="save">Enregistrer</button>
              </div>
            </div>
          </div>
        </div>
      `);

      modal.querySelector("#is_active").value = String(Number(m.is_active) ? 1 : 0);

      const close = () => { state.options.modal = null; render(); };
      modal.querySelector("#close").onclick = close;
      modal.querySelector("#cancel").onclick = close;

      modal.querySelector("#save").onclick = () => {
        state.options.modal.name = modal.querySelector("#name").value;
        state.options.modal.description = modal.querySelector("#description").value;
        state.options.modal.price = modal.querySelector("#price").value;
        state.options.modal.duration_add_min = modal.querySelector("#duration_add_min").value;
        state.options.modal.is_active = modal.querySelector("#is_active").value;
        saveOptionModal();
      };

      return modal;
    }

    // Service modal
    if (state.services.modal) {
      const m = state.services.modal;

      const activeEmployees = (state.employees.items || [])
        .filter(e => Number(e.is_active) === 1);

      const modal = h(`
        <div class="pup-modal-backdrop">
          <div class="pup-modal">
            <div class="pup-row pup-space">
              <h2 style="margin:0;">${m.id ? "Éditer le service" : "Nouveau service"}</h2>
              <button class="pup-btn secondary" id="close">Fermer</button>
            </div>

            ${state.services.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(state.services.error)}</p>` : ""}

            <div class="pup-grid-1" style="margin-top:12px;">
              <div>
                <label>Nom</label>
                <input class="pup-input" id="name" value="${escapeAttr(m.name || "")}">
              </div>

              <div>
                <label>Description (HTML autorisé)</label>
                <textarea class="pup-textarea" id="description" rows="4">${escapeHtml(m.description || "")}</textarea>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Catégorie</label>
                  <select class="pup-select" id="category_id">
                    <option value="">— Aucune —</option>
                    ${(state.categories.items || []).map(c => `
                      <option value="${c.id}">${escapeHtml(c.name)}</option>
                    `).join("")}
                  </select>
                </div>

                <div>
                  <label>Mode de réservation</label>
                  <select class="pup-select" id="booking_mode">
                    <option value="slot">Avec créneau</option>
                    <option value="product">Produit (carte cadeau)</option>
                  </select>
                </div>
              </div>

              <div>
                <label>Ressources nécessaires (humains + matériels)</label>
                <div class="pup-grid-1" style="border:1px solid #eee;padding:10px;border-radius:10px;max-height:220px;overflow:auto;">
                  ${activeEmployees.map(e => {
                    const checked = (m.employee_ids || []).includes(Number(e.id));
                    const tag = (e.kind === 'resource') ? 'Ressource' : 'Humain';
                    return `
                      <label style="display:flex;gap:8px;align-items:center;">
                        <input type="checkbox" data-empchk value="${e.id}" ${checked ? 'checked' : ''}>
                        <span><b>${escapeHtml(e.display_name || ("Employé #" + e.id))}</b> <span class="pup-badge">${escapeHtml(tag)}</span></span>
                      </label>
                    `;
                  }).join("")}
                </div>
                <div class="pup-muted" style="margin-top:6px;">Ex: choisir “Laurent” + “Hammam” + “Sauna”.</div>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Type</label>
                  <select class="pup-select" id="type">
                    <option value="individual">individual</option>
                    <option value="multi">multi</option>
                    <option value="capacity">capacity</option>
                  </select>
                </div>
                <div>
                  <label>Capacité max (si type = capacity)</label>
                  <input class="pup-input" id="capacity_max" type="number" min="1" value="${Number(m.capacity_max || 6)}">
                </div>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Durée (min)</label>
                  <input class="pup-input" id="duration_min" type="number" min="5" value="${Number(m.duration_min || 60)}">
                </div>
                <div>
                  <label>Buffer avant (min)</label>
                  <input class="pup-input" id="buffer_before_min" type="number" min="0" value="${Number(m.buffer_before_min || 0)}">
                </div>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Buffer après (min)</label>
                  <input class="pup-input" id="buffer_after_min" type="number" min="0" value="${Number(m.buffer_after_min || 0)}">
                </div>
                <div>
                  <label>Délai mini réservation (min)</label>
                  <input class="pup-input" id="min_notice_min" type="number" min="0" value="${Number(m.min_notice_min || 0)}">
                </div>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Délai mini annulation (min)</label>
                  <input class="pup-input" id="cancel_limit_min" type="number" min="0" value="${Number(m.cancel_limit_min || 1440)}">
                </div>
                <div>
                  <label>Actif</label>
                  <select class="pup-select" id="is_active">
                    <option value="1">Oui</option>
                    <option value="0">Non</option>
                  </select>
                </div>
              </div>

              <div class="pup-actions">
                <button class="pup-btn secondary" id="cancel">Annuler</button>
                <button class="pup-btn" id="save">Enregistrer</button>
              </div>
            </div>
          </div>
        </div>
      `);

      modal.querySelector("#type").value = m.type || "individual";
      modal.querySelector("#is_active").value = String(Number(m.is_active) ? 1 : 0);
      modal.querySelector("#category_id").value = (m.category_id ?? "") + "";
      modal.querySelector("#booking_mode").value = m.booking_mode || "slot";

      modal.querySelector("#close").onclick = () => { state.services.modal = null; render(); };
      modal.querySelector("#cancel").onclick = () => { state.services.modal = null; render(); };

      modal.querySelector("#save").onclick = () => {
        state.services.modal.name = modal.querySelector("#name").value;
        state.services.modal.description = modal.querySelector("#description").value;
        state.services.modal.category_id = modal.querySelector("#category_id").value;
        state.services.modal.booking_mode = modal.querySelector("#booking_mode").value;
        state.services.modal.type = modal.querySelector("#type").value;
        state.services.modal.duration_min = modal.querySelector("#duration_min").value;
        state.services.modal.buffer_before_min = modal.querySelector("#buffer_before_min").value;
        state.services.modal.buffer_after_min = modal.querySelector("#buffer_after_min").value;
        state.services.modal.min_notice_min = modal.querySelector("#min_notice_min").value;
        state.services.modal.cancel_limit_min = modal.querySelector("#cancel_limit_min").value;
        state.services.modal.capacity_max = modal.querySelector("#capacity_max").value;
        state.services.modal.is_active = modal.querySelector("#is_active").value;

        state.services.modal.employee_ids = Array.from(modal.querySelectorAll("[data-empchk]:checked"))
          .map(x => Number(x.value));

        saveServiceModal();
      };

      return modal;
    }

    // Employee modal
    if (state.employees.modal) {
      const m = state.employees.modal;
      const modal = h(`
        <div class="pup-modal-backdrop">
          <div class="pup-modal">
            <div class="pup-row pup-space">
              <h2 style="margin:0;">${m.id ? "Éditer l’employé / ressource" : "Nouvel employé / ressource"}</h2>
              <button class="pup-btn secondary" id="close">Fermer</button>
            </div>

            ${state.employees.error ? `<p style="color:#b00020;"><b>Erreur:</b> ${escapeHtml(state.employees.error)}</p>` : ""}

            <div class="pup-grid-1" style="margin-top:12px;">
              <div class="pup-grid">
                <div>
                  <label>Nom affiché</label>
                  <input class="pup-input" id="display_name" value="${escapeAttr(m.display_name || "")}">
                </div>
                <div>
                  <label>Email</label>
                  <input class="pup-input" id="email" value="${escapeAttr(m.email || "")}">
                </div>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Type</label>
                  <select class="pup-select" id="kind">
                    <option value="human">Humain</option>
                    <option value="resource">Ressource</option>
                  </select>
                </div>
                <div>
                  <label>Capacité</label>
                  <input class="pup-input" id="capacity" type="number" min="1" value="${Number(m.capacity || 1)}">
                </div>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Timezone</label>
                  <input class="pup-input" id="timezone" value="${escapeAttr(m.timezone || "Europe/Paris")}">
                </div>
                <div>
                  <label>ID utilisateur WP (optionnel)</label>
                  <input class="pup-input" id="wp_user_id" type="number" min="1" value="${escapeAttr(m.wp_user_id ?? "")}">
                </div>
              </div>

              <div class="pup-grid">
                <div>
                  <label>Actif</label>
                  <select class="pup-select" id="is_active">
                    <option value="1">Oui</option>
                    <option value="0">Non</option>
                  </select>
                </div>
                <div>
                  <label>Google Sync</label>
                  <select class="pup-select" id="google_sync_enabled">
                    <option value="0">Non</option>
                    <option value="1">Oui</option>
                  </select>
                </div>
              </div>

              <div class="pup-actions">
                <button class="pup-btn secondary" id="cancel">Annuler</button>
                <button class="pup-btn" id="save">Enregistrer</button>
              </div>
            </div>
          </div>
        </div>
      `);

      modal.querySelector("#is_active").value = String(Number(m.is_active) ? 1 : 0);
      modal.querySelector("#google_sync_enabled").value = String(Number(m.google_sync_enabled) ? 1 : 0);
      modal.querySelector("#kind").value = (m.kind === "resource") ? "resource" : "human";

      const close = () => { state.employees.modal = null; render(); };
      modal.querySelector("#close").onclick = close;
      modal.querySelector("#cancel").onclick = close;

      modal.querySelector("#save").onclick = () => {
        state.employees.modal.display_name = modal.querySelector("#display_name").value;
        state.employees.modal.email = modal.querySelector("#email").value;
        state.employees.modal.kind = modal.querySelector("#kind").value;
        state.employees.modal.capacity = modal.querySelector("#capacity").value;
        state.employees.modal.timezone = modal.querySelector("#timezone").value;
        state.employees.modal.wp_user_id = modal.querySelector("#wp_user_id").value;
        state.employees.modal.is_active = modal.querySelector("#is_active").value;
        state.employees.modal.google_sync_enabled = modal.querySelector("#google_sync_enabled").value;
        saveEmployeeModal();
      };

      return modal;
    }

    return null;
  };

  const render = () => {
    try {
      root.innerHTML = "";

      const shell = h(`<div class="pup-shell"></div>`);
      const header = renderHeader();
      shell.appendChild(header);

      let view;
      if (state.route === "planning") view = renderPlanning();
      else if (state.route === "serviceOptions") view = renderServiceOptions();
      else {
        if (state.tab === "services") view = renderServices();
        else if (state.tab === "employees") view = renderEmployees();
        else if (state.tab === "categories") view = renderCategories();
        else if (state.tab === "visibility") view = renderVisibility();
        else if (state.tab === "options") view = renderOptions();
        else view = renderServices();
      }

      shell.appendChild(view);
      root.appendChild(shell);

      header.querySelectorAll(".pup-tab").forEach(tab => {
        tab.onclick = async () => {
          if (state.route === "planning" || state.route === "serviceOptions") return;
          state.tab = tab.getAttribute("data-tab");
          render();

          if (state.tab === "services") {
            if (state.categories.items.length === 0) await loadCategories().catch(() => {});
            if (state.employees.items.length === 0) await loadEmployees().catch(() => {});
            if (state.services.items.length === 0) await loadServices().catch(() => {});
          }
          if (state.tab === "employees" && state.employees.items.length === 0) await loadEmployees();
          if (state.tab === "categories" && state.categories.items.length === 0) await loadCategories();
          if (state.tab === "visibility" && state.visibility.categories.length === 0) await loadVisibilityMatrix();
          if (state.tab === "options" && state.options.items.length === 0) await loadOptions();
        };
      });

      const modal = renderModal();
      if (modal) root.appendChild(modal);

    } catch (e) {
      console.error(e);
      root.innerHTML = `
        <div class="pup-card">
          <h3>Erreur UI</h3>
          <p style="color:#b00020"><b>${escapeHtml(e.message || String(e))}</b></p>
          <p class="pup-muted">Ouvre la console (F12) pour la stack trace.</p>
        </div>
      `;
    }
  };

  // init
  (async () => {
    render();
    await loadCategories().catch(() => {});
    await loadEmployees().catch(() => {});
    await loadServices();
  })();
})();
