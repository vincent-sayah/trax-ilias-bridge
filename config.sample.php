<?php
/**
 * Configuration de l'adaptateur Trax ILIAS Bridge.
 */
return [
    /**
     * null = utilise automatiquement le protocole et l'hôte de la requête entrante.
     * Exemple possible si Trax est joignable localement : 'http://127.0.0.1'
     *
     * L'adaptateur ajoute l'en-tête X-Trax-Ilias-Bridge-Bypass pour éviter les
     * boucles de réécriture Apache quand cette URL pointe vers le même vhost.
     */
    'trax_base_url' => null,

    /**
     * ILIAS 10 construit souvent l'URL d'agrégation ainsi :
     * /trax/api/gateway/clients/{client}/stores/api/statements/aggregate
     * Le nom du store est donc perdu.
     *
     * Stratégie par défaut : client_name
     * Exemple : client ppm -> store ppm.
     *
     * Pour les exceptions, utiliser client_store_map.
     */
    'aggregate_store_strategy' => 'client_name',

    /**
     * Correspondance client Trax -> store Trax.
     * Exemple :
     * 'ppm' => 'ppm',
     * 'eform' => 'default',
     */
    'client_store_map' => [
        // 'ppm' => 'ppm',
        // 'eform' => 'default',
    ],

    /**
     * Utilisé seulement si aggregate_store_strategy n'est pas client_name
     * et si le client n'est pas déclaré dans client_store_map.
     */
    'default_store' => 'default',

    /**
     * Nombre maximum de statements xAPI chargés depuis Trax pour construire
     * la réponse attendue par ILIAS Learning Experiences / Ranking.
     */
    'max_statements_to_fetch' => 5000,

    /**
     * true = erreurs détaillées en JSON.
     * Laisser false en production.
     */
    'debug' => false,
];
