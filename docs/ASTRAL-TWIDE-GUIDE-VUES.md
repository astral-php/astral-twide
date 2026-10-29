# Guide de référence des vues Astral Twide

**Package :** `astral-php/astral-twide`  
**Cible :** Astral MVC 2.x (`PHP ^8.3`, `astral-core ^2`), intégration optionnelle via `TwideServiceProvider`  
**Statut :** référence des fonctionnalités industrialisées (Phase A). Certaines limites sont signalées explicitement.

Ce guide explique comment écrire une vue HTML `.twide`, transmettre des données depuis un contrôleur, afficher des valeurs, utiliser les conditions et parcourir des collections, puis composer des layouts et composants.

---

## 1. Comment transmettre les données à une vue

Les données sont transmises sous forme de tableau associatif au moteur Twide.

Exemple côté contrôleur :

```php
return $this->twide('users/index', [
    'title' => 'Liste des utilisateurs',
    'users' => [
        ['name' => 'Ada', 'email' => 'ada@example.test'],
        ['name' => 'Lin', 'email' => 'lin@example.test'],
    ],
    'isAdmin' => true,
]);
```

Le nom `users/index` désigne ici le fichier `views/users/index.twide` avec la racine de vues Astral par défaut. Le provider Astral utilise `BASE_PATH/views` pour les vues et `BASE_PATH/storage/cache/views` pour le cache compilé.

Exemple de vue `views/users/index.twide` :

```html
<h1>{{ title }}</h1>

<ul>
    @for(user in users)
        <li>{{ user.name }} — {{ user.email }}</li>
    @endfor
</ul>
```

Les clés du tableau transmis deviennent des variables de template. Pour une valeur comme `['user' => ['name' => 'Ada']]`, on peut écrire `{{ user.name }}`.

---

## 2. Balises d'affichage des données

### 2.1 `{{ expression }}` — afficher une valeur avec échappement HTML

C'est la forme à utiliser par défaut. Twide échappe les caractères HTML spéciaux pour limiter les risques d'injection HTML/XSS.

```html
<h1>{{ title }}</h1>
<p>Bonjour {{ user.name }}.</p>
<p>Prix : {{ product.price }} €</p>
```

Si `title` contient `<script>`, le contenu est affiché comme du texte et non exécuté comme du HTML.

### 2.2 Accéder à une clé imbriquée

```html
<p>{{ user.profile.firstName }}</p>
<p>{{ user.profile.city }}</p>
```

L'accès par point fonctionne sur les tableaux associatifs et les propriétés publiques d'objets. Les méthodes et accesseurs magiques d'objets ne sont pas appelés.

### 2.3 Accéder à un élément par index ou clé

```html
<p>Premier produit : {{ products[0].name }}</p>
<p>Code : {{ product['sku'] }}</p>
```

### 2.4 Valeur absente ou `null`

Une variable absente ou une valeur `null` est rendue comme une chaîne vide par `{{ ... }}`. Pour choisir un texte de remplacement, on peut utiliser le filtre `default` :

```html
<p>{{ user.nickname | default('Aucun surnom') }}</p>
```

Attention : `default` remplace les valeurs `null` ; il ne constitue pas un test général de valeur vide, de chaîne vide ou de tableau vide.

### 2.5 `{! expression !}` — sortie brute sans échappement

```html
<div class="article-content">
    {! trustedHtml !}
</div>
```

Cette syntaxe n'échappe pas le HTML. Elle est réservée à du HTML de confiance ou préalablement assaini par l'application. **Ne jamais l'utiliser directement avec du contenu fourni par un utilisateur.**

### 2.6 `{{-- commentaire --}}` — commentaire de template

```twide
{{-- Ce commentaire ne sera pas affiché dans le HTML final. --}}
<h1>{{ title }}</h1>
```

---

## 3. Conditions

### 3.1 `@if`, `@else`, `@endif`

```twide
@if(isAdmin)
    <a href="/admin">Administration</a>
@else
    <p>Espace utilisateur</p>
@endif
```

### 3.2 `@elseif`

```twide
@if(status == 'published')
    <span class="badge">Publié</span>
@elseif(status == 'draft')
    <span class="badge">Brouillon</span>
@else
    <span class="badge">En attente</span>
@endif
```

### 3.3 Tester un nombre ou une collection vide

La directive `@empty` **n'est pas implémentée dans la phase 13**. Pour une collection, utiliser la fonction intégrée `count()` avec `@if` :

```twide
@if(count(products) == 0)
    <p>Aucun produit à afficher.</p>
@else
    <p>{{ count(products) }} produit(s) disponible(s).</p>
@endif
```

Pour une valeur textuelle, tester explicitement la chaîne attendue si nécessaire :

```twide
@if(title == '')
    <p>Le titre n'est pas renseigné.</p>
@else
    <h1>{{ title }}</h1>
@endif
```

### 3.4 Opérateurs utilisables dans les expressions

Les opérateurs actuellement pris en charge incluent :

- comparaison : `==`, `!=`, `===`, `!==`, `<`, `>`, `<=`, `>=` ;
- logique : `&&`, `||`, `!` ;
- arithmétique : `+`, `-`, `*`, `/`, `%`, `**` ;
- valeur de remplacement si `null` : `??` ;
- expression ternaire : `condition ? valeurSiVrai : valeurSiFaux`.

Exemples :

```twide
@if(isAdmin && user.active)
    <p>Compte administrateur actif.</p>
@endif

@if(order.total >= 100)
    <p>Livraison offerte.</p>
@endif

<p>{{ nickname ?? 'Visiteur' }}</p>
<p>{{ isAdmin ? 'Administrateur' : 'Utilisateur' }}</p>
```

Les expressions sont limitées au langage pris en charge par Twide : il ne s'agit pas de PHP arbitraire et on ne peut pas appeler directement n'importe quelle méthode PHP.

---

## 4. Parcourir une collection : `@for(item in items)`

### Important : `@for` est une boucle de parcours, pas une boucle PHP classique

La syntaxe implémentée est :

```twide
@for(product in products)
    <p>{{ product.name }}</p>
@endfor
```

Elle parcourt un tableau ou un objet `Traversable`. Dans son usage, elle ressemble à un `foreach` PHP, mais **la directive s'appelle `@for` dans Twide**.

Les syntaxes suivantes ne sont pas prises en charge dans la phase 13 :

```text
@foreach(product in products) ... @endforeach
@for($i = 0; $i < 10; $i++) ... @endfor
```

### 4.1 Exemple HTML complet

```html
<h1>Nos produits</h1>

<ul>
    @for(product in products)
        <li>
            <strong>{{ product.name }}</strong>
            — {{ product.price }} €
        </li>
    @endfor
</ul>
```

### 4.2 Métadonnées de boucle

Twide fournit la variable réservée `loop` à l'intérieur d'une boucle :

| Propriété | Signification | Exemple |
|---|---|---|
| `loop.index` | Position à partir de 1 | `{{ loop.index }}` |
| `loop.index0` | Position à partir de 0 | `{{ loop.index0 }}` |
| `loop.first` | `true` sur le premier élément | `@if(loop.first)` |
| `loop.last` | `true` sur le dernier élément | `@if(loop.last)` |
| `loop.length` | Nombre total d'éléments | `{{ loop.length }}` |
| `loop.key` | Clé ou index courant | `{{ loop.key }}` |
| `loop.parent` | Métadonnées de la boucle extérieure | `loop.parent.index` |

Exemple :

```html
<ol>
    @for(user in users)
        <li>
            {{ loop.index }} / {{ loop.length }} — {{ user.name }}

            @if(loop.first)
                <span>(premier)</span>
            @endif

            @if(loop.last)
                <span>(dernier)</span>
            @endif
        </li>
    @endfor
</ol>
```

### 4.3 Parcours imbriqués

```twide
@for(category in categories)
    <h2>{{ category.name }}</h2>

    <ul>
        @for(product in category.products)
            <li>
                {{ loop.parent.index }}.{{ loop.index }}
                — {{ product.name }}
            </li>
        @endfor
    </ul>
@endfor
```

`loop.parent.index` fait référence à l'index de la boucle extérieure.

### 4.4 Afficher un message si la collection est vide

Comme `@empty` n'est pas pris en charge, vérifier la collection avant la boucle :

```twide
@if(count(products) == 0)
    <p>Aucun produit pour le moment.</p>
@else
    <ul>
        @for(product in products)
            <li>{{ product.name }}</li>
        @endfor
    </ul>
@endif
```

La fonction `count()` est une fonction Twide intégrée. Elle renvoie le nombre d'éléments d'une valeur comptable et `0` si la valeur ne l'est pas.

---

## 5. Fonctions intégrées

Les fonctions doivent être enregistrées dans le moteur avant d'être appelées. Les fonctions suivantes sont intégrées dans Twide :

| Fonction | Exemple | Utilité |
|---|---|---|
| `count(value)` | `{{ count(products) }}` | Nombre d'éléments comptables |
| `upper(value)` | `{{ upper(title) }}` | Conversion en majuscules |
| `lower(value)` | `{{ lower(title) }}` | Conversion en minuscules |

Exemple :

```html
<p>{{ upper(title) }}</p>
<p>Nombre de produits : {{ count(products) }}</p>
```

`upper()` et `lower()` utilisent les fonctions PHP de conversion de casse standard. Pour des besoins Unicode avancés, vérifier les extensions et fonctions disponibles dans l'environnement.

---

## 6. Filtres

Un filtre se place après la valeur avec `|`. Certains acceptent des arguments.

### 6.1 Filtres intégrés

| Filtre | Exemple | Utilité |
|---|---|---|
| `upper` | `{{ title \| upper }}` | Majuscules |
| `lower` | `{{ title \| lower }}` | Minuscules |
| `trim` | `{{ name \| trim }}` | Retire les espaces aux extrémités |
| `default('texte')` | `{{ nickname \| default('Visiteur') }}` | Remplace `null` |
| `length` | `{{ products \| length }}` | Taille d'une chaîne ou d'une valeur comptable |

Exemples :

```html
<h1>{{ title | upper }}</h1>
<p>{{ user.name | trim }}</p>
<p>{{ user.nickname | default('Visiteur') }}</p>
<p>{{ products | length }} produit(s)</p>
```

### 6.2 Filtres fournis par `UtilitiesExtension`

Dans l'intégration Astral de la phase 13, le provider enregistre `UtilitiesExtension`. En utilisation autonome, ajouter explicitement cette extension au moteur.

| Filtre | Exemple |
|---|---|
| `slug` | `{{ title \| slug }}` |
| `truncate(80)` | `{{ description \| truncate(80) }}` |
| `excerpt(30)` | `{{ description \| excerpt(30) }}` |
| `ascii` | `{{ title \| ascii }}` |
| `date('d/m/Y')` | `{{ createdAt \| date('d/m/Y') }}` |
| `diff_for_humans` | `{{ createdAt \| diff_for_humans }}` |
| `arr_get('profile.email', '')` | `{{ user \| arr_get('profile.email', '') }}` |
| `arr_only(['id', 'name'])` | `{{ data \| arr_only(['id', 'name']) }}` |
| `arr_except(['password'])` | `{{ data \| arr_except(['password']) }}` |
| `flatten` | `{{ nested \| flatten }}` |

Exemples pratiques :

```html
<a href="/articles/{{ title | slug }}">{{ title }}</a>
<p>{{ description | truncate(120) }}</p>
<p>Publié le {{ createdAt | date('d/m/Y') }}</p>
<p>Adresse : {{ user | arr_get('profile.email', '') }}</p>
```

Les filtres de transformation de tableaux renvoient des tableaux : ne pas les afficher directement comme du texte. Utiliser leur résultat dans une fonction adaptée ou préparer les données dans le contrôleur.

Le filtre `truncate` nécessite l'extension PHP `mbstring`.

---

## 7. Composer les vues

Les directives de composition utilisent des noms de templates et de sections **statiques**, entre guillemets. Les arguments dynamiques pour `@include` ne sont pas pris en charge dans cette version.

### 7.1 Layout : `@extends`

Dans `views/layouts/base.twide` :

```html
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Mon site')</title>
</head>
<body>
    <header><a href="/">Mon site</a></header>
    <main>
        @yield('content')
    </main>
</body>
</html>
```

Dans `views/products/index.twide` :

```twide
@extends('layouts/base')

@section('title')
Catalogue des produits
@endsection

@section('content')
    <h1>Nos produits</h1>
    @for(product in products)
        <p>{{ product.name }}</p>
    @endfor
@endsection
```

`@yield('title', 'Mon site')` affiche le contenu de la section `title`, ou le texte de secours si aucune section n'est définie.

### 7.2 Inclure un fragment : `@include`

Dans `views/partials/notice.twide` :

```html
<aside class="notice">
    <strong>{{ title }}</strong>
    <p>{{ message }}</p>
</aside>
```

Dans une autre vue :

```twide
@include('partials/notice')
```

L'inclusion partage le contexte de données du rendu courant. La syntaxe actuelle n'accepte pas de tableau d'arguments dynamique dans `@include`.

### 7.3 Composant et slot par défaut

Dans `views/components/card.twide` :

```html
<article class="card">
    <div class="card__body">
        @yield('slot')
    </div>
</article>
```

Utilisation :

```twide
@component('components/card')
    <h2>{{ product.name }}</h2>
    <p>{{ product.description }}</p>
@endcomponent
```

Le contenu placé entre `@component` et `@endcomponent` devient le slot par défaut, accessible avec `@yield('slot')` dans le composant.

### 7.4 Slots nommés

Dans `views/components/panel.twide` :

```html
<section class="panel">
    <header>@yield('heading')</header>
    <div class="panel__body">@yield('slot')</div>
</section>
```

Utilisation :

```twide
@component('components/panel')
    @slot('heading')
        <h2>{{ title }}</h2>
    @endslot

    <p>{{ message }}</p>
@endcomponent
```

### 7.5 Ajouter du contenu à une pile : `@push` et `@stack`

Dans un layout :

```html
<head>
    <meta charset="utf-8">
    @stack('head')
</head>
<body>
    {{ title }}
    @stack('scripts')
</body>
```

Dans une vue :

```twide
@push('head')
    <link rel="stylesheet" href="/assets/page.css">
@endpush

@push('scripts')
    <script src="/assets/page.js"></script>
@endpush
```

Les contenus poussés sont rassemblés au niveau des `@stack` portant le même nom. Le contenu des piles est du HTML : n'y placer que du contenu contrôlé par l'application.

---

## 8. Personnaliser Twide côté PHP

Une application peut enregistrer des fonctions, des filtres ou une extension. Exemple autonome :

```php
use Astral\Twide\Twide;

$twide = new Twide([
    'views' => BASE_PATH . '/views',
    'cache' => BASE_PATH . '/storage/cache/views',
]);

$twide->addFunction(
    'price',
    static fn (float $amount): string => number_format($amount, 2, ',', ' ') . ' €'
);
```

Dans la vue :

```html
<p>{{ price(product.price) }}</p>
```

Dans une application Astral utilisant le `TwideServiceProvider` de la phase 13, préférer l'instance configurée et enregistrée dans le conteneur plutôt que de construire un second moteur dans chaque contrôleur.

Ne jamais enregistrer une fonction ou un filtre dont le code provient directement d'un utilisateur final.

---

## 9. Exemple complet de vue HTML

Supposons que le contrôleur fournisse :

```php
return $this->twide('catalog/index', [
    'title' => 'Catalogue',
    'isAdmin' => true,
    'products' => [
        ['name' => 'Clavier', 'price' => 49.9, 'active' => true],
        ['name' => 'Écran', 'price' => 199.0, 'active' => true],
    ],
]);
```

Fichier `views/catalog/index.twide` :

```html
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ title }}</title>
</head>
<body>
    <header>
        <h1>{{ title }}</h1>
        @if(isAdmin)
            <a href="/admin/products">Gérer les produits</a>
        @endif
    </header>

    <main>
        @if(count(products) == 0)
            <p>Aucun produit disponible.</p>
        @else
            <p>{{ count(products) }} produit(s) au catalogue.</p>
            <ul>
                @for(product in products)
                    @if(product.active)
                        <li>
                            <strong>{{ product.name }}</strong>
                            — {{ product.price }} €
                            <small>Article {{ loop.index }} sur {{ loop.length }}</small>
                        </li>
                    @endif
                @endfor
            </ul>
        @endif
    </main>
</body>
</html>
```

---

## 10. Syntaxes non prises en charge dans la phase 13

Pour éviter toute ambiguïté, les éléments suivants ne font pas partie de la syntaxe actuellement implémentée :

- `@foreach ... @endforeach` : utiliser `@for(item in items) ... @endfor`.
- `@empty` : tester la taille avec `@if(count(items) == 0)`.
- boucle PHP indexée `@for($i = 0; ...)` : préparer les données côté contrôleur ou parcourir une collection avec `@for(item in items)`.
- `@switch`, `@case`, `@while`, `@break`, `@continue` : non implémentés.
- exécution de PHP arbitraire dans une vue : non autorisée.
- appels directs de méthodes d'objets : non autorisés.
- `@include('partial', ['foo' => bar])` ou nom d'inclusion calculé dynamiquement : non pris en charge.
- syntaxe Twig ou Blade exacte : non garantie ; Twide possède sa propre grammaire.

La syntaxe est appelée à évoluer. Ce document décrit les fonctionnalités présentes dans le package de la phase 13 et devra être mis à jour lorsque de nouvelles directives seront réellement implémentées et testées.

---

## 11. Règles de sécurité essentielles

1. Utiliser `{{ value }}` pour afficher une valeur textuelle : l'échappement HTML est automatique.
2. Réserver `{! html !}` au HTML fiable ou assaini.
3. Ne pas considérer les données préparées par le contrôleur comme sûres par défaut ; l'échappement reste important.
4. Garder les fichiers compilés dans `storage/cache/views`, hors de la racine publique, avec des permissions adaptées.
5. Ne jamais donner à un utilisateur la possibilité d'enregistrer ses propres fonctions PHP dans le moteur.
6. Ne pas supposer qu'un objet peut être exploré librement : Twide ne lit que ses propriétés publiques.
7. Tester les templates et la composition avec les versions réelles de PHP, Astral et `astral-utilities` utilisées par l'application.

## 12. Vérifications de référence

Avant de considérer une fonctionnalité comme stable dans un projet :

```bash
composer validate
composer install
composer test
```

Puis vérifier au minimum le rendu simple, l'échappement HTML, les conditions, les boucles imbriquées, les layouts, les inclusions, les composants, les slots, les piles et les chemins de cache dans l'application Astral réelle.

**Rappel de philosophie :** le rendu PHP natif reste le choix historique et le plus direct dans Astral. Twide est un composant facultatif qui apporte une syntaxe de vue dédiée aux équipes qui la préfèrent.
