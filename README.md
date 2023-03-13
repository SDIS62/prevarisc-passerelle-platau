# Passerelle Prevarisc - Plat'AU

Le dépôt et l’instruction en ligne de toutes les demandes d'autorisations d’urbanisme, dit programme Démat.ADS, répond aux enjeux de simplification et de modernisation des services publics.

Pour permettre la dématérialisation de l’ensemble de la chaîne d’instruction, l’Etat développe une API dite PLAT’AU, pour PLATeforme des Autorisations d’Urbanisme.

Véritable « hub », PLAT’AU permet l’accès en temps réel aux dossiers par l’ensemble des acteurs concernés par une demande d’autorisation d’urbanisme (services instructeurs des collectivités, services déconcentrés de l’Etat, UDAP, SDIS, contrôle de légalité…).

La connexion entre Prevarisc et Plat'AU est indispensable pour bénéficier d'une connexion unique à l'ensemble de l'écosystème de l'instruction.

Cette connexion est matérialisée par une passerelle permettant d'automatiser les échanges entre Prevarisc et Plat'AU. 

### Fonctionnalités

* Récupération automatique des dossiers liés à une demande de consultation par un service instructeur ;
* Envoi de notifications à Plat'AU de la bonne prise en compte de la consultation ;
* Communique les avis de la commission de sécurité automatiquement vers Plat'AU.

### Compatibilité avec les versions Plat'AU

Nous avons aligné les numéros de versions majeures de la passerelle avec celles de Plat'AU.

Par exemple :
- sdis62/prevarisc-passerelle-platau v8.x et Plat'AU v8.x sont compatibles
- sdis62/prevarisc-passerelle-platau v9.x et Plat'AU v9.x sont compatibles
- sdis62/prevarisc-passerelle-platau v10.x et Plat'AU v10.x sont compatibles

Ainsi de suite ...

> Ps : Les numéros de versions mineures et patches représentés par des x n'ont pas besoin d'être identiques, seule la version majeure compte.

## Documentation

### Installation
La méthode recommandée pour installer la passerelle est d'utiliser Git et [Composer](https://getcomposer.org/).

```
$ git clone https://github.com/SDIS62/prevarisc-passerelle-platau
$ composer install -n --no-dev --no-suggest --no-progress --no-scripts
```

Il est nécessaire d'appliquer un patch mineur sur l'applicatif Prevarisc avant d'utiliser la passerelle.

### Configuration
Afin d'utiliser la passerelle vous aurez besoin d'identifants [PISTE](https://piste.gouv.fr/) ainsi qu'un identifant Acteur Plat'AU. Naturellement, pour que la passerelle puisse se connecter à Prevarisc, elle aura besoin de s'authentifier sur la base de données.
Voici un exemple d'un fichier JSON de configuration :
```json
{
    "platau.options": {
        "PISTE_CLIENT_ID": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
        "PISTE_CLIENT_SECRET": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
        "PLATAU_ID_ACTEUR_APPELANT": "xxx-xxx-xxx"
    },
    "prevarisc.options": {
        "PREVARISC_DB_NAME": "xxxxxxxxxx",
        "PREVARISC_DB_USER": "xxxxxxxxxx",
        "PREVARISC_DB_PASSWORD": "xxxxxxxxxx",
        "PREVARISC_DB_HOST": "xxxxxxxxxx",
        "PREVARISC_DB_DRIVER": "pdo_mysql",
        "PREVARISC_DB_CHARSET": "utf8",
        "PREVARISC_DB_PORT": 3306,
        "PREVARISC_DB_PLATAU_USER_ID": 0,
        "PREVARISC_PIECES_JOINTES_PATH": "xxxxxxxxxx"
    }
}
```

Pour s'assurer que la configuration est bonne, vous pouvez demander un healthcheck comme ceci :
```
$ php bin/platau --config=CHEMIN_RELATIF_VERS_LE_FICHIER_DE_CONFIGURATION.json healthcheck
> RAS. Tout est disponible et prêt à l'emploi !
```

>L'ensemble des communications entre Plat'AU et la passerelle nécessitent l'identification de l'acteur métier concerné (commission de sécurité, SDIS ...). C'est cet identifiant qu'il faut placer dans la valeur de configuration ```PLATAU_ID_ACTEUR_APPELANT```. Afin de faciliter l'enregistrement du SDIS dans l'univers Plat'AU, vous pouvez utiliser une commande d'enrôlement :
>```
>$ php bin/platau --designation=DESIGNATION_DU_SDIS --mail=xxxxx@sdisxx.fr --siren=XXXXXXXXX --config=CHEMIN_RELATIF_VERS_LE_FICHIER_DE_CONFIGURATION.json enroler-acteur
>```

### Utilisation

#### Consultations

Pour importer les demandes de consultations en cours dans Prevarisc :
```
$ php bin/platau --config=CHEMIN_RELATIF_VERS_LE_FICHIER_DE_CONFIGURATION.json import
```

Cela va créer des dossiers d'étude dans Prevarisc correspondant aux demandes de consultation des services consultants.
Ces dossiers sont pré-remplis avec certaines méta-données présentes dans les consultations.
Par défaut, il n'y a pas d'indication de complétude du dossier (complet / incomplet). Il appartient de saisir cette donnée dans Prevarisc afin d'envoyer dans un second temps des Prises En Compte métier dans Plat'AU.

#### Récupération des pièces jointes

Pour télécharger les pièces jointes liées aux consultations importées dans Prevarisc :
```
$ php bin/platau --config=CHEMIN_RELATIF_VERS_LE_FICHIER_DE_CONFIGURATION.json [--force-non-pec] import-pieces
```

#### Prises en compte métier

Les dossiers d'études importés dans lesquels une information de complétude (complet / incomplet) est indiquée feront l'objet d'une Prise En Compte métier dans Plat'AU.
Concrétement, on indique au service consultant que le dossier sera traité (ou pas) et dans quels délais (par défaut la date limite de réponse indiquée par le service instructeur).

Pour envoyer les Prises En Compte des consultations dans Plat'AU :
```
$ php bin/platau --config=CHEMIN_RELATIF_VERS_LE_FICHIER_DE_CONFIGURATION.json [--consultation-id=xxx-xxx-xxx] export-pec
```

#### Avis de commission

Les avis de commission (ainsi que les prescriptions) des dossiers d'étude liés à des consultations Plat'AU seront envoyés afin de notifier le service consultant de la fin de la consultation.

Pour envoyer les avis de commission dans Plat'AU :
```
$ php bin/platau --config=CHEMIN_RELATIF_VERS_LE_FICHIER_DE_CONFIGURATION.json [--consultation-id=xxx-xxx-xxx] export-avis
```

#### Détails d'une consultation

Afin de vérifier l'état d'une consultation dans Plat'AU, la passerelle permet de récupérer facilement l'ensemble des données la concernant afin de les lire directement dans la console.

L'option facultative "champ" ordonne à la commande de ne retourner qu'un champ bien spécifique, en utilisant la syntaxe "dot notation" (par exemple, pour accéder au libellé de l'état d'une consultation, il faut spécifier "dossier.consultations.0.nomEtatConsultation.libNom").

```
$ php bin/platau --config=CHEMIN_RELATIF_VERS_LE_FICHIER_DE_CONFIGURATION.json details-consultation [--champ=xxx.xxx.xxx] xxx-xxx-xxx
```
