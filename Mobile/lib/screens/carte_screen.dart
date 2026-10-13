// ============================================================
// screens/carte_screen.dart — Carte étudiante numérique
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';

class CarteScreen extends StatefulWidget {
  const CarteScreen({super.key});
  @override
  State<CarteScreen> createState() => _CarteScreenState();
}

class _CarteScreenState extends State<CarteScreen>
{
  Map<String, dynamic>? _data;
  bool _loading = true;
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final res = await ApiService.get('carte');
    if (mounted && res['success'] == true) {
      setState(() { _data = res['data']; _loading = false; });
      await ApiService.cacheData('carte', res['data']);
    } else {
      final cached = await ApiService.getCached('carte');
      if (mounted) setState(() { _data = cached; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Ma Carte Étudiante'),
        actions: [
          IconButton(icon: const Icon(Icons.refresh), onPressed: _load),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _data == null
              ? const Center(child: Text('Données indisponibles'))
              : SingleChildScrollView(
                  padding: const EdgeInsets.all(20),
                  child: Column(children: [
                    _buildFront(),

                    const SizedBox(height: 24),

                    // Info
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: Colors.blue.shade50,
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: Colors.blue.shade200),
                      ),
                      child: const Row(children: [
                        Icon(Icons.info_outline, color: Colors.blue, size: 18),
                        SizedBox(width: 8),
                        Expanded(child: Text(
                          'Présentez cette carte pour vous identifier sur le campus.',
                          style: TextStyle(fontSize: 12, color: Colors.blue),
                        )),
                      ]),
                    ),
                  ]),
                ),
    );
  }

  Widget _buildFront() {
    final et       = (_data!['etudiant']    as Map<String, dynamic>?) ?? {};
    final ins      = (_data!['inscription'] as Map<String, dynamic>?) ?? {};
    final photoUrl = _data!['photo_url']    as String?;
    final cachetUrl= _data!['cachet_url']   as String?;
    final logoUrl  = _data!['logo_url']     as String?;

    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(10),
        color: Colors.white,
        border: Border.all(color: Colors.grey.shade300),
        boxShadow: [BoxShadow(color: Colors.black.withOpacity(.2), blurRadius: 12, offset: const Offset(0,4))],
      ),
      child: Stack(children: [

        // ── Filigrane logo ──────────────────────────────────
        if (logoUrl != null)
          Positioned.fill(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: Center(
                child: Opacity(
                  opacity: 0.05,
                  child: Image.network(logoUrl, width: 160, height: 160, fit: BoxFit.contain,
                    errorBuilder: (_,__,___) => const SizedBox()),
                ),
              ),
            ),
          ),

        // ── Contenu ─────────────────────────────────────────
        Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [

        // ── Bande bleue ─────────────────────────────────────
        Container(
          height: 58,
          decoration: const BoxDecoration(
            borderRadius: BorderRadius.only(topLeft: Radius.circular(10), topRight: Radius.circular(10)),
            gradient: LinearGradient(
              colors: [Color(0xFF1A3A5C), Color(0xFF2C5F9E)],
              begin: Alignment.centerLeft, end: Alignment.centerRight,
            ),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          child: Row(children: [
            // Logo dans carré blanc
            Container(
              width: 38, height: 38,
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(3)),
              padding: const EdgeInsets.all(2),
              child: logoUrl != null
                  ? Image.network(logoUrl, fit: BoxFit.contain,
                      errorBuilder: (_,__,___) => const Icon(Icons.school, size: 22, color: Color(0xFF1A3A5C)))
                  : const Icon(Icons.school, size: 22, color: Color(0xFF1A3A5C)),
            ),
            const SizedBox(width: 8),
            const Expanded(
              child: Text('UNIVERSITÉ DJIBO HAMANI DE TAHOUA',
                style: TextStyle(color: Colors.white, fontSize: 11.5, fontWeight: FontWeight.w800, height: 1.3)),
            ),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
              decoration: BoxDecoration(
                border: Border.all(color: Colors.white70, width: 1.2),
                borderRadius: BorderRadius.circular(3),
              ),
              child: const Text("CARTE D'ÉTUDIANT",
                style: TextStyle(color: Colors.white, fontSize: 7.5, fontWeight: FontWeight.w700, letterSpacing: .5)),
            ),
          ]),
        ),

        // ── Corps ───────────────────────────────────────────
        Padding(
          padding: const EdgeInsets.fromLTRB(10, 8, 8, 8),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [

            // Infos gauche
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              _infoChamp('NOM', (et['nom'] ?? '').toString().toUpperCase(),
                valStyle: const TextStyle(fontSize: 14, fontWeight: FontWeight.w900, color: Colors.black)),
              const SizedBox(height: 4),
              _infoChamp('PRÉNOM', et['prenoms'] ?? '—'),
              const SizedBox(height: 4),
              _infoChamp('NÉ(E) LE',
                '${_formatDate(et['date_naissance']?.toString())}${(et['lieu_naissance'] ?? '').isNotEmpty ? '  à ${et['lieu_naissance']}' : ''}'),
              const SizedBox(height: 8),
              // NIE + Niveau
              Row(children: [
                Expanded(child: _infoChamp('NIE', et['numero'] ?? '—',
                  valStyle: const TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: Color(0xFF1A3A5C)))),
                const SizedBox(width: 6),
                Expanded(child: _infoChamp('NIVEAU',
                  '${ins['niveau_code'] ?? ''} ${ins['filiere'] ?? ''}'.trim(),
                  valStyle: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: Color(0xFF1A3A5C)))),
              ]),
              const SizedBox(height: 3),
              // Entité + Année
              Row(children: [
                Expanded(child: _infoChamp('ENTITÉ', ins['faculte'] ?? '—',
                  valStyle: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: Color(0xFF1A3A5C)))),
                const SizedBox(width: 6),
                Expanded(child: _infoChamp('ANNÉE ACAD.', ins['annee'] ?? '—',
                  valStyle: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: Color(0xFF1A3A5C)))),
              ]),
            ])),

            const SizedBox(width: 8),

            // Photo + Signature + Cachet
            Column(mainAxisSize: MainAxisSize.min, children: [
              // Photo
              Container(
                width: 65, height: 78,
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.grey.shade300),
                  borderRadius: BorderRadius.circular(2),
                  color: Colors.grey.shade100,
                ),
                clipBehavior: Clip.antiAlias,
                child: photoUrl != null
                    ? Image.network(photoUrl, fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => _defaultAvatar(et))
                    : _defaultAvatar(et),
              ),
              // Signature
              Container(
                width: 65,
                padding: const EdgeInsets.only(top: 3),
                decoration: const BoxDecoration(
                  border: Border(top: BorderSide(color: Colors.grey, width: .5)),
                ),
                child: const Text("Signature de l'autorité",
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 6, color: Colors.grey, fontStyle: FontStyle.italic)),
              ),
              const SizedBox(height: 3),
              // Cachet
              SizedBox(
                width: 44, height: 36,
                child: cachetUrl != null
                    ? Image.network(cachetUrl, fit: BoxFit.contain,
                        opacity: const AlwaysStoppedAnimation(.82),
                        errorBuilder: (_, __, ___) => _cachetPlaceholder())
                    : _cachetPlaceholder(),
              ),
            ]),

          ]),
        ),
        ]), // Column
      ]), // Stack
    );
  }

  Widget _infoChamp(String label, String val, {TextStyle? valStyle}) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    mainAxisSize: MainAxisSize.min,
    children: [
      Text(label, style: const TextStyle(fontSize: 7, color: Colors.grey, letterSpacing: .3)),
      Text(val,
        style: valStyle ?? const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Color(0xFF1A3A5C)),
        overflow: TextOverflow.ellipsis, maxLines: 1),
    ],
  );

  Widget _cachetPlaceholder() => Container(
    decoration: BoxDecoration(
      shape: BoxShape.circle,
      border: Border.all(color: Colors.grey.shade300, width: .8),
    ),
    child: const Center(child: Text('CACHET', style: TextStyle(fontSize: 5.5, color: Colors.grey))),
  );


  Widget _defaultAvatar(Map et) => Container(
    color: Colors.white24,
    child: Center(child: Text(
      ((et['prenoms']?.isNotEmpty == true ? et['prenoms'][0] : '') +
       (et['nom']?.isNotEmpty == true ? et['nom'][0] : '')).toUpperCase(),
      style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800),
    )),
  );

  String _formatDate(String? d) {
    if (d == null) return '—';
    try {
      final dt = DateTime.parse(d);
      return '${dt.day.toString().padLeft(2,'0')}/${dt.month.toString().padLeft(2,'0')}/${dt.year}';
    } catch (_) { return d; }
  }


}