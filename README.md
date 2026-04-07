# University Club Manager

Application web PHP/MySQL pour gerer des clubs universitaires, des demandes d'adhesion, des evenements et des notifications.

## A propos

Le projet couvre le cycle principal d'un club universitaire:
- Authentification et gestion de profil.
- Creation et administration des clubs.
- Gestion des demandes d'adhesion.
- Creation et gestion d'evenements.
- Notifications utilisateur.

## Fonctionnalites

### Etudiant
- Inscription et connexion.
- Verification d'email obligatoire apres inscription (activation de compte).
- Renvoi de l'email de verification depuis la page de connexion.
- Recuperation de mot de passe par email (lien de reinitialisation).
- Reinitialisation du mot de passe via lien securise a duree limitee.
- Connexion avec Google (OAuth 2.0 serveur).
- Consultation des clubs et evenements.
- Demande d'adhesion a un club.
- Quitter un club.
- Inscription / desinscription a un evenement.
- Consultation des notifications.

### Roles et permissions

`admin`
- Acces global a tous les clubs, evenements, demandes et utilisateurs.
- Acces au panneau d'administration (`backend/admin.php`).
- Gestion des responsables (attribution/retrait) sur tous les clubs.

`responsable`
- Gestion des clubs dont il est responsable (multi-clubs supportes).
- Validation/rejet des demandes d'adhesion sur ses clubs.
- Gestion des participants et des evenements de ses clubs.

`etudiant`
- Consultation des clubs/evenements.
- Demande d'adhesion a un club.
- Participation/desinscription aux evenements selon les regles d'acces.

### Interface
- Design responsive (desktop/tablette/mobile).
- Recherche sur les listes (clubs/evenements).
- Tri des tableaux avec `scripts/sortable-table.js`.
- Tri exclusif par colonne sur les tableaux de details des clubs et evenements: role ou date d'adhesion/inscription, avec ordre ascendant/descendant.
- Affichage des roles avec badges coherents dans les tableaux de details.
- Carousel d'accueil avec navigation et defilement auto.

## Stack technique

- PHP 7.4+
- MySQL 5.7+
- Apache (XAMPP recommande sous Windows)
- HTML5, CSS3, JavaScript

## Securite

- Hash des mots de passe avec `password_hash()`.
- Requetes preparees PDO.
- Sessions PHP et controles d'acces.
- Echappement des sorties HTML.
- Tokens de verification email et reset mot de passe stockes en hash (`sha256`) avec expiration.
- Blocage de connexion tant que `email_verified_at` est vide (comptes email/mot de passe).

## Installation rapide (XAMPP, Windows)

1. Copier le dossier du projet dans `C:\xampp\htdocs\` (exemple: `C:\xampp\htdocs\University-Club-Manager`).
2. Demarrer Apache et MySQL depuis XAMPP Control Panel.
3. Importer `database/schema.sql` dans phpMyAdmin (ou via client SQL).
4. Verifier les identifiants de connexion dans `config/Database.php`.
5. Ouvrir l'application: `http://localhost/University-Club-Manager/backend/index.php`

## Structure actuelle du projet

```text
University-Club-Manager/
├── backend/
│   ├── index.php
│   ├── dashboard.php
│   ├── requests.php
│   ├── notif_count.php
│   ├── notif_data.php
│   ├── club_php/
│   ├── event_php/
│   └── profile_php/
│       ├── login.php
│       ├── register.php
│       ├── forgot_password.php
│       ├── reset_password.php
│       ├── resend_verification.php
│       ├── verify_email.php
│       ├── verification_sent.php
│       ├── google_login.php
│       └── google_callback.php
├── classes/
│   ├── Club.php
│   ├── Event.php
│   ├── MembershipRequest.php
│   ├── User.php
│   ├── PasswordResetToken.php
│   ├── EmailVerificationToken.php
│   └── session.php
├── config/
│   ├── Database.php
│   ├── app.php
│   ├── google_oauth.php
│   ├── mail.php
│   ├── google_oauth_client.json (local, ignore git)
│   └── mail_credentials.php (local, ignore git)
├── database/
│   └── schema.sql
├── html pages/
│   ├── index.html
│   ├── dashboard.html
│   ├── requests.html
│   ├── club/
│   ├── event/
│   └── profile/
│       ├── login.html
│       ├── register.html
│       ├── forgot_password.html
│       ├── reset_password.html
│       ├── verify_email.html
│       └── verification_sent.html
├── scripts/
│   ├── home-carousel.js
│   ├── navbar.js
│   ├── sortable-table.js
│   ├── reset_owner_db.php
│   ├── reset_owner_db.bat
│   └── reset_owner_db.sh
├── styles/
│   ├── variables.css
│   ├── layout.css
│   ├── header-nav.css
│   ├── dashboard.css
│   ├── cards.css
│   ├── buttons.css
│   ├── forms.css
│   ├── tables.css
│   ├── notifications.css
│   ├── alerts.css
│   ├── badges.css
│   ├── search.css
│   ├── carousel.css
│   ├── modals-popups.css
│   └── responsive.css
├── media/
│   ├── club_related/
│   └── event_related/
├── docs/
│   └── diags/
├── .gitignore
└── README.md
```

## Base de donnees

Le schema `database/schema.sql` contient notamment:
- `USERS`
- `CLUBS`
- `CLUB_RESPONSABLES`
- `CLUB_RESPONSABLES_ARCHIVE`
- `CLUB_MEMBERS`
- `MEMBERSHIP_REQUESTS`
- `MEMBERSHIP_REQUEST_COOLDOWNS`
- `EVENTS`
- `EVENT_PARTICIPANTS`
- `EVENT_REJOIN_COOLDOWNS`
- `USER_NOTIFICATIONS`
- `PASSWORD_RESET_TOKENS`
- `EMAIL_VERIFICATION_TOKENS`

### Champs de gestion ajoutes dans `USERS`

- `mot_de_passe` (nullable pour comptes OAuth)
- `google_id`
- `avatar_url`
- `email_verified_at`
- `role` (`admin`, `responsable`, `etudiant`)
- `account_status` (`active`, `inactive`)
- `inactive_reason`
- `special_id`

## Reset proprietaire (vider la base + creer un admin)

Pour repartir de zero en local, un script interactif est disponible:

- `scripts/reset_owner_db.php` (script principal)
- `scripts/reset_owner_db.bat` (wrapper Windows / PowerShell)
- `scripts/reset_owner_db.sh` (wrapper Git Bash / WSL)

Ce script fait exactement 3 actions:

1. Demande l'email admin.
2. Demande le mot de passe admin (minimum 8 caracteres).
3. Vide toutes les tables applicatives puis cree un compte admin verifie.

Execution recommandee sous Windows:

```powershell
.\scripts\reset_owner_db.bat
```

Sortie attendue (exemple):

```text
Admin email: owner@example.com
Admin password (min 8 chars): myStrongPass123
Database reset complete.
Admin account created for: owner@example.com
Done.
```

Important:

- Usage local/dev uniquement (operation destructive).
- Le script utilise la configuration DB definie dans `config/Database.php`.

## Point d'entree

- Page d'accueil applicative: `backend/index.php`
- Les vues HTML sont dans `html pages/`
- La configuration DB est dans `config/Database.php`

## Workflow rapide

1. Creer un compte ou se connecter.
2. Parcourir les clubs et envoyer des demandes d'adhesion.
3. Participer aux evenements disponibles.
4. Pour un admin: gerer clubs, membres, demandes et evenements.

## Panneau d'administration

Le projet inclut maintenant une page reservee aux comptes `admin`:

- Vue et modification de tous les utilisateurs.
- Colonnes separees pour prenom et nom dans le tableau utilisateurs.
- Changement de role entre `etudiant` et `responsable`.
- Activation/desactivation de compte avec raison obligatoire en mode inactif.
- Attribution ou retrait d'un responsable sur un club (multi-responsables).
- Ajout direct d'un utilisateur (`etudiant` ou `responsable`) dans un club sans validation du responsable.
- Actions AJAX sans rechargement complet avec synchronisation des sections.
- Recherche/filtres dynamiques sur les tableaux utilisateurs et responsables.

Acces depuis le dashboard admin ou directement via `backend/admin.php`.

## Politique des comptes inactifs

Un compte `inactive` est traite comme temporairement masque a l'echelle de l'application:

- Connexion classique et OAuth bloquees.
- Reset mot de passe bloque.
- Exclu des listes de membres, responsables et participants.
- Exclu des options de selection (affectation responsable, ajout direct, demandes).

Effets automatiques lors de la desactivation:

- Suppression des demandes d'adhesion en attente.
- Suppression des participations aux evenements.
- Nettoyage des cooldowns associes.
- Suspension des responsabilites de club (avec archivage pour restauration).

Effet lors de la reactivation d'un compte `responsable`:

- Restauration automatique des responsabilites archivees.

## Configuration locale des secrets

Deux fichiers locaux sont utilises pour le developpement:

- `config/google_oauth_client.json`
- `config/mail_credentials.php`

Ils sont deja ignores par git (`.gitignore`) pour eviter de commiter des secrets.

Ordre de priorite applique par le code:

1. Variables d'environnement
2. Fichiers locaux dans `config/`
3. Valeurs par defaut (uniquement quand prevu par le code)

## Connexion avec Google

La connexion Google est basee sur OAuth 2.0 cote serveur.

### Option A (recommandee en local): fichier JSON Google

1. Ouvrir Google Cloud Console -> APIs & Services -> Credentials.
2. Creer (ou reutiliser) un OAuth Client ID de type Web application.
3. Ajouter votre callback dans Authorized redirect URIs (correspondance exacte obligatoire).
4. Telecharger le JSON client OAuth.
5. Copier son contenu dans `config/google_oauth_client.json`.

Format attendu:

```json
{
	"web": {
		"client_id": "...apps.googleusercontent.com",
		"client_secret": "...",
		"redirect_uris": [
			"http://localhost/.../backend/profile_php/google_callback.php"
		]
	}
}
```

Exemple de callback utilisee dans ce workspace:

`http://localhost/Frontend%20course(safebp)/repos/University-Club-Manager/backend/profile_php/google_callback.php`

Important: ne mettez pas `login.php` comme redirect URI. La route de callback est `google_callback.php`.

### Variables d'environnement requises

- `GOOGLE_OAUTH_CLIENT_ID`
- `GOOGLE_OAUTH_CLIENT_SECRET`
- `GOOGLE_OAUTH_REDIRECT_URI` (optionnel, recommande en production)

Exemple sous Windows PowerShell (session courante):

```powershell
$env:GOOGLE_OAUTH_CLIENT_ID="votre-client-id.apps.googleusercontent.com"
$env:GOOGLE_OAUTH_CLIENT_SECRET="votre-client-secret"
$env:GOOGLE_OAUTH_REDIRECT_URI="http://localhost/University-Club-Manager/backend/profile_php/google_callback.php"
```

Variables optionnelles utiles:

- `GOOGLE_OAUTH_JSON_PATH` pour pointer vers un JSON hors du projet.

Notes de resolution:

- Si `GOOGLE_OAUTH_CLIENT_ID` et `GOOGLE_OAUTH_CLIENT_SECRET` sont definis, ils priment sur le JSON.
- Si `GOOGLE_OAUTH_REDIRECT_URI` est vide, l'app prend d'abord la valeur du JSON, sinon construit une valeur par defaut.

### Mise a jour de base existante

Si votre table `USERS` existe deja, appliquez:

```sql
ALTER TABLE USERS MODIFY mot_de_passe VARCHAR(255) NULL;
ALTER TABLE USERS ADD COLUMN google_id VARCHAR(191) UNIQUE NULL AFTER mot_de_passe;
ALTER TABLE USERS ADD COLUMN avatar_url VARCHAR(255) NULL AFTER google_id;
```

### Routes OAuth

- Demarrage OAuth: `backend/profile_php/google_login.php`
- Callback OAuth: `backend/profile_php/google_callback.php`

Assurez-vous que l'URI de callback configuree dans Google Cloud correspond exactement a votre URL reelle.

## Verification d'email a l'inscription

### Principe

1. L'utilisateur cree son compte via `register.php`.
2. Le compte est cree non verifie (`email_verified_at = NULL`).
3. Un email de verification est envoye avec un lien unique (24h).
4. Tant que le lien n'est pas clique, la connexion est refusee.
5. La page de connexion propose `Renvoyer l'email de verification` si besoin.

### Routes principales

- Inscription: `backend/profile_php/register.php`
- Ecran post-inscription: `backend/profile_php/verification_sent.php`
- Verification email: `backend/profile_php/verify_email.php`
- Renvoi verification: `backend/profile_php/resend_verification.php`

## Mot de passe oublie / reset

### Principe

1. L'utilisateur ouvre `Mot de passe oublie`.
2. Le systeme genere un token de reset (1h) et envoie un email.
3. L'utilisateur clique le lien, definit un nouveau mot de passe.
4. Apres succes, redirection vers `login.php` avec message de confirmation.

### Routes principales

- Demande reset: `backend/profile_php/forgot_password.php`
- Reset via token: `backend/profile_php/reset_password.php`

## Configuration email SMTP (Gmail)

L'envoi d'emails (verification + reset) utilise SMTP via `config/mail.php`.

### Fichier local de credentials

- `config/mail_credentials.php` (ignore par git)

Format attendu:

```php
<?php
return [
	'smtp_host' => 'smtp.gmail.com',
	'smtp_port' => 587,
	'smtp_username' => 'votre-compte@gmail.com',
	'smtp_password' => 'mot-de-passe-app-google',
	'from_email' => 'votre-compte@gmail.com',
	'from_name' => 'University Clubs',
];
```

### Variables d'environnement equivalentes

Vous pouvez remplacer le fichier local avec:

- `MAIL_SMTP_HOST`
- `MAIL_SMTP_PORT`
- `MAIL_SMTP_USERNAME`
- `MAIL_SMTP_PASSWORD`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

Exemple PowerShell:

```powershell
$env:MAIL_SMTP_HOST="smtp.gmail.com"
$env:MAIL_SMTP_PORT="587"
$env:MAIL_SMTP_USERNAME="votre-compte@gmail.com"
$env:MAIL_SMTP_PASSWORD="votre-app-password"
$env:MAIL_FROM_ADDRESS="votre-compte@gmail.com"
$env:MAIL_FROM_NAME="University Clubs"
```

### Important

- Utiliser un App Password Google (pas le mot de passe principal du compte).
- Le mot de passe peut etre colle avec ou sans espaces (ils sont normalises par le code).
- En production, preferer des variables d'environnement plutot qu'un fichier local.

### Verification rapide (email)

1. Lancer une inscription avec une vraie adresse email.
2. Verifier la reception de l'email de verification.
3. Tester ensuite `Mot de passe oublie` pour verifier l'email de reset.
4. En cas d'echec SMTP, controler host/port/username/app-password.

## Checklist de test auth

1. Creer un nouveau compte email/mot de passe.
2. Verifier la redirection vers l'ecran `verification_sent`.
3. Ouvrir l'email de verification et cliquer le lien.
4. Se connecter avec ce compte (doit fonctionner apres verification).
5. Tester `Mot de passe oublie` puis reset et retour login avec message succes.
6. Tester `Continuer avec Google`.
