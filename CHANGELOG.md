# Changelog — astral-twide

## [0.1.0-dev] — complément (P1–P4, sans tag)

### Added

- Config `twide.views` / `cache` / `extension` / `extensions` (FQCN) via `TwideServiceProvider`
- Directives : `@isset`, `@empty`/`@endempty`, `@switch`/`@case`/`@default`, `@break`/`@continue` (boucles)
- Boucles : `@foreach`, `@forelse`, `@for(i in start count n)`, `@while`
- `@set(name = expr)`
- Messages d’erreur localisés : `Twide: … (template "…", line N, column M)`
- Lexer : matching des directives connues (plus long préfixe) pour `@emptyNONE`, etc.
- Docs : `DEBUG.md`, extensions app + tests PHPUnit conseillés

### Changed

- Cible feuille 2.x : PHP `^8.3`, `astral-php/astral-core` `^2.0`
- Scaffold officiel : `components-astral/astral-twide`

### Inherited from prototype (phases 6–13)

- Moteur compilé `.twide` : conditions, boucles, composition, composants, piles
- `UtilitiesExtension` + `astral-php/astral-utilities:^0.1.0`
- `TwideServiceProvider`, `TwideViewAdapter`, cache compilé durci
- Suite PHPUnit d’intégration / contrat

---

Les entrées « Phase N » ci-dessous décrivent l’historique du prototype DT, pas des releases Packagist.

## Phase 13 — Intégration Astral

- Provider, chemins `views` / `storage/cache/views`, helper contrôleur `twide()`

## Phase 12 — Stabilisation tests

- Suite PHPUnit consolidée

## Phase 11 — Hardening rendu / cache

- Permissions cache restrictives, signatures, tests sécurité

## Phase 10 — Préparation publication

- Documentation, signature cache, tests parseur

## Phase 9 — Utilities

- `UtilitiesExtension` + dépendance `astral-utilities`

## Phases 6–8

- Composition, composants, piles, adaptateur de vues
