import type { ToneAlias } from '@/types';

/** Couleur des libellés de statut des données de démonstration du module Ouvriers (repris de la maquette). */
const TONES: Record<string, ToneAlias> = {
    'Profil incomplet': 'n', 'À vérifier': 'a', 'En vérification': 'b', Validé: 'g', Rejeté: 'r', Suspendu: 'r', Archivé: 'n',
    Disponible: 'g', Préaffecté: 'b', Affecté: 'p', 'En mission': 'a', Indisponible: 'r',
    Proposée: 'b', 'En attente d’acceptation': 'a', Acceptée: 'b', Refusée: 'r', 'À valider': 'a', Confirmée: 'g', 'En cours': 'g', Suspendue: 'r', Terminée: 'n', Annulée: 'n', 'Remplacement demandé': 'o', Remplacée: 'p',
    Complet: 'g', Incomplet: 'a', 'En retard': 'o', Absence: 'r', Contesté: 'r', Corrigé: 'p', 'En attente': 'a',
    'À calculer': 'n', 'En attente de validation': 'a', Programmé: 'b', Payé: 'g', Échoué: 'r', Remboursé: 'p',
    Vérifié: 'g', Expiré: 'o', Manquant: 'n', Signalé: 'o', 'En analyse': 'b', 'Recherche en cours': 'b', 'Remplaçant proposé': 'p', Refusé: 'r', 'Remplacement effectué': 'g', Annulé: 'n', Clôturé: 'n',
    'Partiellement disponible': 'a', Repos: 'n', 'Absence déclarée': 'r', Publié: 'g', Masqué: 'n',
    Faible: 'n', Moyenne: 'a', Élevée: 'o', Critique: 'r', Ponctuelle: 'b', 'Plusieurs jours': 'a', Contractuelle: 'p',
};

export function workerTone(label: string): ToneAlias {
    return TONES[label] ?? 'n';
}
