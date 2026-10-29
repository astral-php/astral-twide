# Modèle de sécurité et limites

## Principes

- `{{ expression }}` échappe le résultat avec `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- `{! expression !}` émet une chaîne sans échappement. Cette forme est réservée aux contenus HTML de confiance.
- Les templates ne peuvent pas écrire de PHP arbitraire via la syntaxe Twide ; le compilateur génère lui-même le PHP.
- L'accès à un objet via `user.name` se limite aux propriétés publiques visibles par `get_object_vars()`. Twide n'appelle pas automatiquement les méthodes ou les getters.
- Les noms de templates sont résolus sous la racine de vues configurée. Les chemins absolus, segments `.` / `..` et échappements via liens symboliques sont refusés.
- Les templates et le cache compilé doivent être considérés comme des artefacts de confiance. Le répertoire de cache doit être hors de la racine publique du serveur web et accessible uniquement au compte de l'application.
- Les fonctions et filtres enregistrés sont du code PHP de l'application. Ils ne doivent jamais être construits à partir de données utilisateur non fiables.

## Limites actuelles

- Twide n'est pas un bac à sable pour auteurs de templates hostiles. Un accès au système de fichiers du template ou à la configuration du serveur peut permettre des dénis de service par taille, profondeur ou nombre de rendus.
- `{! ... !}` permet une injection HTML si son entrée n'est pas fiable.
- Les noms de templates de `@extends`, `@include` et `@component` sont statiques et passés au `TemplateLoader`, mais le préprocesseur demeure une implémentation à renforcer avec des tests de grammaire plus exhaustifs.
- Les limites de taille et de nombre de tokens réduisent certains risques de consommation de ressources, sans constituer une garantie complète.
- Le moteur n'est pas déclaré prêt pour la production avant exécution de la suite PHPUnit, tests de sécurité et tests d'intégration dans une application Astral réelle.

## Vérifications recommandées avant production

1. Exécuter `composer validate` et `composer test`.
2. Tester l'échappement HTML sur toutes les sorties, notamment les attributs et chaînes Unicode.
3. Tester les cycles, inclusions imbriquées, sections et composants avec cas mal formés.
4. Tester l'isolation de la racine des vues, notamment les symlinks et chemins Windows.
5. Confirmer que le cache n'est pas publiquement accessible et que son répertoire est inscriptible par le processus PHP.
6. Tester la compatibilité sur les versions PHP réellement supportées.
