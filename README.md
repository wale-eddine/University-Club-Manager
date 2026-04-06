# Plateforme de Gestion des Clubs Universitaires

Application web PHP/MySQL pour la gestion des clubs universitaires, des demandes d'adhesion et des evenements.

## A propos du projet

Cette application permet de gerer le cycle complet des clubs universitaires:
- Authentification et gestion de profil utilisateur.
- Creation et administration des clubs.
- Demandes d'adhesion avec validation par les responsables.
- Gestion d'evenements et inscriptions des participants.
- Notifications utilisateur.

Le projet suit une architecture simple en PHP:
- `methods/` pour la logique metier.
- `php/` pour les controleurs/pages dynamiques.
- `html pages/` pour les templates HTML.
- `css/` et `js/` pour la presentation et l'interaction.

## Fonctionnalites

### Etudiant
- Inscription et connexion.
- Consultation des clubs et evenements.
- Envoi d'une demande d'adhesion a un club.
- Quitter un club.
- Inscription / desinscription a un evenement.
- Consultation des notifications.

### Admin de club (`role = admin`)
- Creation, modification et suppression de clubs.
- Gestion des demandes d'adhesion (`pending`, `accepted`, `rejected`).
- Consultation des membres.
- Creation, modification et suppression d'evenements.
- Consultation des participants.

### Interface et experience
- Design responsive (desktop/tablette/mobile).
- Recherche sur clubs et evenements.
- Tri interactif des tableaux de dates via `js/sortable-table.js`.
- Carousel d'accueil avec navigation et auto-defilement.
- Indicateurs visuels du carousel desactives dans le style.

## Securite

- Mots de passe haches avec `password_hash()` (bcrypt).
- Requetes preparees PDO contre les injections SQL.
- Sessions securisees et verification d'acces.
- Echappement des sorties HTML.

## Stack technique

- PHP 7.4+
- MySQL 5.7+
- Apache (XAMPP recommande sous Windows)
- HTML5, CSS3, JavaScript

## Installation rapide (XAMPP)

1. Copier le dossier `Project/` dans `C:\xampp\htdocs\`.
2. Demarrer Apache et MySQL dans XAMPP.
3. Creer la base `university_clubs` puis executer `database/schema.sql`.
4. Verifier les identifiants dans `config/Database.php`.
5. Ouvrir: `http://localhost/waleeddine_djemel/Project/php/index.php`

## Structure reelle du projet

```text
Project/
├── config/
│   └── Database.php
├── css/
│   ├── alerts.css
│   ├── badges.css
│   ├── buttons.css
│   ├── cards.css
│   ├── carousel.css
│   ├── dashboard.css
│   ├── forms.css
│   ├── header-nav.css
│   ├── layout.css
│   ├── modals-popups.css
│   ├── notifications.css
│   ├── responsive.css
│   ├── search.css
│   ├── tables.css
│   ├── variables.css
│   └── README.md
├── database/
│   └── schema.sql
├── html pages/
│   ├── dashboard.html
│   ├── index.html
│   ├── requests.html
│   ├── club/
│   ├── event/
│   └── profile/
├── js/
│   ├── home-carousel.js
│   ├── navbar.js
│   └── sortable-table.js
├── media/
│   ├── club_related/
│   └── event_related/
├── methods/
│   ├── Club.php
│   ├── Event.php
│   ├── MembershipRequest.php
│   ├── User.php
│   └── session.php
├── php/
│   ├── dashboard.php
│   ├── index.php
│   ├── notif_count.php
│   ├── notif_data.php
│   ├── requests.php
│   ├── club_php/
│   ├── event_php/
│   └── profile_php/
├── public/
│   └── uploads/
│       ├── clubs/
│       └── events/
├── DELIVERY_CHECKLIST.md
├── INSTALLATION.md
├── PROJECT_SUMMARY.md
└── README.md
```

## Base de donnees (schema actuel)

Le fichier `database/schema.sql` cree notamment les tables:
- `USERS`
- `CLUBS`
- `CLUB_MEMBERS`
- `MEMBERSHIP_REQUESTS`
- `MEMBERSHIP_REQUEST_COOLDOWNS`
- `EVENTS`
- `EVENT_PARTICIPANTS`
- `USER_NOTIFICATIONS`

## Points importants

- La page d'entree applicative est `php/index.php`.
- Les templates sont inclus depuis `html pages/`.
- Les images des clubs/evenements sont stockees dans `public/uploads/`.
- Le role admin doit etre attribue en base de donnees.

## Workflow rapide

1. S'inscrire / se connecter.
2. Parcourir les clubs et envoyer des demandes d'adhesion.
3. Une fois membre, rejoindre des evenements.
4. Pour un admin: creer des clubs, gerer les demandes et publier des evenements.

## Documentation complementaire

- Guide installation detaille: `INSTALLATION.md`
- Resume fonctionnel complet: `PROJECT_SUMMARY.md`
- Checklist livrable: `DELIVERY_CHECKLIST.md`
