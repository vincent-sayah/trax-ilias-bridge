# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s’inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/), et le projet suit une logique de versionnement sémantique autant que possible.

## [2.0.3] - 2026-06-16

### Corrigé

- Correction de la configuration Apache de l’adaptateur.
- Remplacement des règles `RewriteRule` par des règles `AliasMatch`, plus adaptées à l’installation Trax/Laravel utilisée.
- Correction du problème où les appels ILIAS vers :

  ```text
  /trax/api/gateway/clients/{client}/stores/api/statements/aggregate
  ```

  étaient transmis à Trax/Laravel au lieu d’être interceptés par l’adaptateur.
- Correction du retour `404 Not Found` côté Learning Experiences et Ranking lorsque l’endpoint `/api/statements/aggregate` n’était pas capturé par Apache.

### Validé

- L’endpoint d’agrégation répond correctement en `HTTP 200 OK`.
- L’en-tête `X-Trax-Ilias-Bridge-Version: 2.0.2` ou supérieur est bien renvoyé par l’adaptateur.
- Les onglets ILIAS `Learning Experiences` et `Ranking` fonctionnent après installation de cette version.

## [2.0.2] - 2026-06-16

### Ajouté

- Ajout d’un traitement plus complet des pipelines d’agrégation utilisés par le Ranking ILIAS.
- Support des champs attendus par ILIAS pour l’affichage du classement :
  - `account`
  - `mbox`
  - `username`
  - `timestamp`
  - `duration`
  - `score`

### Corrigé

- Correction de l’erreur ILIAS :

  ```text
  Undefined array key "account"
  ```

- Correction du format de réponse attendu par le rapport Highscore / Ranking ILIAS.
- Amélioration de la compatibilité avec les statements xAPI contenant des acteurs sous différentes formes :
  - `actor.account`
  - `actor.mbox`
  - `actor.name`

### Validé

- L’onglet `Ranking` affiche désormais le classement, le participant, la date, le pourcentage et le temps passé.

## [2.0.1] - 2026-06-16

### Corrigé

- Correction du problème de double en-tête xAPI :

  ```text
  Incorrect X-Experience-API-Version header: [1.0.3, 1.0.3]
  ```

- L’adaptateur ne relaie plus l’en-tête entrant `X-Experience-API-Version` lorsqu’il ajoute lui-même la valeur attendue par Trax.
- Les appels ILIAS vers l’endpoint d’agrégation ne provoquent plus de rejet côté Trax à cause d’un doublon d’en-tête.

### Validé

- L’onglet `Learning Experiences` peut récupérer les verbes et les statements depuis Trax LRS 3.

## [2.0.0] - 2026-06-16

### Ajouté

- Nouvelle version majeure de l’adaptateur.
- Ajout de la gestion du pré-lancement cmi5 via l’endpoint :

  ```text
  /trax/api/gateway/clients/{client}/stores/{store}/cmi5/tokens
  ```

- Suppression automatique du paramètre incompatible envoyé par ILIAS 10 :

  ```text
  activity_id
  ```

- Conservation du paramètre compatible :

  ```text
  activityId
  ```

- Transmission corrigée de la requête vers Trax LRS 3.
- Ajout d’une logique multi-clients / multi-stores via `config.php`.

### Corrigé

- Correction du problème de pré-lancement cmi5 entre ILIAS 10 et Trax LRS 3 :

  ```text
  A request input is not allowed: [activity_id]
  ```

- Correction indirecte du problème de token proxy nul côté ILIAS :

  ```text
  XapiProxy::__construct(): Argument #2 ($token) must be of type string, null given
  ```

### Important

- Cette version permet de conserver ILIAS non modifié.
- Il n’est plus nécessaire de patcher directement le fichier cœur ILIAS :

  ```text
  components/ILIAS/CmiXapi/classes/class.ilCmiXapiLaunchGUI.php
  ```

## [1.0.0] - 2026-06-15

### Ajouté

- Première version de l’adaptateur Trax / ILIAS.
- Ajout d’un endpoint de compatibilité pour les appels ILIAS vers :

  ```text
  /api/statements/aggregate
  ```

- Conversion des appels d’agrégation ILIAS vers les statements xAPI disponibles dans Trax LRS 3.
- Support initial de l’onglet `Learning Experiences`.
- Configuration par fichier `config.php`.
- Support des correspondances client Trax vers store Trax :

  ```php
  'client_store_map' => [
      'eform' => 'default',
      'ppm' => 'ppm',
  ],
  ```

### Limites connues

- Cette première version ne corrigeait pas encore le problème `activity_id` du pré-lancement cmi5.
- Le Ranking ILIAS n’était pas encore complètement pris en charge.
- Certaines configurations Apache pouvaient laisser passer les URLs d’agrégation vers Trax/Laravel au lieu de les envoyer vers l’adaptateur.

## Notes de compatibilité

### ILIAS

- Testé avec ILIAS 10.
- L’adaptateur cible principalement les objets xAPI/cmi5 et les onglets :
  - `Learning Experiences`
  - `Ranking`

### Trax LRS

- Testé avec Trax LRS 3.
- L’adaptateur est prévu pour les endpoints de type :

  ```text
  /trax/api/gateway/clients/{client}/stores/{store}/xapi
  /trax/api/gateway/clients/{client}/stores/{store}/cmi5/tokens
  ```

### Apache

- À partir de la version `2.0.3`, la configuration recommandée utilise `AliasMatch`.
- Les règles de l’adaptateur doivent être chargées avant que les routes Laravel/Trax ne traitent les URLs concernées.

## Migration recommandée

Pour une installation existante, utiliser la version `2.0.3` ou supérieure.

Étapes générales :

```bash
cp aggregate.php /var/www/trax-ilias-aggregate-adapter/aggregate.php
cp config.sample.php /var/www/trax-ilias-aggregate-adapter/config.php
cp apache-trax-ilias-adapter.conf /etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf

apachectl configtest
systemctl restart httpd
```

Adapter ensuite `config.php` selon les clients et stores Trax utilisés.

Exemple :

```php
'client_store_map' => [
    'eform' => 'default',
    'ppm' => 'ppm',
],
```
