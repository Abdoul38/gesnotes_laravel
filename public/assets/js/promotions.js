// Cocher / décocher toutes les filières ou tous les niveaux — repris du
// script en ligne de l'ancien pages/structure/promotions.php.
window.toggleCheck = function (name, check) {
  document.querySelectorAll('input[name="' + name + '[]"]').forEach(function (cb) { cb.checked = check; });
};
