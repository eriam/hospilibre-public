/**
 * @package Hospilibre\Installation
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

import Vue from "vue"
import VueI18n from "vue-i18n"

Vue.use(VueI18n)

/**
 * Traductions de l"Installation
 */
export const lang = new VueI18n({
    locale: "fr",
    fallbackLocale: "fr",
    messages: {
        fr: {
            PreventRefreshMsg: "Rafraichir la page va vous dÃ©connecter. ÃŠtes vous sÃ»r de vouloir faire cela ?",
            AssistantInstallation: "Etat de l'instance",
            datetime: "%3/%2/%1 %4:%5:%6",
            Loading: "Chargement...",
            BackToHospilibre: "Retour Ã  Hospilibre",
            Configuration: "Configuration",
            Packages: "Packages",
            Libraries: "Librairies",
            Connexion: "Connexion",
            Login: "Identifiant",
            Password: "Mot de passe",
            ToDisconnect: "Se dÃ©connecter",
            Information: "Informations",
            Installation: "DÃ©pendances",
            ToFilter: "Filtrer",
            Prerequis: "PrÃ©requis",
            ErreurLog: "Journaux",
            PHPExtensions: "Extensions PHP",
            URLRestrictions: "Restrictions URL",
            PHPSQLVersions: "Version PHP/MySQL",
            PHPVersions: "Version PHP",
            SQLVersions: "Version MySQL",
            "SQLVersions-nameRequired": "Requis",
            "SQLVersions-nameInstalled": "InstallÃ©",
            "PHPVersions-nameRequired": "Requis",
            "PHPVersions-nameInstalled": "InstallÃ©",
            "PHPSQLVersions-unchecked": "Version installÃ©e absente ou invalide",
            PathAccess: "Accès aux rÃ©pertoires",
            Void: "Vide",
            "PHPExtensions-name": "Nom",
            "PHPExtensions-description": "Description",
            "PHPExtensions-reasons": "Raisons",
            "PHPExtensions-mandatory": "Obligatoire",
            "PHPExtensions-check": "PrÃ©sent",
            "URLRestrictions-url": "Url",
            "URLRestrictions-description": "Description",
            "URLRestrictions-check": "Accès",
            "PathAccess-path": "Chemin",
            "PathAccess-description": "Description",
            "PathAccess-check": "Accès",
            "Libraries-name": "Nom",
            "Libraries-description": "Description",
            "Libraries-licenseName": "Licence",
            "Libraries-isInstalled": "InstallÃ©",
            "Libraries-isUptodate": "Ã€ jour",
            "Packages-name": "Nom",
            "Packages-description": "Description",
            "Packages-license": "Licence",
            "Packages-isInstalled": "InstallÃ©",
            "Packages-versionRequired": "Version requise",
            "Packages-versionInstalled": "Version installÃ©e",
            "Configs-rootDir": "RÃ©pertoire racine",
            "Configs-baseUrl": "Url racine",
            "Configs-instanceRole": "Rôle de l'instance",
            "Configs-httpRedirections": "Redirections http actives",
            "Configs-bdd-type": "BDD : Type de base de donnÃ©es",
            "Configs-bdd-host": "BDD : Nom d'hôte",
            "Configs-bdd-name": "BDD : Nom de la base",
            "Configs-bdd-user": "BDD : Identifiant utilisateur",
            "Configs-bdd-password": "BDD : Mot de passe",
            "Configs-memory-sharedMemory": "MÃ©moire : MÃ©moire partagÃ©e locale",
            "Configs-memory-sharedMemoryDistributed": "MÃ©moire : MÃ©moire partagÃ©e distribuÃ©e",
            "Configs-memory-sharedMemoryParams": "MÃ©moire : Paramètres mÃ©moire partagÃ©e",
            "Configs-session": "Gestionnaire de sessions",
            "Configs-mutex-mutexSession": "Mutex : Session",
            "Configs-mutex-mutexRedis": "Mutex : Redis",
            "Configs-mutex-mutexApc": "Mutex : APC",
            "Configs-mutex-mutexFiles": "Mutex : Fichiers",
            "Configs-isMaintenance": "Mode maintenance",
            "Configs-isMaintenanceAllowAdmin": "Mode maintenance (accès admin)",
            "Configs-isMigration": "Mode migration",
            Logs: "Logs",
            Errors: "Erreurs",
            "Logs-date": "Date",
            "Logs-level": "Niveau",
            "Logs-message": "Message",
            "Errors-datetime": "Date",
            "Errors-errorType": "Type",
            "Errors-message": "Message",
            "Errors-file": "Fichier",
            "Show more": "Plus",
            "Connexion-login": "Utilisateur",
            "Connexion-password": "Mot de passe",
            "Connexion-errorDefaultMessage": "Une erreur inconnue est survenue.",
            "Connexion-errorCredentialMessage": "L'identifiant ou le mot de passe saisie est incorrect.",
            "ErrorsBuffer-No files": "Tampon d'erreur : Aucun fichier; dernière mise Ã  jour : %1",
            "ErrorsBuffer-At least one file": "Tampon d'erreur : %1 fichier(s), dernière mise Ã  jour : %2",
            "ambiant-PHP": "",
            "ambiant-PHPExtensions": "PECL est une bibliothèque d'extensions binaires de PHP.\n" +
        "La plupart des extensions de base de PHP est fournie avec votre distribution de PHP. N'hÃ©sitez pas Ã  vous rendre sur le site officiel de PHP <i>http://www.php.net/</i> et de PECL <i>http://pecl.php.net/</i> pour obtenir de plus amples informations.",
            "ambiant-URLRestrictions": "Certaines ressources ne devraient pas Ãªtre accessibles autrement que depuis le serveur local. Pour ce faire, il faut autoriser les fichiers <i>.htaccess</i> de Hospilibre Ã  redÃ©finir certaines règles, en spÃ©cifiant <i>AllowOverride All</i> dans les fichiers de configuration Apache pour le rÃ©pÃ©rtoire web.",
            "ambiant-PathAccess": "Le système a besoin de pouvoir Ã©crire un certain nombre de fichiers pour son fonctionnement.",
            "ambiant-Librairies": "Hospilibre utilise de nombreuses bibliothèques externes non publiÃ©es via PEAR. Celles-ci sont fournies dans leur distribution standard puis extraites. N'hÃ©sitez pas Ã  consulter les sites web correspondant pour obtenir de plus amples informations.",
            "ambiant-Paquets": "Hospilibre utilise <a href='%1'>Composer</a> comme gestionnaire de dÃ©pendances. N'hÃ©sitez pas Ã  consulter le site web <a href='%2'>Packagist</a> pour obtenir de plus amples informations.",
            "ambiant-Logs": "Journaux des logs applicatif de Hospilibre",
            "ambiant-Erreurs": "Journaux des logs d'erreurs de Hospilibre"
        }
    }
})
