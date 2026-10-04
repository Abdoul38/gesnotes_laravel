// ============================================================
// layout.js — comportements communs à toutes les pages
// Repris tel quel du script en ligne de l'ancien render_scripts()
// (includes/layout.php), déplacé dans un fichier pour que la CSP
// n'autorise que les scripts du site (script-src 'self').
// ============================================================

// URL de base, lue dans <meta name="app-url"> (app.js l'utilise).
window.APP_URL = (document.querySelector('meta[name="app-url"]') || {}).content || '';

(function() {
    var sidebar = document.getElementById('sidebar-nav');
    if (!sidebar) return;
    // Restaurer la position sauvegardée
    var saved = sessionStorage.getItem('sidebar_scroll');
    if (saved !== null) {
        sidebar.scrollTop = parseInt(saved, 10);
    } else {
        // Scroll automatique vers l'élément actif si hors de vue
        var active = sidebar.querySelector('.nav-item.active');
        if (active) {
            var sTop = sidebar.scrollTop;
            var sH   = sidebar.clientHeight;
            var aTop = active.offsetTop;
            var aH   = active.clientHeight;
            if (aTop < sTop || aTop + aH > sTop + sH) {
                sidebar.scrollTop = aTop - sH / 2 + aH / 2;
            }
        }
    }
    // Sauvegarder la position avant chaque navigation
    document.querySelectorAll('#sidebar-nav .nav-item').forEach(function(link) {
        link.addEventListener('click', function() {
            sessionStorage.setItem('sidebar_scroll', sidebar.scrollTop);
        });
    });
})();

// ── Tiroir mobile (hamburger) ──────────────────────────────
(function() {
    var toggle   = document.getElementById('menu-toggle');
    var sidebar  = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebar-backdrop');
    if (!toggle || !sidebar) return;

    function openMenu() {
        sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('show');
        document.body.classList.add('sidebar-open');
        toggle.setAttribute('aria-expanded', 'true');
    }
    function closeMenu() {
        sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
        document.body.classList.remove('sidebar-open');
        toggle.setAttribute('aria-expanded', 'false');
    }
    toggle.addEventListener('click', function() {
        sidebar.classList.contains('open') ? closeMenu() : openMenu();
    });
    if (backdrop) backdrop.addEventListener('click', closeMenu);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeMenu();
    });
    // Fermer le tiroir après un choix de menu (mobile)
    document.querySelectorAll('#sidebar-nav .nav-item').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.matchMedia('(max-width: 768px)').matches) closeMenu();
        });
    });
    // Nettoyer l'état si on repasse en grand écran
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) closeMenu();
    });
})();

// ── Afficher/masquer un mot de passe (tout champ type="password") ──
(function() {
    function ajouterBoutons() {
        document.querySelectorAll('input[type="password"]').forEach(function(input) {
            if (input.dataset.pwdToggleAdded) return;
            input.dataset.pwdToggleAdded = '1';
            var wrapper = document.createElement('span');
            wrapper.style.cssText = 'position:relative;display:inline-block;width:100%';
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = '👁';
            btn.setAttribute('aria-label', 'Afficher le mot de passe');
            btn.style.cssText = 'position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:15px;padding:2px 6px;opacity:.55;line-height:1';
            btn.addEventListener('click', function() {
                if (input.type === 'password') { input.type = 'text'; btn.textContent = '🙈'; }
                else { input.type = 'password'; btn.textContent = '👁'; }
            });
            wrapper.appendChild(btn);
            var padActuel = window.getComputedStyle(input).paddingRight;
            input.style.paddingRight = 'calc(' + padActuel + ' + 28px)';
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ajouterBoutons);
    } else {
        ajouterBoutons();
    }
})();

// ── Délégation d'événements sans onclick/onchange/onsubmit en ligne ──
// Remplace les attributs HTML en ligne (bloqués par une CSP stricte sans
// unsafe-inline) par des attributs data-* lus ici. N'utilise JAMAIS
// eval()/new Function() — uniquement des appels directs à des fonctions
// déjà déclarées sur window, ce qui reste autorisé même sans unsafe-eval.
(function() {
    window.cancelRecalcQueue = function() {
        if (window.recalcQueue) window.recalcQueue.cancel();
    };
    window.validerSemestres = function() {
        var ok = document.querySelectorAll("input[name='semestres[]']:checked").length > 0;
        if (!ok) alert('Sélectionnez au moins un semestre');
        return ok;
    };
    function resoudreArg(el, argRaw) {
        if (argRaw === '__this__') return el;
        if (argRaw === '__thisvalue__') return el.value;
        return /^-?\d+(\.\d+)?$/.test(argRaw) ? Number(argRaw) : argRaw;
    }

    function appelerFonction(nomAttr, argAttr, argsAttr, el) {
        var nom = el.getAttribute(nomAttr);
        if (!nom || typeof window[nom] !== 'function') return;
        // Plusieurs arguments, encodés en JSON (ex: cascadeDep(this.value,'x',0))
        var argsRaw = el.getAttribute(argsAttr);
        if (argsRaw !== null) {
            try {
                var liste = JSON.parse(argsRaw);
                var resolus = liste.map(function(v) {
                    return (typeof v === 'string') ? resoudreArg(el, v) : v;
                });
                window[nom].apply(null, resolus);
            } catch (err) { /* JSON invalide : on ignore silencieusement */ }
            return;
        }
        var argRaw = el.getAttribute(argAttr);
        if (argRaw === null) { window[nom](); return; }
        window[nom](resoudreArg(el, argRaw));
    }

    document.addEventListener('click', function(e) {
        var elFn = e.target.closest('[data-onclick]');
        if (elFn) appelerFonction('data-onclick', 'data-arg', 'data-args', elFn);
        var elPrint = e.target.closest('[data-print]');
        if (elPrint) window.print();
        // Bascule l'affichage (none/block) d'un élément ciblé par son id
        var elToggle = e.target.closest('[data-toggle-display]');
        if (elToggle) {
            var cible = document.getElementById(elToggle.getAttribute('data-toggle-display'));
            if (cible) cible.style.display = (cible.style.display === 'none' ? 'block' : 'none');
        }
        // Masque un élément ciblé par son id
        var elHide = e.target.closest('[data-hide]');
        if (elHide) {
            var cibleH = document.getElementById(elHide.getAttribute('data-hide'));
            if (cibleH) cibleH.style.display = 'none';
        }
        var elClose = e.target.closest('[data-window-close]');
        if (elClose) window.close();
        // Navigation directe vers une URL fixe (avec éventuel id inséré côté PHP)
        var elNavStatic = e.target.closest('[data-navigate-static]');
        if (elNavStatic) window.location = elNavStatic.getAttribute('data-navigate-static');
        // Navigation conditionnelle : lit la valeur d'UN AUTRE élément
        // (un select ailleurs sur la page) et n'y va que si elle n'est pas vide
        var elNavFrom = e.target.closest('[data-navigate-from]');
        if (elNavFrom) {
            var source = document.getElementById(elNavFrom.getAttribute('data-navigate-from'));
            if (source && source.value) {
                window.location = elNavFrom.getAttribute('data-navigate-url') + encodeURIComponent(source.value) + (elNavFrom.getAttribute('data-navigate-suffix') || '');
            }
        }
        // Confirmation sur un simple clic (bouton hors formulaire, ou dont
        // l'action par défaut — navigation, soumission — doit être annulée).
        var elConfirm = e.target.closest('[data-confirm-click]');
        if (elConfirm && !window.confirm(elConfirm.getAttribute('data-confirm-click'))) {
            e.preventDefault();
        }
        // Fonction de validation au clic — bloque l'action par défaut si
        // elle renvoie false (ex: onclick="return validerPassage()")
        var elValClick = e.target.closest('[data-validate-click]');
        if (elValClick) {
            var nomFnC = elValClick.getAttribute('data-validate-click');
            if (typeof window[nomFnC] === 'function' && window[nomFnC]() === false) {
                e.preventDefault();
            }
        }
    });

    document.addEventListener('change', function(e) {
        var elFn = e.target.closest('[data-onchange]');
        if (elFn) appelerFonction('data-onchange', 'data-arg', 'data-args', elFn);
        var elAuto = e.target.closest('[data-autosubmit]');
        if (elAuto && elAuto.form) elAuto.form.submit();
        // Navigation avec la valeur choisie ajoutée à l'URL
        // (ex: data-navigate="?page=planning&pv_id=" -> ...&pv_id=VALEUR)
        var elNav = e.target.closest('[data-navigate]');
        if (elNav) window.location = elNav.getAttribute('data-navigate') + encodeURIComponent(elNav.value);
        // Affiche/masque un élément cible selon que la valeur choisie
        // correspond exactement à une valeur donnée
        var elShowIf = e.target.closest('[data-show-if-equals]');
        if (elShowIf) {
            var cibleS = document.getElementById(elShowIf.getAttribute('data-show-if-equals'));
            if (cibleS) cibleS.style.display = (elShowIf.value === elShowIf.getAttribute('data-equals-value')) ? 'block' : 'none';
        }
    });

    document.addEventListener('submit', function(e) {
        var el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
        // Fonction de validation personnalisée — doit renvoyer false pour
        // bloquer l'envoi (ex: onsubmit="return validerFormPV()")
        var elVal = e.target.closest('[data-validate]');
        if (elVal) {
            var nomFn = elVal.getAttribute('data-validate');
            if (typeof window[nomFn] === 'function' && window[nomFn]() === false) {
                e.preventDefault();
            }
        }
    });

    // L'événement "error" (ex: image introuvable) ne remonte pas par
    // bulles — on doit l'écouter en phase de capture pour l'intercepter.
    document.addEventListener('error', function(e) {
        var el = e.target;
        if (!el.getAttribute) return;
        if (el.hasAttribute('data-onerror-hide')) el.style.display = 'none';
        if (el.hasAttribute('data-onerror-invisible')) el.style.visibility = 'hidden';
        var icone = el.getAttribute('data-onerror-fallback-icon');
        if (icone && el.parentNode) el.parentNode.innerHTML = icone;
        var srcSecours = el.getAttribute('data-onerror-src');
        if (srcSecours) el.src = srcSecours;
    }, true);

    document.addEventListener('input', function(e) {
        var elFn = e.target.closest('[data-oninput]');
        if (elFn) appelerFonction('data-oninput', 'data-arg', 'data-args', elFn);
        // Affiche/masque un élément selon que le champ est vide (une fois
        // les espaces superflus retirés)
        var elEmpty = e.target.closest('[data-show-if-empty]');
        if (elEmpty) {
            var cibleE = document.getElementById(elEmpty.getAttribute('data-show-if-empty'));
            if (cibleE) cibleE.style.display = (elEmpty.value.trim() === '') ? 'block' : 'none';
        }
        // Soumission différée du formulaire après une pause de frappe
        // (évite d'envoyer une requête à chaque caractère tapé)
        var elDeb = e.target.closest('[data-autosubmit-debounced]');
        if (elDeb) {
            clearTimeout(elDeb._debounceTimer);
            var delai = parseInt(elDeb.getAttribute('data-autosubmit-debounced'), 10) || 400;
            elDeb._debounceTimer = setTimeout(function() {
                if (elDeb.form) elDeb.form.submit();
            }, delai);
        }
    });

    document.addEventListener('keydown', function(e) {
        var elFn = e.target.closest('[data-onkeydown]');
        if (elFn) appelerFonction('data-onkeydown', 'data-arg', 'data-args', elFn);
        // Soumet un formulaire ciblé quand Entrée est pressée dans ce champ
        var elEnter = e.target.closest('[data-submit-on-enter]');
        if (elEnter && e.key === 'Enter') {
            var frm = document.getElementById(elEnter.getAttribute('data-submit-on-enter'));
            if (frm) frm.submit();
        }
    });

    document.addEventListener('blur', function(e) {
        var elFn = e.target.closest && e.target.closest('[data-onblur]');
        if (elFn) appelerFonction('data-onblur', 'data-arg', 'data-args', elFn);
    }, true);
})();

// ── Notes : 2 décimales au plus ──────────────────────────────
// Le serveur arrondit à 2 décimales ; la valeur affichée est arrondie dès
// qu'on quitte le champ, pour qu'on voie ce qui sera enregistré
// (avant, 5,4135 restait affiché et 5,41 était enregistré).
document.addEventListener('change', function (e) {
    var champ = e.target.closest && e.target.closest('input[data-decimales]');
    if (!champ || champ.value === '') return;
    var n = parseFloat(String(champ.value).replace(',', '.'));
    var d = parseInt(champ.getAttribute('data-decimales'), 10) || 2;
    if (!isNaN(n) && String(champ.value).split(/[.,]/)[1] && String(champ.value).split(/[.,]/)[1].length > d) {
        champ.value = n.toFixed(d);
        champ.dispatchEvent(new Event('input', { bubbles: true }));
    }
}, true);
