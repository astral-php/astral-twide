# Guide de référence des vues Astral Twide

**Package :** `astral-php/astral-twide`  
**Cible :** Astral MVC 2.x (`PHP ^8.3`, `astral-core ^2`), intégration optionnelle via `TwideServiceProvider`  
**Statut :** guide aligné sur le complément **P1–P5** (config, conditionnels, boucles, `@set`, includes enrichis).

Ce guide explique comment écrire une vue HTML `.twide`, préparer les données dans le contrôleur, afficher des valeurs, utiliser conditions / boucles / composition, et personnaliser le moteur en sécurité.

Docs liées : [`ASTRAL-MVC-INTEGRATION.md`](ASTRAL-MVC-INTEGRATION.md) · [`EXTENSIONS.md`](EXTENSIONS.md) · [`SECURITY.md`](SECURITY.md) · [`DEBUG.md`](DEBUG.md).

---

## 0. Philosophie Astral + Twide

- Les **apps Astral officielles** restent sur les **vues PHP natives**.
- Twide est un moteur **opt-in** pour les projets qui le choisissent.
- Préparer les données **dans le contrôleur** (tableaux / DTO plats). Les résultats BDD / ORM sont en général déjà des **arrays** ; si une classe métier est utilisée, **mapper** vers un tableau (ou n’exposer que des **propriétés publiques**) avant `$this->twide(...)`.
- Twide **n’appelle pas** les méthodes ni les getters magiques sur les objets (`user.getName()` interdit). C’est volontaire (sécurité + clarté).

---

## 1. Transmettre des données à une vue

Les données sont un **tableau associatif**.

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

Le nom `users/index` → fichier `views/users/index.twide` (racine et suffixe configurables, voir §13).

```html
<h1>{{ title }}</h1>
<ul>
    @foreach(users as user)
        <li>{{ user.name }} — {{ user.email }}</li>
    @endforeach
</ul>
```

Les clés du tableau deviennent des variables. Pour `['user' => ['name' => 'Ada']]` → `{{ user.name }}`.

---

## 2. Affichage

### 2.1 `{{ expression }}` — échappement HTML (défaut)

```html
<h1>{{ title }}</h1>
<p>Bonjour {{ user.name }}.</p>
```

Si `title` contient `<script>`, le contenu est affiché comme texte, pas exécuté.

### 2.2 Chemins et index

```html
<p>{{ user.profile.firstName }}</p>
<p>{{ products[0].name }}</p>
<p>{{ product['sku'] }}</p>
```

Fonctionne sur tableaux associatifs et **propriétés publiques** d’objets uniquement.

### 2.3 Valeur absente ou `null`

`{{ ... }}` affiche une chaîne vide. Filtre `default` pour un remplacement si `null` :

```html
<p>{{ user.nickname | default('Aucun surnom') }}</p>
```

### 2.4 `{! expression !}` — HTML brut

```html
<div class="article-content">{! trustedHtml !}</div>
```

**Réservé** au HTML de confiance ou déjà assaini. Jamais du contenu utilisateur brut.

### 2.5 Commentaires

```twide
{{-- Invisible dans le HTML final. --}}
```

### 2.6 Tableaux littéraux dans les expressions

```twide
@include('partials/input' with ['type' => 'text', 'name' => 'email'])
```

---

## 3. Conditions

### 3.1 `@if` / `@elseif` / `@else` / `@endif`

```twide
@if(status == 'published')
    <span class="badge">Publié</span>
@elseif(status == 'draft')
    <span class="badge">Brouillon</span>
@else
    <span class="badge">En attente</span>
@endif
```

### 3.2 `@isset` / `@endisset`

Vrai si le chemin est **défini et non `null`**.

```twide
@isset(user)
    <p>{{ user.name }}</p>
@endisset

@isset(user.email)
    <a href="mailto:{{ user.email }}">{{ user.email }}</a>
@endisset
```

### 3.3 `@empty` / `@endempty` (standalone)

Sémantique proche de `empty()` PHP (absent, `null`, `''`, `0`, `[]`, …).

```twide
@empty(users)
    <p>Aucun utilisateur.</p>
@endempty
```

> **Attention :** bare `@empty` (sans arguments) n’est valide que dans un `@forelse` (§4.3).  
> Standalone → toujours `@empty(expr) … @endempty`.

### 3.4 `@switch` / `@case` / `@default` / `@endswitch`

```twide
@switch(status)
@case('actif')
    Actif
    @break
@case('inactif')
    Inactif
    @break
@default
    Inconnu
@endswitch
```

Utiliser `@break` dans chaque `@case` pour éviter le fall-through PHP.

### 3.5 Opérateurs d’expression

- comparaison : `==`, `!=`, `===`, `!==`, `<`, `>`, `<=`, `>=`
- logique : `&&`, `||`, `!`
- arithmétique : `+`, `-`, `*`, `/`, `%`, `**`
- null-coalesce : `??`
- ternaire : `condition ? a : b`

Pas de PHP arbitraire dans les expressions.

---

## 4. Boucles

### 4.1 `@for(item in items)` — parcours (historique Twide)

```twide
@for(product in products)
    <li>{{ product.name }} — {{ product.price }} €</li>
@endfor
```

Équivalent d’usage à un `foreach` PHP sur un tableau / `Traversable`.

### 4.2 `@foreach` — syntaxe étendue

```twide
@foreach(users as user)
    <p>{{ user.name }}</p>
@endforeach

@foreach(users as key => user)
    <p>#{{ key }} — {{ user.name }}</p>
@endforeach

@foreach(users as user if user.active)
    <p>{{ user.name }}</p>
@endforeach
```

### 4.3 `@forelse` / `@empty` / `@endforelse`

```twide
@forelse(products as product)
    <li>{{ product.name }}</li>
@empty
    <p>Aucun produit.</p>
@endforelse
```

Ici `@empty` est **sans arguments** (branche vide du forelse).

### 4.4 `@for(i in start count n)` — plage

`n` = **nombre d’itérations** à partir de `start` (pas une boucle C `for($i=…)`).

```twide
@for(i in 0 count 3)
    <span>{{ i }}</span>
@endfor
{{-- 0 1 2 --}}

@for(page in 1 count totalPages)
    <a href="?p={{ page }}">{{ page }}</a>
@endfor
```

### 4.5 `@while`

Condition = expression Twide / fonctions **enregistrées** uniquement.

```twide
@while(cursor < max)
    <p>{{ cursor }}</p>
    @set(cursor = cursor + 1)
@endwhile
```

### 4.6 `@break` / `@continue`

Autorisés **uniquement** dans une boucle (`@for`, `@foreach`, `@forelse`, `@while`) ; `@break` aussi dans `@switch`.

```twide
@foreach(nums as n)
    @if(n == 2)
        @continue
    @endif
    @if(n == 4)
        @break
    @endif
    {{ n }};
@endforeach
```

### 4.7 Métadonnées `loop`

| Propriété | Signification |
|-----------|----------------|
| `loop.index` | Position depuis 1 |
| `loop.index0` | Position depuis 0 |
| `loop.first` / `loop.last` | booléens |
| `loop.length` | taille |
| `loop.key` | clé courante |
| `loop.parent` | métadonnées de la boucle parente |

Le nom de variable `loop` est **réservé**.

---

## 5. `@set` — variables locales au rendu

```twide
@set(title = 'Mon titre')
@set(total = price * quantity)
<p>{{ title }} — {{ total }}</p>
```

Ne pas `@set(loop = …)`.

---

## 6. Fonctions et filtres

### 6.1 Fonctions intégrées

| Fonction | Exemple |
|----------|---------|
| `count(value)` | `{{ count(products) }}` |
| `upper(value)` | `{{ upper(title) }}` |
| `lower(value)` | `{{ lower(title) }}` |

### 6.2 Filtres intégrés

| Filtre | Exemple |
|--------|---------|
| `upper` / `lower` / `trim` | `{{ title \| upper }}` |
| `default('…')` | `{{ nickname \| default('Visiteur') }}` |
| `length` | `{{ products \| length }}` |

### 6.3 `UtilitiesExtension` (provider Astral)

Enregistrée automatiquement par `TwideServiceProvider`. En autonome : `$twide->addExtension(new UtilitiesExtension())`.

Filtres utiles : `slug`, `truncate(80)`, `excerpt(30)`, `ascii`, `date('d/m/Y')`, `diff_for_humans`, `arr_get`, `arr_only`, `arr_except`, `flatten`.  
Détail : [`EXTENSIONS.md`](EXTENSIONS.md). `truncate` nécessite `ext-mbstring`.

---

## 7. Composition des vues

Noms de templates / sections **statiques** (guillemets). Pas de nom d’include calculé dynamiquement.

### 7.1 Layout : `@extends` / `@section` / `@yield`

```twide
@extends('layouts/base')

@section('title')
Catalogue
@endsection

@section('content')
    <h1>Produits</h1>
@endsection
```

### 7.2 `@include`

```twide
@include('partials/notice')
```

Partage le contexte courant.

### 7.3 `@include … with` — contexte additionnel

Fusionne un **tableau** ou les **propriétés publiques** d’un objet dans le contexte le temps du partial, puis restaure.

```twide
@include('partials/input' with ['type' => 'text', 'name' => 'email'])

@include('user/profile' with user)
```

### 7.4 `@each` — include répété

```twide
@each('user/card', users, 'user')

@each('user/card', users, 'user', 'partials/empty')

@each('user/card', users, 'user', 'raw|Aucun utilisateur')
```

- 3ᵉ argument = nom de variable **entre guillemets** (`'user'`).
- 4ᵉ optionnel : autre template **ou** `'raw|texte'` (texte **échappé** HTML).

Équivalent interne : `@forelse` + `@include` (+ branche vide).

### 7.5 Composants, slots, piles

```twide
@component('components/card')
    @slot('heading')
        <h2>{{ title }}</h2>
    @endslot
    <p>{{ message }}</p>
@endcomponent

@push('scripts')
    <script src="/assets/page.js"></script>
@endpush
```

Layout : `@stack('scripts')`. Contenu des piles = HTML contrôlé par l’app.

---

## 8. Personnaliser Twide (app)

### 8.1 Config `config/app.php`

```php
'twide' => [
    'views' => BASE_PATH . '/views',
    'cache' => BASE_PATH . '/storage/cache/views',
    'extension' => '.twide',
    'extensions' => [
        \App\Twide\ShopExtension::class,
    ],
],
```

Voir [`ASTRAL-MVC-INTEGRATION.md`](ASTRAL-MVC-INTEGRATION.md) et [`EXTENSIONS.md`](EXTENSIONS.md).  
**Conseillé :** tests PHPUnit pour chaque extension applicative.

### 8.2 Fonction / filtre manuel

```php
$twide->addFunction(
    'price',
    static fn (float $amount): string => number_format($amount, 2, ',', ' ') . ' €'
);
```

Ne jamais enregistrer du code provenant d’un utilisateur final.

---

## 9. Exemple complet

Contrôleur (données déjà en arrays) :

```php
return $this->twide('catalog/index', [
    'title' => 'Catalogue',
    'isAdmin' => true,
    'products' => [
        ['name' => 'Clavier', 'price' => 49.9, 'active' => true],
        ['name' => 'Écran', 'price' => 199.0, 'active' => false],
    ],
]);
```

`views/catalog/index.twide` :

```html
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ title }}</title>
</head>
<body>
    <h1>{{ title }}</h1>
    @if(isAdmin)
        <a href="/admin/products">Gérer</a>
    @endif

    @forelse(products as product)
        @if(product.active)
            <p>{{ loop.index }}. {{ product.name }} — {{ product.price }} €</p>
        @endif
    @empty
        <p>Catalogue vide.</p>
    @endforelse
</body>
</html>
```

Pour « uniquement les actifs » avec une branche vide cohérente, **filtrer dans le contrôleur** (recommandé) puis `@forelse(activeProducts as product)`. Le clause `if` existe sur `@foreach`, pas sur `@forelse`.

---

## 10. Débogage

Les erreurs de syntaxe suivent :

```text
Twide: <message> (template "<nom>", line <n>, column <m>).
```

Exemples : `Missing @endif for @if`, `Invalid @foreach syntax`, `@break is only allowed inside a loop…`.

Activer `debug` en local ; en prod, journaliser l’exception et ne pas exposer les chemins serveur dans la page publique. Détail : [`DEBUG.md`](DEBUG.md).

---

## 11. Hors scope / non supporté (volontaire)

| Sujet | Alternative |
|-------|-------------|
| Appels de méthodes / getters dans la vue | Mapper en array / props publiques dans le contrôleur |
| PHP arbitraire dans `.twide` | Logique dans le contrôleur / services |
| `@for($i = 0; $i < n; $i++)` style C | `@for(i in 0 count n)` ou collection préparée |
| Nom d’`@include` dynamique | Noms statiques uniquement |
| Compatibilité syntaxe Blade/Twig exacte | Grammaire Twide dédiée |

---

## 12. Sécurité (rappel)

1. `{{ }}` par défaut ; `{! !}` seulement si confiance.  
2. Cache `storage/cache/views` hors webroot.  
3. Templates = artefacts de confiance ; chemins sous la racine `views`.  
4. Extensions / filtres = code applicatif uniquement.  
5. `'raw|…'` dans `@each` est échappé ; ce n’est pas du HTML brut.

Voir [`SECURITY.md`](SECURITY.md).

---

## 13. Chemins par défaut

| Clé | Défaut |
|-----|--------|
| `twide.views` | `BASE_PATH/views` |
| `twide.cache` | `BASE_PATH/storage/cache/views` |
| `twide.extension` | `.twide` |
| `twide.extensions` | `[]` |

---

## 14. Vérifications

```bash
composer validate
composer install
composer test
```

Vérifier ensuite dans une app réelle : échappement, conditions, boucles, layouts, includes/`with`/`each`, cache, messages d’erreur.

**Rappel :** le rendu PHP natif reste le choix historique Astral. Twide est un composant facultatif pour les équipes qui veulent une syntaxe de vues dédiée.
