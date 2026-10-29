# Installation et démarrage

## Prérequis

- PHP `^8.3`
- Composer 2
- `astral-php/astral-core` `^2.0` et `astral-php/astral-utilities` `^0.1.0` (résolus par Composer)
- Extension PHP `mbstring` pour `Str::truncate()` / filtre `truncate`

## Installation dans une application Astral MVC 2.x

```bash
composer require astral-php/astral-twide
```

Dans `config/dependencies.php`, ajouter `Astral\Twide\Integration\TwideServiceProvider::class` à la liste des providers. Guide complet : [`ASTRAL-MVC-INTEGRATION.md`](ASTRAL-MVC-INTEGRATION.md).

Chemins conventionnels :

- vues : `BASE_PATH . '/views'` (extension `.twide`)
- cache compilé : `BASE_PATH . '/storage/cache/views'`

Le provider enregistre le moteur en singleton et active `UtilitiesExtension`. L’usage reste explicite (`$this->twide(...)`) ; le rendu PHP historique n’est pas modifié.

### Utilisation autonome du moteur

```php
use Astral\Twide\Extension\UtilitiesExtension;
use Astral\Twide\Twide;

$twide = new Twide([
    'views' => BASE_PATH . '/views',
    'cache' => BASE_PATH . '/storage/cache/views',
    'extension' => '.twide',
    'cache_enabled' => true,
    'debug' => false,
]);
$twide->addExtension(new UtilitiesExtension());

echo $twide->render('home', ['title' => 'Astral']);
```

## Développement du dépôt

```bash
composer install
composer test
```

## Documentation liée

- [`ASTRAL-TWIDE-GUIDE-VUES.md`](ASTRAL-TWIDE-GUIDE-VUES.md)
- [`ASTRAL-MVC-INTEGRATION.md`](ASTRAL-MVC-INTEGRATION.md)
- [`EXTENSIONS.md`](EXTENSIONS.md)
- [`SECURITY.md`](SECURITY.md)
