<?php

namespace App\Services;

use RuntimeException;

/**
 * Différentielle ou incrémentielle impossible sans nouvelle complète (aucune
 * complète, complète faite sans journal binaire, journaux supprimés du
 * serveur avant d'avoir été copiés) : le mode automatique fait alors une
 * complète.
 */
class ChaineJournauxRompue extends RuntimeException {}
