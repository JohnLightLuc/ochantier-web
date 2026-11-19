<?php

namespace App\Support\Payments;

use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Éléments en cours de paiement. Tant qu’une demande de paiement est en cours sur un élément (acompte, échéance,
 * versement, abonnement, commande de devis), aucune autre ne peut démarrer dessus : la clé primaire de
 * payment_holds l’interdit, même entre deux requêtes simultanées. Une retenue est libérée dès que le paiement
 * aboutit ou échoue ; si son issue reste inconnue (prestataire injoignable, arrêt du serveur), elle tombe d’elle-même
 * après HOLD_MINUTES.
 */
class PaymentHolds
{
    /**
     * Durée de vie d’une retenue dont le paiement n’a pas abouti (minutes).
     */
    public const HOLD_MINUTES = 15;

    /**
     * Empreinte du payeur et de sa clé d’idempotence : identifie la demande qui détient une retenue.
     */
    public function holder(string $payerType, string $payerId, string $idempotencyKey): string
    {
        return hash('sha256', $payerType.'|'.$payerId.'|'.$idempotencyKey);
    }

    /**
     * Retient tous les éléments, ou aucun : si l’un d’eux est déjà en cours de paiement, la demande est refusée.
     *
     * @param  list<array{0: string, 1: string}>  $targets  [type, identifiant]
     */
    public function acquire(array $targets, string $holder): void
    {
        DB::transaction(function () use ($targets, $holder): void {
            foreach ($targets as [$type, $id]) {
                DB::table('payment_holds')->where('target_type', $type)->where('target_id', $id)->where('expires_at', '<', now())->delete();

                $held = DB::table('payment_holds')->insertOrIgnore([
                    'target_type' => $type,
                    'target_id' => $id,
                    'holder_key' => $holder,
                    'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
                ]);

                if ($held === 0) {
                    throw ValidationException::withMessages(['payment' => 'Un paiement est déjà en cours pour cet élément. Patientez quelques instants avant de réessayer.']);
                }
            }
        });
    }

    /**
     * Rattache les retenues de la demande à sa transaction, une fois celle-ci créée.
     */
    public function attach(string $holder, PaymentTransaction $transaction): void
    {
        DB::table('payment_holds')->where('holder_key', $holder)->update(['transaction_id' => $transaction->getKey()]);
    }

    /**
     * Libère les retenues de la demande : le paiement a abouti, a échoué ou n’a pas pu démarrer.
     */
    public function release(string $holder): void
    {
        DB::table('payment_holds')->where('holder_key', $holder)->delete();
    }
}
