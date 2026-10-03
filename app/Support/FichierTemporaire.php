<?php

namespace App\Support;

/**
 * Chemin de fichier temporaire unique et PAS ENCORE CRÉÉ.
 *
 * ZipArchive écrit d'abord un fichier intermédiaire puis le renomme sur la
 * destination : si celle-ci existe déjà (cas de tempnam()), Windows refuse
 * parfois le remplacement (« Renaming temporary file failed: Permission
 * denied », fichier tenu par l'antivirus ou l'indexation) et l'export
 * échoue au hasard. Une destination inexistante évite ce remplacement.
 */
final class FichierTemporaire
{
    public static function chemin(string $prefixe, string $extension = ''): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefixe.bin2hex(random_bytes(10)).$extension;
    }
}
