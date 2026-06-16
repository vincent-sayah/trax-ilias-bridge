# trax-ilias-bridge

Adaptateur Trax LRS 3 pour ILIAS 10 permettant :

- le pré-lancement cmi5 sans modifier le cœur ILIAS ;
- l'affichage des onglets **Learning Experiences** et **Ranking** des objets xAPI/cmi5 ILIAS.

Cette version conserve les noms de fichiers de la première version du dépôt :

```text
aggregate.php
config.sample.php
apache-trax-ilias-adapter.conf
README.md
```

Le fichier `aggregate.php` n'est plus seulement un endpoint d'agrégation : c'est maintenant le contrôleur unique de l'adaptateur.

---

## 1. Problématique

Dans une intégration **ILIAS 10 + Trax LRS 3**, deux incompatibilités peuvent apparaître.

### 1.1 Pré-lancement cmi5

ILIAS 10 peut envoyer deux paramètres d'activité lors du pré-lancement cmi5 :

```text
activityId
activity_id
```

Trax LRS 3 accepte `activityId`, mais refuse `activity_id` avec une erreur du type :

```text
A request input is not allowed: [activity_id]
```

Conséquence : le pré-lancement cmi5 échoue, le token du proxy xAPI peut être nul, et seuls les statements initiaux comme `launched` apparaissent dans Trax.

L'adaptateur intercepte les appels vers :

```text
/trax/api/gateway/clients/{client}/stores/{store}/cmi5/tokens
```

Il supprime automatiquement `activity_id`, conserve `activityId`, puis transmet la requête à Trax.

### 1.2 Learning Experiences / Ranking

Les statements xAPI sont bien enregistrés dans Trax, mais les onglets ILIAS **Learning Experiences** et **Ranking** peuvent rester vides.

Symptômes typiques dans les logs ILIAS :

```text
ilCmiXapiStatementsGUI::getVerbs: LRS error: <!DOCTYPE html>
ilCmiXapiAbstractRequest::sendRequest: LRS error: <!DOCTYPE html>
```

La cause est que ILIAS appelle un endpoint d'agrégation de type :

```text
/trax/api/gateway/clients/{client}/stores/api/statements/aggregate?pipeline=...
```

ou, selon le contexte :

```text
/trax/api/gateway/clients/{client}/stores/{store}/api/statements/aggregate?pipeline=...
```

Trax expose les statements via l'endpoint xAPI standard :

```text
/trax/api/gateway/clients/{client}/stores/{store}/xapi/statements
```

L'adaptateur traduit donc les requêtes d'agrégation ILIAS vers des lectures xAPI Trax, puis retourne une réponse JSON compatible ILIAS.

---

## 2. Principe de fonctionnement

L'adaptateur est installé sur le serveur Trax. Apache redirige certaines URL Trax vers `aggregate.php` avant qu'elles n'atteignent l'application Trax.

L'adaptateur gère trois routes :

```text
/trax/api/gateway/clients/{client}/stores/{store}/cmi5/tokens
/trax/api/gateway/clients/{client}/stores/{store}/api/statements/aggregate
/trax/api/gateway/clients/{client}/stores/api/statements/aggregate
```

Il transmet ensuite les requêtes corrigées à Trax en conservant les identifiants Basic Auth envoyés par ILIAS.

---

## 3. Fichiers fournis

```text
aggregate.php
config.sample.php
apache-trax-ilias-adapter.conf
README.md
```

---

## 4. Prérequis

- Trax LRS 3 accessible en HTTP ou HTTPS.
- Apache sur le serveur Trax.
- PHP avec l'extension cURL active.
- `mod_rewrite` activé dans Apache.
- Un ou plusieurs clients Trax avec accès Basic HTTP.
- ILIAS 10 configuré avec un endpoint xAPI Trax, par exemple :

```text
http://SERVEUR_TRAX/trax/api/gateway/clients/ppm/stores/ppm/xapi
```

Ne pas configurer l'endpoint ILIAS sur `/cmi5/tokens`.

---

## 5. Installation sur le serveur Trax

Copier les fichiers :

```bash
mkdir -p /var/www/trax-ilias-aggregate-adapter

cp aggregate.php /var/www/trax-ilias-aggregate-adapter/aggregate.php
cp config.sample.php /var/www/trax-ilias-aggregate-adapter/config.php

chown -R apache:apache /var/www/trax-ilias-aggregate-adapter
chmod 0644 /var/www/trax-ilias-aggregate-adapter/aggregate.php
chmod 0640 /var/www/trax-ilias-aggregate-adapter/config.php
```

Installer la configuration Apache :

```bash
cp apache-trax-ilias-adapter.conf /etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf

apachectl configtest
systemctl restart httpd
```

Important : la configuration de l'adaptateur doit être chargée avant les règles générales de Trax/Laravel.

---

## 6. Configuration

Éditer :

```bash
vi /var/www/trax-ilias-aggregate-adapter/config.php
```

Exemple avec deux clients :

```php
return [
    'trax_base_url' => null,

    'aggregate_store_strategy' => 'client_name',

    'client_store_map' => [
        'ppm' => 'ppm',
        'eform' => 'default',
    ],

    'default_store' => 'default',
    'max_statements_to_fetch' => 5000,
    'debug' => false,
];
```

### Paramètres

| Paramètre | Description |
|---|---|
| `trax_base_url` | `null` pour utiliser automatiquement le même protocole et le même hôte que la requête entrante. Peut être forcé à `http://127.0.0.1`. |
| `aggregate_store_strategy` | `client_name` signifie que le store est supposé porter le même nom que le client si ILIAS perd le nom du store. |
| `client_store_map` | Correspondance explicite client Trax → store Trax. Prioritaire sur la stratégie automatique. |
| `default_store` | Store par défaut si la stratégie automatique n'est pas utilisée. |
| `max_statements_to_fetch` | Nombre maximum de statements lus depuis Trax pour construire la réponse ILIAS. |
| `debug` | Si `true`, affiche davantage de détails en cas d'erreur. À laisser à `false` en production. |

---

## 7. Configuration ILIAS

Dans le type LRS ILIAS, configurer l'endpoint xAPI du store Trax :

```text
http://192.168.56.11/trax/api/gateway/clients/ppm/stores/ppm/xapi
```

Avec :

```text
Username: ppm
Password: ********
```

L'objet xAPI/cmi5 doit utiliser ce type LRS.

---

## 8. Tests

### 8.1 Tester l'endpoint xAPI Trax

Depuis le serveur ILIAS :

```bash
curl -i -u ppm:aaaaaaaa \
  -H "X-Experience-API-Version: 1.0.3" \
  "http://192.168.56.11/trax/api/gateway/clients/ppm/stores/ppm/xapi/about"
```

Résultat attendu :

```text
HTTP/1.1 200 OK
{"version":["1.0.3"]}
```

### 8.2 Tester l'agrégation ILIAS

```bash
curl -i -u ppm:aaaaaaaa \
  "http://192.168.56.11/trax/api/gateway/clients/ppm/stores/api/statements/aggregate?pipeline=%5B%7B%22%24group%22%3A%7B%22_id%22%3A%22%24statement.verb.id%22%7D%7D%5D"
```

Résultat attendu :

```text
HTTP/1.1 200 OK
Content-Type: application/json
```

Exemple de réponse :

```json
[
  {"_id":"http://adlnet.gov/expapi/verbs/launched"},
  {"_id":"http://adlnet.gov/expapi/verbs/completed"},
  {"_id":"http://adlnet.gov/expapi/verbs/terminated"}
]
```

### 8.3 Tester le lancement cmi5

1. Restaurer ILIAS sans patch cœur, y compris la ligne `activity_id` si elle existe.
2. Lancer une ressource cmi5 dans ILIAS.
3. Vérifier les logs ILIAS :

```bash
grep -iE "activity_id|cmix|xapi|statement|proxy|LRS error|exception|error" /var/www/logs/ilias.log
```

L'erreur suivante ne doit plus apparaître :

```text
A request input is not allowed: [activity_id]
```

4. Vérifier les statements dans Trax :

```bash
curl -s -u ppm:aaaaaaaa \
  -H "X-Experience-API-Version: 1.0.3" \
  "http://192.168.56.11/trax/api/gateway/clients/ppm/stores/ppm/xapi/statements?limit=20" \
  | jq -r '.statements[] | [.stored, .verb.id, .object.id, (.context.registration // "-")] | @tsv'
```

Tu dois voir des verbs comme :

```text
launched
initialized
progressed
completed
satisfied
terminated
```

---

## 9. Dépannage

### Erreur `activity_id`

Vérifier que la route Apache cmi5 est bien active :

```bash
grep -n "cmi5/tokens" /etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf
```

Puis :

```bash
apachectl configtest
systemctl restart httpd
```

### Boucle ou erreur 500

L'adaptateur transmet à Trax les requêtes avec l'en-tête :

```text
X-Trax-Ilias-Bridge-Bypass: 1
```

La configuration Apache doit contenir la condition :

```apache
RewriteCond %{HTTP:X-Trax-Ilias-Bridge-Bypass} !^1$
```

Sans cette condition, l'adaptateur peut se rappeler lui-même en boucle.

### Erreur 401 ou 403

Vérifier que l'en-tête Authorization est bien transmis à PHP :

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

Vérifier également le login/mot de passe du client Trax dans ILIAS.

### Learning Experiences vide

Vérifier que les statements utilisent le même `object.id` que l'Activity-ID de l'objet ILIAS.

### Ranking vide

Le ranking nécessite un score exploitable, par exemple :

```json
"result": {
  "score": {
    "scaled": 1
  },
  "success": true,
  "completion": true
}
```

Si le contenu cmi5 envoie seulement `completed` avec `duration` mais sans `score.scaled`, le ranking peut rester vide.

---

## 10. Sécurité

- Ne pas publier `config.php` s'il contient des secrets.
- Publier uniquement `config.sample.php`.
- Utiliser HTTPS en production.
- Garder `debug` à `false` en production.
- Limiter l'accès réseau à l'adaptateur si nécessaire.

---

## 11. Licence

À compléter selon la licence choisie pour le dépôt.


### Correction v2.0.1

La version 2.0.1 corrige le cas où ILIAS transmet déjà l'en-tête `X-Experience-API-Version` lors des appels `statements/aggregate`. L'adaptateur ne relaie plus cet en-tête entrant pour le reporting et envoie une seule valeur `1.0.3` vers Trax, afin d'éviter l'erreur Trax : `Incorrect X-Experience-API-Version header: [1.0.3, 1.0.3]`.

### Correction v2.0.2

La version 2.0.2 corrige le traitement de l'onglet **Ranking** d'ILIAS.

ILIAS envoie une pipeline d'agrégation avec un `$group` qui attend des champs calculés comme `account`, `mbox`, `timestamp`, `duration` et `score`. L'adaptateur évalue maintenant les accumulateurs MongoDB nécessaires (`$last`, `$first`, `$push`, `$max`, `$min`, `$sum`) au lieu de retourner uniquement un `_id` distinct. Cela évite l'erreur PHP ILIAS : `Undefined array key "account"`.

### Correction v2.0.3

La version 2.0.3 remplace la configuration Apache basée sur `RewriteRule` par des directives `AliasMatch`.

Cette correction est nécessaire sur certaines installations Trax/Laravel où les règles de réécriture ne sont pas appliquées avant le routeur Laravel. Le symptôme est un retour `HTTP/1.1 404 Not Found` HTML sur :

```text
/trax/api/gateway/clients/{client}/stores/api/statements/aggregate
```

Avec `AliasMatch`, les appels `statements/aggregate` et `cmi5/tokens` sont interceptés directement par Apache et envoyés vers `aggregate.php`.
