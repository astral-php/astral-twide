# astral-twide

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Version](https://img.shields.io/badge/version-1.0.0-blue)](CHANGELOG.md)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-PHPUnit%20OK-9933CC)](phpunit.xml)

Moteur de templates **compilé**, extensible, **opt-in** pour Astral MVC. Syntaxe dédiée (`.twide`) — les apps officielles Astral restent sur les vues PHP natives.

> **1.0.0** — feuille 2.x (`PHP ^8.3`, `astral-core ^2`). Packagist : `composer require astral-php/astral-twide`.

- Paquet : `astral-php/astral-twide`
- Namespace : `Astral\Twide\`
- PHP : `^8.3`
- Core : `astral-php/astral-core:^2.0`
- Utilities : `astral-php/astral-utilities:^0.1.0`

## Compatibilité

| Contrainte | Valeur |
|------------|--------|
| PHP | `^8.3` |
| astral-core | `^2.0` |
| Provider | `TwideServiceProvider` (optionnel) |

## Installation

**Local (path) :**

```json
"repositories": [{ "type": "path", "url": "../astral-twide" }]
```

```bash
composer require astral-php/astral-twide:@dev
```

**Packagist :**

```bash
composer require astral-php/astral-twide
```

Développement du dépôt :

```bash
composer install
composer test
```

`UtilitiesExtension` s’appuie sur `astral-utilities`. Le filtre `truncate` nécessite `ext-mbstring`.

## Démarrage rapide

```php
use Astral\Twide\Twide;

$twide = new Twide([
    'views' => __DIR__ . '/views',
    'cache' => __DIR__ . '/../storage/cache/views',
]);

$html = $twide->render('users', [
    'title' => 'Utilisateurs',
    'users' => [['name' => 'Ada'], ['name' => 'Lin']],
]);
```

`views/users.twide` :

```twide
<h1>{{ title }}</h1>
@for(user in users)
    <p>{{ loop.index }}. {{ user.name }}</p>
@endfor
```

## Intégration Astral MVC 2.x

Enregistrer le provider dans `config/dependencies.php`, puis rendre via `$this->twide(...)` (opt-in explicite, sans remplacer `render()` PHP).

Voir [`docs/ASTRAL-MVC-INTEGRATION.md`](docs/ASTRAL-MVC-INTEGRATION.md) et le guide des vues [`docs/ASTRAL-TWIDE-GUIDE-VUES.md`](docs/ASTRAL-TWIDE-GUIDE-VUES.md).

## Syntaxe (implémentée)

- Sorties `{{ }}` (échappées) et `{! !}` (brutes)
- Commentaires `{{-- --}}`
- `@if` / `@elseif` / `@else` / `@endif`
- `@for` / `@endfor` (`loop.*`)
- Composition : `@extends`, `@section`, `@yield`, `@include`
- Composants : `@component`, `@slot`
- Piles : `@push`, `@stack`
- Extensions / filtres (dont Utilities)
- Cache compilé + `TwideViewAdapter`

## Extension Utilities

```php
use Astral\Twide\Extension\UtilitiesExtension;

$twide->addExtension(new UtilitiesExtension());
```

Filtres : `slug`, `truncate`, `excerpt`, `ascii`, `date`, `diff_for_humans`, `arr_get`, … — [`docs/EXTENSIONS.md`](docs/EXTENSIONS.md).

## Sécurité

- `{{ }}` échappe ; `{! !}` seulement pour HTML de confiance
- Pas d’exécution PHP arbitraire dans les templates
- Cache sous `storage/cache/views` : non public, droits d’écriture PHP

Détails : [`docs/SECURITY.md`](docs/SECURITY.md).

## Documentation

| Doc | Rôle |
|-----|------|
| [`docs/ASTRAL-TWIDE-GUIDE-VUES.md`](docs/ASTRAL-TWIDE-GUIDE-VUES.md) | Référence auteur de vues (P1–P5) |
| [`docs/ASTRAL-MVC-INTEGRATION.md`](docs/ASTRAL-MVC-INTEGRATION.md) | Provider + contrôleur |
| [`docs/INSTALLATION.md`](docs/INSTALLATION.md) | Install |
| [`docs/EXTENSIONS.md`](docs/EXTENSIONS.md) | Extensions |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Sécurité |
| [`docs/DEBUG.md`](docs/DEBUG.md) | Messages d’erreur / debug |
