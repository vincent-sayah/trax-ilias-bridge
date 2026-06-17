# Changelog

## 2.0.5 - 2026-06-17

### Corrigé

- Correction du filtrage des résultats Ranking lorsque le pipeline retourne un score numérique scalaire, par exemple `0.92`, produit par `$max`.
- La version 2.0.4 pouvait retourner une réponse vide `[]` alors que des statements scorés existaient bien dans Trax.
- Mise à jour de l'en-tête de diagnostic `X-Trax-Ilias-Bridge-Version` en `2.0.5`.

### Notes de migration depuis 2.0.4

- Remplacer uniquement `aggregate.php` si la configuration Apache et `config.php` sont déjà en place.
- Aucun changement Apache obligatoire par rapport à la 2.0.4.
- Aucun changement obligatoire dans `config.php`.

## 2.0.4 - 2026-06-17

### Ajouté

- Support de la route xAPI State :
  `/trax/api/gateway/clients/{client}/stores/{store}/xapi/activities/state`.
- Suppression automatique du paramètre non standard `activity_id` sur `/xapi/activities/state`, en conservant le paramètre standard `activityId`.
- Exemple Apache pour architecture DNS/HTTPS avec vhost public et backend Trax interne :
  `apache-trax-ilias-adapter-https-internal.example.conf`.
- Normalisation du timestamp dans les lignes Ranking retournées à ILIAS pour éviter l'erreur :
  `Call to a member function getTimestamp() on false`.

### Corrigé

- Correction du flux cmi5 `LMS.LaunchData` : ILIAS peut maintenant écrire et relire les states cmi5 sans erreur Trax liée à `activity_id`.
- Réduction du risque de boucle Apache/adaptateur en documentant l'usage de `trax_base_url` avec un backend interne, par exemple `http://127.0.0.1:8080`.

### Notes de migration depuis 2.0.3

- Ajouter l'AliasMatch `/xapi/activities/state` dans Apache.
- En architecture HTTPS/DNS/reverse proxy, placer les AliasMatch uniquement dans le vhost public et utiliser un vhost interne Trax direct.
- Configurer `trax_base_url` vers l'URL interne Trax, par exemple :
  `'trax_base_url' => 'http://127.0.0.1:8080'`.
- Vérifier que le client Trax possède les capacités nécessaires, notamment `cmi5-tokens/write`, `state/read`, `state/write`, `statements/read` et `statements/write`.

## 2.0.3

- Remplacement des règles Apache RewriteRule par AliasMatch pour intercepter plus fiablement les routes avant Laravel/Trax.
- Support de `/cmi5/tokens` et `/api/statements/aggregate`.

## 2.0.2

- Amélioration du support de l'onglet Ranking ILIAS avec les accumulateurs MongoDB usuels.

## 2.0.1

- Correction du double en-tête `X-Experience-API-Version` sur les appels aggregate.
