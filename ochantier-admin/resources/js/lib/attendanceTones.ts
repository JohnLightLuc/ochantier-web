import type { AnyTone } from '@/types';

/** Couleur des libellés du module Pointages (statuts, niveaux, localisation). */
const TONES: Record<string, AnyTone> = {
    'Non enregistrée': 'n', 'Non enregistré': 'n', 'En attente de validation': 'a', Validée: 'g', Validé: 'g', Rejetée: 'r', Rejeté: 'r', Corrigée: 'p', Corrigé: 'p', 'Correction demandée': 'a', Justifiée: 'g', 'Non justifiée': 'r', Contestée: 'r', Régularisée: 'p', Annulée: 'n', 'Journée complète': 'g', 'Journée incomplète': 'o',
    Nouvelle: 'r', 'À vérifier': 'o', 'En cours d’analyse': 'a', 'Justification demandée': 'a', Confirmée: 'r', 'Fausse alerte': 'n', Clôturée: 'n', Soumise: 'a', 'En attente': 'a', Appliquée: 'g',
    Brouillon: 'n', Reçue: 'b', Vérifiée: 'g', 'Remplacement demandé': 'o', Information: 'b', Faible: 'n', Modérée: 'a', Élevée: 'o', Critique: 'r', 'Dans la zone': 'g', 'Près de la zone': 'a', 'Hors zone': 'r',
};


export const tone = (label: string, fallback: AnyTone = 'n'): AnyTone => TONES[label] ?? fallback;
