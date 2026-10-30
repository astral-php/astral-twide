# Débogage Twide

## Format des erreurs

Les erreurs de syntaxe / structure du parseur suivent :

```text
Twide: <message clair> (template "<nom>", line <n>, column <m>).
```

Exemples :

- `Missing @endif for @if`
- `@break is only allowed inside a loop … or @switch`
- `Invalid @foreach syntax. Expected: items as item…`

## Bonnes pratiques

1. Activer `debug` dans `config/app.php` en local pour remonter les exceptions.
2. En production, laisser l’app Astral masquer les chemins serveur dans les pages d’erreur HTTP ; le message Twide reste utile dans les logs.
3. Pour une extension custom, écrire des tests PHPUnit (voir [`EXTENSIONS.md`](EXTENSIONS.md)).

## `@empty` : deux sens

| Forme | Rôle |
|-------|------|
| `@empty(expr) … @endempty` | Test « vide » standalone |
| `@forelse … @empty … @endforelse` | Branche vide du `@forelse` (directive **sans** arguments) |

## Includes

- `@include('partial')` — nom **statique** uniquement (sécurité).
- `@include('partial' with expr)` — fusion runtime du tableau / propriétés publiques dans le contexte, puis restauration.
- `@each('partial', items, 'item'[, empty])` — sugar `@forelse` + include ; `empty` = autre template ou `'raw|texte'` (échappé HTML).
