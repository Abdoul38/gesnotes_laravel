#!/bin/bash
# Compare l'ancien et le nouveau moteur de résultats sur des COPIES de la base locale.
set -e
D=$(dirname "$0")
M=/c/xampp00/mysql/bin
PHP=/c/xampp00/php/php
SRC=${1:-gesnotes}

for b in source ancien nouveau; do $M/mysql -uroot -e "DROP DATABASE IF EXISTS gesnotes_cmp_$b; CREATE DATABASE gesnotes_cmp_$b CHARACTER SET utf8mb4"; done
$M/mysqldump -uroot --routines --no-tablespaces "$SRC" > "$D/source.sql"
$M/mysql -uroot gesnotes_cmp_source < "$D/source.sql"
$PHP "$D/generer.php"
$PHP "$D/plan.php"
$M/mysqldump -uroot --no-tablespaces gesnotes_cmp_source > "$D/cmp.sql"
$M/mysql -uroot gesnotes_cmp_ancien < "$D/cmp.sql"
$M/mysql -uroot gesnotes_cmp_nouveau < "$D/cmp.sql"

$PHP "$D/ancien.php"
$PHP "$D/nouveau.php"

compare() { # table, colonnes
  $M/mysql -uroot -N -e "
    SELECT '$1', COUNT(*) FROM (
      SELECT $2 FROM gesnotes_cmp_ancien.$1 UNION ALL SELECT $2 FROM gesnotes_cmp_nouveau.$1
    ) t GROUP BY $2 HAVING COUNT(*) % 2 = 1" | awk -v t="$1" 'BEGIN{n=0} {n++} END{print t": "n" ligne(s) différente(s)"}'
}
compare notes "pv_id, etudiant_id, matiere_id, note_examen, note_cc, note_finale, observation, reportee, pv_origine_id, annee_origine_id"
compare resultats_ue "pv_id, etudiant_id, ue_id, total_points, note_max, moyenne_20, credits_obtenus, valide, defaillant"
compare resultats_semestre "pv_id, etudiant_id, moyenne_generale, credits_obtenus, credits_total, valide, defaillant, mention, rang, decision"
compare parametres "cle, valeur"
for t in notes resultats_ue resultats_semestre; do
  echo "$t : $($M/mysql -uroot -N -e "SELECT COUNT(*) FROM gesnotes_cmp_ancien.$t") lignes (ancien) / $($M/mysql -uroot -N -e "SELECT COUNT(*) FROM gesnotes_cmp_nouveau.$t") (nouveau)"
done
$M/mysql -uroot -N -e "SELECT decision, COUNT(*) FROM gesnotes_cmp_nouveau.resultats_semestre GROUP BY decision"
