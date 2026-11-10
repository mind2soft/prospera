---
last-updated: 2026-11-09
---

> **Document en évolution.** Cette politique est un document évolutif : elle
> évoluera à mesure que le service et sa sécurité se renforcent. Les
> changements importants seront reflétés dans la date « Dernière mise à
> jour » ci-dessus.

Prospera traite des informations financières sensibles. Cette page explique,
en termes simples, quelles données l'application collecte, comment elles sont
stockées et protégées, combien de temps elles sont conservées et ce qu'il
advient d'elles lorsqu'un compte est supprimé.

> **Ceci n'est pas un avis juridique.** Ce document décrit comment Prospera
> est conçu et se comporte aujourd'hui ; il ne s'agit pas d'un avis juridique
> ni d'un substitut à celui-ci.

## Vos données vous appartiennent

Prospera n'utilise **pas** vos données à des fins d'analyse et ne les vend ni
ne les partage avec des tiers.

L'empreinte dans le navigateur est volontairement minimale :

- un **cookie de session de connexion**, requis pour vous maintenir connecté
  (protection de session et CSRF) ;
- une entrée **`localStorage`** sur votre appareil, utilisée uniquement pour
  mémoriser le profil financier que vous avez sélectionné en dernier et votre
  préférence d'interface (thème clair/sombre).

Aucun traceur publicitaire, aucune télémétrie, aucun script d'analyse
tiers.

## Ce qui est collecté

### Votre compte

La création d'un compte requiert un **nom**, une **adresse courriel** et un
**mot de passe** (optionnellement une clé d'accès, un secret à deux facteurs
et des codes de récupération, ainsi qu'une langue préférée). L'adresse
courriel sert à l'authentification, à la vérification du compte, aux avis de
sécurité et aux notifications que vous activez explicitement.

### Données financières

Tout ce que vous saisissez — profils financiers, enveloppes, budgets,
grands livres, transactions, soldes de départ et tout autre montant — est
conservé dans le seul but de vous fournir le service, ainsi
qu'aux membres avec qui vous partagez un profil financier.

### Données techniques côté serveur

Lorsque vous utilisez le **formulaire de contact**, nous journalisons la
soumission afin de pouvoir y répondre et limiter les abus :

- l'**adresse courriel** et l'**objet** que vous fournissez ;
- votre **message**, stocké chiffré au repos ;
- l'**adresse IP** d'où provient la requête, utilisée pour limiter le débit du
  formulaire et appliquer son temps d'attente par IP.

Les soumissions de contact sont supprimées après une période de rétention
configurable (90 jours par défaut). Les adresses IP sont également traitées
de manière transitoire par le serveur web et les limiteurs de débit pour la
protection contre les abus.

## Comment vos données financières sont protégées

**Chiffrement au repos.** Tous les montants financiers — transactions des
grands livres, montants des budgets et soldes de départ — sont stockés **chiffrés** avec une clé de chiffrement
**propre à chaque profil financier**. Les montants ne sont jamais déchiffrés
que côté serveur, après vérification des permissions, pour les servir aux
personnes à qui vous avez accordé l'accès. Les clés sont renouvelées régulièrement
et ne sont jamais partagées avec les clients. Pour les détails techniques,
voir le document de conception
[chiffrement au repos](https://github.com/mind2soft/prospera/blob/main/docs/encryption.md).

**Modèle de permissions.** Le partage est explicite : les membres d'un profil
financier voient ses budgets et ses grands livres, et des permissions plus
fines (lecteur/contributeur) peuvent être accordées par ressource. Les
utilisateurs sans même une permission de lecture sur une enveloppe ne
reçoivent jamais ses montants — le serveur les masque avant de répondre.

**Vos identifiants sont votre responsabilité.** Vous êtes responsable de vos
propres identifiants et méthodes d'authentification (mot de passe,
authentification à deux facteurs, clés d'accès) et de leur confidentialité.
Prospera ne peut être tenue responsable d'une usurpation d'identité
résultant d'identifiants compromis.

**Violations de données.** En cas de violation de données affectant la base
de données, les utilisateurs en seront informés.

## Combien de temps les données sont conservées

Les registres financiers ne survivent pas à leur utilité. Les règles
ci-dessous constituent la politique de rétention à laquelle le service
s'engage :

- un **grand livre**, avec les budgets et les transactions liés à sa période
  couverte, est conservé jusqu'à **7 ans après sa date de fin**, puis
  supprimé (par exemple, un grand livre se terminant le 31 décembre 2026 est
  supprimé au début de 2034) ;
- un **budget publié** est conservé jusqu'à **7 ans après son mois effectif**,
  et l'ébauche d'un budget **7 ans après sa création** ;
- la suppression s'effectue au niveau applicatif — les montants ne peuvent
  être interprétés que dans l'application — de sorte qu'un enregistrement est
  soit entièrement présent, soit entièrement supprimé, jamais partiellement.

Les enveloppes et les transactions planifiées ne sont pas liées à une période
et ne sont pas supprimées automatiquement ; elles sont retirées avec le compte
de leur propriétaire, comme décrit ci-dessous.

> Cette période de rétention est une décision de politique et est susceptible
> de changer ; cette page est l'endroit où la règle actuelle est publiée.

## Lors de la suppression de votre compte

Lorsque vous supprimez votre compte :

- tout profil financier resté sans membre restant — y compris tous ses grands
  livres, budgets et transactions — est supprimé ;
- tout profil partagé comptant encore d'autres membres **conserve ses
  données** : la propriété passe au **membre restant arrivé en premier**
  (égalités départagées par le plus petit identifiant d'utilisateur),
  conformément aux règles documentées de transfert de propriété.

**Il vous incombe de révoquer les permissions de partage avant de supprimer
votre compte** si vous ne voulez pas qu'un profil financier continue
d'exister — et soit transféré — sans vous.

## Espaces réservés — à compléter

Les sections suivantes sont volontairement en attente et seront ajoutées à
mesure que le service mûrit :

- l'identité et les coordonnées du **responsable du traitement** ;
- le lieu d'**hébergement / de traitement** ;
- les **juridictions** et régimes applicables (p. ex. LPRPDE/PIPEDA
  canadienne et Loi 25 du Québec ; droits de type RGPD pour les utilisateurs
  de l'UE) ;
- un résumé de **vos droits** : les données que vous pouvez obtenir, corriger
  et supprimer, et comment exercer ces droits.
