# trax-ilias-bridge
Adaptateur Trax LRS 3 pour ILIAS 10 permettant l’affichage des Learning Experiences xAPI/cmi5.

## 1. Problematique

Dans une integration ILIAS 10 + Trax LRS 3, le lancement cmi5 peut fonctionner correctement :

- ILIAS lance le module cmi5.
- Le module envoie ses statements xAPI vers Trax.
- Trax affiche bien les statements `initialized`, `progressed`, `completed`, `satisfied`, `terminated`, etc.

Cependant, les onglets ILIAS **Learning Experiences** et **Ranking** peuvent rester vides. Le symptome typique dans les logs ILIAS est :

```text
ilCmiXapiStatementsGUI::getVerbs: LRS error: <!DOCTYPE html>
ilCmiXapiAbstractRequest::sendRequest: LRS error: <!DOCTYPE html>
```

La cause est l'endpoint de reporting utilise par ILIAS. ILIAS appelle un endpoint d'agregation de type :

```text
/trax/api/gateway/clients/{client}/stores/api/statements/aggregate?pipeline=...
```

ou, avec un correctif optionnel ILIAS :

```text
/trax/api/gateway/clients/{client}/stores/{store}/api/statements/aggregate?pipeline=...
```

Trax LRS expose les statements via l'endpoint xAPI standard du store :

```text
/trax/api/gateway/clients/{client}/stores/{store}/xapi/statements
```

Trax ne fournit pas nativement l'endpoint Mongo-style `statements/aggregate?pipeline=...` attendu par ILIAS. L'adaptateur comble cet ecart.

## 2. Principe de l'adaptateur

L'adaptateur est installe sur le serveur Trax. Apache redirige les appels ILIAS vers un script PHP `aggregate.php`.

Le script :

1. recoit l'appel ILIAS `statements/aggregate?pipeline=...` ;
2. deduit le client Trax depuis l'URL ;
3. deduit le store depuis l'URL ou depuis `config.php` ;
4. appelle l'endpoint Trax xAPI `/xapi/statements` ;
5. applique localement une partie des pipelines ILIAS ;
6. retourne un JSON au format attendu par ILIAS.

Les identifiants Basic Auth envoyes par ILIAS sont transmis a Trax. Il est donc possible d'utiliser plusieurs clients Trax avec des identifiants differents.

## 3. Fichiers fournis

```text
aggregate.php
config.sample.php
apache-trax-ilias-adapter.conf
README.md
```

## 4. Prerequis

- Serveur Trax LRS 3 accessible en HTTP ou HTTPS.
- Apache sur le serveur Trax.
- PHP avec l'extension cURL active.
- Un client Trax configure avec les droits necessaires pour lire les statements via l'API xAPI.
- ILIAS 10 configure avec un type LRS pointant vers l'endpoint xAPI Trax :

```text
http://SERVEUR_TRAX/trax/api/gateway/clients/{client}/stores/{store}/xapi
```

## 5. Installation sur le serveur Trax

Copier les fichiers de l'adaptateur :

```bash
mkdir -p /var/www/trax-ilias-aggregate-adapter
cp aggregate.php /var/www/trax-ilias-aggregate-adapter/aggregate.php
cp config.sample.php /var/www/trax-ilias-aggregate-adapter/config.php
chown -R apache:apache /var/www/trax-ilias-aggregate-adapter
chmod 0644 /var/www/trax-ilias-aggregate-adapter/aggregate.php
chmod 0640 /var/www/trax-ilias-aggregate-adapter/config.php
```

Copier la configuration Apache :

```bash
cp apache-trax-ilias-adapter.conf /etc/httpd/conf.d/trax-ilias-aggregate-adapter.conf
apachectl configtest
systemctl restart httpd
```

## 6. Configuration Apache

Le fichier `apache-trax-ilias-adapter.conf` contient deux routes :

```apache
AliasMatch "^/trax/api/gateway/clients/([^/]+)/stores/([^/]+)/api/statements/aggregate$" "/var/www/trax-ilias-aggregate-adapter/aggregate.php"
AliasMatch "^/trax/api/gateway/clients/([^/]+)/stores/api/statements/aggregate$" "/var/www/trax-ilias-aggregate-adapter/aggregate.php"

<Directory "/var/www/trax-ilias-aggregate-adapter">
    Require all granted
    Options -Indexes
</Directory>
```

La premiere route est recommandee : elle contient le client et le store.
La deuxieme route gere le comportement natif d'ILIAS 10, qui peut perdre le nom du store lors de la construction de l'URL d'agregation.

Si l'en-tete `Authorization` n'arrive pas a PHP avec FastCGI, ajouter ou decommenter :

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

## 7. Configuration de l'adaptateur

Editer :

```bash
vi /var/www/trax-ilias-aggregate-adapter/config.php
```

Configuration minimale :

```php
return [
    'trax_base_url' => null,
    'default_store' => 'default',
    'client_store_map' => [
        // 'eform' => 'default',
        // 'client2' => 'production',
    ],
    'max_statements_to_fetch' => 5000,
    'debug' => false,
];
```

### Parametres

| Parametre | Description |
| --- | --- |
| `trax_base_url` | `null` pour utiliser automatiquement le meme protocole et hote que la requete entrante. Peut etre force a `http://127.0.0.1` si Trax est local. |
| `default_store` | Store utilise quand l'URL ILIAS ne contient pas le nom du store. |
| `client_store_map` | Correspondance client -> store pour les environnements multi-clients ou multi-stores. |
| `max_statements_to_fetch` | Nombre maximum de statements charges depuis Trax pour construire la reponse. |
| `debug` | Si `true`, renvoie davantage de details en cas d'erreur. A desactiver en production. |

## 8. Plusieurs clients Trax

Si tous les clients utilisent le store `default`, aucune configuration speciale n'est necessaire :

```php
'default_store' => 'default',
```

Exemples automatiquement supportes :

```text
/trax/api/gateway/clients/eform/stores/api/statements/aggregate
/trax/api/gateway/clients/client2/stores/api/statements/aggregate
/trax/api/gateway/clients/client3/stores/api/statements/aggregate
```

L'adaptateur construira :

```text
/trax/api/gateway/clients/eform/stores/default/xapi/statements
/trax/api/gateway/clients/client2/stores/default/xapi/statements
/trax/api/gateway/clients/client3/stores/default/xapi/statements
```

Si certains clients utilisent un autre store :

```php
'client_store_map' => [
    'eform' => 'default',
    'client2' => 'production',
    'client3' => 'lrs2',
],
```

## 9. Patch optionnel ILIAS pour conserver le store dans l'URL

Fichier ILIAS :

```text
/var/www/ilias/components/ILIAS/CmiXapi/classes/class.ilCmiXapiLrsType.php
```

Methode originale :

```php
public function getLrsEndpointStatementsAggregationLink(): string
{
    return dirname($this->getLrsEndpoint(), 2) . '/api/' . self::ENDPOINT_AGGREGATE_SUFFIX;
}
```

Methode recommandee :

```php
public function getLrsEndpointStatementsAggregationLink(): string
{
    return preg_replace('~/xapi/?$~', '/api/' . self::ENDPOINT_AGGREGATE_SUFFIX, $this->getLrsEndpoint());
}
```

Avec ce patch, si l'endpoint LRS ILIAS est :

```text
http://192.168.56.11/trax/api/gateway/clients/eform/stores/default/xapi
```

ILIAS appellera :

```text
http://192.168.56.11/trax/api/gateway/clients/eform/stores/default/api/statements/aggregate
```

L'adaptateur pourra alors deduire automatiquement le client `eform` et le store `default`.

## 10. Test depuis le serveur ILIAS

Test de recuperation des verbes :

```bash
curl -i -u eform:aaaaaaaa \
  "http://192.168.56.11/trax/api/gateway/clients/eform/stores/api/statements/aggregate?pipeline=%5B%7B%22%24group%22%3A%7B%22_id%22%3A%22%24statement.verb.id%22%7D%7D%5D"
```

Resultat attendu :

```text
HTTP/1.1 200 OK
Content-Type: application/json
```

Exemple de reponse :

```json
[
  {"_id":"http://adlnet.gov/expapi/verbs/completed"},
  {"_id":"http://adlnet.gov/expapi/verbs/terminated"}
]
```

## 11. Verification dans ILIAS

1. Ouvrir un objet xAPI/cmi5 dans ILIAS.
2. Lancer le module cmi5 pour generer des statements.
3. Verifier dans Trax que les statements existent.
4. Ouvrir l'onglet ILIAS **Learning Experiences**.
5. Verifier que les statements apparaissent.

Pour le ranking, le module cmi5 doit envoyer un score exploitable :

```json
"result": {
  "score": {
    "scaled": 1
  },
  "success": true,
  "completion": true
}
```

## 12. Depannage

### L'onglet ILIAS affiche encore une erreur LRS

Verifier les logs ILIAS :

```bash
grep -iE "cmix|xapi|statement|verb|ranking|LRS error|exception|error" /var/www/logs/ilias.log
```

Activer temporairement le debug dans `config.php` :

```php
'debug' => true,
```

### Erreur 401 ou 403

Verifier que l'en-tete Basic Auth est bien transmis par Apache a PHP.

Tester :

```bash
curl -i -u eform:aaaaaaaa "URL_AGGREGATE"
```

### Aucun statement affiche

Verifier que les statements Trax utilisent le meme `object.id` que l'Activity-ID de l'objet ILIAS.

```bash
curl -s -u eform:aaaaaaaa \
  -H "X-Experience-API-Version: 1.0.3" \
  "http://192.168.56.11/trax/api/gateway/clients/eform/stores/default/xapi/statements?limit=20" \
  | jq -r '.statements[] | [.stored, .verb.id, .object.id, (.context.registration // "-")] | @tsv'
```

## 13. Limites connues

- L'adaptateur implemente uniquement la partie des pipelines ILIAS necessaire aux onglets Learning Experiences et Ranking.
- Le ranking depend de la presence de `result.score.scaled` dans les statements.
- L'adaptateur charge les statements via pagination xAPI puis filtre localement ; ajuster `max_statements_to_fetch` selon le volume.
- Pour des environnements de production volumineux, prevoir une strategie de cache ou un endpoint Trax dedie.

## 14. Securite

- Ne pas publier `config.php` avec des secrets.
- Utiliser HTTPS en production.
- Limiter l'acces Apache a l'adaptateur si necessaire.
- Laisser `debug` a `false` en production.

## 15. Licence

A definir selon le depot GitHub. Une licence MIT est recommandee si tu souhaites publier librement l'adaptateur.
