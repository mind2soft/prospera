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

];
