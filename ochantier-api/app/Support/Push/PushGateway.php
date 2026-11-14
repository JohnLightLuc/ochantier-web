<?php

namespace App\Support\Push;

/**
 * Envoi des notifications push vers les appareils enregistrés. Brancher ici le fournisseur retenu (FCM…).
 */
interface PushGateway
{
    /**
     * @param  list<string>  $tokens  Jetons push des appareils du destinataire.
     * @param  array<string, mixed>  $data  Données transmises à l’application : event, target (écran à ouvrir) et
     *                                      unread_count (non lues du destinataire, notification envoyée comprise), repris
     *                                      comme badge de l’icône sur iOS.
     */
    public function send(array $tokens, string $title, string $body, array $data = []): void;
}
