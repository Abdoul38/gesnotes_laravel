// ============================================================
// screens/login_screen.dart — Écran de connexion UDH Mobile
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';
import 'home_screen.dart';
import 'reset_password_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey     = GlobalKey<FormState>();
  final _idCtrl      = TextEditingController();
  final _passCtrl    = TextEditingController();
  DateTime? _dateNaissance;
  bool _loading      = false;
  bool _obscurePass  = true;
  String? _error;

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _loading = true; _error = null; });

    final res = await ApiService.login(
      _idCtrl.text.trim().toUpperCase(),
      _passCtrl.text.trim(),
      dateNaissance: _dateNaissance != null
          ? '${_dateNaissance!.year.toString().padLeft(4, '0')}-${_dateNaissance!.month.toString().padLeft(2, '0')}-${_dateNaissance!.day.toString().padLeft(2, '0')}'
          : null,
    );

    if (!mounted) return;
    setState(() => _loading = false);

    if (res['success'] == true) {
      Navigator.pushReplacement(context,
        MaterialPageRoute(builder: (_) => const HomeScreen()));
    } else {
      setState(() => _error = res['message'] ?? 'Erreur de connexion');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppConstants.primaryColor,
      body: SafeArea(
        child: Column(children: [
          // En-tête
          const Expanded(
            flex: 2,
            child: Center(
              child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                Text('UDH', style: TextStyle(color: Colors.white, fontSize: 48, fontWeight: FontWeight.w900, letterSpacing: 3)),
                SizedBox(height: 8),
                Text(AppConstants.universityName,
                  style: TextStyle(color: Colors.white70, fontSize: 15, fontWeight: FontWeight.w600),
                  textAlign: TextAlign.center,
                ),
                Text(AppConstants.universitySubtitle,
                  style: TextStyle(color: Colors.white54, fontSize: 12),
                ),
              ]),
            ),
          ),

          // Formulaire
          Expanded(
            flex: 3,
            child: Container(
              decoration: const BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
              ),
              padding: const EdgeInsets.fromLTRB(24, 32, 24, 24),
              child: SingleChildScrollView(
                child: Form(
                key: _formKey,
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  const Text('Connexion', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: AppConstants.primaryColor)),
                  const SizedBox(height: 6),
                  const Text('Matricule ou N° dossier / N° dépôt', style: TextStyle(fontSize: 13, color: Colors.grey)),
                  const SizedBox(height: 24),

                  // Identifiant
                  TextFormField(
                    controller: _idCtrl,
                    textCapitalization: TextCapitalization.characters,
                    decoration: const InputDecoration(
                      labelText: 'Matricule ou N° dossier',
                      hintText: 'Ex: 00001 ou D915839',
                      prefixIcon: Icon(Icons.person_outline, color: AppConstants.primaryColor),
                    ),
                    validator: (v) => v!.isEmpty ? 'Champ obligatoire' : null,
                  ),
                  const SizedBox(height: 16),

                  // Mot de passe
                  TextFormField(
                    controller: _passCtrl,
                    obscureText: _obscurePass,
                    decoration: InputDecoration(
                      labelText: 'Mot de passe',
                      hintText: 'Votre mot de passe',
                      prefixIcon: const Icon(Icons.lock_outline, color: AppConstants.primaryColor),
                      suffixIcon: IconButton(
                        icon: Icon(_obscurePass ? Icons.visibility_off : Icons.visibility, color: Colors.grey),
                        onPressed: () => setState(() => _obscurePass = !_obscurePass),
                      ),
                    ),
                    validator: (v) => v!.isEmpty ? 'Champ obligatoire' : (v.length < 6 ? 'Minimum 6 caractères' : null),
                  ),
                  const SizedBox(height: 16),

                  // Date de naissance — requise uniquement à la toute
                  // première connexion (création du mot de passe)
                  InkWell(
                    onTap: () async {
                      final choisie = await showDatePicker(
                        context: context,
                        initialDate: DateTime(2000, 1, 1),
                        firstDate: DateTime(1950, 1, 1),
                        lastDate: DateTime.now(),
                        helpText: 'Date de naissance',
                      );
                      if (choisie != null) setState(() => _dateNaissance = choisie);
                    },
                    child: InputDecorator(
                      decoration: const InputDecoration(
                        labelText: 'Date de naissance (1ère connexion uniquement)',
                        prefixIcon: Icon(Icons.cake_outlined, color: AppConstants.primaryColor),
                      ),
                      child: Text(
                        _dateNaissance != null
                            ? '${_dateNaissance!.day.toString().padLeft(2, '0')}/${_dateNaissance!.month.toString().padLeft(2, '0')}/${_dateNaissance!.year}'
                            : 'Sélectionner (si première connexion)',
                        style: TextStyle(
                          color: _dateNaissance != null ? Colors.black87 : Colors.grey,
                        ),
                      ),
                    ),
                  ),

                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: AppConstants.errorColor.withOpacity(.1),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: AppConstants.errorColor.withOpacity(.3)),
                      ),
                      child: Row(children: [
                        const Icon(Icons.error_outline, color: AppConstants.errorColor, size: 18),
                        const SizedBox(width: 8),
                        Expanded(child: Text(_error!, style: const TextStyle(color: AppConstants.errorColor, fontSize: 13))),
                      ]),
                    ),
                  ],

                  const SizedBox(height: 24),

                  // Bouton connexion
                  SizedBox(
                    height: 52,
                    child: ElevatedButton(
                      onPressed: _loading ? null : _login,
                      child: _loading
                          ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                          : const Text('Se connecter'),
                    ),
                  ),

                  const SizedBox(height: 12),

                  // Mot de passe oublié
                  Center(
                    child: TextButton(
                      onPressed: () => Navigator.push(context,
                          MaterialPageRoute(builder: (_) => const ResetPasswordScreen())),
                      child: const Text(
                        'Mot de passe oublié ?',
                        style: TextStyle(
                          color: AppConstants.primaryColor,
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ),

                  const SizedBox(height: 8),

                  // Info premier accès
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.blue.shade50,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Icon(Icons.info_outline, color: Colors.blue, size: 16),
                      SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'Premier accès : saisissez votre matricule ou N° dossier, votre date de naissance, puis créez votre mot de passe.',
                          style: TextStyle(fontSize: 12, color: Colors.blue),
                        ),
                      ),
                    ]),
                  ),
                ]),
              ),
            ),
          ),
        ),
        ]),
      ),
    );
  }

  @override
  void dispose() { _idCtrl.dispose(); _passCtrl.dispose(); super.dispose(); }
}