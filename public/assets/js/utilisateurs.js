// Comptes utilisateurs — champs selon le rôle, départements en cascade,
// fenêtre de modification (repris du script en ligne de utilisateurs.php).
(function () {
  var source = document.getElementById('donnees-departements');
  var departements = source ? JSON.parse(source.textContent) : [];
  var CANTONNES = ['sc_entite', 'chef_departement', 'doyen'];
  var GLOBAUX = ['sc_centrale', 'admin', 'secretaire', 'recteur'];

  var remplirDepartements = function (form, valeur) {
    var fac = form.querySelector('[data-champ="faculte"]').value;
    var sel = form.querySelector('[data-champ="departement"]');
    sel.innerHTML = '';
    var ajouter = function (v, t) { var o = document.createElement('option'); o.value = v; o.textContent = t; sel.appendChild(o); };
    if (!fac) { ajouter('', "— Sélectionner d'abord une faculté —"); return; }
    var deps = departements.filter(function (d) { return String(d.faculte_id) === String(fac); });
    if (!deps.length) { ajouter('', '— Aucun département pour cette faculté —'); return; }
    ajouter('', '— Choisir —');
    deps.forEach(function (d) { ajouter(d.id, (d.code || '') + ' — ' + d.libelle); });
    if (valeur) sel.value = String(valeur);
  };

  var appliquerRole = function (form, garderFaculte) {
    var role = form.querySelector('[data-champ="role"]').value;
    var fac = form.querySelector('[data-champ="faculte"]');
    var groupe = function (n) { return form.querySelector('[data-groupe="' + n + '"]'); };
    var lie = function (n) { var s = groupe(n).querySelector('select'); return s && s.value; };
    groupe('etudiant').style.display = role === 'etudiant' || lie('etudiant') ? '' : 'none';
    groupe('enseignant').style.display = role === 'enseignant' || lie('enseignant') ? '' : 'none';
    var requise = CANTONNES.indexOf(role) !== -1;
    form.querySelector('[data-faculte-requise]').style.display = requise ? 'inline' : 'none';
    fac.required = requise;
    var aide = form.querySelector('[data-faculte-aide]');
    if (role === 'chef_departement') aide.textContent = 'Obligatoire — sélectionnez ensuite le département';
    else if (role === 'doyen') aide.textContent = 'Obligatoire — ce doyen verra toute sa faculté (tous départements)';
    else if (requise) aide.textContent = 'Obligatoire — cet agent ne verra que cette faculté';
    else if (role === 'sc_centrale') aide.textContent = 'Scolarité centrale = toutes les facultés, laisser vide';
    else if (role === 'recteur') aide.textContent = "Recteur = vue globale de l'université, laisser vide";
    else if (GLOBAUX.indexOf(role) !== -1) aide.textContent = 'Laisser vide = accès toutes facultés';
    else aide.textContent = 'Facultatif';
    if (GLOBAUX.indexOf(role) !== -1 && !garderFaculte) fac.value = '';
    var dep = form.querySelector('[data-champ="departement"]');
    var chef = role === 'chef_departement';
    groupe('departement').style.display = chef ? '' : 'none';
    dep.required = chef;
    if (!chef) dep.value = '';
  };

  document.querySelectorAll('form[data-form-utilisateur]').forEach(function (form) {
    form.querySelector('[data-champ="role"]').addEventListener('change', function () { appliquerRole(form, false); remplirDepartements(form); });
    form.querySelector('[data-champ="faculte"]').addEventListener('change', function () { remplirDepartements(form); });
    appliquerRole(form, true);
    remplirDepartements(form);
  });

  var modal = document.getElementById('modal-utilisateur');
  if (!modal) return;
  var form = modal.querySelector('form');
  document.querySelectorAll('[data-utilisateur]').forEach(function (bouton) {
    bouton.addEventListener('click', function () {
      var u = JSON.parse(bouton.getAttribute('data-utilisateur'));
      var champ = function (n) { return form.querySelector('[name="' + n + '"]'); };
      ['id', 'email', 'nom', 'role', 'faculte_id', 'etudiant_id', 'enseignant_id'].forEach(function (k) { champ(k).value = u[k] === null ? '' : u[k]; });
      champ('motdepasse').value = '';
      modal.querySelector('[data-titre-email]').textContent = u.email;
      remplirDepartements(form, u.departement_id);
      appliquerRole(form, true);
      window.openModal('modal-utilisateur');
    });
  });
})();
