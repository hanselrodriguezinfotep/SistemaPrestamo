// js/dashboard.js — Funciones compartidas del dashboard

function toggleSidebar() {
  document.getElementById("sidebar").classList.toggle("open");
  document.getElementById("overlay").classList.toggle("open");
}

function toggleGroup(header) {
  const body = header.nextElementSibling;
  const isOpen = header.classList.contains("open");
  header.classList.toggle("open", !isOpen);
  body.classList.toggle("open", !isOpen);
}

async function logout() {
  if (!confirm("¿Cerrar sesión?")) return;
  await fetch("/GestionPrestamo/api/auth.php?action=logout", {
    method: "POST",
  });
  window.location.href = "/GestionPrestamo/login.php";
}

// Toast
let _toastTimer;
function showToast(msg, type = "") {
  const t = document.getElementById("toast");
  if (!t) return;

  // Limpiar prefijos de emoji redundantes que vengan en el mensaje
  const cleanMsg = msg.replace(/^[✅❌⚠️🗑️]\s*/, '');

  // Ícono según tipo
  const icons = { success: '✓', error: '✕', warning: '⚠' };
  const icon  = icons[type] || 'ℹ';

  t.innerHTML = `<span style="font-size:1rem;flex-shrink:0;opacity:.9">${icon}</span><span>${cleanMsg}</span>`;
  t.className = "toast" + (type ? " " + type : "") + " show";
  clearTimeout(_toastTimer);
  // Errores se quedan más tiempo (5s), éxitos 3s
  const ms = type === 'error' ? 5000 : 3200;
  _toastTimer = setTimeout(() => t.classList.remove("show"), ms);
}
