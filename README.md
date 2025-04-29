# Task Manager API

Un projet d'API Symfony conteneurisée avec Docker Compose, exposant des métriques pour Prometheus et Grafana.

---

## 📦 Architecture

- **PHP-FPM (Symfony)**  
- **Nginx** : serveur web et reverse-proxy  
- **PostgreSQL** : base de données  
- **Adminer** : interface d'administration de la base  
- **cAdvisor** : métriques Docker (CPU, mémoire, I/O, réseau)  
- **nginx-exporter** : métriques Nginx via `stub_status`  
- **Prometheus** : collecte et stockage des métriques  
- **Grafana** : visualisation et dashboards  

---

## 🔧 Prérequis

- **Git** (recommandé pour cloner le projet)
- **Docker** (>= 20.10)  
- **Docker Compose** (>= 1.29)  
- (Optionnel) Git  

---

## 🚀 Initialisation du Projet

Suivez ces étapes pour configurer et lancer le projet pour la première fois :

1.  **Clonez le dépôt** (si vous ne l'avez pas déjà fait) :
    ```bash
    git clone <url-du-repo>
    cd task-manager
    ```

2.  **Copiez le fichier d'environnement** :
    ```bash
    cp .env.example .env
    ```

3.  **Personnalisez vos variables d'environnement** dans le fichier `.env` si nécessaire (les valeurs par défaut sont généralement suffisantes pour démarrer) :
    ```dotenv
    # PostgreSQL
    POSTGRES_VERSION=16-alpine
    POSTGRES_DB=task_manager
    POSTGRES_USER=task_manager_user
    POSTGRES_PASSWORD=SuperSecretPassword

    # Symfony
    APP_ENV=dev
    # Assurez-vous que DATABASE_URL correspond à vos paramètres PostgreSQL
    DATABASE_URL="pgsql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}?serverVersion=16&charset=utf8"
    ```

4.  **Créez le dossier de configuration Prometheus** (si nécessaire) :
    ```bash
    mkdir -p monitoring
    ```
    Assurez-vous que le fichier `monitoring/prometheus.yml` existe et est configuré (voir exemple ci-dessous si manquant).

5.  **Construisez et démarrez les conteneurs** :
    ```bash
    docker-compose up -d --build
    ```
    *Cela peut prendre quelques minutes la première fois.*

6.  **(Première fois uniquement) Générez les clés JWT** pour l'authentification :
    ```bash
    docker-compose exec php php bin/console lexik:jwt:generate-keypair --skip-if-exists
    ```
    *Entrez les passphrases demandées ou laissez vide si non requis.*

7.  **Exécutez les migrations** de la base de données :
    ```bash
    docker-compose exec php php bin/console doctrine:migrations:migrate --no-interaction
    ```

8.  **Vérifiez les logs** pour s'assurer que tout a démarré correctement (optionnel) :
    ```bash
    docker-compose logs -f
    ```

### Accès aux Services

Une fois l'initialisation terminée, les services sont accessibles :

- API Symfony : http://localhost/  
- Adminer (PostgreSQL) : http://localhost:8080  
- cAdvisor (Docker) : http://localhost:8081  
- Nginx-exporter : http://localhost:9113/metrics  
- Prometheus : http://localhost:9090  
- Grafana : http://localhost:3000  
  - Identifiants par défaut : `admin` / `admin`  
  - Changez le mot de passe à la première connexion.

---

## ⚙️ Commandes Utiles (Après Initialisation)

### Symfony

- Exécuter une commande Console Symfony :
  ```bash
  docker-compose exec php php bin/console <commande>
  ```
- Voir les logs d'un service spécifique (ex: php) :
  ```bash
  docker-compose logs -f php
  ```
- Arrêter les services :
  ```bash
  docker-compose down
  ```
- Redémarrer les services :
  ```bash
  docker-compose restart
  ```

### Monitoring

- Vérifiez vos targets dans Prometheus :  
  **Status → Targets**  
- Exemple de requêtes PromQL :
  ```promql
  nginx_connections_active
  rate(container_cpu_usage_seconds_total[1m])
  ```
- Importez des dashboards Grafana :
  - "NGINX Full"
  - "Docker & system monitoring"

---

## 🗂 Structure du projet

```
├── .env.example
├── .env
├── composer.json
├── Dockerfile
├── docker-compose.yml
├── docker/
│   └── nginx/
│       └── conf.d/
│           └── default.conf
├── config/          # Ajout potentiel si lexik:jwt:generate-keypair crée des clés ici
│   └── jwt/
│       ├── private.pem
│       └── public.pem
├── monitoring/
│   └── prometheus.yml
├── public/
├── src/
├── var/
├── vendor/
└── README.md
```
*Note : Le dossier `config/jwt/` sera créé après la génération des clés.*

---

## 🔐 Sécurité & bonnes pratiques

- En **production**, passez `APP_ENV=prod` et assurez-vous que les dépendances de développement ne sont pas installées (`composer install --no-dev`).
- Utilisez des mots de passe forts et ne commitez pas de secrets directement dans le code. Stockez-les dans `.env` (ignoré par Git) ou mieux, utilisez un gestionnaire de secrets (Vault, variables d'environnement CI/CD).
- Restreignez l'accès aux endpoints sensibles comme `/nginx_status`. La configuration Nginx fournie le fait déjà pour l'exporter.
- Configurez Alertmanager pour être notifié des problèmes potentiels détectés par Prometheus.

---

## 📖 Ressources

- [Symfony Documentation](https://symfony.com/doc/current/)  
- [LexikJWTAuthenticationBundle](https://github.com/lexik/LexikJWTAuthenticationBundle)  
- [Prometheus Official](https://prometheus.io/)  
- [Grafana Dashboards](https://grafana.com/grafana/dashboards)  
- [cAdvisor GitHub](https://github.com/google/cadvisor)  
- [nginx-exporter GitHub](https://github.com/nginxinc/nginx-prometheus-exporter)  

---

> **Bon monitoring !** 🚀