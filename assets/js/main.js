// ============================================================
// assets/js/main.js
// PT Andalan Beton - Core JavaScript
// ============================================================

'use strict';

// ── Dark Mode ──────────────────────────────────────────────
const ThemeManager = (() => {
  const STORAGE_KEY = 'andalan_theme';

  function getTheme() {
    return localStorage.getItem(STORAGE_KEY) ||
      (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(STORAGE_KEY, theme);
    const icon = document.getElementById('theme-icon');
    if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
  }

  function toggle() {
    applyTheme(getTheme() === 'dark' ? 'light' : 'dark');
  }

  function init() {
    applyTheme(getTheme());
  }

  return { init, toggle, getTheme };
})();

// ── Toast Notifications ────────────────────────────────────
const Toast = (() => {
  let container;

  function getContainer() {
    if (!container) {
      container = document.querySelector('.toast-container');
      if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
      }
    }
    return container;
  }

  const ICONS = {
    success: 'fas fa-check-circle',
    error:   'fas fa-times-circle',
    warning: 'fas fa-exclamation-triangle',
    info:    'fas fa-info-circle',
  };

  function show(type = 'info', title = '', message = '', duration = 4000) {
    const c    = getContainer();
    const id   = 'toast_' + Date.now();
    const icon = ICONS[type] || ICONS.info;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.id = id;
    toast.innerHTML = `
      <i class="${icon} toast-icon"></i>
      <div class="toast-content">
        <div class="toast-title">${title}</div>
        ${message ? `<div class="toast-message">${message}</div>` : ''}
      </div>
      <button class="toast-close" onclick="Toast.dismiss('${id}')">
        <i class="fas fa-times"></i>
      </button>`;

    c.appendChild(toast);
    requestAnimationFrame(() => {
      requestAnimationFrame(() => toast.classList.add('show'));
    });

    if (duration > 0) {
      setTimeout(() => dismiss(id), duration);
    }
    return id;
  }

  function dismiss(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('show');
    setTimeout(() => el.remove(), 400);
  }

  return {
    show,
    dismiss,
    success: (title, msg, d) => show('success', title, msg, d),
    error:   (title, msg, d) => show('error',   title, msg, d),
    warning: (title, msg, d) => show('warning', title, msg, d),
    info:    (title, msg, d) => show('info',    title, msg, d),
  };
})();

// ── Modal Manager ──────────────────────────────────────────
const Modal = (() => {
  function open(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
    el.addEventListener('click', function handler(e) {
      if (e.target === el) { close(id); el.removeEventListener('click', handler); }
    });
  }

  function close(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('open');
    document.body.style.overflow = '';
  }

  function closeAll() {
    document.querySelectorAll('.modal-overlay.open').forEach(el => {
      el.classList.remove('open');
    });
    document.body.style.overflow = '';
  }

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeAll();
  });

  return { open, close, closeAll };
})();

// ── Counter Animation ──────────────────────────────────────
function animateCounter(el) {
  const target  = parseFloat(el.dataset.target || el.textContent.replace(/\D/g, ''));
  const prefix  = el.dataset.prefix || '';
  const suffix  = el.dataset.suffix || '';
  const format  = el.dataset.format || 'number';
  const duration = 1200;
  const step     = 16;
  const increment = target / (duration / step);
  let current = 0;

  const timer = setInterval(() => {
    current += increment;
    if (current >= target) {
      current = target;
      clearInterval(timer);
    }

    let display;
    if (format === 'rupiah') {
      display = 'Rp ' + Math.floor(current).toLocaleString('id-ID');
    } else {
      display = Math.floor(current).toLocaleString('id-ID');
    }

    el.textContent = prefix + display + suffix;
  }, step);
}

function initCounters() {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting && !entry.target.dataset.counted) {
        entry.target.dataset.counted = '1';
        animateCounter(entry.target);
      }
    });
  }, { threshold: 0.3 });

  document.querySelectorAll('[data-counter]').forEach(el => {
    el.dataset.target = el.textContent.replace(/[^0-9.]/g, '');
    observer.observe(el);
  });
}

// ── Scroll Animations ──────────────────────────────────────
function initScrollAnimations() {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.style.opacity = '1';
        entry.target.style.transform = 'translateY(0)';
      }
    });
  }, { threshold: 0.1 });

  document.querySelectorAll('[data-animate]').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
    observer.observe(el);
  });
}

// ── Sidebar Mobile ─────────────────────────────────────────
function initSidebar() {
  const sidebar  = document.querySelector('.sidebar');
  const overlay  = document.querySelector('.sidebar-overlay');
  const hamburger = document.querySelector('.hamburger');

  if (!sidebar) return;

  function openSidebar() {
    sidebar.classList.add('open');
    if (overlay) overlay.style.display = 'block';
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    sidebar.classList.remove('open');
    if (overlay) overlay.style.display = 'none';
    document.body.style.overflow = '';
  }

  if (hamburger) hamburger.addEventListener('click', openSidebar);
  if (overlay) overlay.addEventListener('click', closeSidebar);
}

// ── AJAX Form Submit ───────────────────────────────────────
async function submitForm(form, options = {}) {
  const btn = form.querySelector('[type=submit]');
  const origText = btn?.innerHTML;

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
  }

  try {
    const formData = new FormData(form);
    const res = await fetch(form.action || window.location.href, {
      method: form.method || 'POST',
      body: formData,
    });

    const data = await res.json();

    if (data.success) {
      Toast.success(data.title || 'Berhasil', data.message || '');
      if (options.onSuccess) options.onSuccess(data);
      if (data.redirect) setTimeout(() => window.location = data.redirect, 1200);
    } else {
      Toast.error(data.title || 'Gagal', data.message || 'Terjadi kesalahan.');
      if (options.onError) options.onError(data);
    }
  } catch (err) {
    Toast.error('Error', 'Terjadi kesalahan jaringan.');
    console.error(err);
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = origText;
    }
  }
}

// ── Confirm Delete ─────────────────────────────────────────
function confirmDelete(url, name = 'data ini') {
  const overlay = document.getElementById('confirm-modal');
  if (!overlay) {
    if (confirm(`Yakin hapus ${name}?`)) window.location = url;
    return;
  }
  document.getElementById('confirm-msg').textContent = `Yakin ingin menghapus ${name}? Tindakan ini tidak dapat dibatalkan.`;
  document.getElementById('confirm-ok').onclick = () => window.location = url;
  Modal.open('confirm-modal');
}

// ── Image Preview ──────────────────────────────────────────
function initImagePreviews() {
  document.querySelectorAll('[data-preview-target]').forEach(input => {
    input.addEventListener('change', function() {
      const target = document.getElementById(this.dataset.previewTarget);
      if (!target || !this.files[0]) return;
      const reader = new FileReader();
      reader.onload = e => {
        if (target.tagName === 'IMG') {
          target.src = e.target.result;
          target.style.display = 'block';
        }
      };
      reader.readAsDataURL(this.files[0]);
    });
  });
}

// ── Auto-dismiss flash alerts ──────────────────────────────
function initFlashAlerts() {
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(el => {
    const delay = parseInt(el.dataset.autoDismiss) || 5000;
    setTimeout(() => {
      el.style.transition = 'opacity 0.4s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 400);
    }, delay);
  });
}

// ── Tooltip ────────────────────────────────────────────────
function initTooltips() {
  document.querySelectorAll('[data-tooltip]').forEach(el => {
    el.style.position = 'relative';
    el.addEventListener('mouseenter', function() {
      const tip = document.createElement('div');
      tip.className = 'tooltip-popup';
      tip.textContent = this.dataset.tooltip;
      tip.style.cssText = `
        position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%);
        background:#1e293b; color:#fff; font-size:12px; padding:5px 10px;
        border-radius:6px; white-space:nowrap; z-index:9999; pointer-events:none;
      `;
      this.appendChild(tip);
    });
    el.addEventListener('mouseleave', function() {
      const tip = this.querySelector('.tooltip-popup');
      if (tip) tip.remove();
    });
  });
}

// ── Number formatter ──────────────────────────────────────
function formatRupiah(num) {
  return 'Rp ' + parseInt(num || 0).toLocaleString('id-ID');
}

// ── Live search (debounce) ─────────────────────────────────
function debounce(fn, delay = 300) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
}

// ── Init on DOM ready ──────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  ThemeManager.init();
  initSidebar();
  initCounters();
  initScrollAnimations();
  initImagePreviews();
  initFlashAlerts();
  initTooltips();

  document.getElementById('theme-toggle')?.addEventListener('click', ThemeManager.toggle);
});

// ── Expose globals ─────────────────────────────────────────
window.Toast  = Toast;
window.Modal  = Modal;
window.confirmDelete = confirmDelete;
window.submitForm    = submitForm;
window.formatRupiah  = formatRupiah;
window.debounce      = debounce;

// ── Hero Background Slider ─────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  const sliderContainers = document.querySelectorAll('.hero-slides');

  sliderContainers.forEach(function (container) {
    const slides = container.querySelectorAll('.hero-slide');
    if (slides.length <= 1) return;

    let current = 0;

    setInterval(function () {
      slides[current].classList.remove('active');
      current = (current + 1) % slides.length;
      slides[current].classList.add('active');
    }, 5000);
  });
});