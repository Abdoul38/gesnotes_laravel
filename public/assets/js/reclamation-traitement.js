// Traitement d'une réclamation — repris du script en ligne de l'ancien
// reclamation_traitement.php : aperçu de la note finale, étapes « fondée »,
// confirmation des autres décisions.
(function () {
  var form = document.getElementById('form-traitement');
  if (!form) return;
  var MAX_EXAM = parseFloat(form.getAttribute('data-max-exam')) || 20;
  var MAX_CC = parseFloat(form.getAttribute('data-max-cc')) || 0;
  var TOTAL_MAX = MAX_EXAM + MAX_CC;
  var $ = function (id) { return document.getElementById(id); };

  function calculerApercu() {
    var exam = parseFloat($('inp-exam').value);
    var cc = $('inp-cc') ? parseFloat($('inp-cc').value) : NaN;
    var ov = $('inp-finale-override').value;
    var override = ov !== '' ? parseFloat(ov) : NaN;
    var apercu = $('apercu-finale'), valeur = $('apercu-valeur'), detail = $('apercu-detail');

    if (!isNaN(override)) {
      var v = Math.min(TOTAL_MAX, Math.max(0, override));
      valeur.textContent = v.toFixed(2) + ' / ' + TOTAL_MAX;
      detail.textContent = 'Note saisie directement';
      valeur.style.color = (v / TOTAL_MAX >= 0.5) ? 'var(--green-700)' : 'var(--red-600)';
      apercu.style.display = 'block';
      return;
    }
    if (isNaN(exam)) { apercu.style.display = 'none'; return; }
    var note, det;
    if (MAX_CC > 0 && !isNaN(cc)) {
      note = exam + cc;
      det = exam + ' (exam) + ' + cc + ' (CC) = ' + note.toFixed(2) + ' / ' + TOTAL_MAX;
    } else {
      note = exam;
      det = 'Note examen : ' + exam + ' / ' + MAX_EXAM;
    }
    note = Math.round(note * 100) / 100;
    valeur.textContent = note.toFixed(2) + ' / ' + TOTAL_MAX;
    detail.textContent = det;
    valeur.style.color = (note / TOTAL_MAX >= 0.5) ? 'var(--green-700)' : 'var(--red-600)';
    apercu.style.display = 'block';
  }

  function afficher(fondee) {
    $('bloc-notes-corrigees').style.display = fondee ? 'block' : 'none';
    $('bloc-confirmer-fondee').style.display = fondee ? 'block' : 'none';
    $('bloc-action-fondee').style.display = fondee ? 'none' : 'block';
    $('btn-non-fondee').style.display = fondee ? 'none' : 'block';
    $('btn-rejeter').style.display = fondee ? 'none' : 'block';
  }

  ['inp-exam', 'inp-cc', 'inp-finale-override'].forEach(function (id) {
    if ($(id)) $(id).addEventListener('input', calculerApercu);
  });
  $('btn-fondee').addEventListener('click', function () {
    afficher(true);
    calculerApercu();
    $('bloc-notes-corrigees').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
  $('btn-annuler-fondee').addEventListener('click', function () {
    afficher(false);
    $('inp-exam').value = '';
    $('inp-finale-override').value = '';
    if ($('inp-cc')) $('inp-cc').value = '';
    $('apercu-finale').style.display = 'none';
  });
  $('btn-confirmer-fondee').addEventListener('click', function () {
    if (!$('inp-exam').value && !$('inp-finale-override').value) {
      alert('⚠️ Veuillez saisir la note examen corrigée ou la note finale directement.');
      return;
    }
    $('inp-action').value = 'fondee';
    form.submit();
  });
  var messages = {
    non_fondee: '❌ Confirmer que la réclamation est non fondée (note inchangée) ?',
    rejetee: '🚫 Confirmer le rejet de cette réclamation ?'
  };
  form.querySelectorAll('[data-action]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var action = btn.getAttribute('data-action');
      if (!confirm(messages[action])) return;
      $('inp-action').value = action;
      form.submit();
    });
  });
})();
