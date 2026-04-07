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

### Admin de club (`role = admin`)
- Creation, modification et suppression de clubs.
- Validation des demandes d'adhesion (`pending`, `accepted`, `rejected`).
- Consultation des membres d'un club.
- Creation, modification et suppression d'evenements.
- Consultation des participants.

### Interface
- Design responsive (desktop/tablette/mobile).
- Recherche sur les listes (clubs/evenements).
- Tri des tableaux avec `scripts/sortable-table.js`.
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
│   └── sortable-table.js
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
├── LICENSE
└── README.md
```

## Base de donnees

Le schema `database/schema.sql` contient notamment:
- `USERS`
- `CLUBS`
- `CLUB_MEMBERS`
- `MEMBERSHIP_REQUESTS`
- `MEMBERSHIP_REQUEST_COOLDOWNS`
- `EVENTS`
- `EVENT_PARTICIPANTS`
- `USER_NOTIFICATIONS`
- `PASSWORD_RESET_TOKENS`
- `EMAIL_VERIFICATION_TOKENS`

### Champs auth ajoutes dans `USERS`

- `mot_de_passe` (nullable pour comptes OAuth)
- `google_id`
- `avatar_url`
- `email_verified_at`

## Point d'entree

- Page d'accueil applicative: `backend/index.php`
- Les vues HTML sont dans `html pages/`
- La configuration DB est dans `config/Database.php`

## Workflow rapide

1. Creer un compte ou se connecter.
2. Parcourir les clubs et envoyer des demandes d'adhesion.
3. Participer aux evenements disponibles.
4. Pour un admin: gerer clubs, membres, demandes et evenements.

## Connexion avec Google

La connexion Google est basee sur OAuth 2.0 cote serveur.

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

### Important

- Utiliser un App Password Google (pas le mot de passe principal du compte).
- Le mot de passe peut etre colle avec ou sans espaces (ils sont normalises par le code).
- En production, preferer des variables d'environnement plutot qu'un fichier local.

## Checklist de test auth

1. Creer un nouveau compte email/mot de passe.
2. Verifier la redirection vers l'ecran `verification_sent`.
3. Ouvrir l'email de verification et cliquer le lien.
4. Se connecter avec ce compte (doit fonctionner apres verification).
5. Tester `Mot de passe oublie` puis reset et retour login avec message succes.
6. Tester `Continuer avec Google`.
