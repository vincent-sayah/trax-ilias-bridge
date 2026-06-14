<?php
/**
 * Configuration optionnelle de l'adaptateur Trax -> ILIAS.
 *
 * Dans la plupart des cas, laisse trax_base_url à null : l'adaptateur utilise
 * automatiquement le même protocole/hôte que la requête entrante.
 */
return [
    // null = auto, par exemple http://192.168.56.11 depuis la requête ILIAS.
    // Tu peux forcer une URL locale si besoin : 'http://127.0.0.1'
    'trax_base_url' => null,

    // Utilisé uniquement avec l'URL ILIAS 10 actuelle qui perd le nom du store :
    // /clients/{client}/stores/api/statements/aggregate
    'default_store' => 'default',

    // Si certains clients n'utilisent pas le store "default", déclare-les ici.
    // Exemple : 'client2' => 'store2'
    'client_store_map' => [
        // 'eform' => 'default',
    ],

    // Nombre maximum de statements à lire pour construire la réponse ILIAS.
    'max_statements_to_fetch' => 5000,

    // true = renvoie davantage de détails en cas d'erreur.
    'debug' => false,
];
