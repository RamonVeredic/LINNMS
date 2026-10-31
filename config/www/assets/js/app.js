((() => {
  "use strict";
  const e = document.querySelector("[data-account-menu]");
  if (e) {
    const t = e.querySelector("[data-account-menu-toggle]"),
      a = e.querySelector("[data-account-menu-dropdown]");
    if (t && a) {
      const n = (e) => {
        (t.setAttribute("aria-expanded", e ? "true" : "false"),
          (a.hidden = !e));
      };
      (t.addEventListener("click", () => {
        n("true" !== t.getAttribute("aria-expanded"));
      }),
        document.addEventListener("click", (t) => {
          e.contains(t.target) || n(!1);
        }),
        document.addEventListener("keydown", (e) => {
          "Escape" !== e.key || a.hidden || (n(!1), t.focus());
        }));
    }
  }
  const t = document.getElementById("appSidebar"),
    a = document.querySelector("[data-sidebar-open]"),
    n = document.querySelector(".sidebar-backdrop");
  if (!t || !a || !n) return;
  const s = (e) => {
    (t.classList.toggle("is-open", e),
      a.setAttribute("aria-expanded", e ? "true" : "false"),
      (n.hidden = !e),
      document.body.classList.toggle("sidebar-open", e));
  };
  (a.addEventListener("click", () => s(!0)),
    n.addEventListener("click", () => s(!1)),
    t.querySelectorAll("a").forEach((e) => {
      e.addEventListener("click", () => {
        window.matchMedia("(max-width: 799px)").matches && s(!1);
      });
    }),
    window.addEventListener("resize", () => {
      window.innerWidth >= 800 && s(!1);
    }),
    document.addEventListener("keydown", (e) => {
      "Escape" === e.key && s(!1);
    }));
})(),
  document.querySelectorAll("[data-log-search]").forEach((e) => {
    const t = e.getAttribute("data-log-search"),
      a = t ? document.getElementById(t) : null;
    a &&
      e.addEventListener("input", () => {
        const t = (e.value || "").trim().toLowerCase();
        a.querySelectorAll("[data-log-row]").forEach((e) => {
          const a = (e.dataset.logText || e.textContent || "").toLowerCase();
          e.hidden = "" !== t && !a.includes(t);
        });
      });
  }),
  (() => {
    "use strict";
    let e = null;
    const t = (t = !1) => {
        if (!e) return;
        const a = e;
        ((e = null), a.close(t));
      },
      a = (e) =>
        Array.from(
          e.querySelectorAll(".select-option:not(:disabled):not([hidden])"),
        ),
      n = (n) => {
        if (!(n instanceof HTMLSelectElement)) return;
        if (n.size > 1 || "1" === n.dataset.selectReady) return;
        if (void 0 !== n.dataset.selectPlain) return;
        const s = n.getBoundingClientRect(),
          i = getComputedStyle(n),
          l = document.createElement("span"),
          o = document.createElement("button"),
          r = document.createElement("span"),
          d = document.createElement("i"),
          c = document.createElement("div"),
          optionsWrap = document.createElement("div"),
          u = `select-${Math.random().toString(36).slice(2)}`,
          m = n.dataset.selectSearch,
          p = void 0 !== m,
          b = (n.dataset.selectLabel || "").trim(),
          h = (n.dataset.selectIcon || "").trim(),
          g = (e) => ((e && e.dataset ? e.dataset.icon : "") || "").trim(),
          f = p || "" !== h || "" !== b;
        ((l.className = f ? "select is-gh" : "select"),
          !f &&
            !n.closest(
              ".field, .select-loading-shell, .onu-inline-edit-shell",
            ) &&
            s.width > 0 &&
            (l.style.width = `${Math.ceil(s.width)}px`),
          (o.type = "button"),
          (o.className = "select-button"),
          o.setAttribute("role", "combobox"),
          o.setAttribute("aria-haspopup", "listbox"),
          o.setAttribute("aria-expanded", "false"),
          o.setAttribute("aria-controls", u),
          n.getAttribute("aria-label") &&
            o.setAttribute("aria-label", n.getAttribute("aria-label")),
          f ||
            (s.height > 0 && (o.style.height = `${Math.ceil(s.height)}px`),
            (o.style.backgroundColor = i.backgroundColor),
            (o.style.borderColor = i.borderColor),
            (o.style.borderStyle = i.borderStyle),
            (o.style.borderWidth = i.borderWidth),
            (o.style.borderRadius = i.borderRadius),
            (o.style.color = i.color),
            (o.style.font = i.font),
            (o.style.padding = i.padding)),
          (r.className = "select-value"));
        const v = document.createElement("i");
        ((v.className = "select-icon"),
          v.setAttribute("aria-hidden", "true"),
          (v.hidden = !0),
          (d.className = "bi bi-caret-down-fill select-chevron"),
          d.setAttribute("aria-hidden", "true"),
          o.append(v, r, d),
          (c.id = u),
          (c.className = f ? "select-menu is-gh" : "select-menu"),
          (optionsWrap.className = "select-options"),
          "cyan" === n.dataset.selectBullet &&
            c.classList.add("has-cyan-bullets"),
          (n.dataset.selectMenuClass || "")
            .split(/\s+/)
            .filter((e) => /^[a-zA-Z0-9_-]+$/.test(e))
            .forEach((e) => c.classList.add(e)),
          c.setAttribute("role", "listbox"),
          (c.hidden = !0),
          n.parentNode.insertBefore(l, n),
          l.append(n, o),
          document.body.append(c),
          n.classList.add("select-native"),
          (n.dataset.selectReady = "1"));
        const y = () => {
            if (n.multiple) {
              const e = Array.from(n.options)
                  .filter((e) => e.selected && "" !== e.value)
                  .map((e) => e.value)
                  .filter((e) => "" !== e),
                t =
                  e.length > 12
                    ? e.slice(0, 12).join(", ") + " …"
                    : e.join(", "),
                a = "" !== t ? t : b || "Belum ada dipilih";
              ((r.textContent = a), (r.style.color = "var(--text-primary)"));
              const s =
                n.getAttribute("aria-label") || b || n.name || "Pilih opsi";
              return (
                o.setAttribute("aria-label", s + ": " + a),
                (o.disabled = n.disabled),
                l.classList.toggle("is-disabled", n.disabled),
                void c.querySelectorAll(".select-option").forEach((e) => {
                  const t = n.options[Number(e.dataset.index)],
                    a = !!t && t.selected;
                  (e.classList.toggle("is-active", a),
                    e.setAttribute("aria-selected", a ? "true" : "false"));
                })
              );
            }
            const e = n.options[n.selectedIndex] || null,
              t = !e || "" === e.value;
            ((r.textContent =
              t && "" !== b ? b : e ? e.textContent.trim() : ""),
              (r.title = e ? e.textContent.trim() : r.textContent),
              (r.style.color =
                f || !t ? "var(--text-primary)" : "var(--text-muted)"),
              l.classList.toggle("is-placeholder", t));
            const a = (e ? g(e) : "") || h;
            ((v.className = "" !== a ? `${a} select-icon` : "select-icon"),
              (v.hidden = "" === a),
              (o.disabled = n.disabled),
              l.classList.toggle("is-disabled", n.disabled),
              c.querySelectorAll(".select-option").forEach((e) => {
                const t = Number(e.dataset.index) === n.selectedIndex;
                (e.classList.toggle("is-active", t),
                  e.setAttribute("aria-selected", t ? "true" : "false"));
              }));
          },
          E = (e) => {
            if (e.hasAttribute("data-select-placeholder")) return;
            const a = document.createElement("button"),
              s = document.createElement("span"),
              i = document.createElement("i");
            ((a.type = "button"),
              (a.className = "select-option"),
              n.multiple && a.classList.add("is-multiple"),
              n.hasAttribute("data-select-checkbox") &&
                a.classList.add("has-checkbox"),
              e.hasAttribute("data-select-heading") &&
                a.classList.add("is-heading"),
              a.setAttribute("role", "option"),
              (a.dataset.index = String(
                Array.prototype.indexOf.call(n.options, e),
              )),
              (s.className = "select-option-text"),
              (s.textContent = e.textContent.trim()),
              (i.className = "bi ri-check-line select-check"),
              i.setAttribute("aria-hidden", "true"));
            const l = (e.dataset.meta || "").trim(),
              o = g(e) || (n.dataset.selectBullet ? "" : h),
              r = document.createElement("i");
            "" !== o &&
              ((r.className = `${o} select-option-icon`),
              r.setAttribute("aria-hidden", "true"));
            const d = document.createElement("span");
            "" !== l &&
              ((d.className = "select-option-meta"),
              (d.textContent = l),
              (d.title = l));
            const u = f ? [i] : [];
            ("" !== o && u.push(r),
              u.push(s),
              "" !== l && u.push(d),
              f || u.push(i),
              a.append(...u),
              (a.dataset.search = (e.dataset.search || `${s.textContent} ${l}`)
                .trim()
                .toLowerCase()),
              (a.title = "" !== l ? `${s.textContent} — ${l}` : s.textContent),
              (a.disabled = e.disabled || Boolean(e.parentElement?.disabled)),
              a.addEventListener("click", () =>
                ((e) => {
                  const a = n.options[e];
                  if (!a || a.disabled || a.parentElement?.disabled) return;
                  if (n.multiple)
                    return (
                      (a.selected = !a.selected),
                      y(),
                      n.dispatchEvent(new Event("input", { bubbles: !0 })),
                      void n.dispatchEvent(new Event("change", { bubbles: !0 }))
                    );
                  const s = n.selectedIndex !== e;
                  ((n.selectedIndex = e),
                    y(),
                    t(!0),
                    s &&
                      (n.dispatchEvent(new Event("input", { bubbles: !0 })),
                      n.dispatchEvent(new Event("change", { bubbles: !0 }))));
                })(Number(a.dataset.index)),
              ),
              optionsWrap.append(a));
          },
          w = () => c.querySelector(".select-search-input"),
          L = () => {
            const e = (w()?.value || "").trim().toLowerCase();
            let t = 0;
            (c.querySelectorAll(".select-option").forEach((a) => {
              const n = "" === e || (a.dataset.search || "").includes(e);
              ((a.hidden = !n), n && (t += 1));
            }),
              c.querySelectorAll(".select-group").forEach((e) => {
                let t = e.nextElementSibling,
                  a = !1;
                for (; t && !t.classList.contains("select-group"); ) {
                  if (t.classList.contains("select-option") && !t.hidden) {
                    a = !0;
                    break;
                  }
                  t = t.nextElementSibling;
                }
                e.hidden = !a;
              }));
            const a = c.querySelector(".select-empty");
            a && (a.hidden = 0 !== t);
          },
          x = () => {
            if ((c.replaceChildren(), optionsWrap.replaceChildren(), p)) {
              const e = document.createElement("label");
              e.className = "select-search";
              const t = document.createElement("i");
              ((t.className = "bi bi-search"),
                t.setAttribute("aria-hidden", "true"));
              const n = document.createElement("input");
              ((n.type = "text"),
                (n.className = "select-search-input"),
                (n.placeholder = m || "Cari..."),
                (n.autocomplete = "off"),
                n.setAttribute("aria-label", m || "Cari"),
                e.append(t, n),
                n.addEventListener("input", L),
                n.addEventListener("keydown", (e) => {
                  ("ArrowDown" !== e.key && "ArrowUp" !== e.key) ||
                    (e.preventDefault(), a(c)[0]?.focus());
                }),
                c.append(e));
            }
            c.append(optionsWrap);
            Array.from(n.children).forEach((e) => {
              if (e instanceof HTMLOptGroupElement) {
                const t = document.createElement("div");
                ((t.className = ["select-group", ...e.classList].join(" ")),
                  (t.textContent = e.label),
                  (t.title = e.label),
                  optionsWrap.append(t),
                  Array.from(e.children).forEach((e) => E(e)));
              } else e instanceof HTMLOptionElement && E(e);
            });
            const e = document.createElement("p");
            ((e.className = "select-empty"),
              (e.textContent = "Tidak ada hasil"),
              (e.hidden = !0),
              optionsWrap.append(e));
            const t = w();
            (t && (t.value = ""), y(), p && L());
          },
          A = () => {
            const e = o.getBoundingClientRect(),
              t = window.visualViewport,
              a = t?.offsetTop || 0,
              s =
                a +
                (t?.height ||
                  document.documentElement.clientHeight ||
                  window.innerHeight),
              i = document.documentElement.clientWidth || window.innerWidth,
              l = n.closest(".modal-card, dialog")?.getBoundingClientRect(),
              r = Math.max(a + 8, l ? l.top + 8 : 8),
              d = Math.min(s - 8, l ? l.bottom - 8 : s - 8),
              u = Math.max(8, l ? l.left + 8 : 8),
              m = Math.min(i - 8, l ? l.right - 8 : i - 8);
            if (e.bottom <= r || e.top >= d) return void S();
            ((c.style.width = Math.max(1, Math.round(e.width)) + "px"),
              (c.style.maxWidth = Math.max(1, m - u) + "px"));
            const p = Math.min(c.getBoundingClientRect().width, m - u);
            c.style.left =
              Math.round(Math.max(u, Math.min(e.left, m - p))) + "px";
            const b = Math.max(0, d - e.bottom - 5),
              h = Math.max(0, e.top - r - 5),
              g = b < Math.min(360, c.scrollHeight + 2) && h > b,
              f = g ? h : b;
            c.style.maxHeight = Math.max(1, Math.min(360, f)) + "px";
            const v = c.getBoundingClientRect().height;
            c.style.top =
              Math.round(
                g ? Math.max(r, e.top - v - 5) : Math.min(d - v, e.bottom + 5),
              ) + "px";
          },
          S = (e = !1) => {
            ((c.hidden = !0),
              l.classList.remove("is-open"),
              o.setAttribute("aria-expanded", "false"),
              e && o.focus());
          },
          k = () => {
            n.disabled ||
              (e && e.wrapper !== l && t(!1),
              x(),
              (c.hidden = !1),
              l.classList.add("is-open"),
              o.setAttribute("aria-expanded", "true"),
              (e = { wrapper: l, menu: c, button: o, close: S, position: A }),
              A(),
              p && w()?.focus());
          };
        (o.addEventListener("click", () => {
          e?.wrapper === l ? t(!1) : k();
        }),
          o.addEventListener("keydown", (e) => {
            if (["ArrowDown", "ArrowUp", "Enter", " "].includes(e.key)) {
              if ((e.preventDefault(), k(), p)) return;
              const t = a(c);
              (
                t.find((e) => e.classList.contains("is-active")) || t[0]
              )?.focus();
            }
          }),
          c.addEventListener("keydown", (e) => {
            const n = a(c),
              s = n.indexOf(document.activeElement);
            "Escape" === e.key
              ? (e.preventDefault(), t(!0))
              : "ArrowDown" === e.key || "ArrowUp" === e.key
                ? (e.preventDefault(),
                  n[
                    (s + ("ArrowDown" === e.key ? 1 : -1) + n.length) % n.length
                  ]?.focus())
                : ("Home" !== e.key && "End" !== e.key) ||
                  (e.preventDefault(),
                  n["Home" === e.key ? 0 : n.length - 1]?.focus());
          }),
          n.addEventListener("change", y),
          n.addEventListener("invalid", (e) => {
            (e.preventDefault(), o.focus(), k());
          }),
          n.form?.addEventListener("reset", () => setTimeout(y, 0)),
          new MutationObserver(() => x()).observe(n, {
            childList: !0,
            subtree: !0,
            attributes: !0,
            attributeFilter: ["disabled", "label", "selected"],
          }),
          x());
      },
      s = (e) => {
        (e instanceof HTMLSelectElement && n(e),
          e.querySelectorAll?.("select").forEach(n));
      };
    (document.addEventListener("pointerdown", (a) => {
      e && (e.wrapper.contains(a.target) || e.menu.contains(a.target) || t(!1));
    }),
      window.addEventListener("resize", () => e?.position()),
      window.addEventListener(
        "scroll",
        (t) => {
          e &&
            (t.target === e.menu ||
              (t.target instanceof Node && e.menu.contains(t.target)) ||
              e.position());
        },
        !0,
      ),
      document.addEventListener("linnms:table-updated", () => s(document)),
      new MutationObserver((e) => {
        e.forEach((e) =>
          e.addedNodes.forEach((e) => {
            e instanceof Element && s(e);
          }),
        );
      }).observe(document.documentElement, { childList: !0, subtree: !0 }),
      s(document));
  })(),
  (() => {
    "use strict";
    const e = (e) => {
        if (!(e instanceof HTMLInputElement) || "password" !== e.type) return;
        if ("1" === e.dataset.passwordToggleReady) return;
        e.dataset.passwordToggleReady = "1";
        const t = document.createElement("div");
        ((t.className = "password-input-wrap"),
          e.parentNode.insertBefore(t, e),
          t.appendChild(e));
        const a = document.createElement("button");
        ((a.type = "button"),
          (a.className = "password-visibility-toggle"),
          a.setAttribute("aria-label", "Tampilkan password"),
          a.setAttribute("aria-pressed", "false"),
          (a.innerHTML = '<i class="bi-eye" aria-hidden="true"></i>'),
          a.addEventListener("click", () => {
            const t = "password" === e.type;
            ((e.type = t ? "text" : "password"),
              a.setAttribute("aria-pressed", t ? "true" : "false"),
              a.setAttribute(
                "aria-label",
                t ? "Sembunyikan password" : "Tampilkan password",
              ),
              (a.innerHTML = t
                ? '<i class="bi-eye-slash" aria-hidden="true"></i>'
                : '<i class="bi-eye" aria-hidden="true"></i>'),
              e.focus({ preventScroll: !0 }));
            try {
              const t = e.value.length;
              e.setSelectionRange(t, t);
            } catch (e) {}
          }),
          t.appendChild(a));
      },
      t = (t = document) => {
        t.querySelectorAll('input[type="password"]').forEach(e);
      };
    ("loading" === document.readyState
      ? document.addEventListener("DOMContentLoaded", () => t(), { once: !0 })
      : t(),
      new MutationObserver((a) => {
        for (const n of a)
          for (const a of n.addedNodes)
            a instanceof Element &&
              (a.matches('input[type="password"]') && e(a), t(a));
      }).observe(document.documentElement, { childList: !0, subtree: !0 }));
  })(),
  (() => {
    "use strict";
    const e = Array.from(
      document.querySelectorAll("[data-device-uptime-clock]"),
    )
      .map((e) => {
        const t = Number.parseInt(e.dataset.uptimeSeconds || "", 10);
        return Number.isFinite(t) && t >= 0
          ? {
              element: e,
              uptimeSeconds: t,
              dayLabel: e.dataset.uptimeDayLabel || "d",
            }
          : null;
      })
      .filter(Boolean);
    if (0 === e.length) return;
    const t = Date.now();
    let a = null;
    const n = (e) => String(e).padStart(2, "0"),
      s = () => {
        const a = Math.floor((Date.now() - t) / 1e3);
        e.forEach(({ element: e, uptimeSeconds: t, dayLabel: s }) => {
          e.textContent = ((e, t) => {
            const a = Math.floor(e / 86400),
              s = Math.floor((e % 86400) / 3600),
              i = Math.floor((e % 3600) / 60),
              l = e % 60;
            return `${a}${"d" === t ? "d" : ` ${t}`} ${n(s)}:${n(i)}:${n(l)}`;
          })(t + a, s);
        });
      },
      i = () => {
        null !== a && (window.clearTimeout(a), (a = null));
      },
      l = () => {
        if ((i(), document.hidden)) return;
        s();
        const e = 1e3 - ((Date.now() - t) % 1e3);
        a = window.setTimeout(l, e);
      };
    (document.addEventListener("visibilitychange", l),
      window.addEventListener("pagehide", i),
      window.addEventListener("pageshow", l),
      l());
  })(),
  (() => {
    "use strict";
    const e = document.body;
    if (!e?.dataset.sessionRemaining) return;
    const t = Math.max(
        0,
        Number.parseInt(e.dataset.sessionRemaining || "0", 10) || 0,
      ),
      a = e.dataset.sessionLogin || "/login",
      n = Date.now() + 1e3 * t;
    let s = !1;
    const i = window.fetch.bind(window);
    window.fetch = async (...e) => {
      const t = await i(...e);
      return (
        401 !== t.status || s || ((s = !0), window.location.assign(a)),
        t
      );
    };
    const l = () => {
      s || Date.now() < n || ((s = !0), window.location.assign(a));
    };
    (l(),
      window.setInterval(l, 1e3),
      window.addEventListener("pageshow", (e) => {
        e.persisted && window.location.reload();
      }));
  })(),
  (() => {
    "use strict";
    const e = new WeakSet(),
      t = (e) => {
        if (e.classList.contains("is-expanded")) return;
        const t = e.querySelector("[data-log-expand-text]"),
          a = e.querySelector("[data-log-expand-toggle]");
        t &&
          a &&
          ((a.hidden = !0),
          window.requestAnimationFrame(() => {
            const n = t.scrollWidth > t.clientWidth + 1;
            ((a.hidden = !n), e.classList.toggle("is-expandable", n));
          }));
      },
      a = (a) => {
        if (e.has(a)) return;
        e.add(a);
        const n = a.querySelector("[data-log-expand-toggle]");
        (n &&
          ((n.innerHTML = '<i class="bi-chevron-down" aria-hidden="true"></i>'),
          n.setAttribute("aria-label", "Tampilkan log selengkapnya"),
          n.setAttribute("title", "Tampilkan selengkapnya")),
          t(a),
          "ResizeObserver" in window &&
            new ResizeObserver(() => t(a)).observe(a));
      },
      n = (e = document) => {
        (e.matches?.("[data-log-expandable]") && a(e),
          e.querySelectorAll?.("[data-log-expandable]").forEach(a));
      };
    (document.addEventListener("click", (e) => {
      const a = e.target.closest("[data-log-expand-toggle]"),
        n = e.target.closest("[data-log-expandable]"),
        s = e.target.closest(".public-log-entry, .channel-history-item"),
        i =
          a?.closest("[data-log-expandable]") ||
          n ||
          s?.querySelector("[data-log-expandable]");
      i &&
        i.classList.contains("is-expandable") &&
        ((e.target.closest("a, input, select, textarea, button") && !a) ||
          ((e) => {
            const a = e.querySelector("[data-log-expand-toggle]");
            if (!a || a.hidden) return;
            const n = !e.classList.contains("is-expanded");
            (e.classList.toggle("is-expanded", n),
              a.setAttribute("aria-expanded", n ? "true" : "false"),
              a.setAttribute(
                "aria-label",
                n ? "Tutup detail log" : "Tampilkan log selengkapnya",
              ),
              a.setAttribute("title", n ? "Tutup" : "Tampilkan selengkapnya"),
              n || t(e));
          })(i));
    }),
      n(),
      new MutationObserver((e) => {
        e.forEach((e) =>
          e.addedNodes.forEach((e) => {
            e instanceof Element && n(e);
          }),
        );
      }).observe(document.body, { childList: !0, subtree: !0 }));
  })(),
  (() => {
    "use strict";
    const e = (e) =>
      String(e ?? "")
        .toLowerCase()
        .trim();
    document.querySelectorAll("[data-table-search]").forEach((t) => {
      const a = document.getElementById(t.dataset.tableSearch);
      if (!a || a.hasAttribute("data-onu-server-search")) return;
      const n = [...document.querySelectorAll(`[data-table-filter="${a.id}"]`)],
        s = Math.max(
          0,
          Number.parseInt(a.dataset.tablePageSize || "0", 10) || 0,
        ),
        i = a.dataset.tableItemLabel || "data",
        l = document.querySelector(`[data-table-footer="${a.id}"]`),
        o = document.querySelector(`[data-table-page-numbers="${a.id}"]`),
        r = document.querySelector(`[data-table-prev="${a.id}"]`),
        d = document.querySelector(`[data-table-next="${a.id}"]`);
      let c = 1,
        u = 1;
      const m = ({ resetPage: m = !1 } = {}) => {
          m && (c = 1);
          const p = ((e) =>
              [...e.querySelectorAll("tbody tr")].filter(
                (e) =>
                  !e.hasAttribute("data-table-empty-row") &&
                  !e.hasAttribute("data-table-no-results"),
              ))(a),
            b = a.querySelector("[data-table-empty-row]"),
            h =
              p.length && s > 0
                ? ((e, t) => {
                    const a = e.tBodies[0];
                    if (!a) return null;
                    let n = a.querySelector("[data-table-no-results]");
                    if (n) return n;
                    ((n = document.createElement("tr")),
                      (n.hidden = !0),
                      n.setAttribute("data-table-no-results", ""));
                    const s = document.createElement("td");
                    return (
                      (s.className = "empty-cell"),
                      (s.colSpan = Math.max(
                        1,
                        e.tHead?.rows?.[0]?.cells?.length || 1,
                      )),
                      (s.textContent = `Tidak ada ${t} yang cocok`),
                      n.appendChild(s),
                      a.appendChild(n),
                      n
                    );
                  })(a, i)
                : null,
            g = e(t.value),
            f = p.filter((t) => {
              const a = `${t.dataset.searchText || ""} ${t.textContent || ""}`,
                s = !g || e(a).includes(g),
                i = n.every((a) =>
                  ((t, a) => {
                    const n = e(a.value);
                    if (!n) return !0;
                    const s = a.dataset.rowAttribute;
                    if (s) {
                      const i = String(t.dataset[s] || "");
                      return "token" === a.dataset.match
                        ? i.split("|").some((t) => e(t) === n)
                        : e(i) === n;
                    }
                    const i = t.children[Number(a.dataset.column || 0)];
                    return Boolean(i && e(i.textContent).includes(n));
                  })(t, a),
                );
              return s && i;
            }),
            v = f.length,
            y = s > 0 ? Math.max(1, Math.ceil(v / s)) : 1;
          c = Math.min(Math.max(1, c), y);
          const E = s > 0 ? (c - 1) * s : 0,
            w = s > 0 ? Math.min(E + s, v) : v,
            L = new Set(f.slice(E, w));
          (p.forEach((e) => {
            e.hidden = !L.has(e);
          }),
            b && (b.hidden = p.length > 0),
            h && (h.hidden = !(p.length > 0 && 0 === v)),
            (u = y),
            o &&
              (o.innerHTML = ((e, t, a = 1, n = 2) => {
                const s = [],
                  i = (e) => {
                    e >= 1 && e <= t && !s.includes(e) && s.push(e);
                  };
                for (let e = 1; e <= a + 1; e += 1) i(e);
                for (
                  let t = e - Math.max(1, n);
                  t <= e + Math.max(1, n);
                  t += 1
                )
                  i(t);
                for (let e = t - a; e <= t; e += 1) i(e);
                s.sort((e, t) => e - t);
                const l = [];
                let o = 0;
                return (
                  s.forEach((e) => {
                    (o > 0 && e > o + 1 && l.push("..."), l.push(e), (o = e));
                  }),
                  l
                );
              })(c, y)
                .map((e) =>
                  "..." === e
                    ? '<li class="page-number-ellipsis" aria-hidden="true">&hellip;</li>'
                    : ((e, t) =>
                        e === t
                          ? `<li><span class="page-number is-active" aria-current="page">${e}</span></li>`
                          : `<li><button class="page-number" type="button" data-table-goto="${e}" aria-label="Halaman ${e}">${e}</button></li>`)(
                        e,
                        c,
                      ),
                )
                .join("")),
            r && (r.disabled = c <= 1 || 0 === v),
            d && (d.disabled = c >= y || 0 === v),
            l && (l.hidden = 0 === p.length && !b));
        },
        p = (e) => {
          e < 1 ||
            e > u ||
            e === c ||
            ((c = e),
            m(),
            a
              .closest(".table-panel")
              ?.scrollIntoView({ block: "start", behavior: "smooth" }));
        };
      (t.addEventListener("input", () => m({ resetPage: !0 })),
        n.forEach((e) => {
          if (e instanceof HTMLSelectElement) {
            const t = ((e) => {
              let t = e.closest("[data-select-loading-shell]");
              if (t) return t;
              ((t = document.createElement("span")),
                (t.className = "select-loading-shell"),
                t.setAttribute("data-select-loading-shell", ""));
              const a = e.closest(".select") || e;
              return (a.before(t), t.appendChild(a), t);
            })(e);
            let a;
            e.addEventListener("change", () => {
              window.clearTimeout(a);
              const n = performance.now();
              (e.setAttribute("aria-busy", "true"),
                t
                  .querySelector(".select-button")
                  ?.setAttribute("aria-busy", "true"),
                t.classList.add("is-loading"),
                window.requestAnimationFrame(() =>
                  window.requestAnimationFrame(() => {
                    try {
                      m({ resetPage: !0 });
                    } finally {
                      a = window.setTimeout(
                        () => {
                          (t.classList.remove("is-loading"),
                            e.setAttribute("aria-busy", "false"),
                            t
                              .querySelector(".select-button")
                              ?.setAttribute("aria-busy", "false"));
                        },
                        Math.max(0, 180 - (performance.now() - n)),
                      );
                    }
                  }),
                ));
            });
          } else
            (e.addEventListener("change", () => m({ resetPage: !0 })),
              e.addEventListener("input", () => m({ resetPage: !0 })));
        }),
        a.addEventListener("linnms:table-updated", () => m()),
        r?.addEventListener("click", () => p(c - 1)),
        d?.addEventListener("click", () => p(c + 1)),
        o?.addEventListener("click", (e) => {
          const t =
            e.target instanceof Element
              ? e.target.closest("[data-table-goto]")
              : null;
          t instanceof HTMLElement &&
            (e.preventDefault(),
            p(Number.parseInt(t.dataset.tableGoto || "0", 10) || 0));
        }),
        m());
    });
    const t = document.getElementById("globalSearch");
    t?.addEventListener("input", () => {
      const e = document.querySelector("[data-table-search]");
      e && ((e.value = t.value), e.dispatchEvent(new Event("input")));
    });
  })(),
  (() => {
    "use strict";
    const e = new WeakSet();
    document.addEventListener(
      "invalid",
      (t) => {
        const a = t.target;
        if (
          a instanceof HTMLInputElement ||
          a instanceof HTMLTextAreaElement ||
          a instanceof HTMLSelectElement
        ) {
          t.preventDefault();
          const n = a.form || a;
          if (e.has(n)) return;
          (e.add(n), queueMicrotask(() => e.delete(n)), u(a));
          const s =
            a instanceof HTMLSelectElement &&
            a.classList.contains("select-native")
              ? a.nextElementSibling
              : a;
          (s instanceof HTMLElement && s.focus({ preventScroll: !0 }),
            a.scrollIntoView({ behavior: "smooth", block: "center" }));
        }
      },
      !0,
    );
    const t = (e) => {
      const t = e.target;
      !(
        t instanceof HTMLInputElement ||
        t instanceof HTMLTextAreaElement ||
        t instanceof HTMLSelectElement
      ) ||
        "true" !== t.getAttribute("aria-invalid") ||
        (t.willValidate && !t.validity.valid) ||
        t.removeAttribute("aria-invalid");
    };
    (document.addEventListener("input", t),
      document.addEventListener("change", t));
    const a = "input, textarea, select",
      n = ["hidden", "submit", "button", "reset", "image"],
      s = "Harap isi bidang ini",
      i = (e) =>
        String(e ?? "")
          .replace(/\s+/g, " ")
          .trim(),
      l = (e, t) =>
        e.required
          ? s
          : i(e.getAttribute("placeholder")) ||
            i(
              ((e) => {
                const t = e.closest("label");
                if (!t) return "";
                const a = t.querySelector("span") || t;
                return i(a.textContent);
              })(e),
            ) ||
            i(e.getAttribute("aria-label")) ||
            t;
    document
      .querySelectorAll("[title]")
      .forEach((e) => e.removeAttribute("title"));
    const o = new Map(),
      r = new WeakMap(),
      d = (e) => {
        if (o.has(e)) return;
        if (
          e instanceof HTMLInputElement &&
          "switch" === e.getAttribute("role")
        )
          return;
        if (
          !(
            ((t = e) instanceof HTMLInputElement && !n.includes(t.type)) ||
            t instanceof HTMLTextAreaElement ||
            t instanceof HTMLSelectElement
          )
        )
          return;
        var t;
        const s = ((e) => {
          const t = e.closest("label");
          if (t) return t;
          const n = e.parentElement;
          return n
            ? (n.querySelectorAll(a).length > 1 && e.closest(".field")) || n
            : null;
        })(e);
        if (!s) return;
        const d = l(
          e,
          ((e) => {
            const t = i(e.getAttribute("title"));
            return (e.removeAttribute("title"), t);
          })(e),
        );
        "" !== d &&
          ((s.dataset.tip = d),
          r.set(s, d),
          (s.dataset.tipPos = "bottom"),
          o.set(e, s));
      };
    document.querySelectorAll(a).forEach(d);
    const c = (e) => {
        const t = o.get(e);
        t &&
          ((t.dataset.tip = r.get(t) || ""),
          t.classList.remove("is-tip-open", "is-tip-error"));
      },
      u = (e) => {
        (d(e), e.setAttribute("aria-invalid", "true"));
        const t = o.get(e);
        t &&
          ((t.dataset.tip = ((e) =>
            e.validity.customError
              ? e.validationMessage
              : e.validity.valueMissing
                ? s
                : e.validity.tooShort
                  ? "Minimal " + e.minLength + " karakter"
                  : e.validity.tooLong
                    ? "Maksimal " + e.maxLength + " karakter"
                    : e.validity.rangeUnderflow
                      ? "Nilai minimal " + e.min
                      : e.validity.rangeOverflow
                        ? "Nilai maksimal " + e.max
                        : "Periksa kembali isian ini")(e)),
          t.classList.add("is-tip-open", "is-tip-error"));
      };
    ([...new Set([...o.keys()].map((e) => e.form).filter(Boolean))].forEach(
      (e) => {
        0 !== [...o.keys()].filter((t) => t.form === e && t.required).length &&
          (e.setAttribute("novalidate", ""),
          e.addEventListener("submit", (e) => {
            const t = [...e.currentTarget.elements].filter(
                (e) => e.required && e.willValidate,
              ),
              a = t.find((e) => !e.validity.valid);
            a &&
              (e.preventDefault(),
              t.forEach(c),
              u(a),
              a.focus({ preventScroll: !0 }),
              a.scrollIntoView({ behavior: "smooth", block: "center" }));
          }));
      },
    ),
      ["input", "change", "focusout"].forEach((e) =>
        document.addEventListener(e, (e) => {
          const t = e.target;
          if (!o.has(t)) return;
          const a = o.get(t);
          t.validity.valid ? c(t) : a.classList.contains("is-tip-open") && u(t);
        }),
      ));
  })(),
  (() => {
    "use strict";
    const e = (e) => e instanceof HTMLInputElement && "number" === e.type;
    (document.addEventListener("keydown", (t) => {
      ("ArrowUp" !== t.key && "ArrowDown" !== t.key) ||
        (e(t.target) && t.preventDefault());
    }),
      document.addEventListener(
        "wheel",
        (t) => {
          e(t.target) &&
            document.activeElement === t.target &&
            t.preventDefault();
        },
        { passive: !1 },
      ));
  })(),
  (() => {
    "use strict";
    const e = new WeakMap();
    document.addEventListener("click", async (t) => {
      const a =
        t.target instanceof Element
          ? t.target.closest("[data-copy-serial]")
          : null;
      if (!(a instanceof HTMLButtonElement) || a.disabled) return;
      const n = a.dataset.copySerial || "",
        s = a.closest(".serial-copy-action"),
        i = s?.closest(".serial-copy")?.querySelector("[data-serial-value]"),
        l = a.querySelector("i"),
        o = (e, t) =>
          window.linnmsToast?.(e, t, {
            mount: s,
            closeable: !1,
            timeout: 1800,
          });
      if (!n || "NotSuporte" === n)
        return void o("warning", "Serial belum tersedia");
      a.disabled = !0;
      const r = document.createElement("textarea");
      ((r.value = n),
        (r.readOnly = !0),
        (r.style.cssText =
          "position:fixed;left:0;top:0;opacity:0;pointer-events:none"),
        document.body.append(r),
        r.select());
      let d = !1;
      try {
        d = document.execCommand("copy");
      } catch {}
      if ((r.remove(), !d && navigator.clipboard?.writeText))
        try {
          (await navigator.clipboard.writeText(n), (d = !0));
        } catch {}
      ((a.disabled = !1),
        a.focus(),
        d
          ? (clearTimeout(e.get(a)),
            s?.classList.remove("is-copied"),
            i?.classList.remove("is-copied"),
            a.offsetWidth,
            l && (l.className = "bi bi-check-lg"),
            s?.classList.add("is-copied"),
            i?.classList.add("is-copied"),
            o("success", "Serial tersalin"),
            e.set(
              a,
              setTimeout(() => {
                (s?.classList.remove("is-copied"),
                  i?.classList.remove("is-copied"),
                  l && (l.className = "bi bi-clipboard"));
              }, 1800),
            ))
          : o("error", "Gagal menyalin serial"));
    });
  })(),
  (() => {
    "use strict";
    let e = null,
      t = null,
      a = null,
      n = null;
    const s = new WeakSet();
    document.addEventListener(
      "submit",
      (i) => {
        const l = i.target;
        if (!(l instanceof HTMLFormElement)) return;
        const o = i.submitter;
        if (
          !(
            l.matches("[data-device-delete-form]") ||
            (o instanceof HTMLElement &&
              o.matches("[data-device-delete-trigger]"))
          )
        )
          return;
        if (s.has(l)) return void s.delete(l);
        (i.preventDefault(),
          (t = l),
          (a = o),
          (n = o instanceof HTMLElement ? o : document.activeElement));
        const r =
          e ||
          ((e = document.createElement("dialog")),
          (e.className = "device-delete-dialog"),
          e.setAttribute("aria-labelledby", "device-delete-title"),
          e.setAttribute("aria-describedby", "device-delete-question"),
          (e.innerHTML =
            '<div class="device-delete-dialog-content"><span class="device-delete-dialog-icon" aria-hidden="true"><i class="bi bi-trash3"></i></span><div><h2 id="device-delete-title">Hapus perangkat</h2><p id="device-delete-question">Apakah yakin ingin menghapus perangkat ini?</p></div></div><div class="device-delete-dialog-actions"><button type="button" class="button secondary" data-device-delete-cancel>Batal</button><button type="button" class="button button-danger" data-device-delete-confirm>Oke</button></div>'),
          document.body.append(e),
          e
            .querySelector("[data-device-delete-cancel]")
            .addEventListener("click", () => e.close()),
          e
            .querySelector("[data-device-delete-confirm]")
            .addEventListener("click", () => {
              const n = t,
                i = a;
              ((t = null),
                (a = null),
                e.close(),
                n instanceof HTMLFormElement &&
                  n.isConnected &&
                  (s.add(n),
                  n.requestSubmit(
                    i instanceof HTMLElement && i.isConnected ? i : void 0,
                  )));
            }),
          e.addEventListener("close", () => {
            ((t = null),
              (a = null),
              n?.isConnected && n.focus({ preventScroll: !0 }),
              (n = null));
          }),
          e.addEventListener("click", (t) => {
            t.target === e && e.close();
          }),
          e);
        (r.showModal(), r.querySelector("[data-device-delete-cancel]").focus());
      },
      !0,
    );
  })());
