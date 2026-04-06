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
├── classes/
│   ├── Club.php
│   ├── Event.php
│   ├── MembershipRequest.php
│   ├── User.php
│   └── session.php
├── config/
│   └── Database.php
├── database/
│   └── schema.sql
├── html pages/
│   ├── index.html
│   ├── dashboard.html
│   ├── requests.html
│   ├── club/
│   ├── event/
│   └── profile/
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

## Point d'entree

- Page d'accueil applicative: `backend/index.php`
- Les vues HTML sont dans `html pages/`
- La configuration DB est dans `config/Database.php`

## Workflow rapide

1. Creer un compte ou se connecter.
2. Parcourir les clubs et envoyer des demandes d'adhesion.
3. Participer aux evenements disponibles.
4. Pour un admin: gerer clubs, membres, demandes et evenements.
