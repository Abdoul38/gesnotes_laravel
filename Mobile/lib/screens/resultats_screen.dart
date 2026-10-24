// ============================================================
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';

class ResultatsScreen extends StatefulWidget {
  /// Espace parent : résultats de cet enfant (sinon ceux de l'étudiant connecté).
  final int? etudiantId;
  final String? titre;
  const ResultatsScreen({super.key, this.etudiantId, this.titre});
  @override
  State<ResultatsScreen> createState() => _ResultatsScreenState();
}

class _ResultatsScreenState extends State<ResultatsScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabs;
  List<dynamic> _notes     = [];
  List<dynamic> _resultats = [];
  bool _loading = true;
  bool _offline = false;

  @override
  void initState() {
    super.initState();
    _tabs = TabController(length: 2, vsync: this);
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final id = widget.etudiantId;
    final cle = id == null ? 'resultats' : 'resultats_enfant_$id';
    final res = id == null ? await ApiService.getResultats() : await ApiService.getEnfant('resultats', id);
    if (!mounted) return;
    if (res['success'] == true) {
      setState(() {
        _notes     = res['data']['notes']     ?? [];
        _resultats = res['data']['resultats'] ?? [];
        _loading   = false;
        _offline   = false;
      });
      await ApiService.cacheData(cle, res['data']);
    } else {
      final cached = await ApiService.getCached(cle);
      setState(() {
        _notes     = cached?['notes']     ?? [];
        _resultats = cached?['resultats'] ?? [];
        _loading   = false;
        _offline   = true;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.titre ?? 'Mes Résultats'),
        actions: [
          if (_offline) const Padding(
            padding: EdgeInsets.only(right: 12),
            child: Icon(Icons.wifi_off, color: Colors.orange),
          ),
          IconButton(icon: const Icon(Icons.refresh), onPressed: _load),
        ],
        bottom: TabBar(
          controller: _tabs,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white54,
          indicatorColor: Colors.white,
          tabs: const [
            Tab(icon: Icon(Icons.bar_chart), text: 'Résumé'),
            Tab(icon: Icon(Icons.list_alt),  text: 'Notes détaillées'),
          ],
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Column(children: [
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(8),
              ),
              Expanded(
                child: RefreshIndicator(
                  onRefresh: _load,
                  child: TabBarView(
                    controller: _tabs,
                    children: [_buildResume(), _buildNotes()],
                  ),
                ),
              ),
            ]),
    );
  }

  Widget _buildResume() {
    if (_resultats.isEmpty && _notes.isEmpty) {
      return Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
        const Text('📭', style: TextStyle(fontSize: 48)),
        const SizedBox(height: 12),
        const Text('Aucun résultat disponible'),
        const SizedBox(height: 8),
        Text('Notes: ${_notes.length} | Résultats: ${_resultats.length}',
          style: const TextStyle(fontSize: 12, color: Colors.grey)),
      ]));
    }

    final Map<String, List<dynamic>> parSemestre = {};
    for (final n in _notes) {
      final key = '${n['annee']} — ${n['semestre']}';
      parSemestre.putIfAbsent(key, () => []).add(n);
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_resultats.isNotEmpty) ...[
          _sectionTitle('📊 Résultats de session'),
          const SizedBox(height: 10),
          ..._resultats.map((r) => _carteResultat(r)),
          const SizedBox(height: 20),
        ],
        _sectionTitle('📋 Résumé par semestre'),
        const SizedBox(height: 10),
        ...parSemestre.entries.map((e) => _resumeSemestre(e.key, e.value)),
      ],
    );
  }

  Widget _carteResultat(Map r) {
    final valide = r['valide'] == 1 || r['valide'] == '1';
    final moy    = double.tryParse(r['moyenne_generale']?.toString() ?? '') ?? 0;
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${r['annee']} — ${r['niveau']}',
                style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15,
                    color: AppConstants.primaryColor)),
              Text(r['semestre'] ?? '—',
                style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
              Text(r['type_session'] == 'rattrapage'
                  ? '🔄 Session rattrapage' : '📝 Session normale',
                style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
            ])),
            _statusBadge(valide),
          ]),
          const Divider(height: 20),
          Row(mainAxisAlignment: MainAxisAlignment.spaceAround, children: [
            _stat('Moyenne', '${moy.toStringAsFixed(2)}/20'),
            if (r['mention'] != null) _stat('Mention', r['mention']),
            if (r['rang'] != null) _stat('Rang', '#${r['rang']}'),
          ]),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(6),
            child: LinearProgressIndicator(
              value: (moy / 20).clamp(0.0, 1.0),
              minHeight: 10,
              backgroundColor: Colors.grey.shade200,
              valueColor: AlwaysStoppedAnimation(
                moy >= 10 ? AppConstants.accentColor : AppConstants.errorColor),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _resumeSemestre(String titre, List<dynamic> notes) {
    final avecNotes = notes.where((n) => n['note_finale'] != null).toList();
    final sansNotes = notes.where((n) => n['note_finale'] == null).toList();
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: const BoxDecoration(
            color: AppConstants.primaryColor,
            borderRadius: BorderRadius.vertical(top: Radius.circular(12)),
          ),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Expanded(child: Text(titre,
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 13))),
            Text('${avecNotes.length}/${notes.length}',
              style: const TextStyle(color: Colors.white70, fontSize: 11)),
          ]),
        ),
        if (avecNotes.isEmpty)
          const Padding(
            padding: EdgeInsets.all(14),
            child: Text('Aucune note saisie pour ce semestre',
              style: TextStyle(color: Colors.grey, fontSize: 13, fontStyle: FontStyle.italic)),
          )
        else
          ...avecNotes.map((n) => _ligneResume(n)),
        if (sansNotes.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 4, 14, 10),
            child: Text('${sansNotes.length} matière(s) en attente de notes',
              style: TextStyle(color: Colors.grey.shade400, fontSize: 11,
                  fontStyle: FontStyle.italic)),
          ),
      ]),
    );
  }

  Widget _ligneResume(Map n) {
    final note    = double.tryParse(n['note_finale']?.toString() ?? '') ?? 0;
    final noteMax = double.tryParse(n['note_max_totale']?.toString() ?? '20') ?? 20;
    final obs     = n['observation']?.toString() ?? '';
    final hasRatt = n['has_rattrapage'] == 1 || n['has_rattrapage'] == '1';
    final valide  = obs == 'AV' || obs == 'AC' || note >= (noteMax / 2);
    final Color noteColor = obs == 'AV' ? AppConstants.accentColor
        : obs == 'AC' ? Colors.orange.shade700
        : obs == 'NV' || (!valide && note > 0) ? AppConstants.errorColor
        : Colors.grey.shade600;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
      child: Row(children: [
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(n['matiere'] ?? '—',
            style: const TextStyle(fontSize: 12), overflow: TextOverflow.ellipsis),
          if (hasRatt)
            Text('2ème session', style: TextStyle(fontSize: 9, color: Colors.orange.shade600)),
        ])),
        const SizedBox(width: 8),
        if (note > 0)
          Text('${note.toStringAsFixed(2)}/${noteMax.toStringAsFixed(0)}',
            style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: noteColor))
        else
          Text('—', style: TextStyle(fontSize: 12, color: Colors.grey.shade400)),
        const SizedBox(width: 6),
        if (obs.isNotEmpty)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            decoration: BoxDecoration(
              color: obs == 'AV' ? Colors.green.shade50
                   : obs == 'AC' ? Colors.orange.shade50 : Colors.red.shade50,
              borderRadius: BorderRadius.circular(4),
              border: Border.all(color: obs == 'AV' ? Colors.green.shade300
                   : obs == 'AC' ? Colors.orange.shade300 : Colors.red.shade300),
            ),
            child: Text(obs, style: TextStyle(fontSize: 9, fontWeight: FontWeight.w700,
              color: obs == 'AV' ? Colors.green.shade700
                   : obs == 'AC' ? Colors.orange.shade700 : Colors.red.shade700)),
          )
        else
          Icon(valide ? Icons.check_circle : Icons.remove_circle_outline,
            color: valide ? AppConstants.accentColor : Colors.grey.shade400, size: 15),
      ]),
    );
  }

  Widget _buildNotes() {
    if (_notes.isEmpty) {
      return const Center(child: Text('Aucune note disponible'));
    }
    final Map<String, Map<String, Map<String, List<dynamic>>>> grouped = {};
    for (final n in _notes) {
      final annee    = n['annee']    ?? '—';
      final semestre = n['semestre'] ?? '—';
      final ue       = '${n['ue_code']} — ${n['ue_lib']}';
      grouped.putIfAbsent(annee, () => {});
      grouped[annee]!.putIfAbsent(semestre, () => {});
      grouped[annee]![semestre]!.putIfAbsent(ue, () => []).add(n);
    }
    return ListView(
      padding: const EdgeInsets.all(16),
      children: grouped.entries.map((anneeEntry) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
            decoration: BoxDecoration(
              color: AppConstants.primaryColor,
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text('🎓 ${anneeEntry.key}',
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 14)),
          ),
          ...anneeEntry.value.entries.map((semEntry) => Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                margin: const EdgeInsets.only(bottom: 8),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                decoration: BoxDecoration(
                  color: const Color(0xFF4472C4),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Text('📅 ${semEntry.key}',
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 12)),
              ),
              ...semEntry.value.entries.map((ueEntry) => Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    decoration: BoxDecoration(
                      color: Colors.blue.shade50,
                      borderRadius: const BorderRadius.vertical(top: Radius.circular(12)),
                    ),
                    child: Text(ueEntry.key,
                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12,
                          color: AppConstants.primaryColor)),
                  ),
                  ...ueEntry.value.map((n) => _noteCard(n)),
                ]),
              )),
              const SizedBox(height: 8),
            ],
          )),
        ],
      )).toList(),
    );
  }

  Widget _noteCard(Map note) {
    final note1      = double.tryParse(note['note_finale']?.toString()    ?? '');
    final noteCc     = double.tryParse(note['note_cc']?.toString()         ?? '');
    final noteExamen = double.tryParse(note['note_examen']?.toString()     ?? '');
    final noteMax    = double.tryParse(note['note_max_totale']?.toString() ?? '20') ?? 20;
    final noteMaxCc  = double.tryParse(note['note_max_cc']?.toString()     ?? '0')  ?? 0;
    final noteMaxEx  = double.tryParse(note['note_max_examen']?.toString() ?? '20') ?? 20;
    final obs        = note['observation']?.toString() ?? '';
    final hasRatt    = note['has_rattrapage'] == 1 || note['has_rattrapage'] == '1';
    final note1S1    = double.tryParse(note['note_finale_s1']?.toString() ?? '');
    final obsS1      = note['obs_s1']?.toString() ?? '';
    final seuil      = noteMax / 2;
    final valide     = obs == 'AV' || obs == 'AC' || (note1 != null && note1 >= seuil);
    final Color finalColor = obs == 'AV'
        ? AppConstants.accentColor
        : obs == 'AC'
            ? Colors.orange.shade700
            : obs == 'NV' || (note1 != null && note1 < seuil)
                ? AppConstants.errorColor
                : Colors.grey;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: Colors.grey.shade100)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        // Nom matière + badge session
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Expanded(child: Text(note['matiere'] ?? '—',
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600))),
          if (hasRatt)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(
                color: Colors.orange.shade50,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.orange.shade300),
              ),
              child: Text('🔄 2ème session',
                style: TextStyle(fontSize: 9, color: Colors.orange.shade700,
                    fontWeight: FontWeight.w700)),
            )
          else if (note1 != null)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(
                color: Colors.blue.shade50,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.blue.shade200),
              ),
              child: Text('📝 1ère session',
                style: TextStyle(fontSize: 9, color: Colors.blue.shade700,
                    fontWeight: FontWeight.w600)),
            ),
        ]),
        // Note S1 si rattrapé
        if (hasRatt && note1S1 != null)
          Padding(
            padding: const EdgeInsets.only(top: 2, bottom: 2),
            child: Row(children: [
              Text('1ère session : ',
                style: TextStyle(fontSize: 10, color: Colors.grey.shade500)),
              Text('${note1S1.toStringAsFixed(2)}/${noteMax.toStringAsFixed(0)}',
                style: TextStyle(fontSize: 10, color: Colors.grey.shade500,
                    decoration: TextDecoration.lineThrough)),
              const SizedBox(width: 4),
              if (obsS1.isNotEmpty)
                Text(obsS1,
                  style: TextStyle(fontSize: 9, color: Colors.grey.shade500)),
            ]),
          ),
        const SizedBox(height: 6),

        Row(children: [
          // TD/CC
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (noteMaxCc > 0) ...[
              Text('TD / CC', style: TextStyle(fontSize: 10, color: Colors.grey.shade500)),
              const SizedBox(height: 2),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: noteCc != null
                      ? (noteCc >= noteMaxCc/2 ? Colors.green.shade50 : Colors.orange.shade50)
                      : Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: noteCc != null
                      ? (noteCc >= noteMaxCc/2 ? Colors.green.shade200 : Colors.orange.shade200)
                      : Colors.grey.shade300),
                ),
                child: Text(
                  noteCc != null
                      ? '${noteCc.toStringAsFixed(2)} / ${noteMaxCc.toStringAsFixed(0)}'
                      : '— / ${noteMaxCc.toStringAsFixed(0)}',
                  style: TextStyle(
                    fontSize: 12, fontWeight: FontWeight.w700,
                    color: noteCc != null
                        ? (noteCc >= noteMaxCc/2 ? Colors.green.shade700 : Colors.orange.shade700)
                        : Colors.grey,
                  ),
                ),
              ),
            ],
          ])),

          const SizedBox(width: 8),

          // Examen
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Examen', style: TextStyle(fontSize: 10, color: Colors.grey.shade500)),
            const SizedBox(height: 2),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: noteExamen != null
                    ? (noteExamen >= noteMaxEx/2 ? Colors.blue.shade50 : Colors.orange.shade50)
                    : Colors.grey.shade100,
                borderRadius: BorderRadius.circular(6),
                border: Border.all(color: noteExamen != null
                    ? (noteExamen >= noteMaxEx/2 ? Colors.blue.shade200 : Colors.orange.shade200)
                    : Colors.grey.shade300),
              ),
              child: Text(
                noteExamen != null
                    ? '${noteExamen.toStringAsFixed(2)} / ${noteMaxEx.toStringAsFixed(0)}'
                    : '— / ${noteMaxEx.toStringAsFixed(0)}',
                style: TextStyle(
                  fontSize: 12, fontWeight: FontWeight.w700,
                  color: noteExamen != null
                      ? (noteExamen >= noteMaxEx/2 ? Colors.blue.shade700 : Colors.orange.shade700)
                      : Colors.grey,
                ),
              ),
            ),
          ])),

          const SizedBox(width: 8),

          // Note finale + badge AV/AC/NV
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('Note finale', style: TextStyle(fontSize: 10, color: Colors.grey.shade500)),
            const SizedBox(height: 2),
            if (note1 != null)
              Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text(note1.toStringAsFixed(2),
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900,
                      color: finalColor)),
                Text('/ ${noteMax.toStringAsFixed(0)}',
                  style: TextStyle(fontSize: 10, color: Colors.grey.shade500)),
                if (obs.isNotEmpty)
                  Container(
                    margin: const EdgeInsets.only(top: 3),
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: obs == 'AV' ? Colors.green.shade50
                           : obs == 'AC' ? Colors.orange.shade50
                           : Colors.red.shade50,
                      borderRadius: BorderRadius.circular(4),
                      border: Border.all(
                        color: obs == 'AV' ? Colors.green.shade300
                             : obs == 'AC' ? Colors.orange.shade300
                             : Colors.red.shade300,
                      ),
                    ),
                    child: Text(obs,
                      style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800,
                        color: obs == 'AV' ? Colors.green.shade700
                             : obs == 'AC' ? Colors.orange.shade700
                             : Colors.red.shade700)),
                  ),
              ])
            else
              Text('—', style: TextStyle(fontSize: 16, color: Colors.grey.shade400)),
          ]),
        ]),
      ]),
    );
  }

  Widget _chip(String t, Color bg, Color fg) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
    decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(6)),
    child: Text(t, style: TextStyle(fontSize: 10, color: fg, fontWeight: FontWeight.w600)),
  );

  Widget _stat(String label, String val) => Column(children: [
    Text(val, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800,
        color: AppConstants.primaryColor)),
    Text(label, style: TextStyle(fontSize: 11, color: Colors.grey.shade500)),
  ]);

  Widget _statusBadge(bool ok) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
    decoration: BoxDecoration(
      color: ok ? AppConstants.accentColor.withOpacity(.1) : AppConstants.errorColor.withOpacity(.1),
      borderRadius: BorderRadius.circular(20),
      border: Border.all(color: ok ? AppConstants.accentColor : AppConstants.errorColor),
    ),
    child: Text(ok ? '✅ Admis' : '❌ Ajourné',
      style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700,
          color: ok ? AppConstants.accentColor : AppConstants.errorColor)),
  );

  Widget _sectionTitle(String t) => Text(t,
    style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800,
        color: AppConstants.primaryColor));
    
  @override
  void dispose() { _tabs.dispose(); super.dispose(); }
}