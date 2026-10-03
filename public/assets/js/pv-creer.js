// Création d'un PV (repris du script de l'ancien pv_creer.php) : blocs selon
// le type de session, liste des PV normaux filtrée, intitulé proposé.
(function () {
  var normales = JSON.parse(document.getElementById('donnees-pv-normales').textContent);
  var $ = function (id) { return document.getElementById(id); };
  var types = { rattrapage: '2ème Session', normale: '1ère Session', deliberation: 'Délibération',
    reclamation: 'Réclamation (fondées)', reclamation_depot: 'Réclamation (déposées)' };

  function majFormulaire() {
    var type = $('sel-type').value, promo = $('sel-promo').value, sem = $('sel-sem').value;
    $('bloc-rattrapage').style.display = type === 'rattrapage' ? 'block' : 'none';
    $('bloc-reclamation').style.display = (type === 'reclamation' || type === 'reclamation_depot') ? 'block' : 'none';
    $('msg-reclamation').textContent = type === 'reclamation_depot'
      ? 'Le PV inclura tous les étudiants ayant déposé une réclamation (en attente ou fondée). Leurs notes originales seront copiées.'
      : 'Le PV inclura uniquement les étudiants ayant une réclamation fondée. Leurs notes corrigées seront reprises.';

    var sel = $('sel-pv-normale'), ancien = sel.value || sel.dataset.ancien;
    sel.innerHTML = '<option value="">— Sélectionner le PV normale obligatoirement —</option>';
    normales.filter(function (p) { return (!promo || p.promotion_id == promo) && (!sem || p.semestre_id == sem); })
      .forEach(function (p) {
        var o = document.createElement('option');
        o.value = p.id; o.textContent = p.libelle; o.selected = String(p.id) === String(ancien);
        sel.appendChild(o);
      });

    var libelle = $('inp-libelle');
    if (!libelle.value) {
      var pr = $('sel-promo'), se = $('sel-sem');
      var tp = pr.selectedIndex > 0 ? pr.options[pr.selectedIndex].text : '';
      var ts = se.selectedIndex > 0 ? se.options[se.selectedIndex].text : '';
      if (tp && ts) libelle.value = 'PV ' + ts + ' – ' + tp + ' – ' + (types[type] || type);
    }
  }
  ['sel-type', 'sel-promo', 'sel-sem'].forEach(function (id) { $(id).addEventListener('change', majFormulaire); });
  majFormulaire();

  $('form-pv').addEventListener('submit', function (e) {
    var type = $('sel-type').value;
    if (type === 'rattrapage' && !$('sel-pv-normale').value) {
      alert('⚠️ Veuillez sélectionner le PV de 1ère session correspondant.');
      e.preventDefault(); $('sel-pv-normale').focus();
    } else if ((type === 'reclamation' || type === 'reclamation_depot') && !$('sel-pv-source').value) {
      alert('⚠️ Veuillez sélectionner le PV source.');
      e.preventDefault(); $('sel-pv-source').focus();
    }
  });
})();
