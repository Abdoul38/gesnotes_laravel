// ============================================================
// screens/reclamation_screen.dart — Dépôt de réclamation
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';

class ReclamationScreen extends StatefulWidget {
  const ReclamationScreen({super.key});
  @override
  State<ReclamationScreen> createState() => _ReclamationScreenState();
}

class _ReclamationScreenState extends State<ReclamationScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabs;

  // Données
  List<dynamic> _pvList    = [];
  List<dynamic> _matieres  = [];
  List<dynamic> _mesReclamations = [];
  bool _loading     = true;
  bool _loadingMat  = false;
  bool _submitting  = false;

  // Formulaire
  final _motifCtrl   = TextEditingController();
  int? _pvSelecId;
  int? _matSelecId;

  // Helpers pour retrouver l'objet Map depuis l'id
  dynamic get _pvSelec  => _pvList.isEmpty ? null : _pvList.cast<dynamic>().firstWhere(
      (p) => p['id'] == _pvSelecId, orElse: () => null);
  dynamic get _matSelec => _matieres.isEmpty ? null : _matieres.cast<dynamic>().firstWhere(
      (m) => m['id'] == _matSelecId, orElse: () => null);

  bool get _reclamationOuverte {
    if (_pvSelec == null) return false;
    return (_pvSelec['reclamation_ouverte'] == 1 || _pvSelec['reclamation_ouverte'] == '1');
  }

  String _delaiLabel(dynamic pv) {
    final h = double.tryParse(pv['heures_restantes']?.toString() ?? '0') ?? 0;
    if (h <= 0) return 'Délai expiré';
    if (h < 1)  return '${(h * 60).round()} min restantes';
    return '${h.toStringAsFixed(1)} h restantes';
  }

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 2, vsync: this);
    _load();
  }

  @override
  void dispose() {
    _tabs.dispose();
    _motifCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading   = true;
      _pvSelecId  = null;
      _matSelecId = null;
      _matieres   = [];
    });
    final res = await ApiService.getReclamationsData();
    if (mounted && res['success'] == true) {
      setState(() {
        _pvList          = res['data']['pv_list']          ?? [];
        _mesReclamations = res['data']['mes_reclamations'] ?? [];
        _loading         = false;
      });
    } else {
      setState(() => _loading = false);
    }
  }

  Future<void> _loadMatieres(int pvId) async {
    setState(() { _loadingMat = true; _matieres = []; _matSelecId = null; });
    final res = await ApiService.getMatieresPv(pvId);
    if (mounted && res['success'] == true) {
      setState(() { _matieres = res['data'] ?? []; _loadingMat = false; });
    } else {
      setState(() => _loadingMat = false);
      _snack(res['message'] ?? 'Erreur lors du chargement des matières', error: true);
    }
  }

  Future<void> _soumettre() async {
    if (_pvSelec == null)    { _snack('Sélectionnez un PV', error: true); return; }
    if (!_reclamationOuverte){ _snack('Le délai de réclamation est expiré pour ce PV', error: true); return; }
    if (_matSelec == null)   { _snack('Sélectionnez une matière', error: true); return; }
    if (_motifCtrl.text.trim().length < 20) {
      _snack('Le motif doit contenir au moins 20 caractères', error: true); return;
    }

    setState(() => _submitting = true);
    final res = await ApiService.soumettreReclamation(
      pvId:     _pvSelec['id'],
      matiereId:_matSelec['id'],
      motif:    _motifCtrl.text.trim(),
    );
    setState(() => _submitting = false);

    if (res['success'] == true) {
      _snack('✅ Réclamation soumise avec succès');
      _motifCtrl.clear();
      setState(() { _pvSelecId = null; _matSelecId = null; _matieres = []; });
      await _load();
      _tabs.animateTo(1);
    } else {
      _snack(res['message'] ?? 'Erreur lors de la soumission', error: true);
    }
  }

  void _snack(String msg, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      backgroundColor: error ? AppConstants.errorColor : AppConstants.accentColor,
      behavior: SnackBarBehavior.floating,
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Réclamations'),
        actions: [IconButton(icon: const Icon(Icons.refresh), onPressed: _load)],
        bottom: TabBar(
          controller: _tabs,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white54,
          indicatorColor: Colors.white,
          tabs: [
            const Tab(icon: Icon(Icons.edit_note), text: 'Déposer'),
            Tab(
              icon: Badge(
                isLabelVisible: _mesReclamations.where((r) => r['statut'] == 'en_attente').isNotEmpty,
                backgroundColor: AppConstants.warningColor,
                child: const Icon(Icons.history),
              ),
              text: 'Mes réclamations',
            ),
          ],
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : TabBarView(
              controller: _tabs,
              children: [_buildFormulaire(), _buildHistorique()],
            ),
    );
  }

  // ── Formulaire dépôt ─────────────────────────────────────
  Widget _buildFormulaire() {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

        // Bannière info
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: const Color(0xFFEFF6FF),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: const Color(0xFFBFDBFE)),
          ),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text('ℹ️', style: TextStyle(fontSize: 20)),
            const SizedBox(width: 10),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('Comment ça marche ?',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13,
                    color: AppConstants.primaryColor)),
              const SizedBox(height: 4),
              Text(
                'Si vous avez constaté une erreur sur l\'une de vos notes, '
                'sélectionnez le PV concerné, la matière et expliquez votre motif. '
                'La scolarité examinera votre réclamation.',
                style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
              ),
            ])),
          ]),
        ),

        const SizedBox(height: 20),

        // Sélection PV
        const Text('PV concerné *',
          style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13,
              color: AppConstants.primaryColor)),
        const SizedBox(height: 8),
        if (_pvList.isEmpty)
          _emptyCard('Aucun PV publié disponible')
        else
          DropdownButtonFormField<int>(
            value: _pvSelecId,
            isExpanded: true,
            decoration: InputDecoration(
              hintText: '— Sélectionner un PV —',
              prefixIcon: const Icon(Icons.assignment_outlined, color: AppConstants.primaryColor),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
            ),
            items: _pvList.map((pv) {
              final ouvert = pv['reclamation_ouverte'] == 1 || pv['reclamation_ouverte'] == '1';
              final h = double.tryParse(pv['heures_restantes']?.toString() ?? '0') ?? 0;
              return DropdownMenuItem<int>(
                value: (pv['id'] as num).toInt(),
                child: Row(children: [
                  Expanded(child: Text(pv['libelle'] ?? '—',
                    overflow: TextOverflow.ellipsis, maxLines: 1,
                    style: TextStyle(fontSize: 13,
                      color: ouvert ? null : Colors.grey.shade400))),
                  const SizedBox(width: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: ouvert ? Colors.green.shade50 : Colors.red.shade50,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: ouvert ? Colors.green.shade300 : Colors.red.shade300),
                    ),
                    child: Text(
                      ouvert ? '⏱ ${h.toStringAsFixed(1)}h' : '⛔ Expiré',
                      style: TextStyle(fontSize: 9, fontWeight: FontWeight.w700,
                        color: ouvert ? Colors.green.shade700 : Colors.red.shade700)),
                  ),
                ]),
              );
            }).toList(),
            onChanged: (val) {
              setState(() { _pvSelecId = val; _matSelecId = null; _matieres = []; });
              if (val != null) _loadMatieres(val);
            },
          ),

        // Bannière délai si PV sélectionné
        if (_pvSelec != null) ...[
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: _reclamationOuverte ? Colors.green.shade50 : Colors.red.shade50,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: _reclamationOuverte
                  ? Colors.green.shade200 : Colors.red.shade200),
            ),
            child: Row(children: [
              Text(_reclamationOuverte ? '✅' : '⛔', style: const TextStyle(fontSize: 16)),
              const SizedBox(width: 8),
              Expanded(child: Text(
                _reclamationOuverte
                    ? 'Réclamations ouvertes — ${_delaiLabel(_pvSelec)}'
                    : 'Le délai de 72h est expiré. Aucune réclamation acceptée pour ce PV.',
                style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600,
                  color: _reclamationOuverte ? Colors.green.shade800 : Colors.red.shade800),
              )),
            ]),
          ),
        ],

        const SizedBox(height: 16),

        // Sélection matière
        const Text('Matière réclamée *',
          style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13,
              color: AppConstants.primaryColor)),
        const SizedBox(height: 8),
        if (_pvSelec == null)
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.grey.shade100,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: Colors.grey.shade300),
            ),
            child: Text('Sélectionnez d\'abord un PV',
              style: TextStyle(color: Colors.grey.shade500, fontSize: 13)),
          )
        else if (_loadingMat)
          const Center(child: Padding(
            padding: EdgeInsets.all(12),
            child: CircularProgressIndicator(strokeWidth: 2),
          ))
        else if (_matieres.isEmpty)
          _emptyCard('Aucune matière disponible pour ce PV')
        else
          DropdownButtonFormField<int>(
            value: _matSelecId,
            isExpanded: true,
            decoration: InputDecoration(
              hintText: '— Sélectionner une matière —',
              prefixIcon: const Icon(Icons.book_outlined, color: AppConstants.primaryColor),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
            ),
            items: _matieres.map((m) => DropdownMenuItem<int>(
              value: (m['id'] as num).toInt(),
              child: Row(children: [
                Expanded(child: Text(m['libelle'] ?? '—',
                  overflow: TextOverflow.ellipsis,
                  maxLines: 1,
                  style: const TextStyle(fontSize: 13))),
                if (() {
                    final n = m['note_finale'] ?? m['note_examen'];
                    return n != null;
                  }()) ...[
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: AppConstants.primaryColor.withOpacity(.1),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(() {
                        final maxExam  = num.tryParse(m['note_max_examen']?.toString() ?? '') ?? 20.0;
                        final maxCc    = num.tryParse(m['note_max_cc']?.toString()    ?? '') ?? 0.0;
                        final total    = maxExam + maxCc;
                        final totalStr = total % 1 == 0 ? total.toInt().toString() : total.toString();
                        final note     = m['note_finale'] ?? m['note_examen'];
                        final noteVal  = double.tryParse(note.toString());
                        return '${noteVal?.toStringAsFixed(2) ?? "—"}/$totalStr';
                      }(),
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700,
                          color: AppConstants.primaryColor)),
                  ),
                ],
              ]),
            )).toList(),
            onChanged: (val) => setState(() => _matSelecId = val),
          ),

        // Afficher la note actuelle si matière sélectionnée
        if (_matSelec != null && (_matSelec['note_finale'] != null || _matSelec['note_examen'] != null)) ...[
          const SizedBox(height: 10),
          Builder(builder: (context) {
            final maxExam  = num.tryParse(_matSelec['note_max_examen']?.toString() ?? '') ?? 20.0;
            final maxCc    = num.tryParse(_matSelec['note_max_cc']?.toString()    ?? '') ?? 0.0;
            final total    = maxExam + maxCc;
            final examStr  = maxExam % 1 == 0 ? maxExam.toInt().toString() : maxExam.toString();
            final ccStr    = maxCc   % 1 == 0 ? maxCc.toInt().toString()   : maxCc.toString();
            final totalStr = total   % 1 == 0 ? total.toInt().toString()   : total.toString();
            return Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.amber.shade50,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.amber.shade200),
              ),
              child: Row(children: [
                const Text('⚠️', style: TextStyle(fontSize: 16)),
                const SizedBox(width: 8),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Note actuelle enregistrée',
                    style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
                  Text(
                    'Examen : ${_matSelec["note_examen"] ?? "—"}/$examStr  |  '
                    'CC : ${_matSelec["note_cc"] ?? "—"}/$ccStr  |  '
                    'Finale : ${_matSelec["note_finale"] ?? _matSelec["note_examen"] ?? "—"}/$totalStr',
                    style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                  ),
                ])),
              ]),
            );
          }),
        ],

        const SizedBox(height: 16),

        // Motif
        const Text('Motif de la réclamation *',
          style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13,
              color: AppConstants.primaryColor)),
        const SizedBox(height: 4),
        Text('Expliquez précisément la nature de l\'erreur constatée (min. 20 caractères)',
          style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
        const SizedBox(height: 8),
        TextFormField(
          controller: _motifCtrl,
          maxLines: 5,
          maxLength: 500,
          decoration: InputDecoration(
            hintText: 'Ex: Ma note d\'examen ne correspond pas à celle de ma copie. '
                'J\'ai obtenu 14/20 sur ma copie mais 10/20 est enregistré...',
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
            alignLabelWithHint: true,
          ),
        ),

        const SizedBox(height: 20),

        // Bouton soumettre
        SizedBox(
          width: double.infinity,
          child: ElevatedButton.icon(
            onPressed: (_submitting || !_reclamationOuverte) ? null : _soumettre,
            icon: _submitting
                ? const SizedBox(width: 18, height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.send_outlined),
            label: Text(_submitting ? 'Envoi en cours...' : 'Soumettre la réclamation'),
            style: ElevatedButton.styleFrom(
              padding: const EdgeInsets.symmetric(vertical: 14),
            ),
          ),
        ),

        const SizedBox(height: 8),
        Center(
          child: Text('Votre réclamation sera examinée par le service de scolarité',
            style: TextStyle(fontSize: 11, color: Colors.grey.shade500),
            textAlign: TextAlign.center),
        ),
      ]),
    );
  }

  // ── Historique des réclamations ──────────────────────────
  Widget _buildHistorique() {
    if (_mesReclamations.isEmpty) {
      return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
        const Text('📨', style: TextStyle(fontSize: 48)),
        const SizedBox(height: 12),
        const Text('Aucune réclamation déposée',
          style: TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
        const SizedBox(height: 6),
        Text('Utilisez l\'onglet "Déposer" pour soumettre une réclamation',
          style: TextStyle(fontSize: 12, color: Colors.grey.shade500),
          textAlign: TextAlign.center),
      ]));
    }

    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _mesReclamations.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (_, i) => _carteReclamation(_mesReclamations[i]),
    );
  }

  Widget _carteReclamation(Map r) {
    final statut = r['statut'] as String? ?? 'en_attente';
    final cfg = _statutConfig(statut);

    return Card(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        // Header
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
            color: cfg['bg'] as Color,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(12)),
          ),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Expanded(child: Text(r['matiere'] ?? '—',
              style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13,
                  color: cfg['fg'] as Color))),
            _statutBadge(statut),
          ]),
        ),

        Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            // PV
            Row(children: [
              Icon(Icons.assignment_outlined, size: 14, color: Colors.grey.shade500),
              const SizedBox(width: 6),
              Expanded(child: Text(r['pv_libelle'] ?? '—',
                style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                overflow: TextOverflow.ellipsis)),
            ]),
            const SizedBox(height: 8),

            // Note
            Row(children: [
              _noteChip('Note enregistrée',
                r['note_originale']?.toString() ?? '—',
                Colors.grey.shade100, Colors.grey.shade700),
              if (r['note_corrigee'] != null) ...[
                const SizedBox(width: 8),
                const Icon(Icons.arrow_forward, size: 14, color: Colors.green),
                const SizedBox(width: 8),
                _noteChip('Note corrigée',
                  r['note_corrigee'].toString(),
                  Colors.green.shade50, Colors.green.shade700),
              ],
            ]),

            const SizedBox(height: 10),

            // Motif
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Colors.grey.shade50,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('Motif :',
                  style: TextStyle(fontSize: 11, color: Colors.grey.shade500,
                      fontWeight: FontWeight.w600)),
                const SizedBox(height: 2),
                Text(r['motif'] ?? '—',
                  style: const TextStyle(fontSize: 12)),
              ]),
            ),

            // Commentaire scolarité
            if (r['commentaire'] != null && (r['commentaire'] as String).isNotEmpty) ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: Colors.blue.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.blue.shade100),
                ),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Réponse de la scolarité :',
                    style: TextStyle(fontSize: 11, color: AppConstants.primaryColor,
                        fontWeight: FontWeight.w700)),
                  const SizedBox(height: 2),
                  Text(r['commentaire'],
                    style: const TextStyle(fontSize: 12)),
                ]),
              ),
            ],

            const SizedBox(height: 8),
            Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              Text(_formatDate(r['created_at']),
                style: TextStyle(fontSize: 11, color: Colors.grey.shade400)),
              if (r['traitee_le'] != null)
                Text('Traité le ${_formatDate(r['traitee_le'])}',
                  style: TextStyle(fontSize: 11, color: Colors.grey.shade400)),
            ]),
          ]),
        ),
      ]),
    );
  }

  Map<String, dynamic> _statutConfig(String statut) => switch (statut) {
    'fondee'     => {'bg': Colors.green.shade50,  'fg': Colors.green.shade800},
    'non_fondee' => {'bg': Colors.red.shade50,    'fg': Colors.red.shade800},
    'rejetee'    => {'bg': Colors.grey.shade100,  'fg': Colors.grey.shade700},
    _            => {'bg': Colors.amber.shade50,  'fg': Colors.amber.shade800},
  };

  Widget _statutBadge(String statut) {
    final (label, color) = switch (statut) {
      'fondee'     => ('✅ Fondée',     AppConstants.accentColor),
      'non_fondee' => ('❌ Non fondée', AppConstants.errorColor),
      'rejetee'    => ('🚫 Rejetée',   Colors.grey),
      _            => ('🕐 En attente', AppConstants.warningColor),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(.15),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withOpacity(.4)),
      ),
      child: Text(label,
        style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: color)),
    );
  }

  Widget _noteChip(String label, String val, Color bg, Color fg) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(label, style: TextStyle(fontSize: 10, color: Colors.grey.shade500)),
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6)),
        child: Text(val,
          style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: fg)),
      ),
    ],
  );

  Widget _emptyCard(String msg) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: Colors.grey.shade100,
      borderRadius: BorderRadius.circular(10),
    ),
    child: Text(msg, style: TextStyle(color: Colors.grey.shade500, fontSize: 13)),
  );

  String _formatDate(String? d) {
    if (d == null) return '—';
    try {
      final dt = DateTime.parse(d);
      return '${dt.day.toString().padLeft(2,'0')}/${dt.month.toString().padLeft(2,'0')}/${dt.year}';
    } catch(_) { return d; }
  }
}