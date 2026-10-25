<?php

namespace App\Support\Push;

/**
 * Envoi des notifications push vers les appareils enregistrés. Brancher ici le fournisseur retenu (FCM…).
 */
interface PushGateway
{
    /**
     * @param  list<string>  $tokens  Jetons push des appareils du destinataire.
     * @param  array<string, mixed>  $data  Données de navigation transmises à l’application.
     */
    public function send(array $tokens, string $title, string $body, array $data = []): void;
}
