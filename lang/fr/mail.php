<?php

// Textes des courriels (objets et corps en texte brut). Les courriels sont
// rendus dans la langue du destinataire (users.locale, repli en) —
// docs/notifications.md.
return [

    'roles' => [
        'owner' => 'propriétaire',
        'collaborator' => 'collaborateur',
        'viewer' => 'lecteur',
    ],

    'invite' => [
        'subject' => '[Prospera] :inviter a partagé son profil financier « :profile » avec vous',
        'subject_generic' => '[Prospera] Un profil financier a été partagé avec vous',
        'new_account' => [
            'intro' => ':inviter (:email) a partagé son profil financier « :profile » avec vous sur Prospera, en tant que :role.',
            'action' => 'Pour le rejoindre, créez votre compte avec le lien ci-dessous — votre adresse courriel est déjà renseignée, et le profil partagé vous est accessible dès que votre compte est vérifié.',
        ],
        'existing_account' => [
            'intro' => ':inviter (:email) a partagé son profil financier « :profile » avec vous, en tant que :role. Pour accepter, cliquez ci-dessous ; si vous ne souhaitez pas le rejoindre, ignorez simplement ce message — ignorer la demande revient à refuser le partage.',
            'other_account' => 'Si le lien ci-dessus vous connecte sous un autre compte, ouvrez plutôt :url.',
        ],
        'expires' => 'Cette invitation expire le :date (48 heures après son envoi).',
        'manage_notifications' => 'Gérer vos notifications :',
    ],

    // Grand livre expiré (issue #50) : un grand livre ouvert dont la date
    // de fin est passée, envoyé une seule fois par grand livre, quand la
    // tâche quotidienne le détecte pour la première fois.
    'ledger_expired' => [
        'subject' => '[Prospera] Le grand livre « :ledger » a atteint sa date de fin',
        'subject_generic' => '[Prospera] Un grand livre a atteint sa date de fin',
        'intro' => 'Le grand livre ouvert « :ledger » de votre profil financier « :profile » a atteint sa date de fin (:end — il s\'est déroulé du :start). Sa date de fin est passée : plus aucune transaction postérieure au :end ne peut y être enregistrée.',
        'next_steps_owner' => 'En tant que propriétaire du grand livre, vous pouvez le fermer dès maintenant, ou créer d\'abord le grand livre de la prochaine période et fermer celui-ci une fois votre révision terminée.',
        'next_steps_member' => 'Le propriétaire du grand livre peut le fermer, ou créer le grand livre de la prochaine période pour continuer à enregistrer des transactions.',
        'manage_notifications' => 'Gérer vos notifications :',
    ],

    // Transactions des collaborateurs (issue #51) : un autre utilisateur
    // enregistre une transaction dans un grand livre dont on est le
    // propriétaire — un courriel par transaction, livré au propriétaire
    // actuel du grand livre.
    'contributor_transaction' => [
        'subject' => '[Prospera] :actor a enregistré une transaction dans « :ledger »',
        'subject_generic' => '[Prospera] Une transaction a été enregistrée',
        'subject_generic_named' => '[Prospera] Une transaction a été enregistrée dans « :ledger »',
        'actor_generic' => 'Un membre ayant depuis supprimé son compte',
        'intro' => ':actor a enregistré une transaction dans « :ledger » (profil financier « :profile ») : enveloppe « :envelope » — :direction, datée du :date.',
        'amount' => 'Montant : :amount',
        'next_steps' => 'La transaction est visible dans le grand livre ci-dessous.',
        'manage_notifications' => 'Gérer vos notifications :',
    ],

    'directions' => [
        'expense' => 'dépense',
        'revenue' => 'revenu',
    ],

    // Limite d'enveloppe dépassée (issue #52) : les dépenses enregistrées
    // pour une enveloppe au cours d'un mois ont franchi son allocation sur
    // le budget publié de ce mois — un courriel par franchissement, par
    // budget publié, enveloppe et grand livre.
    'envelope_limit_exceeded' => [
        'subject' => '[Prospera] L\'enveloppe « :envelope » dépasse son budget de :month',
        'subject_generic' => '[Prospera] Une enveloppe dépasse son budget mensuel',
        'intro' => 'L\'enveloppe « :envelope » du grand livre « :ledger » (profil financier « :profile ») dépasse son allocation pour :month.',
        'allocated' => 'Alloué : :amount',
        'spent' => 'Enregistré ce mois : :amount',
        'over' => 'Montant dépassé : :amount',
        'next_steps' => 'Le mois est visible dans le grand livre ci-dessous.',
        'manage_notifications' => 'Gérer vos notifications :',
    ],

];
