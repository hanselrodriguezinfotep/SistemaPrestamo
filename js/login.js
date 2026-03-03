// js/login.js — GestionPrestamo | Login + Registro + Recuperación
"use strict";

const API = "/GestionPrestamo/api/auth.php";
const $ = (id) => document.getElementById(id);
const show = (el) => el && (el.style.display = "block");
const hide = (el) => el && (el.style.display = "none");

// ── Auto-foco al cargar ───────────────────────────────────────
document.addEventListener("DOMContentLoaded", () => {
  // Restaurar usuario recordado
  const saved = localStorage.getItem("ce_remember_user");
  const usernameField = document.querySelector('#loginForm [name="username"]');
  const rememberChk = $("remember");
  if (saved && usernameField) {
    usernameField.value = saved;
    if (rememberChk) rememberChk.checked = true;
    document.querySelector('#loginForm [name="password"]')?.focus();
  } else {
    usernameField?.focus();
  }
});

// ── Tabs ──────────────────────────────────────────────────────
function switchTab(tab) {
  document
    .querySelectorAll(".tab")
    .forEach((t) => t.classList.remove("active"));
  document
    .querySelectorAll(".tab-panel")
    .forEach((p) => p.classList.remove("active"));
  document.querySelector(`.tab[data-tab="${tab}"]`)?.classList.add("active");
  $(`panel-${tab}`)?.classList.add("active");
  document
    .querySelectorAll(".alert")
    .forEach((a) => (a.style.display = "none"));
}

function showAlert(id, msg, type = "error") {
  const el = $(id);
  if (!el) return;
  el.className = `alert alert-${type}`;
  el.innerHTML = msg;
  el.style.display = "flex";
  el.scrollIntoView({ behavior: "smooth", block: "nearest" });
}

function setBtnLoading(btn, loading, txt) {
  btn.disabled = loading;
  btn.innerHTML = loading ? `<span class="btn-spinner"></span>${txt}` : txt;
}

async function api(action, data) {
  const res = await fetch(`${API}?action=${action}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });
  return { status: res.status, body: await res.json() };
}

// ── LOGIN ─────────────────────────────────────────────────────
$("loginForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  hide($("loginError"));
  const btn = $("btnLogin");
  const username = $("loginForm").username.value.trim();
  const password = $("loginForm").password.value;

  const remember = $("remember")?.checked;

  if (!username || !password) {
    showAlert("loginError", "⚠️ Completa usuario y contraseña.");
    return;
  }

  // Guardar o limpiar usuario recordado
  if (remember) {
    localStorage.setItem("ce_remember_user", username);
  } else {
    localStorage.removeItem("ce_remember_user");
  }

  setBtnLoading(btn, true, "Verificando…");
  try {
    const { body } = await api("login", { username, password });
    if (body.success && body.redirect) {
      btn.innerHTML = "✅ Acceso correcto…";
      window.location.href = body.redirect;
      return;
    }
    showAlert("loginError", `⚠️ ${body.error ?? "Error al iniciar sesión."}`);
  } catch {
    showAlert("loginError", "❌ No se pudo conectar con el servidor.");
  } finally {
    if (btn.disabled) setBtnLoading(btn, false, "Iniciar Sesión");
  }
});

// ── Toggle mostrar contraseña ─────────────────────────────────
document.querySelectorAll(".toggle-pw").forEach((btn) => {
  btn.addEventListener("click", () => {
    const sel = btn.dataset.target;
    const target = document.querySelector(sel);
    if (!target) return;
    const isText = target.type === "text";
    target.type = isText ? "password" : "text";
    btn.textContent = isText ? "👁️" : "🙈";
  });
});

// ── REGISTRO ──────────────────────────────────────────────────
const registroForm = $("registroForm");
if (registroForm) {
  const pwInput = registroForm.querySelector('[name="password"]');
  const pwStrength = $("pwStrength");
  const pwBars = document.querySelectorAll(".pw-bar");
  const pwLabel = $("pwLabel");
  const reqs = { len: $("req-len"), upper: $("req-upper"), num: $("req-num") };

  function evalPw(pw) {
    const c = {
      len: pw.length >= 8,
      upper: /[A-Z]/.test(pw),
      num: /[0-9]/.test(pw),
      sym: /[^a-zA-Z0-9]/.test(pw),
    };
    Object.keys(reqs).forEach((k) => reqs[k]?.classList.toggle("ok", c[k]));
    return Object.values(c).filter(Boolean).length;
  }

  pwInput?.addEventListener("input", () => {
    const score = evalPw(pwInput.value);
    const labels = ["", "Débil", "Regular", "Buena", "Fuerte"];
    const cls = ["", "weak", "fair", "good", "strong"];
    pwStrength?.classList.add("visible");
    if (pwLabel) pwLabel.textContent = labels[score] || "";
    pwBars.forEach((b, i) => {
      b.className = "pw-bar";
      if (i < score) b.classList.add(cls[score]);
    });
  });

  // Confirmar contraseña en tiempo real
  const pw2 = registroForm.querySelector('[name="confirm"]');
  pw2?.addEventListener("input", () => {
    pw2.classList.toggle(
      "error",
      pw2.value !== "" && pw2.value !== pwInput.value,
    );
  });

  registroForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    hide($("registroError"));
    hide($("registroSuccess"));

    const btn = $("btnRegistro");
    const f = registroForm;
    const nombre = f.nombre.value.trim();
    const apellido = f.apellido.value.trim();
    const username = f.username.value.trim();
    const email = f.email.value.trim();
    const password = f.password.value;
    const confirm = f.confirm.value;
    const tipo = f.tipo_persona.value;
    const fnac = f.fecha_nacimiento.value;
    const genero = f.genero.value;

    if (!nombre || !apellido || !username || !password) {
      showAlert(
        "registroError",
        "⚠️ Nombre, apellido, usuario y contraseña son requeridos.",
      );
      return;
    }
    if (password !== confirm) {
      showAlert("registroError", "⚠️ Las contraseñas no coinciden.");
      return;
    }
    if (
      password.length < 8 ||
      !/[A-Z]/.test(password) ||
      !/[0-9]/.test(password)
    ) {
      showAlert(
        "registroError",
        "⚠️ La contraseña necesita mínimo 8 caracteres, una mayúscula y un número.",
      );
      return;
    }
    if (!fnac) {
      showAlert("registroError", "⚠️ La fecha de nacimiento es requerida.");
      return;
    }

    // id_centro: leer de campo oculto en el formulario o de ?centro= en la URL
    const idCentro = parseInt(
      f.id_centro?.value ||
        new URLSearchParams(location.search).get("centro") ||
        "0",
    );
    if (!idCentro) {
      showAlert(
        "registroError",
        "⚠️ No se pudo identificar el empresa. Contacta al administrador.",
      );
      return;
    }

    setBtnLoading(btn, true, "Creando cuenta…");
    try {
      const { body } = await api("registro", {
        nombre,
        apellido,
        username,
        email,
        password,
        confirm,
        tipo_persona: tipo,
        fecha_nacimiento: fnac,
        genero,
        id_centro: idCentro,
      });
      if (body.success) {
        showAlert(
          "registroSuccess",
          `✅ ${body.mensaje} Redirigiendo al login…`,
          "success",
        );
        registroForm.reset();
        pwStrength?.classList.remove("visible");
        setTimeout(() => switchTab("login"), 2200);
        return;
      }
      showAlert(
        "registroError",
        `⚠️ ${body.error ?? "Error al crear la cuenta."}`,
      );
    } catch {
      showAlert("registroError", "❌ No se pudo conectar con el servidor.");
    } finally {
      setBtnLoading(btn, false, "Crear Cuenta");
    }
  });
}

// ── RECUPERAR CONTRASEÑA ──────────────────────────────────────
let recoverUsername = "";

$("recoverForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  hide($("recoverError"));
  hide($("recoverSuccess"));
  const btn = $("btnRecover");
  const username = $("recoverForm").username.value.trim();
  const email = $("recoverForm").email.value.trim();

  if (!username || !email) {
    showAlert("recoverError", "⚠️ Completa usuario y correo.");
    return;
  }
  setBtnLoading(btn, true, "Enviando código…");
  try {
    const { body } = await api("solicitar_reset", { username, email });
    if (body.success) {
      recoverUsername = username;
      showAlert(
        "recoverSuccess",
        "📧 Si los datos coinciden recibirás un código en tu correo. Revisa también el spam.",
        "success",
      );
      setTimeout(() => {
        show($("recoverCodeSection"));
        hide($("recoverForm"));
        hide($("recoverSuccess"));
      }, 1200);
      return;
    }
    showAlert("recoverError", `⚠️ ${body.error}`);
  } catch {
    showAlert("recoverError", "❌ Error de conexión.");
  } finally {
    setBtnLoading(btn, false, "Enviar instrucciones");
  }
});

$("resetCodeForm")?.addEventListener("submit", async (e) => {
  e.preventDefault();
  hide($("resetError"));
  const btn = $("btnResetCode");
  const code = $("resetCodeForm").code.value.trim();
  const new_password = $("resetCodeForm").new_password.value;
  const confirm = $("resetCodeForm").new_password2.value;

  if (!code || !new_password) {
    showAlert("resetError", "⚠️ Completa todos los campos.");
    return;
  }
  if (new_password !== confirm) {
    showAlert("resetError", "⚠️ Las contraseñas no coinciden.");
    return;
  }
  if (new_password.length < 8) {
    showAlert("resetError", "⚠️ Mínimo 8 caracteres.");
    return;
  }

  setBtnLoading(btn, true, "Cambiando contraseña…");
  try {
    const { body } = await api("reset_con_codigo", {
      username: recoverUsername,
      code,
      new_password,
      confirm,
    });
    if (body.success) {
      $("recoverCodeSection").innerHTML =
        `<div class="alert alert-success" style="display:flex">✅ ¡Contraseña cambiada! Iniciando sesión…</div>`;
      setTimeout(() => switchTab("login"), 2000);
      return;
    }
    showAlert(
      "resetError",
      `⚠️ ${body.error ?? "Código incorrecto o expirado."}`,
    );
  } catch {
    showAlert("resetError", "❌ Error de conexión.");
  } finally {
    setBtnLoading(btn, false, "Cambiar Contraseña");
  }
});
