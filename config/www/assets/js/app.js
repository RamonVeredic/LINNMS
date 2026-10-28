((() => {
  "use strict";
  const e = document.querySelector("[data-account-menu]");
  if (e) {
    const t = e.querySelector("[data-account-menu-toggle]"),
      n = e.querySelector("[data-account-menu-dropdown]");
    if (t && n) {
      const a = (e) => {
        (t.setAttribute("aria-expanded", e ? "true" : "false"),
          (n.hidden = !e));
      };
      (t.addEventListener("click", () => {
        a("true" !== t.getAttribute("aria-expanded"));
      }),
        document.addEventListener("click", (t) => {
          e.contains(t.target) || a(!1);
        }),
        document.addEventListener("keydown", (e) => {
          "Escape" !== e.key || n.hidden || (a(!1), t.focus());
        }));
    }
  }
  const t = document.getElementById("appSidebar"),
    n = document.querySelector("[data-sidebar-open]"),
    a = document.querySelector(".sidebar-backdrop");
  if (!t || !n || !a) return;
  const s = (e) => {
    (t.classList.toggle("is-open", e),
      n.setAttribute("aria-expanded", e ? "true" : "false"),
      (a.hidden = !e),
      document.body.classList.toggle("sidebar-open", e));
  };
  (n.addEventListener("click", () => s(!0)),
    a.addEventListener("click", () => s(!1)),
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
      n = t ? document.getElementById(t) : null;
    if (!n) return;
    e.addEventListener("input", () => {
      const t = (e.value || "").trim().toLowerCase();
      n.querySelectorAll("[data-log-row]").forEach((e) => {
        const n = (e.dataset.logText || e.textContent || "").toLowerCase();
        e.hidden = "" !== t && !n.includes(t);
      });
    });
  }),
  (() => {
    "use strict";
    let e = null;
    const t = (t = !1) => {
        if (!e) return;
        const n = e;
        ((e = null), n.close(t));
      },
      n = (e) =>
        Array.from(
          e.querySelectorAll(".select-option:not(:disabled):not([hidden])"),
        ),
      a = (a) => {
        if (!(a instanceof HTMLSelectElement)) return;
        if (a.size > 1 || "1" === a.dataset.selectReady) return;
        if (void 0 !== a.dataset.selectPlain) return;
        const s = a.getBoundingClientRect(),
          i = getComputedStyle(a),
          o = document.createElement("span"),
          r = document.createElement("button"),
          l = document.createElement("span"),
          d = document.createElement("i"),
          c = document.createElement("div"),
          u = `select-${Math.random().toString(36).slice(2)}`,
          m = a.dataset.selectSearch,
          p = void 0 !== m,
          b = (a.dataset.selectLabel || "").trim(),
          h = (a.dataset.selectIcon || "").trim(),
          g = (e) => ((e && e.dataset ? e.dataset.icon : "") || "").trim(),
          f = p || "" !== h || "" !== b;
        ((o.className = f ? "select is-gh" : "select"),
          !f &&
            !a.closest(
              ".field, .select-loading-shell, .onu-inline-edit-shell",
            ) &&
            s.width > 0 &&
            (o.style.width = `${Math.ceil(s.width)}px`),
          (r.type = "button"),
          (r.className = "select-button"),
          r.setAttribute("role", "combobox"),
          r.setAttribute("aria-haspopup", "listbox"),
          r.setAttribute("aria-expanded", "false"),
          r.setAttribute("aria-controls", u),
          a.getAttribute("aria-label") &&
            r.setAttribute("aria-label", a.getAttribute("aria-label")),
          f ||
            (s.height > 0 && (r.style.height = `${Math.ceil(s.height)}px`),
            (r.style.backgroundColor = i.backgroundColor),
            (r.style.borderColor = i.borderColor),
            (r.style.borderStyle = i.borderStyle),
            (r.style.borderWidth = i.borderWidth),
            (r.style.borderRadius = i.borderRadius),
            (r.style.color = i.color),
            (r.style.font = i.font),
            (r.style.padding = i.padding)),
          (l.className = "select-value"));
        const y = document.createElement("i");
        ((y.className = "select-icon"),
          y.setAttribute("aria-hidden", "true"),
          (y.hidden = !0),
          (d.className = "bi bi-caret-down-fill select-chevron"),
          d.setAttribute("aria-hidden", "true"),
          r.append(y, l, d),
          (c.id = u),
          (c.className = f ? "select-menu is-gh" : "select-menu"),
          "cyan" === a.dataset.selectBullet && c.classList.add("has-cyan-bullets"),
          (a.dataset.selectMenuClass || "").split(/\s+/).filter((name) => /^[a-zA-Z0-9_-]+$/.test(name)).forEach((name) => c.classList.add(name)),
          c.setAttribute("role", "listbox"),
          (c.hidden = !0),
          a.parentNode.insertBefore(o, a),
          o.append(a, r),
          document.body.append(c),
          a.classList.add("select-native"),
          (a.dataset.selectReady = "1"));
        const w = () => {
            if (a.multiple) {
                const selectedOptions = Array.from(a.options).filter((opt) => opt.selected && opt.value !== "");
                const selectedValues = selectedOptions.map((opt) => opt.value).filter((value) => value !== "");
                const shown = selectedValues.length > 12 ? selectedValues.slice(0, 12).join(", ") + " …" : selectedValues.join(", ");
                const summary = shown !== "" ? shown : (b || "Belum ada dipilih");
                l.textContent = summary;
                l.style.color = "var(--text-primary)";
                const multiLabel = a.getAttribute("aria-label") || b || a.name || "Pilih opsi";
                r.setAttribute("aria-label", multiLabel + ": " + summary);
                r.disabled = a.disabled;
                o.classList.toggle("is-disabled", a.disabled);
                c.querySelectorAll(".select-option").forEach((optEl) => {
                    const opt = a.options[Number(optEl.dataset.index)];
                    const active = !!opt && opt.selected;
                    optEl.classList.toggle("is-active", active);
                    optEl.setAttribute("aria-selected", active ? "true" : "false");
                });
                return;
            }
            const e = a.options[a.selectedIndex] || null,
              t = !e || "" === e.value;
            ((l.textContent =
              t && "" !== b ? b : e ? e.textContent.trim() : ""),
              (l.title = e ? e.textContent.trim() : l.textContent),
              (l.style.color =
                f || !t ? "var(--text-primary)" : "var(--text-muted)"));
            o.classList.toggle("is-placeholder", t);
            const n = (e ? g(e) : "") || h;
            ((y.className = "" !== n ? `${n} select-icon` : "select-icon"),
              (y.hidden = "" === n),
              (r.disabled = a.disabled),
              o.classList.toggle("is-disabled", a.disabled),
              c.querySelectorAll(".select-option").forEach((e) => {
                const t = Number(e.dataset.index) === a.selectedIndex;
                (e.classList.toggle("is-active", t),
                  e.setAttribute("aria-selected", t ? "true" : "false"));
              }));
          },
          v = (e) => {
            if (e.hasAttribute("data-select-placeholder")) return;
            const n = document.createElement("button"),
              s = document.createElement("span"),
              i = document.createElement("i");
            ((n.type = "button"),
            (n.className = "select-option"),
              a.multiple && n.classList.add("is-multiple"),
              a.hasAttribute("data-select-checkbox") && n.classList.add("has-checkbox"),
              e.hasAttribute("data-select-heading") && n.classList.add("is-heading"),
              n.setAttribute("role", "option"),
              (n.dataset.index = String(
                Array.prototype.indexOf.call(a.options, e),
              )),
              (s.className = "select-option-text"),
              (s.textContent = e.textContent.trim()),
              (i.className = "bi ri-check-line select-check"),
              i.setAttribute("aria-hidden", "true"));
            const o = (e.dataset.meta || "").trim(),
            r = g(e) || (a.dataset.selectBullet ? "" : h),
              l = document.createElement("i");
            "" !== r &&
              ((l.className = `${r} select-option-icon`),
              l.setAttribute("aria-hidden", "true"));
            const d = document.createElement("span");
            "" !== o &&
              ((d.className = "select-option-meta"),
              (d.textContent = o),
              (d.title = o));
            const u = f ? [i] : [];
            ("" !== r && u.push(l),
              u.push(s),
              "" !== o && u.push(d),
              f || u.push(i),
              n.append(...u),
              (n.dataset.search = (e.dataset.search || `${s.textContent} ${o}`)
                .trim()
                .toLowerCase()),
              (n.title = "" !== o ? `${s.textContent} — ${o}` : s.textContent),
              (n.disabled = e.disabled || Boolean(e.parentElement?.disabled)),
              n.addEventListener("click", () =>
                ((e) => {
                  const n = a.options[e];
                  if (!n || n.disabled || n.parentElement?.disabled) return;
                  if (a.multiple) {
                    n.selected = !n.selected;
                    w();
                    a.dispatchEvent(new Event("input", { bubbles: !0 }));
                    a.dispatchEvent(new Event("change", { bubbles: !0 }));
                    return;
                  }
                  const s = a.selectedIndex !== e;
                  ((a.selectedIndex = e),
                    w(),
                    t(!0),
                    s &&
                      (a.dispatchEvent(new Event("input", { bubbles: !0 })),
                      a.dispatchEvent(new Event("change", { bubbles: !0 }))));
                })(Number(n.dataset.index)),
              ),
              c.append(n));
          },
          E = () => c.querySelector(".select-search-input"),
          L = () => {
            const e = (E()?.value || "").trim().toLowerCase();
            let t = 0;
            (c.querySelectorAll(".select-option").forEach((n) => {
              const a = "" === e || (n.dataset.search || "").includes(e);
              ((n.hidden = !a), a && (t += 1));
            }),
              c.querySelectorAll(".select-group").forEach((e) => {
                let t = e.nextElementSibling,
                  n = !1;
                for (; t && !t.classList.contains("select-group"); ) {
                  if (t.classList.contains("select-option") && !t.hidden) {
                    n = !0;
                    break;
                  }
                  t = t.nextElementSibling;
                }
                e.hidden = !n;
              }));
            const n = c.querySelector(".select-empty");
            n && (n.hidden = 0 !== t);
          },
          x = () => {
            if ((c.replaceChildren(), p)) {
              const e = document.createElement("label");
              e.className = "select-search";
              const t = document.createElement("i");
              ((t.className = "bi bi-search"),
                t.setAttribute("aria-hidden", "true"));
              const a = document.createElement("input");
              ((a.type = "text"),
                (a.className = "select-search-input"),
                (a.placeholder = m || "Cari..."),
                (a.autocomplete = "off"),
                a.setAttribute("aria-label", m || "Cari"),
                e.append(t, a),
                a.addEventListener("input", L),
                a.addEventListener("keydown", (e) => {
                  ("ArrowDown" !== e.key && "ArrowUp" !== e.key) ||
                    (e.preventDefault(), n(c)[0]?.focus());
                }),
                c.append(e));
            }
            Array.from(a.children).forEach((e) => {
              if (e instanceof HTMLOptGroupElement) {
                const t = document.createElement("div");
                ((t.className = ["select-group", ...e.classList].join(" ")),
                  (t.textContent = e.label),
                  (t.title = e.label),
                  c.append(t),
                  Array.from(e.children).forEach((e) => v(e)));
              } else e instanceof HTMLOptionElement && v(e);
            });
            const e = document.createElement("p");
            ((e.className = "select-empty"),
              (e.textContent = "Tidak ada hasil"),
              (e.hidden = !0),
              c.append(e));
            const t = E();
            (t && (t.value = ""), w(), p && L());
          },
          A = () => {
            const anchor = r.getBoundingClientRect();
            const viewport = window.visualViewport;
            const viewportTop = viewport?.offsetTop || 0;
            const viewportBottom = viewportTop + (viewport?.height || document.documentElement.clientHeight || window.innerHeight);
            const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
            const dialog = a.closest('.modal-card, dialog');
            const bounds = dialog?.getBoundingClientRect();
            const top = Math.max(viewportTop + 8, bounds ? bounds.top + 8 : 8);
            const bottom = Math.min(viewportBottom - 8, bounds ? bounds.bottom - 8 : viewportBottom - 8);
            const left = Math.max(8, bounds ? bounds.left + 8 : 8);
            const right = Math.min(viewportWidth - 8, bounds ? bounds.right - 8 : viewportWidth - 8);
            if (anchor.bottom <= top || anchor.top >= bottom) { S(); return; }
            c.style.width = Math.max(1, Math.round(anchor.width)) + 'px';
            c.style.maxWidth = Math.max(1, right - left) + 'px';
            const width = Math.min(c.getBoundingClientRect().width, right - left);
            c.style.left = Math.round(Math.max(left, Math.min(anchor.left, right - width))) + 'px';
            const below = Math.max(0, bottom - anchor.bottom - 5);
            const above = Math.max(0, anchor.top - top - 5);
            const desired = Math.min(360, c.scrollHeight + 2);
            const upward = below < desired && above > below;
            const available = upward ? above : below;
            c.style.maxHeight = Math.max(1, Math.min(360, available)) + 'px';
            const height = c.getBoundingClientRect().height;
            c.style.top = Math.round(upward ? Math.max(top, anchor.top - height - 5) : Math.min(bottom - height, anchor.bottom + 5)) + 'px';
          },
          S = (e = !1) => {
            ((c.hidden = !0),
              o.classList.remove("is-open"),
              r.setAttribute("aria-expanded", "false"),
              e && r.focus());
          },
          k = () => {
            a.disabled ||
              (e && e.wrapper !== o && t(!1),
              x(),
              (c.hidden = !1),
              o.classList.add("is-open"),
              r.setAttribute("aria-expanded", "true"),
              (e = { wrapper: o, menu: c, button: r, close: S, position: A }),
              A(),
              p && E()?.focus());
          };
        (r.addEventListener("click", () => {
          e?.wrapper === o ? t(!1) : k();
        }),
          r.addEventListener("keydown", (e) => {
            if (["ArrowDown", "ArrowUp", "Enter", " "].includes(e.key)) {
              if ((e.preventDefault(), k(), p)) return;
              const t = n(c);
              (
                t.find((e) => e.classList.contains("is-active")) || t[0]
              )?.focus();
            }
          }),
          c.addEventListener("keydown", (e) => {
            const a = n(c),
              s = a.indexOf(document.activeElement);
            if ("Escape" === e.key) (e.preventDefault(), t(!0));
            else if ("ArrowDown" === e.key || "ArrowUp" === e.key) {
              e.preventDefault();
              a[
                (s + ("ArrowDown" === e.key ? 1 : -1) + a.length) % a.length
              ]?.focus();
            } else
              ("Home" !== e.key && "End" !== e.key) ||
                (e.preventDefault(),
                a["Home" === e.key ? 0 : a.length - 1]?.focus());
          }),
          a.addEventListener("change", w),
          a.addEventListener("invalid", (e) => {
            (e.preventDefault(), r.focus(), k());
          }),
          a.form?.addEventListener("reset", () => setTimeout(w, 0)),
          new MutationObserver(() => x()).observe(a, {
            childList: !0,
            subtree: !0,
            attributes: !0,
            attributeFilter: ["disabled", "label", "selected"],
          }),
          x());
      },
      s = (e) => {
        (e instanceof HTMLSelectElement && a(e),
          e.querySelectorAll?.("select").forEach(a));
      };
    (document.addEventListener("pointerdown", (n) => {
      e && (e.wrapper.contains(n.target) || e.menu.contains(n.target) || t(!1));
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
        const n = document.createElement("button");
        ((n.type = "button"),
          (n.className = "password-visibility-toggle"),
          n.setAttribute("aria-label", "Tampilkan password"),
          n.setAttribute("aria-pressed", "false"),
          (n.innerHTML = '<i class="bi-eye" aria-hidden="true"></i>'),
          n.addEventListener("click", () => {
            const t = "password" === e.type;
            ((e.type = t ? "text" : "password"),
              n.setAttribute("aria-pressed", t ? "true" : "false"),
              n.setAttribute(
                "aria-label",
                t ? "Sembunyikan password" : "Tampilkan password",
              ),
              (n.innerHTML = t
                ? '<i class="bi-eye-slash" aria-hidden="true"></i>'
                : '<i class="bi-eye" aria-hidden="true"></i>'),
              e.focus({ preventScroll: !0 }));
            try {
              const t = e.value.length;
              e.setSelectionRange(t, t);
            } catch (e) {}
          }),
          t.appendChild(n));
      },
      t = (t = document) => {
        t.querySelectorAll('input[type="password"]').forEach(e);
      };
    "loading" === document.readyState
      ? document.addEventListener("DOMContentLoaded", () => t(), { once: !0 })
      : t();
    new MutationObserver((n) => {
      for (const a of n)
        for (const n of a.addedNodes)
          n instanceof Element &&
            (n.matches('input[type="password"]') && e(n), t(n));
    }).observe(document.documentElement, { childList: !0, subtree: !0 });
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
    let n = null;
    const a = (e) => String(e).padStart(2, "0"),
      s = () => {
        const n = Math.floor((Date.now() - t) / 1e3);
        e.forEach(({ element: e, uptimeSeconds: t, dayLabel: s }) => {
          e.textContent = ((e, t) => {
            const n = Math.floor(e / 86400),
              s = Math.floor((e % 86400) / 3600),
              i = Math.floor((e % 3600) / 60),
              o = e % 60;
            return `${n}${"d" === t ? "d" : ` ${t}`} ${a(s)}:${a(i)}:${a(o)}`;
          })(t + n, s);
        });
      },
      i = () => {
        null !== n && (window.clearTimeout(n), (n = null));
      },
      o = () => {
        if ((i(), document.hidden)) return;
        s();
        const e = 1e3 - ((Date.now() - t) % 1e3);
        n = window.setTimeout(o, e);
      };
    (document.addEventListener("visibilitychange", o),
      window.addEventListener("pagehide", i),
      window.addEventListener("pageshow", o),
      o());
  })(),
  (() => {
    "use strict";
    const e = document.body;
    if (!e?.dataset.sessionRemaining) return;
    const t = Math.max(
        0,
        Number.parseInt(e.dataset.sessionRemaining || "0", 10) || 0,
      ),
      n = e.dataset.sessionLogin || "/login",
      a = Date.now() + 1e3 * t;
    let s = !1;
    const i = window.fetch.bind(window);
    window.fetch = async (...e) => {
      const t = await i(...e);
      return (
        401 !== t.status || s || ((s = !0), window.location.assign(n)),
        t
      );
    };
    const o = () => {
      s || Date.now() < a || ((s = !0), window.location.assign(n));
    };
    (o(),
      window.setInterval(o, 1e3),
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
          n = e.querySelector("[data-log-expand-toggle]");
        t &&
          n &&
          ((n.hidden = !0),
          window.requestAnimationFrame(() => {
            const a = t.scrollWidth > t.clientWidth + 1;
            ((n.hidden = !a), e.classList.toggle("is-expandable", a));
          }));
      },
      n = (n) => {
        if (e.has(n)) return;
        e.add(n);
        const a = n.querySelector("[data-log-expand-toggle]");
        if (
          (a &&
            ((a.innerHTML =
              '<i class="bi-chevron-down" aria-hidden="true"></i>'),
            a.setAttribute("aria-label", "Tampilkan log selengkapnya"),
            a.setAttribute("title", "Tampilkan selengkapnya")),
          t(n),
          "ResizeObserver" in window)
        ) {
          new ResizeObserver(() => t(n)).observe(n);
        }
      },
      a = (e = document) => {
        (e.matches?.("[data-log-expandable]") && n(e),
          e.querySelectorAll?.("[data-log-expandable]").forEach(n));
      };
    (document.addEventListener("click", (e) => {
      const n = e.target.closest("[data-log-expand-toggle]"),
        a = e.target.closest("[data-log-expandable]"),
        s = e.target.closest(".public-log-entry, .channel-history-item"),
        i =
          n?.closest("[data-log-expandable]") ||
          a ||
          s?.querySelector("[data-log-expandable]");
      if (!i || !i.classList.contains("is-expandable")) return;
      (e.target.closest("a, input, select, textarea, button") && !n) ||
        ((e) => {
          const n = e.querySelector("[data-log-expand-toggle]");
          if (!n || n.hidden) return;
          const a = !e.classList.contains("is-expanded");
          (e.classList.toggle("is-expanded", a),
            n.setAttribute("aria-expanded", a ? "true" : "false"),
            n.setAttribute(
              "aria-label",
              a ? "Tutup detail log" : "Tampilkan log selengkapnya",
            ),
            n.setAttribute("title", a ? "Tutup" : "Tampilkan selengkapnya"),
            a || t(e));
        })(i);
    }),
      a(),
      new MutationObserver((e) => {
        e.forEach((e) =>
          e.addedNodes.forEach((e) => {
            e instanceof Element && a(e);
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
      const n = document.getElementById(t.dataset.tableSearch);
      if (!n || n.hasAttribute("data-onu-server-search")) return;
      const a = [...document.querySelectorAll(`[data-table-filter="${n.id}"]`)],
        s = Math.max(
          0,
          Number.parseInt(n.dataset.tablePageSize || "0", 10) || 0,
        ),
        i = n.dataset.tableItemLabel || "data",
        o = document.querySelector(`[data-table-footer="${n.id}"]`),
        r = document.querySelector(`[data-table-page-info-top="${n.id}"]`),
        l = document.querySelector(`[data-table-page-numbers="${n.id}"]`),
        d = document.querySelector(`[data-table-prev="${n.id}"]`),
        c = document.querySelector(`[data-table-next="${n.id}"]`);
      let u = 1,
        m = 1;
      const p = ({ resetPage: p = !1 } = {}) => {
          p && (u = 1);
          const b = ((e) =>
              [...e.querySelectorAll("tbody tr")].filter(
                (e) =>
                  !e.hasAttribute("data-table-empty-row") &&
                  !e.hasAttribute("data-table-no-results"),
              ))(n),
            h = n.querySelector("[data-table-empty-row]"),
            g =
              b.length && s > 0
                ? ((e, t) => {
                    const n = e.tBodies[0];
                    if (!n) return null;
                    let a = n.querySelector("[data-table-no-results]");
                    if (a) return a;
                    ((a = document.createElement("tr")),
                      (a.hidden = !0),
                      a.setAttribute("data-table-no-results", ""));
                    const s = document.createElement("td");
                    return (
                      (s.className = "empty-cell"),
                      (s.colSpan = Math.max(
                        1,
                        e.tHead?.rows?.[0]?.cells?.length || 1,
                      )),
                      (s.textContent = `Tidak ada ${t} yang cocok`),
                      a.appendChild(s),
                      n.appendChild(a),
                      a
                    );
                  })(n, i)
                : null,
            f = e(t.value),
            y = b.filter((t) => {
              const n = `${t.dataset.searchText || ""} ${t.textContent || ""}`,
                s = !f || e(n).includes(f),
                i = a.every((n) =>
                  ((t, n) => {
                    const a = e(n.value);
                    if (!a) return !0;
                    const s = n.dataset.rowAttribute;
                    if (s) {
                      const i = String(t.dataset[s] || "");
                      return "token" === n.dataset.match
                        ? i.split("|").some((t) => e(t) === a)
                        : e(i) === a;
                    }
                    const i = t.children[Number(n.dataset.column || 0)];
                    return Boolean(i && e(i.textContent).includes(a));
                  })(t, n),
                );
              return s && i;
            }),
            w = y.length,
            v = s > 0 ? Math.max(1, Math.ceil(w / s)) : 1;
          u = Math.min(Math.max(1, u), v);
          const E = s > 0 ? (u - 1) * s : 0,
            L = s > 0 ? Math.min(E + s, w) : w,
            x = new Set(y.slice(E, L));
          (b.forEach((e) => {
            e.hidden = !x.has(e);
          }),
            h && (h.hidden = b.length > 0),
            g && (g.hidden = !(b.length > 0 && 0 === w)),
            (m = v),
            r && (r.textContent = `Halaman ${u} dari ${v}`),
            l &&
              (l.innerHTML = ((e, t, n = 1, a = 2) => {
                const s = [],
                  i = (e) => {
                    e >= 1 && e <= t && !s.includes(e) && s.push(e);
                  };
                for (let e = 1; e <= n + 1; e += 1) i(e);
                for (
                  let t = e - Math.max(1, a);
                  t <= e + Math.max(1, a);
                  t += 1
                )
                  i(t);
                for (let e = t - n; e <= t; e += 1) i(e);
                s.sort((e, t) => e - t);
                const o = [];
                let r = 0;
                return (
                  s.forEach((e) => {
                    (r > 0 && e > r + 1 && o.push("..."), o.push(e), (r = e));
                  }),
                  o
                );
              })(u, v)
                .map((e) =>
                  "..." === e
                    ? '<li class="page-number-ellipsis" aria-hidden="true">&hellip;</li>'
                    : ((e, t) =>
                        e === t
                          ? `<li><span class="page-number is-active" aria-current="page">${e}</span></li>`
                          : `<li><button class="page-number" type="button" data-table-goto="${e}" aria-label="Halaman ${e}">${e}</button></li>`)(
                        e,
                        u,
                      ),
                )
                .join("")),
            d && (d.disabled = u <= 1 || 0 === w),
            c && (c.disabled = u >= v || 0 === w),
            o && (o.hidden = 0 === b.length && !h));
        },
        b = (e) => {
          e < 1 ||
            e > m ||
            e === u ||
            ((u = e),
            p(),
            n
              .closest(".table-panel")
              ?.scrollIntoView({ block: "start", behavior: "smooth" }));
        };
      (t.addEventListener("input", () => p({ resetPage: !0 })),
        a.forEach((e) => {
          if (e instanceof HTMLSelectElement) {
            const t = ((e) => {
              let t = e.closest("[data-select-loading-shell]");
              if (t) return t;
              ((t = document.createElement("span")),
                (t.className = "select-loading-shell"),
                t.setAttribute("data-select-loading-shell", ""));
              const n = e.closest(".select") || e;
              return (n.before(t), t.appendChild(n), t);
            })(e);
            let n;
            e.addEventListener("change", () => {
              window.clearTimeout(n);
              const a = performance.now();
              (e.setAttribute("aria-busy", "true"),
                t
                  .querySelector(".select-button")
                  ?.setAttribute("aria-busy", "true"),
                t.classList.add("is-loading"),
                window.requestAnimationFrame(() =>
                  window.requestAnimationFrame(() => {
                    try {
                      p({ resetPage: !0 });
                    } finally {
                      n = window.setTimeout(
                        () => {
                          (t.classList.remove("is-loading"),
                            e.setAttribute("aria-busy", "false"),
                            t
                              .querySelector(".select-button")
                              ?.setAttribute("aria-busy", "false"));
                        },
                        Math.max(0, 180 - (performance.now() - a)),
                      );
                    }
                  }),
                ));
            });
          } else
            (e.addEventListener("change", () => p({ resetPage: !0 })),
              e.addEventListener("input", () => p({ resetPage: !0 })));
        }),
        n.addEventListener("linnms:table-updated", () => p()),
        d?.addEventListener("click", () => b(u - 1)),
        c?.addEventListener("click", () => b(u + 1)),
        l?.addEventListener("click", (e) => {
          const t =
            e.target instanceof Element
              ? e.target.closest("[data-table-goto]")
              : null;
          t instanceof HTMLElement &&
            (e.preventDefault(),
            b(Number.parseInt(t.dataset.tableGoto || "0", 10) || 0));
        }),
        p());
    });
    const t = document.getElementById("globalSearch");
    t?.addEventListener("input", () => {
      const e = document.querySelector("[data-table-search]");
      e && ((e.value = t.value), e.dispatchEvent(new Event("input")));
    });
  })(),

  (() => {
    "use strict";
    const validationShown = new WeakSet();
    document.addEventListener("invalid", (event) => {
      const field = event.target;
      if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
        event.preventDefault();
        const owner = field.form || field;
        if (validationShown.has(owner)) return;
        validationShown.add(owner);
        queueMicrotask(() => validationShown.delete(owner));
        r(field);
        const focusTarget = field instanceof HTMLSelectElement && field.classList.contains("select-native")
          ? field.nextElementSibling : field;
        if (focusTarget instanceof HTMLElement) focusTarget.focus({ preventScroll: true });
        field.scrollIntoView({ behavior: "smooth", block: "center" });
      }
    }, true);
    const clearInvalid = (event) => {
      const field = event.target;
      if ((field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement)
        && field.getAttribute("aria-invalid") === "true" && (!field.willValidate || field.validity.valid)) {
        field.removeAttribute("aria-invalid");
      }
    };
    document.addEventListener("input", clearInvalid);
    document.addEventListener("change", clearInvalid);
    const e = "input, textarea, select",
      t = ["hidden", "submit", "button", "reset", "image"],
      n = "Harap isi bidang ini",
      a = (e) =>
        String(e ?? "")
          .replace(/\s+/g, " ")
          .trim(),
      s = (e, t) =>
        e.required
          ? n
          : a(e.getAttribute("placeholder")) ||
            a(
              ((e) => {
                const t = e.closest("label");
                if (!t) return "";
                const n = t.querySelector("span") || t;
                return a(n.textContent);
              })(e),
            ) ||
            a(e.getAttribute("aria-label")) ||
            t;
    document
      .querySelectorAll("[title]")
      .forEach((e) => e.removeAttribute("title"));
    const i = new Map(),
      tipDefaults = new WeakMap();
    const prepareField = (n) => {
      if (i.has(n)) return;
      if (
        !(
          ((o = n) instanceof HTMLInputElement && !t.includes(o.type)) ||
          o instanceof HTMLTextAreaElement ||
          o instanceof HTMLSelectElement
        )
      )
        return;
      var o;
      const r = ((t) => {
        const n = t.closest("label");
        if (n) return n;
        const a = t.parentElement;
        return a ? (a.querySelectorAll(e).length > 1 ? t.closest(".field") || a : a) : null;
      })(n);
      if (!r) return;
      const l = s(
        n,
        ((e) => {
          const t = a(e.getAttribute("title"));
          return (e.removeAttribute("title"), t);
        })(n),
      );
      "" !== l &&
        ((r.dataset.tip = l),
        tipDefaults.set(r, l),
        (r.dataset.tipPos = "bottom"),
        i.set(n, r));
    };
    document.querySelectorAll(e).forEach(prepareField);
    const o = (e) => {
        const t = i.get(e);
        t &&
          ((t.dataset.tip = tipDefaults.get(t) || ""),
          t.classList.remove("is-tip-open", "is-tip-error"));
      },
      r = (e) => {
        prepareField(e);
        e.setAttribute("aria-invalid", "true");
        const t = i.get(e);
        t &&
          ((t.dataset.tip = ((e) =>
            e.validity.customError
              ? e.validationMessage
              : e.validity.valueMissing
              ? n
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
    [...new Set([...i.keys()].map((e) => e.form).filter(Boolean))].forEach(
      (e) => {
        const t = [...i.keys()].filter((t) => t.form === e && t.required);
        0 !== t.length &&
          (e.setAttribute("novalidate", ""),
          e.addEventListener("submit", (e) => {
            const requiredFields = [...e.currentTarget.elements]
              .filter((field) => field.required && field.willValidate);
            const n = requiredFields.find((field) => !field.validity.valid);
            n &&
              (e.preventDefault(),
              requiredFields.forEach(o),
              r(n),
              n.focus({ preventScroll: !0 }),
              n.scrollIntoView({ behavior: "smooth", block: "center" }));
          }));
      },
    );
    ["input", "change", "focusout"].forEach((eventName) => document.addEventListener(eventName, (event) => {
      const field = event.target;
      if (!i.has(field)) return;
      const tip = i.get(field);
      if (field.validity.valid) o(field);
      else if (tip.classList.contains("is-tip-open")) r(field);
    }));
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
  })());

(() => {
  'use strict';

  const copyIconTimers = new WeakMap();

  document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-copy-serial]') : null;
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;

    const value = button.dataset.copySerial || '';
    const copy = button.closest('.serial-copy-action');
    const serial = copy?.closest('.serial-copy')?.querySelector('[data-serial-value]');
    const icon = button.querySelector('i');
    const showCopyToast = (type, message) => window.linnmsToast?.(type, message, {
      mount: copy,
      closeable: false,
      timeout: 1800,
    });

    if (!value || value === 'NotSuporte') {
      showCopyToast('warning', 'Serial belum tersedia');
      return;
    }

    button.disabled = true;
    const input = document.createElement('textarea');
    input.value = value;
    input.readOnly = true;
    input.style.cssText = 'position:fixed;left:0;top:0;opacity:0;pointer-events:none';
    document.body.append(input);
    input.select();
    let copied = false;
    try {
      copied = document.execCommand('copy');
    } catch {}
    input.remove();
    if (!copied && navigator.clipboard?.writeText) {
      try {
        await navigator.clipboard.writeText(value);
        copied = true;
      } catch {}
    }
    button.disabled = false;
    button.focus();
    if (!copied) {
      showCopyToast('error', 'Gagal menyalin serial');
      return;
    }

    clearTimeout(copyIconTimers.get(button));
    copy?.classList.remove('is-copied');
    serial?.classList.remove('is-copied');
    void button.offsetWidth;
    if (icon) icon.className = 'bi bi-check-lg';
    copy?.classList.add('is-copied');
    serial?.classList.add('is-copied');
    showCopyToast('success', 'Serial tersalin');
    copyIconTimers.set(button, setTimeout(() => {
      copy?.classList.remove('is-copied');
      serial?.classList.remove('is-copied');
      if (icon) icon.className = 'bi bi-clipboard';
    }, 1800));
  });
})();

(() => {
  'use strict';

  let dialog = null;
  let pendingForm = null;
  let pendingSubmitter = null;
  let returnFocus = null;
  const approvedForms = new WeakSet();

  const getDialog = () => {
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.className = 'device-delete-dialog';
    dialog.setAttribute('aria-labelledby', 'device-delete-title');
    dialog.setAttribute('aria-describedby', 'device-delete-question');
    dialog.innerHTML = '<div class="device-delete-dialog-content"><span class="device-delete-dialog-icon" aria-hidden="true"><i class="bi bi-trash3"></i></span><div><h2 id="device-delete-title">Hapus perangkat</h2><p id="device-delete-question">Apakah yakin ingin menghapus perangkat ini?</p></div></div><div class="device-delete-dialog-actions"><button type="button" class="button secondary" data-device-delete-cancel>Batal</button><button type="button" class="button button-danger" data-device-delete-confirm>Oke</button></div>';
    document.body.append(dialog);
    dialog.querySelector('[data-device-delete-cancel]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-device-delete-confirm]').addEventListener('click', () => {
      const form = pendingForm;
      const submitter = pendingSubmitter;
      pendingForm = null;
      pendingSubmitter = null;
      dialog.close();
      if (!(form instanceof HTMLFormElement) || !form.isConnected) return;
      approvedForms.add(form);
      form.requestSubmit(submitter instanceof HTMLElement && submitter.isConnected ? submitter : undefined);
    });
    dialog.addEventListener('close', () => {
      pendingForm = null;
      pendingSubmitter = null;
      if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
      returnFocus = null;
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
    return dialog;
  };

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const submitter = event.submitter;
    if (!form.matches('[data-device-delete-form]')
        && (!(submitter instanceof HTMLElement) || !submitter.matches('[data-device-delete-trigger]'))) return;
    if (approvedForms.has(form)) {
      approvedForms.delete(form);
      return;
    }
    event.preventDefault();
    pendingForm = form;
    pendingSubmitter = submitter;
    returnFocus = submitter instanceof HTMLElement ? submitter : document.activeElement;
    const modal = getDialog();
    modal.showModal();
    modal.querySelector('[data-device-delete-cancel]').focus();
  }, true);
})();
