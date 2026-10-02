<?php

return [
    // Clé de signature des licences (ancien EDUFILE_LICENCE_SECRET).
    // Vide = toute licence est refusée.
    'secret' => env('LICENCE_SECRET', ''),
];
