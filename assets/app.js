(() => {
  const formatBRL = (cents) =>
    (cents / 100).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const numbersEl = $(".numbers");
  if (numbersEl) {
    const raffleId = Number(numbersEl.dataset.raffleId || 0);
    const priceCents = Number(numbersEl.dataset.priceCents || 0);

    const bottomBar = $("[data-bottom-bar]");
    const selectedCountEl = $("[data-selected-count]");
    const totalEl = $("[data-total]");
    const openReserveBtn = $("[data-open-reserve]");
    const modal = $("[data-modal]");
    const reserveForm = $("[data-reserve-form]");
    const errorEl = $("[data-form-error]");
    const submitBtn = $("[data-submit-reserve]");

    const selected = new Set();

    const updateBar = () => {
      const count = selected.size;
      if (selectedCountEl) selectedCountEl.textContent = String(count);
      if (totalEl) totalEl.textContent = formatBRL(count * priceCents);
      if (bottomBar) bottomBar.hidden = count === 0;
    };

    const setModalOpen = (open) => {
      if (!modal) return;
      modal.hidden = !open;
      document.body.style.overflow = open ? "hidden" : "";
      if (!open && errorEl) {
        errorEl.hidden = true;
        errorEl.textContent = "";
      }
    };

    numbersEl.addEventListener("click", (e) => {
      const btn = e.target.closest("button[data-number]");
      if (!btn || btn.disabled) return;
      const num = Number(btn.dataset.number);
      if (selected.has(num)) {
        selected.delete(num);
        btn.classList.remove("num--selected");
      } else {
        selected.add(num);
        btn.classList.add("num--selected");
      }
      updateBar();
    });

    if (openReserveBtn) {
      openReserveBtn.addEventListener("click", () => setModalOpen(true));
    }

    $$("[data-modal-close]").forEach((el) => {
      el.addEventListener("click", () => setModalOpen(false));
    });

    if (reserveForm) {
      reserveForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (selected.size === 0) return;

        const formData = new FormData(reserveForm);
        const name = String(formData.get("name") || "").trim();
        const phone = String(formData.get("phone") || "").trim();
        if (!name || !phone) return;

        if (errorEl) {
          errorEl.hidden = true;
          errorEl.textContent = "";
        }
        if (submitBtn) submitBtn.disabled = true;

        try {
          const res = await fetch("./api.php?action=create_order", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              raffle_id: raffleId,
              name,
              phone,
              numbers: Array.from(selected.values()),
            }),
          });
          const data = await res.json().catch(() => null);
          if (!res.ok || !data || !data.ok) {
            const msg = data && data.error ? data.error : "Não foi possível reservar.";
            throw new Error(msg);
          }
          window.location.href = data.checkout_url;
        } catch (err) {
          if (errorEl) {
            errorEl.textContent = err instanceof Error ? err.message : "Erro ao reservar.";
            errorEl.hidden = false;
          }
        } finally {
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    }

    updateBar();
  }

  const checkoutEl = $("[data-checkout]");
  if (checkoutEl) {
    const orderId = Number(checkoutEl.dataset.orderId || 0);
    const expiresAt = String(checkoutEl.dataset.expiresAt || "");
    const timerEl = $("[data-timer]", checkoutEl);
    const pixEl = $("[data-pix]", checkoutEl);
    const statusEl = $("[data-pay-status]", checkoutEl);
    const copyBtn = $("[data-copy-pix]", checkoutEl);

    const parseIso = (s) => {
      const ms = Date.parse(s);
      return Number.isFinite(ms) ? ms : null;
    };

    const expiresMs = parseIso(expiresAt);
    const tick = () => {
      if (!timerEl || expiresMs === null) return;
      const diff = Math.max(0, expiresMs - Date.now());
      const totalSec = Math.floor(diff / 1000);
      const mm = String(Math.floor(totalSec / 60)).padStart(2, "0");
      const ss = String(totalSec % 60).padStart(2, "0");
      timerEl.textContent = `${mm}:${ss}`;
    };
    tick();
    setInterval(tick, 500);

    if (copyBtn && pixEl) {
      copyBtn.addEventListener("click", async () => {
        try {
          await navigator.clipboard.writeText(pixEl.value);
          copyBtn.textContent = "Copiado!";
          setTimeout(() => (copyBtn.textContent = "Copiar código PIX"), 1200);
        } catch {
          pixEl.select();
          document.execCommand("copy");
        }
      });
    }

    const renderStatus = (st) => {
      if (!statusEl) return;
      if (st === "paid") {
        statusEl.textContent = "Pagamento confirmado. Seus números estão garantidos.";
        statusEl.style.color = "var(--success)";
      } else if (st === "expired") {
        statusEl.textContent = "Reserva expirada. Selecione os números novamente.";
        statusEl.style.color = "var(--danger)";
      } else if (st === "cancelled") {
        statusEl.textContent = "Pagamento cancelado.";
        statusEl.style.color = "var(--danger)";
      } else {
        statusEl.textContent = "Aguardando confirmação do pagamento…";
        statusEl.style.color = "var(--muted)";
      }
    };

    let stopped = false;
    const poll = async () => {
      if (stopped || !orderId) return;
      try {
        const res = await fetch(`./api.php?action=order_status&id=${orderId}`, { cache: "no-store" });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok) return;
        renderStatus(data.status);
        if (data.status === "paid" || data.status === "expired" || data.status === "cancelled") {
          stopped = true;
        }
      } catch {
      }
    };

    renderStatus("pending");
    poll();
    setInterval(poll, 4000);
  }
})();

