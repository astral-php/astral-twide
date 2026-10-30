# Changelog — astral-twide

## [1.0.0] — 2026-10-30

Première release stable Packagist feuille 2.x — moteur de templates **opt-in** pour Astral MVC.

### Added

- Config `twide.views` / `cache` / `extension` / `extensions` (FQCN) via `TwideServiceProvider`
- Directives : `@isset`, `@empty`/`@endempty`, `@switch`/`@case`/`@default`, `@break`/`@continue`
- Boucles : `@for(item in items)`, `@foreach`, `@forelse`, `@for(i in start count n)`, `@while`
- `@set(name = expr)`
- `@include('x' with expr)` · `@each('x', items, 'item'[, empty|raw|…])`
- Composition : `@extends`, `@section`, `@yield`, `@include`, `@component`, `@slot`, `@push`, `@stack`
- Tableaux associatifs d’expressions `['k' => v]`
- `UtilitiesExtension` + `astral-php/astral-utilities:^0.1.0`
- Messages d’erreur localisés : `Twide: … (template "…", line N, column M)`
- Docs : guide vues, intégration MVC, extensions, security, debug

### Compatibility

- PHP `^8.3`
- `astral-php/astral-core` `^2.0`

### Notes

- Apps Astral officielles restent sur vues PHP natives ; Twide = opt-in externe.
- Pas d’appels méthodes/getters dans les templates (préparer arrays / props publiques dans le contrôleur).
