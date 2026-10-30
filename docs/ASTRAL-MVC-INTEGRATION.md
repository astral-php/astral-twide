# Intégration officielle dans Astral MVC 2.x

Twide est un composant optionnel d'Astral. Il s'enregistre via `Core\ServiceProviderInterface`, sans modification d'`astral-core` ni remplacement du moteur PHP historique.

## Installation

```bash
composer require astral-php/astral-twide
```

## Enregistrer le provider

Dans `config/dependencies.php`, ajouter le provider à la liste existante :

```php
use Astral\Twide\Integration\TwideServiceProvider;

return [
    FrameworkServiceProvider::class,
    DatabaseServiceProvider::class,
    TwideServiceProvider::class,
    AppServiceProvider::class,
];
```

Conserver les providers déjà présents et leur ordre. Le provider Twide doit être enregistré avant la résolution des contrôleurs qui l'injectent.

## Chemins par défaut

Le provider suit la structure standard d'une application Astral :

- templates Twide : `BASE_PATH . '/views'` ; les fichiers utilisent l'extension `.twide` ;
- templates compilés : `BASE_PATH . '/storage/cache/views'`.

Aucune clé supplémentaire dans `.env` n'est requise. Les chemins peuvent être remplacés, si nécessaire, via `config/app.php` avec une clé `twide` :

```php
return [
    // conserver les autres clés de configuration existantes
    'twide' => [
        'views' => BASE_PATH . '/views',
        'cache' => BASE_PATH . '/storage/cache/views',
        'extension' => '.twide',
        'extensions' => [
            // \App\Twide\ShopExtension::class,
        ],
    ],
];
```

| Clé | Défaut | Rôle |
|-----|--------|------|
| `views` | `BASE_PATH/views` | Racine des templates |
| `cache` | `BASE_PATH/storage/cache/views` | Cache compilé |
| `extension` | `.twide` | Suffixe des fichiers |
| `extensions` | `[]` | FQCN `ExtensionInterface` applicatifs |

En usage standard, les défauts suffisent. Le dossier de cache est créé au premier rendu si le processus PHP a les droits nécessaires.

Le provider enregistre `Twide`, `TemplateEngineInterface` et `TwideViewAdapter` en singleton, active `UtilitiesExtension`, puis charge chaque entrée de `twide.extensions` (résolution container + contrôle `instanceof ExtensionInterface`). Voir [`EXTENSIONS.md`](EXTENSIONS.md).

## Utilisation depuis un contrôleur Astral 2.x

Astral 2.x garde `Controller\AbstractController::render()` et le rendu PHP inchangés. Un contrôleur peut injecter le moteur Twide en complément de `Core\View` :

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Astral\Twide\Twide;
use Controller\AbstractController;
use Core\Http\Response;
use Core\View;

final class ExampleController extends AbstractController
{
    public function __construct(
        View $view,
        private readonly Twide $twideEngine,
    ) {
        parent::__construct($view);
    }

    /** @param array<string, mixed> $data */
    protected function twide(string $view, array $data = []): Response
    {
        return Response::html($this->twideEngine->render($view, $data));
    }

    public function index(): Response
    {
        return $this->twide('example/index', ['title' => 'Twide dans Astral']);
    }

    public function legacy(): Response
    {
        return $this->render('example/legacy', ['title' => 'Vue PHP historique']);
    }
}
```

Le conteneur Astral résout `View` et `Twide` à partir des providers. `Response::html()` est compatible avec cet exemple sous Astral Core 2.x. Adapter le constructeur si le contrôleur applicatif possède déjà des dépendances : conserver tous les paramètres et appeler `parent::__construct($view)`.

## Exemple de template

Créer `views/example/index.twide` :

```html
<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><title>{{ title }}</title></head>
<body><h1>{{ title }}</h1></body>
</html>
```

Le moteur PHP historique et les fichiers `.php` continuent à fonctionner comme auparavant. L'utilisation de Twide est explicite, action par action, avec `$this->twide(...)`.

## Sécurité et exploitation

- Les expressions `{{ ... }}` sont échappées par défaut.
- La sortie brute `{! ... !}` doit être réservée au HTML fiable ou assaini.
- Ne pas exposer le répertoire `storage/cache/views` comme répertoire public statique.
- Vérifier les droits d'écriture du compte PHP sur `storage/cache/views`.
- Les chemins personnalisés sont facultatifs ; la structure Astral standard reste le choix recommandé.

## Tests

Après installation des dépendances :

```bash
composer validate
composer install
composer test
```

Les tests d'intégration de l'application doivent aussi vérifier l'enregistrement du provider dans le `config/dependencies.php` réel, la résolution des contrôleurs et le rendu d'une route Twide.
