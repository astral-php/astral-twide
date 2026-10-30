# Extensions Twide

## Enregistrer une extension

Les extensions implémentent `Astral\Twide\Contracts\ExtensionInterface` et exposent des fonctions / filtres.

### Via `config/app.php` (recommandé en app Astral)

```php
'twide' => [
    'extensions' => [
        \App\Twide\ShopExtension::class,
    ],
],
```

`TwideServiceProvider` résout chaque FQCN (autowiring container) et refuse les classes absentes ou non conformes avec un message explicite. **Pas de scan de dossier.**

### Manuellement (moteur autonome)

```php
use Astral\Twide\Extension\UtilitiesExtension;
use Astral\Twide\Twide;

$twide = new Twide([
    'views' => __DIR__ . '/views',
    'cache' => __DIR__ . '/var/cache/twide',
]);

$twide->addExtension(new UtilitiesExtension());
$twide->addExtension(new \App\Twide\ShopExtension());
```

Les noms exposés doivent être uniques, sauf remplacement explicite (`addExtension(..., true)` / `addFilter(..., true)`).

### Tests PHPUnit (fortement conseillé)

Pour chaque extension applicative, couvrir au minimum :

1. Enregistrement des noms de fonctions / filtres attendus  
2. Rendu d’un `.twide` minimal qui les utilise  
3. Collision de nom (comportement avec / sans `replace`)  
4. Cas d’erreur (argument invalide) si l’extension valide ses entrées  

Exemple de squelette :

```php
final class ShopExtensionTest extends TestCase
{
    public function testFilterIsAvailableInTemplate(): void
    {
        $engine = new Twide(['views' => $this->views, 'cache' => $this->cache]);
        $engine->addExtension(new ShopExtension());
        self::assertSame('…', $engine->render('shop/price', ['amount' => 10]));
    }
}
```

## Extension `astral-utilities`

`UtilitiesExtension` fait le pont vers `astral-php/astral-utilities` (dépendance Composer `^0.1.0`). Les API appelées sont celles documentées par le README officiel du package : `Str::slug()`, `Str::truncate()`, `Str::excerpt()`, `Str::ascii()`, `Date::format()`, `Date::diffForHumans()`, `Arr::get()`, `Arr::only()`, `Arr::except()` et `Arr::flatten()`.

### Fonctions de template

| Fonction | Exemple |
|---|---|
| `str_slug` | `{{ str_slug(title) }}` |
| `str_truncate` | `{{ str_truncate(description, 80) }}` |
| `str_excerpt` | `{{ str_excerpt(description, 30) }}` |
| `str_ascii` | `{{ str_ascii(title) }}` |
| `date_format` | `{{ date_format(createdAt, 'd/m/Y') }}` |
| `date_diff_for_humans` | `{{ date_diff_for_humans(createdAt) }}` |
| `arr_get` | `{{ arr_get(user, 'profile.email', '') }}` |
| `arr_only` | À utiliser dans une expression consommant un tableau |
| `arr_except` | À utiliser dans une expression consommant un tableau |
| `arr_flatten` | À utiliser dans une expression consommant un tableau |

### Filtres

```twide
{{ title | slug }}
{{ description | truncate(80) }}
{{ description | excerpt(30) }}
{{ title | ascii }}
{{ createdAt | date('d/m/Y') }}
{{ createdAt | diff_for_humans }}
{{ user | arr_get('profile.email', '') }}
{{ data | arr_only(['id', 'name']) }}
{{ data | arr_except(['password']) }}
{{ nested | flatten }}
```

Les fonctions de transformation de tableaux retournent des tableaux. Twide n'autorise pas à afficher directement un tableau comme une chaîne : il faut l'utiliser dans une autre fonction ou dans le code applicatif.

## Sécurité et échappement

Les filtres de cette extension retournent des valeurs, pas du HTML de confiance. L'opérateur `{{ ... }}` échappe la sortie HTML par défaut. L'opérateur `{! ... !}` désactive cet échappement et ne doit être utilisé que pour du contenu explicitement fiable.

Les fonctions/filtres personnalisés sont du code PHP exécuté dans le processus de l'application : ne jamais enregistrer une fonction provenant directement d'un utilisateur final ou d'un template non fiable.
