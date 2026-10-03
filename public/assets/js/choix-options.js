// Choix des options : met en évidence les cases cochées (ancien majLigneStyle,
// branché en onchange en ligne, donc bloqué par la CSP).
document.addEventListener('change', function (e) {
  var input = e.target;
  if (!input.closest || !input.closest('label.choix-option')) return;
  var groupe = input.type === 'radio' ? document.getElementsByName(input.name) : [input];
  Array.prototype.forEach.call(groupe, function (r) {
    var l = r.closest('label');
    l.style.background = r.checked ? '#dcfce7' : 'transparent';
    l.style.borderColor = r.checked ? '#16a34a' : '#e5e7eb';
  });
});
