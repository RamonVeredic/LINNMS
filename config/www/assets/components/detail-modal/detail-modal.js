(() => {
  "use strict";
  const e = document.getElementById("dashboardDetailModal");
  if (!(e instanceof HTMLElement)) return;
  if (0) return;
  const t = e.querySelector("[data-detail-title]"),
    n = e.querySelector("[data-detail-subtitle]"),
    a = e.querySelector("[data-detail-icon]"),
    i = e.querySelector("[data-detail-list]"),
    r = e.querySelector("[data-detail-pagination]"),
    o = e.querySelector("[data-detail-foot]"),
    d = e.querySelector("[data-detail-search]"),
    l = e.querySelector("[data-detail-device-shell]"),
    s = e.querySelector("[data-detail-device]"),
    c = [...e.querySelectorAll("[data-detail-close]")];
  if (!(t && n && a && i && r && o && d && l && s instanceof HTMLSelectElement))
    return;
  const u = {
      total_devices: {
        title: "Total Perangkat",
        subtitle: "",
        accent: "blue",
        icon: "bi-boxes",
        kind: "device",
      },
      total_onus: {
        title: "Daftar ONU",
        subtitle: "",
        accent: "teal",
        icon: "bi-list-ul",
        kind: "onu",
      },
      online_onus: {
        title: "Online",
        subtitle: "",
        accent: "green",
        icon: "bi-wifi",
        kind: "onu",
      },
      down_onus: {
        title: "Offline",
        subtitle: "",
        accent: "down",
        icon: "bi-arrow-down-right",
        kind: "onu",
      },
      power_down_onus: {
        title: "DyingGasp",
        subtitle: "",
        accent: "amber",
        icon: "bi-plug",
        kind: "onu",
      },
      loss_onus: {
        title: "Lost",
        subtitle: "",
        accent: "lost",
        icon: "bi-broadcast",
        kind: "onu",
      },
      problem_onus: {
        title: "Bermasalah",
        subtitle: "",
        accent: "red",
        icon: "bi-exclamation-triangle",
        kind: "onu",
      },
    },
    m = {
      device: ["Jenis", "Alamat", "Model", "Info", "Status"],
      online_onus: ["OLT", "Index", "Serial", "RX Power"],
      down_onus: ["OLT", "Index", "Serial"],
      loss_onus: ["OLT", "Index", "Serial"],
      power_down_onus: ["OLT", "Index", "Serial", "RX Power"],
      problem_onus: ["OLT", "Index", "Serial", "RX Power", "Note"],
    },
    p = [...document.querySelectorAll("[data-dashboard-detail]")];
  let b = "",
    h = 1,
    g = 1,
    f = [],
    E = null,
    S = null;
  const y = document.createElement("div");
  ((y.className = "dashboard-detail-results"), i.before(y));
  const v = document.createElement("div");
  ((v.className = "dashboard-detail-table-head"),
    (v.hidden = !0),
    v.setAttribute("aria-hidden", "true"),
    y.append(v),
    y.append(i),
    i.addEventListener("scroll", () => {
      v.scrollLeft = i.scrollLeft;
    }));
  const w = document.createElement("div");
  ((w.className = "onu-search-status"),
    (w.hidden = !0),
    w.setAttribute("role", "status"),
    w.setAttribute("aria-label", "Data kategori sedang diproses"));
  const k = document.createElement("span");
  k.className = "onu-loading-badge";
  const x = document.createElement("i");
  ((x.className = "bi bi-arrow-repeat sync-loading-spinner"),
    x.setAttribute("aria-hidden", "true"),
    k.append(x),
    w.append(k),
    y.append(w));
  const L = (e, t, n) => {
      const a = document.createElement(n || "td");
      return (t && (a.className = t), (a.textContent = e), a);
    },
    A = (e) => (u[e] || {}).kind || "onu",
    N = (e) => {
      const t = document.createElement("table");
      t.className = "dashboard-detail-table";
      const n = document.createElement("thead"),
        a = document.createElement("tr");
      return (
        ((e) => m["device" === e ? "device" : b] || m.online_onus)(e).forEach(
          (e) => {
            const t = L(e, null, "th");
            ((t.textContent = e), a.append(t));
          },
        ),
        n.append(a),
        t.append(n),
        t.append(document.createElement("tbody")),
        t
      );
    },
    C = (e = {}) => {
      const t = document.createElement("span");
      ((t.className =
        "dashboard-detail-status" + (e.tone ? ` is-${e.tone}` : "")),
        t.setAttribute("role", "error" === e.tone ? "alert" : "status"));
      const n = document.createElement("i");
      ((n.className = `bi ${e.icon || "bi-arrow-repeat"}`),
        n.setAttribute("aria-hidden", "true"));
      const a = document.createElement("span");
      if (((a.textContent = e.text || ""), t.append(n, a), e.retry)) {
        const e = document.createElement("button");
        ((e.type = "button"),
          (e.className = "button secondary"),
          (e.textContent = "Muat ulang"),
          e.addEventListener("click", () => {
            I(b);
          }),
          t.append(e));
      }
      return t;
    },
    T = (e) => {
      ((v.hidden = !0), i.replaceChildren(C(e)), i.classList.add("is-compact"));
    },
    q = () => A(b),
    _ = () => {
      if (
        (r.replaceChildren(),
        (o.hidden = 0 === f.length || g <= 1 || "device" === q()),
        o.hidden)
      )
        return;
      const e = document.createElement("div");
      e.className = "page-controls";
      const t = (e, t, n) => {
          const a = document.createElement("button");
          ((a.type = "button"),
            (a.className = "button secondary table-page-button"),
            (a.disabled = n),
            a.setAttribute(
              "aria-label",
              "left" === e ? "Halaman sebelumnya" : "Halaman berikutnya",
            ));
          const i = document.createElement("i");
          return (
            (i.className = `bi bi-chevron-${e}`),
            i.setAttribute("aria-hidden", "true"),
            a.append(i),
            a.addEventListener("click", () => {
              I(b, t);
            }),
            a
          );
        },
        n = new Set([1, g]),
        a = window.matchMedia("(max-width: 480px)").matches;
      a || (n.add(2), n.add(g - 1));
      const i = a ? 0 : 2;
      for (let e = h - i; e <= h + i; e++) e >= 1 && e <= g && n.add(e);
      const d = document.createElement("ul");
      d.className = "page-number-list";
      let l = 0;
      ([...n]
        .filter((e) => e >= 1 && e <= g)
        .sort((e, t) => e - t)
        .forEach((e) => {
          if (l && e > l + 1) {
            const e = document.createElement("li");
            ((e.className = "page-number-ellipsis"),
              e.setAttribute("aria-hidden", "true"),
              (e.textContent = "…"),
              d.append(e));
          }
          const t = document.createElement("li"),
            n = document.createElement(e === h ? "span" : "button");
          ((n.className = "page-number"),
            (n.textContent = String(e)),
            e === h
              ? (n.classList.add("is-active"),
                n.setAttribute("aria-current", "page"))
              : ((n.type = "button"),
                n.setAttribute("aria-label", `Halaman ${e}`),
                n.addEventListener("click", () => {
                  I(b, e);
                })),
            t.append(n),
            d.append(t),
            (l = e));
        }),
        e.append(t("left", h - 1, h <= 1), d, t("right", h + 1, h >= g)),
        r.append(e));
    },
    $ = () => {
      const e = s.selectedOptions[0]?.textContent || "Semua Perangkat";
      (l.classList.toggle("is-filtered", "" !== s.value),
        (l.title = `Filter perangkat: ${e}`),
        l.querySelector(".select-button")?.setAttribute("aria-label", l.title));
    },
    D = () => {
      if (0 === f.length) return;
      const e = i.querySelector("table"),
        t = i.querySelector("tbody");
      if (!e || !t) return;
      i.querySelector(".dashboard-detail-status")?.remove();
      const n = d.value.trim().toLowerCase(),
        a = s.value;
      let r = 0;
      (t.querySelectorAll("tr[data-detail-row]").forEach((e) => {
        const t = String(e.dataset.detailSearch || ""),
          i = String(e.dataset.detailDevice || ""),
          o = ("" === n || t.includes(n)) && ("" === a || i === a);
        ((e.hidden = !o), o && (r += 1));
      }),
        0 === r
          ? ((e.hidden = !0),
            (v.hidden = !0),
            i.append(
              C({ icon: "bi-inbox", tone: "empty", text: "Tidak tersedia" }),
            ))
          : ((e.hidden = !1), (v.hidden = !1)),
        _());
    };
  let M = 0;
  const O = () => {
      const e = f.length > 0 ? String(f[0].kind || "onu") : q();
      if (
        (v.replaceChildren(),
        (v.hidden = !0),
        i.replaceChildren(),
        i.classList.add("is-compact"),
        0 === f.length)
      )
        return void T({
          icon: "bi-inbox",
          tone: "empty",
          text: "Tidak tersedia",
        });
      const t = N(e),
        n = t.querySelector("tbody");
      (f.forEach((t) => {
        const a = document.createElement("tr");
        if (
          ((a.dataset.detailRow = "1"),
          (a.dataset.href = String(t.url || "")),
          (a.tabIndex = 0),
          a.setAttribute("role", "link"),
          "device" === e)
        ) {
          ((a.dataset.detailSearch = String(t.search || "")),
            a.setAttribute("aria-label", `Buka detail perangkat ${t.name}`));
          const e = document.createElement("span");
          ((e.className =
            "status " +
            ("online" === (r = String(t.state_key || ""))
              ? "success"
              : "offline" === r || "problem" === r
                ? "danger"
                : "neutral")),
            (e.textContent = String(t.status || "")));
          const n = L("");
          (n.append(e),
            a.append(
              L(String(t.type || "")),
              L(String(t.address || ""), "mono"),
              L(
                ((i = t.model),
                String(i || "")
                  .replace(/[^\p{L}\p{N}]+/gu, " ")
                  .replace(/\s+/g, " ")
                  .trim()),
              ),
              L(String(t.info || ""), "dashboard-detail-info-cell"),
              n,
            ));
        } else {
          ((a.dataset.detailSearch = String(t.search || "")),
            (a.dataset.detailDevice = String(t.olt || "")),
            a.setAttribute(
              "aria-label",
              `Buka detail ONU ${t.olt} ${t.index}`,
            ));
          const e = document.createElement("span");
          e.className = `signal ${String(t.rx_class || "neutral")}`;
          const n = document.createElement("i");
          ((n.className = `bi ${String(t.rx_icon || "ri-signal-cellular-off-line")}`),
            n.setAttribute("aria-hidden", "true"),
            e.append(n, document.createElement("span")),
            (e.lastElementChild.textContent = String(t.rx || "-")));
          const i = L("", "mono uppercase"),
            r = document.createElement("span");
          ((r.className = "mono"),
            (r.dataset.serialValue = ""),
            (r.textContent = String(t.serial || "")));
          const o = document.createElement("span");
          o.className = "serial-copy-action text-muted";
          const d = document.createElement("button");
          ((d.type = "button"),
            (d.className = "icon-button"),
            (d.dataset.copySerial = String(t.serial || "")),
            d.setAttribute("aria-label", `Salin serial ${t.serial || ""}`));
          const l = document.createElement("i");
          ((l.className = "bi bi-clipboard"),
            l.setAttribute("aria-hidden", "true"),
            d.append(l),
            o.append(d));
          const s = document.createElement("span");
          if (
            ((s.className = "serial-copy"),
            s.append(r, o),
            i.append(s),
            a.append(
              L(String(t.olt || "")),
              L(String(t.index || ""), "mono"),
              i,
            ),
            !["down_onus", "loss_onus"].includes(b))
          ) {
            const t = L("");
            (t.append(e), a.append(t));
          }
          if ("problem_onus" === b) {
            const e = L(String(t.note || ""));
            if ("" !== String(t.since || "")) {
              const n = document.createElement("span");
              ((n.className = "dashboard-detail-note-line"),
                (n.textContent = String(t.since)),
                e.append(n));
            }
            a.append(e);
          }
        }
        var i, r;
        n.append(a);
      }),
        t.append(n),
        i.append(t));
      const a = N(e);
      (a.querySelector("tbody")?.remove(),
        v.append(a),
        (v.hidden = !1),
        (v.scrollLeft = i.scrollLeft));
    },
    H = (t) => {
      (e.setAttribute("aria-busy", String(t)),
        (w.hidden = !t),
        y.classList.toggle("is-loading", t),
        (i.inert = t),
        (r.inert = t),
        (w.style.top = (v.hidden ? 0 : v.offsetHeight) + "px"));
    },
    I = async (t, n = 1) => {
      if ("" === t || e.hidden) return;
      E?.abort();
      const a = new AbortController();
      ((E = a), H(!0));
      const c = s.value;
      try {
        const r = new URLSearchParams({
          type: "dashboard-detail",
          category: t,
          p: String(n),
          per: "100",
        });
        if ("onu" === A(t)) {
          const e = d.value.trim();
          ([...e].length >= 2 && r.set("q", e), c && r.set("device", c));
        }
        const o = await window.linnmsAjax.request(`data.php?${r.toString()}`, {
          credentials: "same-origin",
          cache: "no-store",
          signal: a.signal,
        });
        if (E !== a || e.hidden) return;
        if (401 === o.status)
          throw new Error(
            "Sesi telah berakhir. Muat ulang halaman untuk melanjutkan.",
          );
        const u = await o.json().catch(() => null);
        if (E !== a || e.hidden) return;
        if (!o.ok || !u || !0 !== u.ok || !Array.isArray(u.rows))
          throw new Error(
            String(u?.message || "Data kategori belum dapat dimuat."),
          );
        ((f = u.rows),
          (h = Number(u.page) || 1),
          (g = Number(u.pages) || 1),
          (e.dataset.detailTruncated = !0 === u.truncated ? "1" : "0"),
          ((e) => {
            const t = s.value;
            s.replaceChildren();
            const n = document.createElement("option");
            if (
              ((n.value = ""),
              (n.textContent = "Semua Perangkat"),
              s.append(n),
              (Array.isArray(e) ? e : []).forEach((e) => {
                if (!e || "object" != typeof e) return;
                const t = document.createElement("option");
                ((t.value = String(e.value || "")),
                  (t.textContent = String(e.label || "")),
                  (t.dataset.meta = String(e.meta || "")),
                  s.append(t));
              }),
              t && ![...s.options].some((e) => e.value === t))
            ) {
              const e = document.createElement("option");
              ((e.value = t), (e.textContent = t), s.append(e));
            }
            ((s.value = t), (l.hidden = s.options.length <= 1 && !t), $());
          })(u.devices),
          O(),
          "device" === A(t) && D(),
          _(),
          (i.scrollTop = 0));
      } catch (t) {
        if ("AbortError" === t?.name || E !== a || e.hidden) return;
        (T({
          icon: "bi-arrow-clockwise",
          tone: "error",
          text: String(t?.message || "Data kategori belum dapat dimuat."),
          retry: !0,
        }),
          (o.hidden = !0),
          r.replaceChildren());
      } finally {
        E === a && ((E = null), H(!1));
      }
    },
    P = (t = !0) => {
      e.hidden ||
        (window.clearTimeout(M),
        E && E.abort(),
        (E = null),
        H(!1),
        (e.hidden = !0),
        e.setAttribute("aria-hidden", "true"),
        document.body.classList.remove("dashboard-detail-open"),
        (d.value = ""),
        (s.value = ""),
        (l.hidden = !0),
        t && S instanceof HTMLElement && S.focus({ preventScroll: !0 }));
    };
  (p.forEach((c) =>
    c.addEventListener("click", () =>
      ((c) => {
        const m = String(c.dataset.dashboardDetail || "").trim();
        if ("" === m || !u[m]) return;
        const p = u[m];
        (E && E.abort(),
          (b = m),
          window.clearTimeout(M),
          (h = 1),
          (g = 1),
          (f = []),
          (e.dataset.detailTruncated = "0"),
          (t.textContent =
            "string" == typeof c.dataset.detailTitle &&
            "" !== c.dataset.detailTitle.trim()
              ? c.dataset.detailTitle.trim()
              : p.title),
          (n.textContent = p.subtitle),
          (a.className = `dashboard-detail-icon accent-${c.dataset.detailAccent || p.accent}`),
          a.replaceChildren());
        const y = document.createElement("i");
        ((y.className = `bi ${c.dataset.detailIcon || p.icon}`),
          y.setAttribute("aria-hidden", "true"),
          a.append(y),
          (d.value = ""),
          (l.hidden = !0),
          (s.value = ""),
          (e.hidden = !1),
          e.setAttribute("aria-hidden", "false"),
          document.body.classList.add("dashboard-detail-open"),
          (o.hidden = !0),
          r.replaceChildren(),
          (v.hidden = !0),
          i.replaceChildren(),
          (S = c),
          window.requestAnimationFrame(() => d.focus({ preventScroll: !0 })),
          I(m));
      })(c),
    ),
  ),
    c.forEach((e) => e.addEventListener("click", () => P())),
    e.addEventListener("click", (t) => {
      t.target === e && P();
    }),
    document.addEventListener("keydown", (t) => {
      "Escape" !== t.key ||
        t.defaultPrevented ||
        e.hidden ||
        (t.preventDefault(), P());
    }));
  (d.addEventListener("input", () => {
    (window.clearTimeout(M),
      e.hidden ||
        ("device" !== q()
          ? (E?.abort(),
            (E = null),
            H(!0),
            (M = window.setTimeout(() => {
              I(b);
            }, 200)))
          : (M = window.setTimeout(D, 200))));
  }),
    d.addEventListener("keydown", (e) => {
      "Enter" === e.key &&
        (e.preventDefault(),
        window.clearTimeout(M),
        "onu" === q() ? I(b) : D());
    }),
    s.addEventListener("change", () => {
      ($(), window.clearTimeout(M), "onu" === q() ? I(b) : D());
    }),
    window.addEventListener("resize", () => {
      e.hidden || _();
    }),
    i.addEventListener("click", (e) => {
      const t =
        e.target instanceof Element ? e.target.closest("tr[data-href]") : null;
      if (!(t instanceof HTMLElement)) return;
      if (e.target.closest("a, button, input, select, textarea, label")) return;
      const n = String(t.dataset.href || "");
      "" !== n && (P(!1), (window.location.href = n));
    }),
    i.addEventListener("keydown", (e) => {
      if ("Enter" !== e.key && " " !== e.key) return;
      if (
        e.target instanceof Element &&
        e.target.closest("a, button, input, select, textarea, label")
      )
        return;
      const t =
        e.target instanceof Element ? e.target.closest("tr[data-href]") : null;
      if (!(t instanceof HTMLElement)) return;
      e.preventDefault();
      const n = String(t.dataset.href || "");
      "" !== n && (P(!1), (window.location.href = n));
    }));
})();
