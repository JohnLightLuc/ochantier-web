<?php

namespace App\Support\Sms;

/**
 * Envoi de SMS (codes de connexion, notifications). Brancher ici le fournisseur retenu (Orange, MTN, Moov…).
 */
interface SmsGateway
{
    /**
     * @param  string  $phone  Numéro au format international (+225…).
     */
    public function send(string $phone, string $message): void;
}
