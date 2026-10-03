<?php

// Paiement en ligne KomiPay (inscription académique du portail candidats).
// Identifiants dans .env (jamais versionné) — ils étaient écrits en clair
// dans l'ancien dépôt (candidature/includes/komipay.php).
return [
    'env' => env('KOMIPAY_ENV', 'sandbox'),              // 'live' ou 'sandbox' : doit correspondre au type de la clé
    'login' => env('KOMIPAY_LOGIN', ''),
    'password' => env('KOMIPAY_PASSWORD', ''),
    'api_key' => env('KOMIPAY_API_KEY', ''),
    'keypass' => env('KOMIPAY_KEYPASS', ''),               // APIs bancaires (carte)
    'url_live' => 'https://www.komipay.com/APIs/mobile_money.php',
    'url_sandbox' => 'http://sandbox.komipay.com:8181/APIs/mobile_money.php',
    // Frais de transaction supportés par l'étudiant, délai de validité d'un paiement
    'taux_frais' => 0.03,
    'expiration_minutes' => 15,
    // Moyens proposés (canaux activés sur le compte KomiPay) : mynita, amana, mobile_money, visa_master
    'moyens' => array_values(array_filter(array_map('trim', explode(',', (string) env('KOMIPAY_MOYENS', 'mynita,amana'))))),
];
