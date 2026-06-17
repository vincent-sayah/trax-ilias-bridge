<?php
/**
 * Configuration de l'adaptateur Trax ILIAS Bridge.
 * Version 2.0.4
 */
return [
    /**
     * null = utilise automatiquement le protocole et l'hôte de la requête entrante.
     *
     * Architecture simple HTTP/IP : null peut suffire.
     * Architecture DNS/HTTPS ou reverse proxy : utiliser une URL interne Trax
     * qui ne repasse pas par les AliasMatch de l'adaptateur, par exemple :
     * 'http://127.0.0.1:8080'
     *
     * L'adaptateur ajoute l'en-tête X-Trax-Ilias-Bridge-Bypass. Cet en-tête
     * est utile pour le diagnostic, mais la séparation avec un vhost interne
     * reste la méthode la plus fiable pour éviter les boucles.
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
