# config/update

Configurazioni che devono restare **sempre** quelle nostre, anche dopo un
aggiornamento della distribuzione Ouitoulía.

## Differenza con le altre cartelle

| Cartella | Chi la legge | Quando |
| --- | --- | --- |
| `config/install` | core | all'installazione del modulo; errore se la configurazione esiste già |
| `config/optional` | core | all'installazione e quando vengono abilitati i moduli da cui dipende; salta le configurazioni già presenti |
| `config/update` | questo modulo | **a ogni** `drush updb`, tramite `src/Drush/Commands/CustomConfigCommands.php` |

Una configurazione va messa **o** in `config/optional` **o** qui, non in
entrambe: `config/optional` viene ignorata se la configurazione esiste già,
quindi per ciò che va riallineato a ogni aggiornamento fa fede solo questa
cartella.

## Come si scrivono i file

Si esportano da un sito allineato, per esempio:

```bash
ddev drush config:get views.view.aree_tematiche --format=yaml > \
  web/modules/custom/custom_config/config/update/views.view.aree_tematiche.yml
```

Poi si **rimuovono le chiavi `uuid:` e `_core:`**, come nei file di
`exesti/config/update` e `prosis/config/update`. Servono a rendere il file
applicabile a scuole diverse, dove la stessa configurazione ha uuid diversi:
per le entità già presenti core conserva l'uuid del sito.

## Come si applicano

Automaticamente **a ogni** `ddev drush updb`, anche quando non ci sono
aggiornamenti in sospeso: se ne occupa l'hook post-comando in
`src/Drush/Commands/CustomConfigCommands.php`. Oppure a mano:

```bash
ddev drush php:eval '_custom_config_import_config_update();'
```

Le configurazioni presenti vengono sovrascritte, quelle mancanti create.
Nessuna configurazione viene mai cancellata.