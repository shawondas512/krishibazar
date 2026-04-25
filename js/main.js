// ===== KrishiBazar Main JavaScript =====

// Language toggle (Bangla / English)
const translations = {
  en: {
    home: "Home", products: "Products", prices: "Market Prices",
    login: "Login", register: "Register", logout: "Logout",
    search_placeholder: "Search crops, vegetables...",
    hero_title: "Fresh from Farm to Your Door",
    hero_sub: "Buy directly from farmers across Bangladesh",
    featured: "Featured Products", market_prices: "Today's Market Prices"
  },
  bn: {
    home: "হোম", products: "পণ্য সমূহ", prices: "বাজার দর",
    login: "লগইন", register: "নিবন্ধন", logout: "লগআউট",
    search_placeholder: "ফসল, সবজি খুঁজুন...",
    hero_title: "খামার থেকে সরাসরি আপনার দরজায়",
    hero_sub: "সারা বাংলাদেশের কৃষকদের কাছ থেকে সরাসরি কিনুন",
    featured: "বিশেষ পণ্য", market_prices: "আজকের বাজার দর"
  }
};

let currentLang = localStorage.getItem('lang') || 'en';

function switchLang() {
  currentLang = currentLang === 'en' ? 'bn' : 'en';
  localStorage.setItem('lang', currentLang);
  applyTranslations();
}

function applyTranslations() {
  const t = translations[currentLang];
  document.querySelectorAll('[data-i18n]').forEach(el => {
    const key = el.getAttribute('data-i18n');
    if (t[key]) el.textContent = t[key];
  });
  document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
    const key = el.getAttribute('data-i18n-placeholder');
    if (t[key]) el.placeholder = t[key];
  });
  const btn = document.getElementById('lang-btn');
  if (btn) btn.textContent = currentLang === 'en' ? '🇧🇩 বাংলা' : '🇬🇧 English';
}

// Cart management
function getCart() {
  return JSON.parse(localStorage.getItem('cart') || '[]');
}

function saveCart(cart) {
  localStorage.setItem('cart', JSON.stringify(cart));
  updateCartCount();
}

function addToCart(productId, name, price, unit, image) {
  const cart = getCart();
  const existing = cart.find(i => i.id === productId);
  if (existing) {
    existing.qty += 1;
  } else {
    cart.push({ id: productId, name, price, unit, image, qty: 1 });
  }
  saveCart(cart);
  showToast(`${name} added to cart! 🛒`);
}

function updateCartCount() {
  const cart = getCart();
  const total = cart.reduce((sum, i) => sum + i.qty, 0);
  document.querySelectorAll('.cart-count').forEach(el => {
    el.textContent = total;
    el.style.display = total > 0 ? 'inline-block' : 'none';
  });
}

// Toast notification
function showToast(message, type = 'success') {
  const existing = document.querySelector('.toast');
  if (existing) existing.remove();

  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.style.cssText = `
    position:fixed; bottom:24px; right:24px; z-index:9999;
    background:${type === 'success' ? '#28a745' : '#dc3545'};
    color:white; padding:14px 22px; border-radius:10px;
    font-size:15px; font-weight:600;
    box-shadow:0 4px 20px rgba(0,0,0,0.2);
    animation: slideIn 0.3s ease;
  `;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

// Check auth
function isLoggedIn() {
  return !!localStorage.getItem('user');
}

function getUser() {
  return JSON.parse(localStorage.getItem('user') || 'null');
}

function logout() {
  localStorage.removeItem('user');
  window.location.href = 'index.html';
}

function requireAuth(role = null) {
  const user = getUser();
  if (!user) {
    window.location.href = 'login.html';
    return false;
  }
  if (role && user.role !== role) {
    alert('Access denied.');
    window.location.href = 'index.html';
    return false;
  }
  return true;
}

// API helper
// ===== Updated API helper for XAMPP =====
const API_BASE = window.location.hostname === 'localhost'
  ? '/krishibazar/api/'      // XAMPP local
  : '/api/';                 // Live server (after deploy)

async function apiCall(endpoint, method = 'GET', data = null) {
  const options = {
    method,
    headers: { 'Content-Type': 'application/json' }
  };
  const user = getUser();
  if (user?.token) options.headers['Authorization'] = 'Bearer ' + user.token;
  if (data) options.body = JSON.stringify(data);

  try {
    const res = await fetch(API_BASE + endpoint, options);
    const json = await res.json();
    return json;
  } catch (err) {
    console.error('API error:', err);
    return { success: false, message: 'Connection error' };
  }
}

// Format BDT price
function formatPrice(amount) {
  return '৳' + Number(amount).toLocaleString('bn-BD');
}

// On page load
document.addEventListener('DOMContentLoaded', () => {
  applyTranslations();
  updateCartCount();

  // Update nav based on login status
  const user = getUser();
  const loginBtn = document.getElementById('nav-login');
  const logoutBtn = document.getElementById('nav-logout');
  const userNameEl = document.getElementById('nav-username');

  if (user) {
    if (loginBtn) loginBtn.style.display = 'none';
    if (logoutBtn) logoutBtn.style.display = 'inline';
    if (userNameEl) userNameEl.textContent = user.name;
  } else {
    if (logoutBtn) logoutBtn.style.display = 'none';
  }
});

// Keyframe for toast
const style = document.createElement('style');
style.textContent = `@keyframes slideIn { from { transform: translateX(80px); opacity:0 } to { transform: translateX(0); opacity:1 } }`;
document.head.appendChild(style);