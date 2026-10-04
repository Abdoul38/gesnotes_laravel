{{-- Liste imprimable des identifiants d'une promotion (ancien generer_comptes_etudiants&pdf=1) --}}
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Comptes — {{ $promo->filiere }} {{ $promo->niveau }}</title>
<style>
body{font-family:Arial,sans-serif;font-size:10pt;margin:20px}
h2{text-align:center;font-size:14pt;margin-bottom:4px}
h3{text-align:center;font-size:11pt;color:#555;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:9pt}
th{background:#1A3A5C;color:#fff;padding:6px 8px;text-align:left}
td{border:1px solid #ddd;padding:5px 8px;vertical-align:middle}
tr:nth-child(even) td{background:#f9f9f9}
.mdp{font-weight:bold;color:#c2410c;background:#fef3c7;padding:2px 6px;border-radius:4px}
.footer{margin-top:20px;font-size:8pt;color:#888;text-align:center}
</style>
</head>
<body>
<h2>{{ mb_strtoupper($universite->nom) }}</h2>
<h3>Identifiants de connexion — {{ $promo->filiere }} {{ $promo->niveau }} — {{ $promo->annee }}</h3>
<table>
<thead><tr><th>N°</th><th>Nom & Prénoms</th><th>Matricule</th><th>Email de connexion</th><th>Première connexion</th></tr></thead>
<tbody>
@foreach ($comptes as $c)
<tr>
  <td style="text-align:center">{{ $loop->iteration }}</td>
  <td>{{ $c->nom }} {{ $c->prenoms }}</td>
  <td style="text-align:center">{{ $c->numero }}</td>
  <td><code>{{ $c->email }}</code></td>
  <td style="text-align:center">{{ $c->derniere_connexion ? 'faite' : 'à faire' }}</td>
</tr>
@endforeach
</tbody>
</table>
<div class="footer">
  NB : le mot de passe provisoire a été remis à la génération des comptes et n'est pas conservé. Mot de passe perdu : régénérer le compte de l'étudiant (option « écraser »).<br>
  URL de connexion : {{ $urlConnexion }}
</div>
</body>
</html>
