# trax-ilias-bridge 2.0.5

`trax-ilias-bridge` est un adaptateur Apache/PHP installé sur le serveur **Trax LRS 3** pour améliorer la compatibilité avec les objets **xAPI/cmi5 ILIAS 10**.

Il sert principalement à corriger certains appels générés par ILIAS afin que Trax puisse les accepter, et à fournir à ILIAS une réponse compatible pour les onglets **Learning Experiences** et **Ranking**.

## Résumé fonctionnel

L'adaptateur ne remplace pas Trax et ne modifie pas le stockage des statements xAPI standards. Il intercepte uniquement quelques routes précises utilisées par ILIAS :

```text
/trax/api/gateway/clients/{client}/stores/{store}/cmi5/tokens
/trax/api/gateway/clients/{client}/stores/{store}/xapi/activities/state
/trax/api/gateway/clients/{client}/stores/{store}/api/statements/aggregate
/trax/api/gateway/clients/{client}/stores/api/statements/aggregate
```

Les routes xAPI standards restent servies directement par Trax :

```text
/trax/api/gateway/clients/{client}/stores/{store}/xapi/statements
/trax/api/gateway/clients/{client}/stores/{store}/xapi/about
```

Cela signifie que les traces xAPI non-cmi5 continuent d'être écrites et lues normalement par Trax.

---

## 1. Problèmes corrigés

### 1.1 Pré-lancement cmi5 : correction de `/cmi5/tokens`

ILIAS 10 peut envoyer deux paramètres d'activité lors du pré-lancement cmi5 :

```text
activityId
activity_id
```

Trax LRS 3 accepte `activityId`, mais peut refuser `activity_id` avec une erreur du type :

```text
A request input is not allowed: [activity_id]
```

L'adaptateur intercepte donc :

```text
/trax/api/gateway/clients/{client}/stores/{store}/cmi5/tokens
```

Il supprime uniquement :

```text
activity_id
```

et conserve les paramètres standards attendus par Trax, notamment :

```text
activityId
agent
registration
```

### 1.2 Données de lancement cmi5 : correction de `/xapi/activities/state`

La version 2.0.4 ajoute une correction importante. ILIAS peut aussi envoyer le paramètre non standard `activity_id` sur l'API xAPI State :

```text
/trax/api/gateway/clients/{client}/stores/{store}/xapi/activities/state
```

Cette route est utilisée pour écrire et lire des données cmi5 comme :

```text
LMS.LaunchData
quizProgress
courseAUProgress
cmi5LearnerPreferences
```

Sans correction, Trax peut répondre :

```text
PUT ... stateId=LMS.LaunchData ... 400
GET ... stateId=LMS.LaunchData ... 404
```

Le contenu cmi5 peut alors afficher :

```text
error initializing cmi5 with new session
```

L'adaptateur 2.0.4 intercepte donc aussi `/xapi/activities/state`, supprime `activity_id`, puis transmet la requête corrigée à Trax.

### 1.3 Onglets ILIAS Learning Experiences et Ranking

ILIAS interroge parfois Trax via un endpoint d'agrégation de type :

```text
/trax/api/gateway/clients/{client}/stores/api/statements/aggregate?pipeline=...
```

ou :

```text
/trax/api/gateway/clients/{client}/stores/{store}/api/statements/aggregate?pipeline=...
```

Trax expose les statements via l'endpoint xAPI standard :

```text
/trax/api/gateway/clients/{client}/stores/{store}/xapi/statements
```

L'adaptateur lit donc les statements dans Trax, applique une partie de la logique d'agrégation attendue par ILIAS, puis retourne une réponse JSON compatible.

La version 2.0.4 normalise également certains timestamps dans les réponses Ranking pour éviter l'erreur ILIAS :

```text
Call to a member function getTimestamp() on false
```

La version 2.0.5 corrige en plus le filtrage des lignes Ranking lorsque le pipeline calcule un score numérique avec `$max`, par exemple :

```json
"score": 0.92
```

La version 2.0.4 pouvait supprimer à tort ces lignes, ce qui produisait une réponse vide `[]` malgré la présence de statements scorés dans Trax.

---

## 2. Fichiers fournis

```text
aggregate.php
config.sample.php
apache-trax-ilias-adapter.conf
apache-trax-ilias-adapter-https-internal.example.conf
README.md
CHANGELOG.md
```

### Rôle des fichiers

| Fichier | Rôle |
|---|---|
| `aggregate.php` | Contrôleur principal de l'adaptateur. Il traite les routes interceptées, nettoie `activity_id`, relaie les requêtes vers Trax et construit les réponses d'agrégation pour ILIAS. |
| `config.sample.php` | Modèle de configuration à copier en `config.php` sur le serveur Trax. |
| `apache-trax-ilias-adapter.conf` | Exemple simple de configuration Apache basée sur `AliasMatch`. Adapté aux architectures simples HTTP/IP. |
| `apache-trax-ilias-adapter-https-internal.example.conf` | Exemple complet pour architecture DNS/HTTPS avec vhost interne Trax en `127.0.0.1:8080`. |
| `CHANGELOG.md` | Historique des versions. |

---

## 3. Prérequis

- Trax LRS 3 installé et fonctionnel.
- ILIAS 10 configuré avec un type LRS pointant vers Trax.
- Apache sur le serveur Trax.
- PHP avec l'extension `curl` active.
- Module Apache `mod_alias` actif pour `AliasMatch`.
- Accès root ou sudo sur le serveur Trax.
- Un client Trax disposant des capacités nécessaires.

### Capacités Trax recommandées pour le client utilisé par ILIAS

Le client Trax utilisé par ILIAS doit avoir au minimum :

```text
statements/write
statements/read
state/write
state/read
cmi5-tokens/write
```

Si `cmi5-tokens/write` manque, Trax peut retourner :

```text
The client does not have the 'cmi5-tokens/write' capability.
```

---

## 4. Installation simple HTTP/IP

Cette installation convient à une architecture simple, par exemple :

```text
ILIAS  ->  http://192.168.56.11/trax/...
Trax   ->  Apache HTTP direct
```

### 4.1 Copier les fichiers

Sur le serveur Trax :

```bash
mkdir -p /var/www/trax-ilias-aggregate-adapter

cp aggregate.php /var/www/trax-ilias-aggregate-adapter/aggregate.php
cp config.sample.php /var/www/trax-ilias-aggregate-adapter/config.php

chown -R apache:apache /var/www/trax-ilias-aggregate-adapter
chmod 0644 /var/www/trax-ilias-aggregate-adapter/aggregate.php
chmod 0640 /var/www/trax-ilias-aggregate-adapter/config.php
```

### 4.2 Installer la configuration Apache simple

```bash
cp apache-trax-ilias-adapter.conf /etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf

apachectl configtest
systemctl reload httpd
```

### 4.3 Exemple de `config.php` pour architecture simple

```php
<?php
return [
    'trax_base_url' => null,

    'aggregate_store_strategy' => 'client_name',

    'client_store_map' => [
        'ppm' => 'ppm',
    ],

    'default_store' => 'default',
    'max_statements_to_fetch' => 5000,
    'debug' => false,
];
```

Avec `trax_base_url => null`, l'adaptateur reconstruit l'URL Trax à partir de la requête entrante. Cela fonctionne en général sur une architecture simple HTTP/IP.

---

## 5. Installation sur le serveur Trax en DNS/HTTPS

Ce chapitre est important pour les architectures de production ou d'intégration où Trax est exposé avec un nom DNS et un certificat HTTPS, par exemple :

```text
URL Trax publique : https://trax.example.org
URL ILIAS         : https://ilias.example.org
```

Dans ce type d'architecture, il ne faut pas que l'adaptateur rappelle l'URL publique Trax, sinon Apache peut réintercepter la requête et rappeler de nouveau l'adaptateur. Cela provoque une boucle :

```text
ILIAS / navigateur
    -> https://trax.example.org/cmi5/tokens
    -> Apache intercepte avec AliasMatch
    -> aggregate.php
    -> aggregate.php rappelle https://trax.example.org/cmi5/tokens
    -> Apache réintercepte
    -> boucle
    -> 500 / 504 / timeout
```

La solution consiste à séparer clairement deux accès :

```text
Accès public HTTPS avec adaptateur :
https://trax.example.org

Accès interne Trax direct sans adaptateur :
http://127.0.0.1:8080
```

L'adaptateur reçoit les appels publics ILIAS sur le vhost HTTPS, puis retransmet les requêtes corrigées vers le vhost interne `127.0.0.1:8080`, qui sert Trax directement sans `AliasMatch`.

### 5.1 Architecture cible

```text
Navigateur / ILIAS
        |
        | HTTPS public
        v
Apache vhost *:443
ServerName trax.example.org
        |
        | AliasMatch uniquement sur les routes ILIAS à corriger
        v
/var/www/trax-ilias-aggregate-adapter/aggregate.php
        |
        | HTTP interne sans adaptateur
        v
Apache vhost 127.0.0.1:8080
DocumentRoot /Data/www/traxlrs/public
        |
        v
Trax LRS 3
```

### 5.2 Copier les fichiers de l'adaptateur

Sur le serveur Trax :

```bash
mkdir -p /var/www/trax-ilias-aggregate-adapter

cp aggregate.php /var/www/trax-ilias-aggregate-adapter/aggregate.php
cp config.sample.php /var/www/trax-ilias-aggregate-adapter/config.php

chown -R apache:apache /var/www/trax-ilias-aggregate-adapter
chmod 0644 /var/www/trax-ilias-aggregate-adapter/aggregate.php
chmod 0640 /var/www/trax-ilias-aggregate-adapter/config.php
```

Vérifier la syntaxe PHP :

```bash
php -l /var/www/trax-ilias-aggregate-adapter/aggregate.php
php -l /var/www/trax-ilias-aggregate-adapter/config.php
```

Résultat attendu :

```text
No syntax errors detected
```

### 5.3 Ne pas charger les `AliasMatch` globalement

En architecture DNS/HTTPS, éviter cette configuration :

```text
/etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf
```

si elle contient des `AliasMatch` globaux hors d'un `<VirtualHost>`. Des règles globales peuvent aussi intercepter les appels internes de l'adaptateur.

Si le fichier existe déjà, le sauvegarder puis le désactiver :

```bash
cp /etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf \
   /etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf.bak

mv /etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf \
   /etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf.disabled
```

Les `AliasMatch` doivent être placés dans le vhost HTTPS public, pas globalement.

### 5.4 Exemple complet de configuration Apache DNS/HTTPS

Exemple à adapter à votre environnement. Les chemins de certificats, le `ServerName` et le `DocumentRoot` doivent correspondre à votre serveur.

Fichier possible :

```bash
vi /etc/httpd/conf.d/traxlrs.conf
```

Exemple complet :

```apache
# Vhost interne Trax direct, sans adaptateur.
# L'adaptateur utilisera cette URL en backend : http://127.0.0.1:8080
Listen 127.0.0.1:8080

<VirtualHost *:443>
    ServerName trax.example.org
    DocumentRoot /Data/www/traxlrs/public

    ## Certificats SSL ##
    SSLEngine on
    SSLCertificateFile /etc/pki/tls/certs/cert_front_xapi.crt
    SSLCertificateKeyFile /etc/pki/tls/private/cert_front_xapi.key
    SSLCertificateChainFile /etc/httpd/ssl/ca/CA_CHAIN_XAPI_MN.crt

    # Adaptateur Trax / ILIAS actif uniquement sur le vhost HTTPS public.
    # 1) URL aggregate ILIAS sans store.
    AliasMatch "^/trax/api/gateway/clients/[^/]+/stores/api/statements/aggregate$" "/var/www/trax-ilias-aggregate-adapter/aggregate.php"

    # 2) URL aggregate ILIAS avec store.
    AliasMatch "^/trax/api/gateway/clients/[^/]+/stores/[^/]+/api/statements/aggregate$" "/var/www/trax-ilias-aggregate-adapter/aggregate.php"

    # 3) Pré-lancement cmi5 : suppression de activity_id.
    AliasMatch "^/trax/api/gateway/clients/[^/]+/stores/[^/]+/cmi5/tokens$" "/var/www/trax-ilias-aggregate-adapter/aggregate.php"

    # 4) xAPI State API utilisée par cmi5 : LMS.LaunchData, quizProgress, etc.
    AliasMatch "^/trax/api/gateway/clients/[^/]+/stores/[^/]+/xapi/activities/state$" "/var/www/trax-ilias-aggregate-adapter/aggregate.php"

    <Directory "/var/www/trax-ilias-aggregate-adapter">
        Require all granted
        Options -Indexes
        AllowOverride None
    </Directory>

    <Directory /Data/www/traxlrs/public>
        Options Indexes FollowSymLinks MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    # Permet à PHP de récupérer l'en-tête Authorization.
    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

    ErrorLog /var/log/httpd/trax-ssl_error.log
    CustomLog /var/log/httpd/trax-ssl_access.log combined
</VirtualHost>


<VirtualHost 127.0.0.1:8080>
    ServerName trax-internal.local
    DocumentRoot /Data/www/traxlrs/public

    <Directory /Data/www/traxlrs/public>
        Options Indexes FollowSymLinks MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

    ErrorLog /var/log/httpd/trax-internal_error.log
    CustomLog /var/log/httpd/trax-internal_access.log combined
</VirtualHost>


<VirtualHost *:80>
    ServerName trax.example.org
    Redirect permanent / https://trax.example.org/
</VirtualHost>
```

Le fichier `apache-trax-ilias-adapter-https-internal.example.conf` fourni dans l'archive contient aussi cet exemple.

### 5.5 Configuration `config.php` en DNS/HTTPS

Éditer :

```bash
vi /var/www/trax-ilias-aggregate-adapter/config.php
```

Exemple pour un client Trax `eform` et un store Trax `default` :

```php
<?php
return [
    'trax_base_url' => 'http://127.0.0.1:8080',

    'aggregate_store_strategy' => 'client_name',

    'client_store_map' => [
        'eform' => 'default',
    ],

    'default_store' => 'default',
    'max_statements_to_fetch' => 5000,
    'debug' => false,
];
```

Point important : en DNS/HTTPS, ne pas utiliser :

```php
'trax_base_url' => null,
```

ni :

```php
'trax_base_url' => 'https://trax.example.org',
```

car cela peut faire repasser l'adaptateur par l'URL publique et provoquer une boucle.

La bonne valeur est une URL interne qui atteint Trax directement, sans repasser dans l'adaptateur :

```php
'trax_base_url' => 'http://127.0.0.1:8080',
```

### 5.6 Vérifier et recharger Apache

```bash
apachectl configtest
systemctl reload httpd
```

Résultat attendu :

```text
Syntax OK
```

### 5.7 Tests de validation DNS/HTTPS

#### Test 1 : Trax interne direct

```bash
curl -i "http://127.0.0.1:8080/trax/api/gateway/clients/eform/stores/default/xapi/about"
```

Résultat attendu :

```text
HTTP/1.1 200 OK
{"version":["1.0.3"]}
```

Si ce test retourne une redirection HTTPS ou une erreur 500/504, le vhost interne n'est pas correctement isolé.

#### Test 2 : route publique `/cmi5/tokens`

Un `GET` sur `/cmi5/tokens` peut retourner `405 Method Not Allowed`, car Trax attend un `POST` :

```bash
curl -k -i \
  "https://trax.example.org/trax/api/gateway/clients/eform/stores/default/cmi5/tokens?activity_id=test"
```

Résultat acceptable :

```text
HTTP/1.1 405 Method Not Allowed
allow: POST
```

Ce qui ne doit pas arriver :

```text
500
504
timeout
rafale de requêtes 127.0.0.1 -> 127.0.0.1
```

#### Test 3 : API State avec `activity_id`

Le vrai test se fait en lançant une ressource cmi5 depuis ILIAS. Dans les logs, on veut voir :

```text
PUT ... stateId=LMS.LaunchData ... 204
GET ... stateId=LMS.LaunchData ... 200
```

On ne veut plus voir :

```text
PUT ... stateId=LMS.LaunchData ... 400
GET ... stateId=LMS.LaunchData ... 404
```

#### Test 4 : logs à surveiller

```bash
tail -f /var/log/httpd/trax-ssl_access.log \
       /var/log/httpd/trax-ssl_error.log \
       /var/log/httpd/trax-internal_access.log \
       /var/log/httpd/trax-internal_error.log \
  | grep --line-buffered -E "cmi5/tokens|activities/state|LMS.LaunchData|xapi/statements|aggregate| 400 | 403 | 404 | 500 | 504"
```

### 5.8 Résultats attendus après lancement d'une ressource cmi5

Dans Trax, les statements doivent progressivement apparaître, par exemple :

```text
launched
initialized
answered
progressed
completed
satisfied
terminated
```

L'onglet ILIAS **Learning Experiences** doit afficher les traces.

L'onglet **Ranking** n'affiche un classement que si un statement contient un score global, par exemple :

```json
"result": {
  "score": {
    "scaled": 0.92
  }
}
```

sur l'activité principale.

---

## 6. Configuration ILIAS

Dans ILIAS, configurer le type LRS avec l'endpoint xAPI du store Trax.

Exemple HTTP/IP :

```text
http://192.168.56.11/trax/api/gateway/clients/ppm/stores/ppm/xapi
```

Exemple DNS/HTTPS :

```text
https://trax.example.org/trax/api/gateway/clients/eform/stores/default/xapi
```

Avec les identifiants Basic Auth du client Trax :

```text
Username: eform
Password: ********
```

Ne pas configurer l'endpoint ILIAS directement sur `/cmi5/tokens` ou `/api/statements/aggregate`. ILIAS doit toujours pointer vers `/xapi`.

---

## 7. Configuration détaillée de `config.php`

```php
<?php
return [
    'trax_base_url' => 'http://127.0.0.1:8080',

    'aggregate_store_strategy' => 'client_name',

    'client_store_map' => [
        'eform' => 'default',
        'ppm' => 'ppm',
    ],

    'default_store' => 'default',
    'max_statements_to_fetch' => 5000,
    'debug' => false,
];
```

| Paramètre | Description |
|---|---|
| `trax_base_url` | URL utilisée par l'adaptateur pour appeler Trax. En HTTP/IP simple, `null` peut suffire. En DNS/HTTPS, utiliser une URL interne sans adaptateur, par exemple `http://127.0.0.1:8080`. |
| `aggregate_store_strategy` | Stratégie utilisée si ILIAS appelle `/clients/{client}/stores/api/statements/aggregate` sans préciser le store. `client_name` signifie : store = client. |
| `client_store_map` | Correspondance explicite `client Trax -> store Trax`. Prioritaire sur `aggregate_store_strategy`. Indispensable si le client et le store n'ont pas le même nom, par exemple `eform -> default`. |
| `default_store` | Store utilisé si aucune correspondance n'est trouvée et si la stratégie automatique ne s'applique pas. |
| `max_statements_to_fetch` | Nombre maximum de statements lus pour reconstruire les agrégations ILIAS. Augmenter si nécessaire, en tenant compte des performances. |
| `debug` | Active les erreurs détaillées en JSON. À laisser `false` en production. |

---

## 8. Tests fonctionnels

### 8.1 Tester l'endpoint xAPI Trax

```bash
curl -k -i -u eform:aaaaaaaa \
  -H "X-Experience-API-Version: 1.0.3" \
  "https://trax.example.org/trax/api/gateway/clients/eform/stores/default/xapi/about"
```

Résultat attendu :

```text
HTTP/1.1 200 OK
{"version":["1.0.3"]}
```

### 8.2 Tester l'écriture xAPI standard

```bash
AUTH="eform:aaaaaaaa"
ENDPOINT="https://trax.example.org/trax/api/gateway/clients/eform/stores/default/xapi/statements"
TEST_ID="urn:test:trax-ilias-bridge:$(date +%s)"

curl -k -i -u "$AUTH" \
  -X POST "$ENDPOINT" \
  -H "X-Experience-API-Version: 1.0.3" \
  -H "Content-Type: application/json" \
  -d '{
    "actor": {
      "objectType": "Agent",
      "name": "Test Bridge",
      "mbox": "mailto:test-bridge@example.org"
    },
    "verb": {
      "id": "http://adlnet.gov/expapi/verbs/experienced",
      "display": {"en-US": "experienced"}
    },
    "object": {
      "objectType": "Activity",
      "id": "'"$TEST_ID"'"
    },
    "timestamp": "'"$(date -u +%Y-%m-%dT%H:%M:%S.000Z)"'"
  }'
```

### 8.3 Tester l'agrégation Learning Experiences / Ranking

Exemple simple :

```bash
curl -k -i -u eform:aaaaaaaa \
  "https://trax.example.org/trax/api/gateway/clients/eform/stores/api/statements/aggregate?pipeline=%5B%7B%22%24group%22%3A%7B%22_id%22%3A%22%24statement.verb.id%22%7D%7D%5D"
```

Résultat attendu :

```text
HTTP/1.1 200 OK
Content-Type: application/json
```

### 8.4 Tester le Ranking avec un score global

ILIAS Ranking ne s'affiche que si les statements contiennent un score exploitable :

```json
"result": {
  "score": {
    "scaled": 0.92,
    "raw": 92,
    "min": 0,
    "max": 100
  }
}
```

Le score doit porter sur l'activité principale, pas seulement sur les questions du quiz.

Exemple d'injection d'un score de test :

```bash
AUTH="eform:aaaaaaaa"
ENDPOINT="https://trax.example.org/trax/api/gateway/clients/eform/stores/default/xapi/statements"
ACTIVITY_ID="https://ilias.de/cmi5/activityid/854bac21-99e1-3b23-94b2-54b067df6d23"
STATEMENT_ID="$(uuidgen)"
TS="$(date -u +%Y-%m-%dT%H:%M:%S.000Z)"

curl -k -i -u "$AUTH" \
  -X PUT "$ENDPOINT?statementId=$STATEMENT_ID" \
  -H "X-Experience-API-Version: 1.0.3" \
  -H "Content-Type: application/json" \
  -d '{
    "actor": {
      "objectType": "Agent",
      "name": "root user",
      "account": {
        "homePage": "https://ilias.example.org",
        "name": "ilias@yourserver.com"
      }
    },
    "verb": {
      "id": "http://adlnet.gov/expapi/verbs/completed",
      "display": {"en-US": "completed"}
    },
    "object": {
      "objectType": "Activity",
      "id": "'"$ACTIVITY_ID"'"
    },
    "result": {
      "score": {
        "scaled": 0.92,
        "raw": 92,
        "min": 0,
        "max": 100
      },
      "completion": true,
      "success": true,
      "duration": "PT45S"
    },
    "timestamp": "'"$TS"'"
  }'
```

Après cette injection, l'onglet Ranking d'ILIAS doit afficher une ligne si l'`ACTIVITY_ID` correspond bien à l'activité principale de la ressource ILIAS.

---

## 9. Dépannage

### 9.1 La ressource cmi5 affiche `error initializing cmi5 with new session`

Vérifier les logs Apache :

```bash
tail -f /var/log/httpd/trax-ssl_access.log \
       /var/log/httpd/trax-internal_access.log \
       /var/log/httpd/trax-ssl_error.log \
       /var/log/httpd/trax-internal_error.log \
  | grep --line-buffered -E "cmi5/tokens|activities/state|LMS.LaunchData|xapi/statements| 400 | 403 | 404 | 500 | 504"
```

Points à vérifier :

```text
/cmi5/tokens ne doit pas boucler en 500/504.
PUT LMS.LaunchData doit retourner 204.
GET LMS.LaunchData doit retourner 200.
Le client Trax doit avoir cmi5-tokens/write.
```

### 9.2 Boucle 500/504 entre Apache et l'adaptateur

Symptôme :

```text
127.0.0.1 -> 127.0.0.1 ... 500
127.0.0.1 -> 127.0.0.1 ... 504
```

Cause probable : `trax_base_url` pointe vers une URL qui repasse dans les `AliasMatch` de l'adaptateur.

Correction :

```php
'trax_base_url' => 'http://127.0.0.1:8080',
```

et un vhost interne `127.0.0.1:8080` sans `AliasMatch`.

### 9.3 Erreur `The client does not have the 'cmi5-tokens/write' capability`

Ajouter la capacité suivante au client Trax utilisé par ILIAS :

```text
cmi5-tokens/write
```

Vérifier aussi :

```text
statements/write
statements/read
state/write
state/read
```

### 9.4 Learning Experiences fonctionne mais Ranking est vide

C'est généralement normal si le contenu cmi5 n'envoie pas de score global.

Le Ranking ILIAS cherche un statement avec :

```text
statement.result.score.scaled
```

sur l'activité principale.

Les statements `answered` sur les questions peuvent contenir `success: true`, mais cela ne suffit pas forcément à alimenter le Ranking.

### 9.5 Ranking plante avec `getTimestamp() on false`

La version 2.0.4 normalise les timestamps retournés dans les réponses Ranking. Si l'erreur apparaît encore, vérifier les statements contenant un score :

```bash
curl -k -s -u "eform:aaaaaaaa" \
  -H "X-Experience-API-Version: 1.0.3" \
  "https://trax.example.org/trax/api/gateway/clients/eform/stores/default/xapi/statements?limit=100" \
  | jq '.statements[]
    | select(.result.score.scaled != null)
    | {
        id: .id,
        verb: .verb.id,
        object: .object.id,
        timestamp: .timestamp,
        stored: .stored,
        score: .result.score
      }'
```

Le timestamp recommandé est :

```text
2026-06-17T10:07:52.000Z
```

---

## 10. Sécurité et exploitation

- Ne pas publier `config.php` s'il contient des informations sensibles.
- Publier uniquement `config.sample.php` dans le dépôt.
- Garder `debug` à `false` en production.
- Utiliser HTTPS pour l'accès public.
- Limiter l'accès au vhost interne `127.0.0.1:8080` à l'interface locale uniquement.
- Vérifier les droits SELinux si Apache ne peut pas lire ou exécuter les fichiers de l'adaptateur.

Commandes utiles SELinux :

```bash
getenforce
ls -lZ /var/www/trax-ilias-aggregate-adapter/
restorecon -Rv /var/www/trax-ilias-aggregate-adapter
```

Si nécessaire :

```bash
chcon -R -t httpd_sys_content_t /var/www/trax-ilias-aggregate-adapter
```

---

## 11. Historique rapide

### 2.0.1

Correction du doublon d'en-tête `X-Experience-API-Version` sur les appels d'agrégation.

### 2.0.2

Amélioration du support des pipelines Ranking ILIAS avec `$group`, `$last`, `$first`, `$push`, `$sum`, `$min`, `$max`.

### 2.0.3

Remplacement des `RewriteRule` par `AliasMatch` pour garantir l'interception avant Laravel/Trax.

### 2.0.5

- Correction du filtrage Ranking lorsque `score` est un nombre calculé par `$max`, par exemple `0.92`.
- La version 2.0.4 pouvait retourner `[]` pour un pipeline Ranking valide, car elle attendait à tort un tableau pour le champ `score`.
- En-tête HTTP de diagnostic mis à jour : `X-Trax-Ilias-Bridge-Version: 2.0.5`.

### 2.0.4

Ajout du support `/xapi/activities/state`, correction de `LMS.LaunchData`, exemple complet DNS/HTTPS avec backend interne `127.0.0.1:8080`, et normalisation des timestamps Ranking.

---

