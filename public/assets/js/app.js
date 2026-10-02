'use strict';

const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

function debounce(fn, ms = 300) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

// ── Toast ─────────────────────────────────────────────────────
const Toast = {
  container: null,
  init() {
    this.container = document.createElement('div');
    this.container.id = 'toast-container';
    Object.assign(this.container.style, {
      position:'fixed', bottom:'80px', right:'24px',
      zIndex:'9999', display:'flex', flexDirection:'column', gap:'8px'
    });
    document.body.appendChild(this.container);
  },
  show(msg, type='info', duration=3000) {
    const colors = {
      success:{bg:'#E1F5EE',border:'#1D9E75',color:'#085041'},
      error:  {bg:'#FCEBEB',border:'#A32D2D',color:'#791F1F'},
      warning:{bg:'#FAEEDA',border:'#BA7517',color:'#BA7517'},
      info:   {bg:'#E6F1FB',border:'#378ADD',color:'#0C447C'},
    };
    const c = colors[type] || colors.info;
    const el = document.createElement('div');
    el.innerHTML = msg;
    Object.assign(el.style, {
      padding:'10px 16px', borderRadius:'8px', fontSize:'13px',
      border:`1px solid ${c.border}`, background:c.bg, color:c.color,
      boxShadow:'0 2px 8px rgba(0,0,0,.12)', minWidth:'220px',
      animation:'toastIn .2s ease-out', cursor:'pointer'
    });
    el.onclick = () => el.remove();
    this.container.appendChild(el);
    setTimeout(() => {
      el.style.opacity = '0'; el.style.transition = 'opacity .3s';
      setTimeout(() => el.remove(), 300);
    }, duration);
  }
};

// ── Auto-save des notes ───────────────────────────────────────
let saveTimer = null;
let pendingSaves = 0;

function setSaveStatus(status) {
  const bar = document.getElementById('save-status-bar');
  if (!bar) return;
  const icons = {saving:'⏳', saved:'✅', error:'❌', idle:''};
  const texts = {saving:'Enregistrement...', saved:'Notes enregistrées', error:'Erreur d\'enregistrement', idle:'Mode saisie'};
  const colors = {saving:'var(--amber-600)', saved:'var(--green-600)', error:'var(--red-600)', idle:'rgba(255,255,255,.7)'};
  bar.textContent = icons[status] + ' ' + texts[status];
  bar.style.color = colors[status];
}

async function autoSaveNote(pvId, etId, matId, noteExam, noteCC, defaillant) {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
  
  const examVal = (noteExam !== '' && noteExam !== null) ? parseFloat(noteExam) : null;
  const ccVal   = (noteCC   !== '' && noteCC   !== null) ? parseFloat(noteCC)   : null;
  const final   = !defaillant ? ((examVal ?? 0) + (ccVal ?? 0)) : null;

  const body = {
    csrf_token:  csrf,
    pv_id:       parseInt(pvId),
    etudiant_id: parseInt(etId),
    matiere_id:  parseInt(matId),
    note_examen: defaillant ? null : examVal,
    note_cc:     defaillant ? null : ccVal,
    note_finale: defaillant ? null : final,
    defaillant:  defaillant ? 1 : 0
  };

  const res  = await fetch(`${APP_URL}/api/notes.php`, {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    credentials: 'same-origin',
    body: JSON.stringify(body)
  });

  const text = await res.text();
  let data;
  try { data = JSON.parse(text); }
  catch(e) {
    const m = text.match(/(?:Fatal error|Parse error|Warning)[^<]*/);
    throw new Error(m ? m[0].trim() : 'Erreur PHP (voir logs)');
  }
  if (!data.success) throw new Error(data.message || 'Erreur serveur');
  return data;
}

// ── Init tableau PV ───────────────────────────────────────────
function initPVTable() {
  const table = $('.pv-table');
  if (!table) return;

  const pvId = table.dataset.pvId;

  // Blur sur input exam ou CC → auto-save
  table.addEventListener('focusout', async (e) => {
    const inp = e.target;
    if (!inp.matches('.note-input')) return;

    const tr    = inp.closest('tr');
    if (!tr) return;
    const etId  = tr.dataset.etudiantId;
    const matId = inp.dataset.matiereId || inp.dataset.matiereIdCc;
    if (!etId || !matId) return;

    const val    = inp.value.trim();
    const isCC   = inp.classList.contains('cc-input');
    const noteMax = parseFloat(inp.dataset.noteMax || 100);

    // Validation basique
    if (val !== '') {
      const num = parseFloat(val);
      if (isNaN(num) || num < 0 || num > noteMax) {
        inp.style.borderColor = 'var(--red-600)';
        Toast.show(`⚠️ Note invalide (max: ${noteMax})`, 'warning', 2500);
        inp.value = '';
        return;
      }
    }

    // Récupérer les deux valeurs exam + CC pour cette matière
    const examInp = tr.querySelector(`.note-input:not(.cc-input)[data-matiere-id="${matId}"]`);
    const ccInp   = tr.querySelector(`.cc-input[data-matiere-id-cc="${matId}"]`);
    const noteExam = examInp ? examInp.value.trim() : (isCC ? '' : val);
    const noteCC   = ccInp   ? ccInp.value.trim()   : '';

    // Indicateur visuel
    inp.style.borderColor = 'var(--blue-400)';
    setSaveStatus('saving');
    pendingSaves++;

    try {
      await autoSaveNote(pvId, etId, matId, noteExam, noteCC, false);
      inp.style.borderColor = 'var(--green-400)';
      setTimeout(() => { inp.style.borderColor = ''; }, 2000);
      pendingSaves--;
      if (pendingSaves <= 0) { pendingSaves = 0; setSaveStatus('saved'); }
    } catch(err) {
      inp.style.borderColor = 'var(--red-600)';
      setSaveStatus('error');
      Toast.show('❌ ' + err.message, 'error', 4000);
      pendingSaves--;
    }
  });

  // Touche Tab/Enter pour naviguer entre cellules
  table.addEventListener('keydown', (e) => {
    if (!e.target.matches('.note-input')) return;
    if (e.key === 'Enter' || e.key === 'Tab') {
      if (e.key === 'Enter') e.preventDefault();
      const inputs = [...table.querySelectorAll('.note-input:not(:disabled)')];
      const idx = inputs.indexOf(e.target);
      if (idx >= 0 && idx < inputs.length - 1) {
        setTimeout(() => inputs[idx + 1].focus(), 50);
      }
    }
  });

  // Pas de case défaillant — automatique si note vide ou < 5/20
}

// ── Recherche ─────────────────────────────────────────────────
function initSearch() {
  const inp = $('#search-input');
  if (!inp) return;
  inp.addEventListener('input', debounce(() => {
    const q = inp.value.toLowerCase().trim();
    $$('.table-row-searchable').forEach(row => {
      row.style.display = (!q || row.textContent.toLowerCase().includes(q)) ? '' : 'none';
    });
  }, 200));
}

// ── Modals ────────────────────────────────────────────────────
function openModal(id)  { const m=document.getElementById(id); if(m){m.style.display='flex';document.body.style.overflow='hidden';} }
function closeModal(id) { const m=document.getElementById(id); if(m){m.style.display='none';document.body.style.overflow='';} }
function confirmDelete(url, msg='Confirmer ?') { if(confirm(msg)) window.location.href = url; }

// ── Import CSV preview ────────────────────────────────────────
function initImportCSV() {
  const textarea   = $('#csv-input');
  const previewDiv = $('#csv-preview');
  if (!textarea || !previewDiv) return;
  textarea.addEventListener('input', debounce(() => {
    const lines = textarea.value.trim().split('\n').filter(l => l.trim() && !l.startsWith('#'));
    if (!lines.length) { previewDiv.innerHTML = ''; return; }
    let html = `<table class="table table-sm mt-16">
      <thead><tr><th>N° Ét.</th><th>Nom</th><th>Prénoms</th><th>Naiss.</th><th>Lieu</th><th>Sexe</th></tr></thead><tbody>`;
    lines.slice(0, 10).forEach(l => {
      const c = l.split(/[;,\t]/);
      html += '<tr>' + c.slice(0,6).map(x=>`<td>${x.trim()}</td>`).join('') + '</tr>';
    });
    if (lines.length > 10) html += `<tr><td colspan="6" class="text-muted text-center">+${lines.length-10} autres</td></tr>`;
    html += '</tbody></table>';
    previewDiv.innerHTML = html;
    const ctr = $('#csv-count');
    if (ctr) ctr.textContent = `${lines.length} étudiant(s) détecté(s)`;
  }, 200));
}

// ── Onglets ───────────────────────────────────────────────────
function initTabs() {
  $$('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const g = btn.dataset.group;
      $$(`[data-group="${g}"].tab-btn`).forEach(b => b.classList.remove('active'));
      $$(`[data-group="${g}"].tab-panel`).forEach(p => p.style.display = 'none');
      btn.classList.add('active');
      const panel = document.getElementById(btn.dataset.target);
      if (panel) panel.style.display = 'block';
    });
  });
  const groups = new Set($$('.tab-btn').map(b => b.dataset.group));
  groups.forEach(g => { const f = $(`.tab-btn[data-group="${g}"]`); if(f) f.click(); });
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  Toast.init();
  initPVTable();
  initSearch();
  initTabs();
  initImportCSV();
  $$('.modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if(e.target===el) closeModal(el.id); });
  });
  const flash = document.getElementById('flash-data');
  if (flash) Toast.show(flash.dataset.msg, flash.dataset.type);
});

window.openModal     = openModal;
window.closeModal    = closeModal;
window.confirmDelete = confirmDelete;
window.APP_URL       = window.APP_URL || '';

const s = document.createElement('style');
s.textContent = `@keyframes toastIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}`;
document.head.appendChild(s);