// ============================================================
// screens/reset_password_screen.dart — Mot de passe oublié
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
  final _dateCtrl      = TextEditingController();
  final _mdpCtrl       = TextEditingController();
  final _confirmCtrl   = TextEditingController();

  bool _loading    = false;
  bool _showMdp    = false;
  bool _showConfirm= false;
  String? _erreur;
  bool _succes     = false;

  @override
  void dispose() {
    _matriculeCtrl.dispose();
    _dateCtrl.dispose();
    _mdpCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_mdpCtrl.text != _confirmCtrl.text) {
      setState(() => _erreur = 'Les mots de passe ne correspondent pas');
      return;
    }

    setState(() { _loading = true; _erreur = null; });

    final res = await ApiService.post('reset_password', {
      'identifiant':    _matriculeCtrl.text.trim(),
      'date_naissance': _dateCtrl.text.trim(),
      'nouveau_mdp':    _mdpCtrl.text.trim(),
    });

    setState(() => _loading = false);

    if (res['success'] == true) {
      setState(() => _succes = true);
    } else {
      setState(() => _erreur = res['message'] ?? 'Erreur inconnue');
    }
  }

  Future<void> _selectDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: DateTime(2000),
      firstDate: DateTime(1950),
      lastDate: DateTime.now(),
      helpText: 'Date de naissance',
      confirmText: 'Confirmer',
      cancelText: 'Annuler',
    );
    if (picked != null) {
      _dateCtrl.text =
          '${picked.year}-${picked.month.toString().padLeft(2,'0')}-${picked.day.toString().padLeft(2,'0')}';
    }
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
        style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800,
            color: AppConstants.primaryColor),
        textAlign: TextAlign.center),
      const SizedBox(height: 12),
      Text('Votre nouveau mot de passe a été enregistré.',
        style: TextStyle(fontSize: 14, color: Colors.grey.shade600),
        textAlign: TextAlign.center),
      const SizedBox(height: 32),
      SizedBox(
        width: double.infinity,
        child: ElevatedButton(
          onPressed: () => Navigator.pushAndRemoveUntil(context,
            MaterialPageRoute(builder: (_) => const LoginScreen()),
            (_) => false),
          child: const Text('Se connecter'),
        ),
      ),
    ],
  );

  Widget _buildForm() => Form(
    key: _formKey,
    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

      // En-tête
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
          const Expanded(child: Text(
            'Pour réinitialiser votre mot de passe, saisissez votre matricule et votre date de naissance.',
            style: TextStyle(fontSize: 13, color: AppConstants.primaryColor),
          )),
        ]),
      ),
      const SizedBox(height: 24),

      // Matricule
      const Text('Matricule ou N° dossier',
        style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
      const SizedBox(height: 6),
      TextFormField(
        controller: _matriculeCtrl,
        textCapitalization: TextCapitalization.characters,
        decoration: const InputDecoration(
          hintText: 'Ex: 00001',
          prefixIcon: Icon(Icons.person_outline),
          border: OutlineInputBorder(),
        ),
        validator: (v) => (v == null || v.isEmpty) ? 'Requis' : null,
      ),
      const SizedBox(height: 16),

      // Date de naissance
      const Text('Date de naissance',
        style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
      const SizedBox(height: 6),
      TextFormField(
        controller: _dateCtrl,
        readOnly: true,
        onTap: _selectDate,
        decoration: const InputDecoration(
          hintText: 'Appuyez pour sélectionner',
          prefixIcon: Icon(Icons.calendar_today_outlined),
          border: OutlineInputBorder(),
        ),
        validator: (v) => (v == null || v.isEmpty) ? 'Requis' : null,
      ),
      const SizedBox(height: 16),

      // Nouveau mot de passe
      const Text('Nouveau mot de passe',
        style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
      const SizedBox(height: 6),
      TextFormField(
        controller: _mdpCtrl,
        obscureText: !_showMdp,
        decoration: InputDecoration(
          hintText: 'Minimum 6 caractères',
          prefixIcon: const Icon(Icons.lock_outline),
          border: const OutlineInputBorder(),
          suffixIcon: IconButton(
            icon: Icon(_showMdp ? Icons.visibility_off : Icons.visibility),
            onPressed: () => setState(() => _showMdp = !_showMdp),
          ),
        ),
        validator: (v) {
          if (v == null || v.isEmpty) return 'Requis';
          if (v.length < 6) return 'Minimum 6 caractères';
          return null;
        },
      ),
      const SizedBox(height: 16),

      // Confirmer mot de passe
      const Text('Confirmer le mot de passe',
        style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
      const SizedBox(height: 6),
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

      // Erreur
      if (_erreur != null) ...[
        const SizedBox(height: 16),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: AppConstants.errorColor.withOpacity(.1),
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: AppConstants.errorColor.withOpacity(.3)),
          ),
          child: Row(children: [
            Icon(Icons.error_outline, color: AppConstants.errorColor, size: 18),
            const SizedBox(width: 8),
            Expanded(child: Text(_erreur!,
              style: TextStyle(color: AppConstants.errorColor, fontSize: 13))),
          ]),
        ),
      ],

      const SizedBox(height: 24),

      // Bouton
      SizedBox(
        width: double.infinity,
        child: ElevatedButton(
          onPressed: _loading ? null : _submit,
          style: ElevatedButton.styleFrom(
            padding: const EdgeInsets.symmetric(vertical: 14)),
          child: _loading
              ? const SizedBox(width: 20, height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Text('Réinitialiser le mot de passe',
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700)),
        ),
      ),

      const SizedBox(height: 16),
      Center(
        child: TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('← Retour à la connexion'),
        ),
      ),
    ]),
  );
}