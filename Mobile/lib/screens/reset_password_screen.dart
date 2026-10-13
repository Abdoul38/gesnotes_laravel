// ============================================================
// screens/reset_password_screen.dart — Mot de passe oublié
// Deux étapes : 1) demande d'un code (envoyé par SMS ou e-mail),
// 2) code + nouveau mot de passe. (Le matricule et la date de naissance
// ne suffisent plus : n'importe qui pouvait changer le mot de passe d'un
// autre étudiant ; le serveur refuse désormais cette méthode.)
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';
import 'login_screen.dart';

class ResetPasswordScreen extends StatefulWidget {
  const ResetPasswordScreen({super.key});
  @override
  State<ResetPasswordScreen> createState() => _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends State<ResetPasswordScreen> {
  final _formKey       = GlobalKey<FormState>();
  final _matriculeCtrl = TextEditingController();
  final _codeCtrl      = TextEditingController();
  final _mdpCtrl       = TextEditingController();
  final _confirmCtrl   = TextEditingController();

  bool _loading     = false;
  bool _showMdp     = false;
  bool _showConfirm = false;
  bool _codeDemande = false;
  bool _succes      = false;
  String? _erreur;
  String? _info;

  @override
  void dispose() {
    _matriculeCtrl.dispose();
    _codeCtrl.dispose();
    _mdpCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  String get _identifiant => _matriculeCtrl.text.trim().toUpperCase();

  Future<void> _demanderCode() async {
    if (_identifiant.isEmpty) {
      setState(() => _erreur = 'Saisissez votre matricule ou N° dossier.');
      return;
    }
    setState(() { _loading = true; _erreur = null; _info = null; });
    final res = await ApiService.demanderCodeReinitialisation(_identifiant);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res['success'] == true) {
        _codeDemande = true;
        _info = res['message']?.toString();
      } else {
        _erreur = res['message']?.toString() ?? 'Erreur inconnue';
      }
    });
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_mdpCtrl.text != _confirmCtrl.text) {
      setState(() => _erreur = 'Les mots de passe ne correspondent pas');
      return;
    }
    setState(() { _loading = true; _erreur = null; });
    final res = await ApiService.reinitialiserMotDePasse(_identifiant, _codeCtrl.text.trim(), _mdpCtrl.text.trim());
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res['success'] == true) {
        _succes = true;
      } else {
        _erreur = res['message']?.toString() ?? 'Erreur inconnue';
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mot de passe oublié')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: _succes ? _buildSucces() : _buildForm(),
      ),
    );
  }

  Widget _buildSucces() => Column(
    mainAxisAlignment: MainAxisAlignment.center,
    children: [
      const SizedBox(height: 40),
      const Icon(Icons.check_circle, color: AppConstants.accentColor, size: 80),
      const SizedBox(height: 20),
      const Text('Mot de passe réinitialisé !',
        style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppConstants.primaryColor),
        textAlign: TextAlign.center),
      const SizedBox(height: 12),
      Text('Votre nouveau mot de passe a été enregistré. Vos autres appareils ont été déconnectés.',
        style: TextStyle(fontSize: 14, color: Colors.grey.shade600),
        textAlign: TextAlign.center),
      const SizedBox(height: 32),
      SizedBox(
        width: double.infinity,
        child: ElevatedButton(
          onPressed: () => Navigator.pushAndRemoveUntil(context,
            MaterialPageRoute(builder: (_) => const LoginScreen()), (_) => false),
          child: const Text('Se connecter'),
        ),
      ),
    ],
  );

  Widget _label(String t) => Padding(
    padding: const EdgeInsets.only(bottom: 6),
    child: Text(t, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
  );

  Widget _bandeau(String texte, Color couleur, IconData icone) => Container(
    margin: const EdgeInsets.only(top: 16),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: couleur.withOpacity(.08),
      borderRadius: BorderRadius.circular(8),
      border: Border.all(color: couleur.withOpacity(.3)),
    ),
    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Icon(icone, color: couleur, size: 18),
      const SizedBox(width: 8),
      Expanded(child: Text(texte, style: TextStyle(color: couleur, fontSize: 13))),
    ]),
  );

  Widget _bouton(String texte, VoidCallback action) => SizedBox(
    width: double.infinity,
    child: ElevatedButton(
      onPressed: _loading ? null : action,
      style: ElevatedButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
      child: _loading
          ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
          : Text(texte, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700)),
    ),
  );

  Widget _buildForm() => Form(
    key: _formKey,
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppConstants.primaryColor.withOpacity(.05),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppConstants.primaryColor.withOpacity(.2)),
        ),
        child: Row(children: [
          const Icon(Icons.info_outline, color: AppConstants.primaryColor, size: 20),
          const SizedBox(width: 10),
          Expanded(child: Text(
            _codeDemande
                ? 'Saisissez le code reçu par SMS ou e-mail (valable 15 minutes) et votre nouveau mot de passe.'
                : 'Saisissez votre matricule : un code de vérification sera envoyé au téléphone ou à l\'e-mail enregistré à la scolarité.',
            style: const TextStyle(fontSize: 13, color: AppConstants.primaryColor),
          )),
        ]),
      ),
      const SizedBox(height: 24),

      _label('Matricule ou N° dossier'),
      TextFormField(
        controller: _matriculeCtrl,
        enabled: !_codeDemande,
        textCapitalization: TextCapitalization.characters,
        decoration: const InputDecoration(
          hintText: 'Ex: 00001',
          prefixIcon: Icon(Icons.person_outline),
          border: OutlineInputBorder(),
        ),
        validator: (v) => (v == null || v.isEmpty) ? 'Requis' : null,
      ),

      if (_codeDemande) ...[
        const SizedBox(height: 16),
        _label('Code de vérification'),
        TextFormField(
          controller: _codeCtrl,
          keyboardType: TextInputType.number,
          maxLength: 6,
          decoration: const InputDecoration(
            hintText: '6 chiffres',
            prefixIcon: Icon(Icons.pin_outlined),
            border: OutlineInputBorder(),
            counterText: '',
          ),
          validator: (v) => (v == null || !RegExp(r'^\d{6}$').hasMatch(v.trim())) ? 'Code à 6 chiffres' : null,
        ),
        const SizedBox(height: 16),
        _label('Nouveau mot de passe'),
        TextFormField(
          controller: _mdpCtrl,
          obscureText: !_showMdp,
          decoration: InputDecoration(
            hintText: '8 caractères, lettres et chiffres',
            prefixIcon: const Icon(Icons.lock_outline),
            border: const OutlineInputBorder(),
            suffixIcon: IconButton(
              icon: Icon(_showMdp ? Icons.visibility_off : Icons.visibility),
              onPressed: () => setState(() => _showMdp = !_showMdp),
            ),
          ),
          // Même règle que le serveur
          validator: (v) {
            if (v == null || v.isEmpty) return 'Requis';
            if (v.length < 8) return 'Minimum 8 caractères';
            if (!RegExp(r'[A-Za-z]').hasMatch(v) || !RegExp(r'\d').hasMatch(v)) return 'Lettres et chiffres';
            return null;
          },
        ),
        const SizedBox(height: 16),
        _label('Confirmer le mot de passe'),
        TextFormField(
          controller: _confirmCtrl,
          obscureText: !_showConfirm,
          decoration: InputDecoration(
            hintText: 'Répétez le mot de passe',
            prefixIcon: const Icon(Icons.lock_outline),
            border: const OutlineInputBorder(),
            suffixIcon: IconButton(
              icon: Icon(_showConfirm ? Icons.visibility_off : Icons.visibility),
              onPressed: () => setState(() => _showConfirm = !_showConfirm),
            ),
          ),
          validator: (v) => (v == null || v.isEmpty) ? 'Requis' : null,
        ),
      ],

      if (_info != null && _erreur == null) _bandeau(_info!, AppConstants.accentColor, Icons.mark_email_read_outlined),
      if (_erreur != null) _bandeau(_erreur!, AppConstants.errorColor, Icons.error_outline),

      const SizedBox(height: 24),
      _codeDemande
          ? _bouton('Réinitialiser le mot de passe', _submit)
          : _bouton('Recevoir un code', _demanderCode),

      const SizedBox(height: 16),
      Center(
        child: TextButton(
          onPressed: _loading ? null : () => _codeDemande
              ? setState(() { _codeDemande = false; _info = null; _erreur = null; })
              : Navigator.pop(context),
          child: Text(_codeDemande ? 'Renvoyer un code / changer de matricule' : '← Retour à la connexion'),
        ),
      ),
    ]),
  );
}
