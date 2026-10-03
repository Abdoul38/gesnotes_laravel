// Pages imprimables autonomes (sans layout.js) : bouton d'impression et
// images de secours (repris des scripts en ligne des anciennes pages PDF).
document.addEventListener('click', function (e) {
  if (e.target.closest('[data-print]')) window.print();
});
document.addEventListener('error', function (e) {
  var el = e.target;
  if (!el.hasAttribute) return;
  if (el.hasAttribute('data-onerror-hide')) el.style.display = 'none';
  if (el.hasAttribute('data-onerror-invisible')) el.style.visibility = 'hidden';
}, true);
