// Paiements — repris du script en ligne de l'ancien paiements.php :
// fenêtre d'ajout / de modification et suggestion du montant dû d'après la
// grille tarifaire (filière × niveau × type).
(function () {
  var form = document.getElementById('form-paiement');
  if (!form) return;
  var $ = function (id) { return document.getElementById(id); };
  var grille = {};
  try { grille = JSON.parse($('grille-tarifaire').textContent) || {}; } catch (e) {}

  function suggererMontant() {
    if ($('inp-action').value !== 'ajouter') return;   // ne jamais écraser un montant en modification
    var filiere, niveau, sel = $('inp-etudiant');
    if (sel) {
      var opt = sel.options[sel.selectedIndex];
      filiere = opt ? opt.getAttribute('data-filiere') : null;
      niveau = opt ? opt.getAttribute('data-niveau') : null;
    } else {
      filiere = form.getAttribute('data-filiere');
      niveau = form.getAttribute('data-niveau');
    }
    if (!filiere || !niveau || filiere === '0' || niveau === '0') return;
    var cle = filiere + '_' + niveau + '_' + $('inp-type').value;
    if (Object.prototype.hasOwnProperty.call(grille, cle)) $('inp-du').value = grille[cle];
  }

  var bouton = $('btn-nouveau-paiement');
  if (bouton) bouton.addEventListener('click', function () {
    form.reset();
    $('inp-action').value = 'ajouter';
    $('inp-id').value = '';
    $('modal-titre-paiement').textContent = '➕ Nouveau paiement';
    $('inp-date').value = form.getAttribute('data-aujourdhui');
    $('inp-annee').value = form.getAttribute('data-annee-defaut');
    if ($('inp-etudiant')) $('inp-etudiant').disabled = false;
    window.openModal('modal-paiement');
    suggererMontant();
  });

  document.querySelectorAll('[data-paiement]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var p = JSON.parse(btn.getAttribute('data-paiement'));
      $('inp-action').value = 'modifier';
      $('inp-id').value = p.id;
      $('modal-titre-paiement').textContent = '✏️ Modifier le paiement';
      if ($('inp-etudiant')) { $('inp-etudiant').value = p.etudiant_id; $('inp-etudiant').disabled = true; }
      $('inp-type').value = p.type_paiement;
      $('inp-annee').value = p.annee_id || form.getAttribute('data-annee-defaut');
      $('inp-du').value = p.montant_du;
      $('inp-paye').value = p.montant_paye;
      $('inp-date').value = p.date_paiement || form.getAttribute('data-aujourdhui');
      $('inp-mode').value = p.mode_paiement;
      $('inp-ref').value = p.reference || '';
      $('inp-notes').value = p.notes || '';
      window.openModal('modal-paiement');
    });
  });

  $('inp-type').addEventListener('change', suggererMontant);
  if ($('inp-etudiant')) $('inp-etudiant').addEventListener('change', suggererMontant);
})();
