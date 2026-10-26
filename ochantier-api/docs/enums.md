# OChantier API — Référentiel des enums

_Généré le 26/10/2026 à partir de `app/Enums` (136 enums)._

Ce document liste les valeurs acceptées et renvoyées par l'API pour chaque champ énuméré.

- **Valeur API** : la chaîne exacte envoyée dans les requêtes et reçue dans les réponses JSON (sensible à la casse).
- **Libellé** : texte d'affichage recommandé côté application.

## Sommaire

- [AdminStatus](#adminstatus)
- [AnomalyDetector](#anomalydetector)
- [AppName](#appname)
- [AppPlatform](#appplatform)
- [ApprovalStatus](#approvalstatus)
- [AssignmentStatus](#assignmentstatus)
- [AttendanceAnomalyStatus](#attendanceanomalystatus)
- [AttendanceAnomalyType](#attendanceanomalytype)
- [AttendanceCorrectionStatus](#attendancecorrectionstatus)
- [AttendanceEventStatus](#attendanceeventstatus)
- [AttendanceEventType](#attendanceeventtype)
- [Audience](#audience)
- [AvailabilityConfirmationChannel](#availabilityconfirmationchannel)
- [AvailabilityStatus](#availabilitystatus)
- [BillingPeriod](#billingperiod)
- [BroadcastCampaignStatus](#broadcastcampaignstatus)
- [ClaimStatus](#claimstatus)
- [CommercialAdjustmentKind](#commercialadjustmentkind)
- [CommercialAdjustmentStatus](#commercialadjustmentstatus)
- [CommercialEarningEvent](#commercialearningevent)
- [CommercialEarningStatus](#commercialearningstatus)
- [CommercialObjectiveIndicator](#commercialobjectiveindicator)
- [CommercialPayoutStatus](#commercialpayoutstatus)
- [CommercialProfileStatus](#commercialprofilestatus)
- [CommercialProfileVerificationStatus](#commercialprofileverificationstatus)
- [ConsentType](#consenttype)
- [ContactChannel](#contactchannel)
- [ContactStatus](#contactstatus)
- [ContractPaymentMode](#contractpaymentmode)
- [DevicePlatform](#deviceplatform)
- [DisputePartyRole](#disputepartyrole)
- [DisputeStatus](#disputestatus)
- [DocumentAudience](#documentaudience)
- [DocumentVerificationStatus](#documentverificationstatus)
- [DocumentVisibility](#documentvisibility)
- [DuplicateCandidateStatus](#duplicatecandidatestatus)
- [EarningCalculation](#earningcalculation)
- [EarningCapPeriod](#earningcapperiod)
- [ExclusionDecider](#exclusiondecider)
- [ExportFormat](#exportformat)
- [FinancialExceptionStatus](#financialexceptionstatus)
- [FinancialPeriodClosingStatus](#financialperiodclosingstatus)
- [Gender](#gender)
- [IntegrationEnvironment](#integrationenvironment)
- [IntegrationServiceType](#integrationservicetype)
- [IntegrationStatus](#integrationstatus)
- [MatchingCycleScope](#matchingcyclescope)
- [MatchingCycleStatus](#matchingcyclestatus)
- [MatchingExclusionNature](#matchingexclusionnature)
- [MatchingRunType](#matchingruntype)
- [MessageChannel](#messagechannel)
- [MissionStatus](#missionstatus)
- [NeedStatus](#needstatus)
- [NotificationChannel](#notificationchannel)
- [NotificationStatus](#notificationstatus)
- [OfferMilestoneStatus](#offermilestonestatus)
- [OfferStatus](#offerstatus)
- [OfferType](#offertype)
- [OfferVisibility](#offervisibility)
- [OrganizationMemberStatus](#organizationmemberstatus)
- [OrganizationRole](#organizationrole)
- [OtpPurpose](#otppurpose)
- [ParameterStatus](#parameterstatus)
- [PaymentFrequency](#paymentfrequency)
- [PaymentInstallmentStatus](#paymentinstallmentstatus)
- [PaymentMethodStatus](#paymentmethodstatus)
- [PaymentMethodType](#paymentmethodtype)
- [PaymentScheduleStatus](#paymentschedulestatus)
- [PaymentScheduleType](#paymentscheduletype)
- [PaymentTransactionDirection](#paymenttransactiondirection)
- [PaymentTransactionPurpose](#paymenttransactionpurpose)
- [PaymentTransactionStatus](#paymenttransactionstatus)
- [PayoutAdjustmentKind](#payoutadjustmentkind)
- [PayoutAdjustmentStatus](#payoutadjustmentstatus)
- [PermissionAction](#permissionaction)
- [PreferenceChannel](#preferencechannel)
- [PriceOrigin](#priceorigin)
- [PricingParameterScope](#pricingparameterscope)
- [PricingSnapshotKind](#pricingsnapshotkind)
- [PricingVariable](#pricingvariable)
- [Priority](#priority)
- [ProgressDeclarationStatus](#progressdeclarationstatus)
- [PromoterProfileKind](#promoterprofilekind)
- [PromoterVerificationStatus](#promoterverificationstatus)
- [ProposalStatus](#proposalstatus)
- [ProspectFollowupChannel](#prospectfollowupchannel)
- [ProspectInterestLevel](#prospectinterestlevel)
- [ProspectStatus](#prospectstatus)
- [RatingModerationStatus](#ratingmoderationstatus)
- [RatingScope](#ratingscope)
- [RatingStatus](#ratingstatus)
- [RatingVerificationStatus](#ratingverificationstatus)
- [ReceiptType](#receipttype)
- [ReconciliationDiscrepancy](#reconciliationdiscrepancy)
- [ReconciliationItemStatus](#reconciliationitemstatus)
- [ReconciliationRunStatus](#reconciliationrunstatus)
- [ReferralStatus](#referralstatus)
- [RefundStatus](#refundstatus)
- [ReplacementPromoterDecision](#replacementpromoterdecision)
- [ReplacementStatus](#replacementstatus)
- [ReportFrequency](#reportfrequency)
- [ReportScheduleStatus](#reportschedulestatus)
- [ResponseRecorder](#responserecorder)
- [RetentionAction](#retentionaction)
- [SalaryPeriod](#salaryperiod)
- [SanctionAppealStatus](#sanctionappealstatus)
- [SelectionStatus](#selectionstatus)
- [SiteIncidentSeverity](#siteincidentseverity)
- [SiteIncidentStatus](#siteincidentstatus)
- [SiteIncidentType](#siteincidenttype)
- [SiteStatus](#sitestatus)
- [SiteStepStatus](#sitestepstatus)
- [SiteWorkType](#siteworktype)
- [SkillLevel](#skilllevel)
- [SubscriptionInvoiceStatus](#subscriptioninvoicestatus)
- [SubscriptionStatus](#subscriptionstatus)
- [SuggestionStatus](#suggestionstatus)
- [TicketStatus](#ticketstatus)
- [TransactionStatusSource](#transactionstatussource)
- [UnavailabilityDeclarant](#unavailabilitydeclarant)
- [UnavailabilityRequestPhase](#unavailabilityrequestphase)
- [UnavailabilityRequestStatus](#unavailabilityrequeststatus)
- [UserStatus](#userstatus)
- [UserType](#usertype)
- [VerificationCorrectionPriority](#verificationcorrectionpriority)
- [VerificationReviewStatus](#verificationreviewstatus)
- [WebhookProcessingStatus](#webhookprocessingstatus)
- [WorkDayStatus](#workdaystatus)
- [WorkerAvailabilitySource](#workeravailabilitysource)
- [WorkerPayoutBatchStatus](#workerpayoutbatchstatus)
- [WorkerPayoutStatus](#workerpayoutstatus)
- [WorkerReservationStatus](#workerreservationstatus)
- [WorkerResponseDecision](#workerresponsedecision)
- [WorkerSanctionType](#workersanctiontype)
- [WorkerVerificationStatus](#workerverificationstatus)
- [ZoneRequestStatus](#zonerequeststatus)

## AdminStatus

Valeurs de `status` pour un administrateur.

| Valeur API | Libellé |
|---|---|
| `invite` | Invité |
| `actif` | Actif |
| `suspendu` | Suspendu |
| `desactive` | Désactivé |

## AnomalyDetector

Valeurs de `detected_by` pour une anomalie de pointage.

| Valeur API | Libellé |
|---|---|
| `systeme` | Système |
| `promoteur` | Promoteur |
| `admin` | Administrateur |

## AppName

Valeurs de `app_name` pour la table `app_versions`.

| Valeur API | Libellé |
|---|---|
| `promoteur` | Promoteur |
| `commercial` | Commercial |
| `ouvrier` | Ouvrier |

## AppPlatform

Valeurs de `platform` pour la table `app_versions`.

| Valeur API | Libellé |
|---|---|
| `android` | Android |
| `ios` | iOS |

## ApprovalStatus

Valeurs de `status` pour une approbation.

| Valeur API | Libellé |
|---|---|
| `en_attente` | En attente |
| `approuve` | Approuvé |
| `refuse` | Refusé |
| `expire` | Expiré |

## AssignmentStatus

Valeurs de `status` pour une affectation.

| Valeur API | Libellé |
|---|---|
| `confirmee` | Confirmée |
| `active` | Active |
| `terminee` | Terminée |
| `remplacee` | Remplacée |
| `annulee` | Annulée |

## AttendanceAnomalyStatus

Valeurs de `status` pour une anomalie de pointage.

| Valeur API | Libellé |
|---|---|
| `ouverte` | Ouverte |
| `en_traitement` | En traitement |
| `resolue` | Résolue |
| `rejetee` | Rejetée |

## AttendanceAnomalyType

Valeurs de `anomaly_type` pour une anomalie de pointage.

| Valeur API | Libellé |
|---|---|
| `absence` | Absence |
| `retard` | Retard |
| `depart_anticipe` | Départ anticipé |
| `doublon` | Doublon |
| `hors_zone` | Hors zone |
| `preuve_manquante` | Preuve manquante |
| `incoherence_horaire` | Incohérence horaire |
| `photo_reutilisee` | Photo réutilisée |

## AttendanceCorrectionStatus

Valeurs de `status` pour une correction de pointage.

| Valeur API | Libellé |
|---|---|
| `demandee` | Demandée |
| `approuvee` | Approuvée |
| `refusee` | Refusée |

## AttendanceEventStatus

Valeurs de `status` pour un pointage.

| Valeur API | Libellé |
|---|---|
| `soumis` | Soumis |
| `valide` | Validé |
| `rejete` | Rejeté |
| `corrige` | Corrigé |
| `annule` | Annulé |

## AttendanceEventType

Valeurs de `event_type` pour un pointage.

| Valeur API | Libellé |
|---|---|
| `arrivee` | Arrivée |
| `depart` | Départ |

## Audience

Public visé par un texte légal ou un article d’aide.

| Valeur API | Libellé |
|---|---|
| `promoter` | Promoteur |
| `commercial` | Commercial |
| `worker` | Ouvrier |
| `all` | Tous |

## AvailabilityConfirmationChannel

Valeurs de `confirmation_channel` pour une disponibilité d’ouvrier.

| Valeur API | Libellé |
|---|---|
| `appel` | Appel |
| `sms` | SMS |
| `visite` | Visite |
| `application` | Application |

## AvailabilityStatus

Disponibilité d’un ouvrier.

| Valeur API | Libellé |
|---|---|
| `disponible` | Disponible |
| `occupe` | Occupé |
| `indisponible` | Indisponible |

## BillingPeriod

Valeurs de `billing_period` pour la table `subscription_plans`.

| Valeur API | Libellé |
|---|---|
| `mois` | Mois |
| `trimestre` | Trimestre |
| `annee` | Année |

## BroadcastCampaignStatus

Valeurs de `status` pour une campagne de diffusion.

| Valeur API | Libellé |
|---|---|
| `brouillon` | Brouillon |
| `a_valider` | À valider |
| `programmee` | Programmée |
| `envoyee` | Envoyée |
| `annulee` | Annulée |

## ClaimStatus

Valeurs de `status` pour une réclamation.

| Valeur API | Libellé |
|---|---|
| `ouverte` | Ouverte |
| `en_traitement` | En traitement |
| `resolue` | Résolue |
| `escaladee` | Escaladée |
| `rejetee` | Rejetée |
| `close` | Close |

## CommercialAdjustmentKind

Valeurs de `kind` pour une prime ou retenue de commercial.

| Valeur API | Libellé |
|---|---|
| `prime` | Prime |
| `retenue` | Retenue |

## CommercialAdjustmentStatus

Valeurs de `status` pour une prime ou retenue de commercial.

| Valeur API | Libellé |
|---|---|
| `proposee` | Proposée |
| `approuvee` | Approuvée |
| `refusee` | Refusée |
| `payee` | Payée |
| `annulee` | Annulée |

## CommercialEarningEvent

Valeurs de `event_type` pour la table `commercial_earning_rules`.

| Valeur API | Libellé |
|---|---|
| `ouvrier_valide` | Ouvrier validé |

## CommercialEarningStatus

Valeurs de `status` pour un gain de commercial.

| Valeur API | Libellé |
|---|---|
| `estime` | Estimé |
| `en_attente` | En attente |
| `acquis` | Acquis |
| `approuve` | Approuvé |
| `paye` | Payé |
| `rejete` | Rejeté |
| `annule` | Annulé |

## CommercialObjectiveIndicator

Valeurs de `indicator` pour un objectif commercial.

| Valeur API | Libellé |
|---|---|
| `prospects` | Prospects |
| `dossiers_complets` | Dossiers complets |
| `ouvriers_valides` | Ouvriers validés |
| `ouvriers_affectes` | Ouvriers affectés |

## CommercialPayoutStatus

Valeurs de `status` pour un paiement de commercial.

| Valeur API | Libellé |
|---|---|
| `a_payer` | À payer |
| `en_traitement` | En traitement |
| `paye` | Payé |
| `rejete` | Rejeté |

## CommercialProfileStatus

Valeurs de `status` pour un profil commercial.

| Valeur API | Libellé |
|---|---|
| `actif` | Actif |
| `suspendu` | Suspendu |
| `termine` | Terminé |

## CommercialProfileVerificationStatus

Valeurs de `verification_status` pour un profil commercial.

| Valeur API | Libellé |
|---|---|
| `incomplet` | Incomplet |
| `soumis` | Soumis |
| `a_corriger` | À corriger |
| `valide` | Validé |
| `suspendu` | Suspendu |

## ConsentType

Valeurs de `consent_type` pour un consentement.

| Valeur API | Libellé |
|---|---|
| `cgu` | CGU |
| `privacy` | Confidentialité |
| `data_processing` | Traitement des données |
| `photo_usage` | Utilisation des photos |
| `financial_terms` | Conditions financières |
| `contact` | Contact |
| `geolocation` | Géolocalisation |
| `reference_contact` | Contact des références |

## ContactChannel

Valeurs de `channel` pour la table `worker_offer_contacts`.

| Valeur API | Libellé |
|---|---|
| `appel` | Appel |
| `sms` | SMS |
| `notification` | Notification |
| `whatsapp` | WhatsApp |
| `visite` | Visite |
| `application` | Application |

## ContactStatus

Valeurs de `status` pour la table `worker_offer_contacts`.

| Valeur API | Libellé |
|---|---|
| `en_attente` | En attente |
| `repondu` | Répondu |
| `expire` | Expiré |
| `annule` | Annulé |

## ContractPaymentMode

Paiement du solde d’une offre contractuelle (« Mode de paiement »).

| Valeur API | Libellé |
|---|---|
| `hebdomadaire` | Hebdomadaire |
| `par_etape` | Par étape |
| `fin_de_chantier` | Fin de chantier |

## DevicePlatform

Valeurs de `platform` pour la table `device_tokens`.

| Valeur API | Libellé |
|---|---|
| `android` | Android |
| `ios` | iOS |
| `web` | Web |

## DisputePartyRole

Valeurs de `role` pour une partie à un litige.

| Valeur API | Libellé |
|---|---|
| `demandeur` | Demandeur |
| `defendeur` | Défendeur |
| `tiers` | Tiers |

## DisputeStatus

Valeurs de `status` pour un litige.

| Valeur API | Libellé |
|---|---|
| `ouvert` | Ouvert |
| `en_instruction` | En instruction |
| `resolu` | Résolu |
| `clos` | Clos |
| `en_recours` | En recours |

## DocumentAudience

Valeurs de `audience` pour un type de pièce.

| Valeur API | Libellé |
|---|---|
| `worker` | Ouvrier |
| `promoter` | Promoteur |
| `organization` | Organisation |
| `commercial` | Commercial |
| `site` | Chantier |
| `offer` | Offre |
| `need` | Besoin |
| `mission` | Mission |
| `subscription` | Abonnement |
| `dispute` | Litige |
| `claim` | Réclamation |
| `incident` | Incident |
| `ticket` | Ticket |
| `other` | Autre |

## DocumentVerificationStatus

Valeurs de `verification_status` pour un document.

| Valeur API | Libellé |
|---|---|
| `en_attente` | En attente |
| `valide` | Validé |
| `rejete` | Rejeté |
| `expire` | Expiré |
| `remplace` | Remplacé |

## DocumentVisibility

Valeurs de `visibility` pour un document.

| Valeur API | Libellé |
|---|---|
| `interne` | Interne |
| `promoteur_mission` | Promoteur de la mission |
| `public` | Public |

## DuplicateCandidateStatus

Valeurs de `status` pour la table `worker_duplicate_candidates`.

| Valeur API | Libellé |
|---|---|
| `a_verifier` | À vérifier |
| `confirme` | Confirmé |
| `faux_positif` | Faux positif |
| `fusionne` | Fusionné |

## EarningCalculation

Valeurs de `calculation` pour la table `commercial_earning_rules`.

| Valeur API | Libellé |
|---|---|
| `montant_fixe` | Montant fixe |
| `pourcentage` | Pourcentage |

## EarningCapPeriod

Valeurs de `cap_period` pour la table `commercial_earning_rules`.

| Valeur API | Libellé |
|---|---|
| `semaine` | Semaine |
| `mois` | Mois |

## ExclusionDecider

Valeurs de `decided_by_type` pour une exclusion de matching.

| Valeur API | Libellé |
|---|---|
| `systeme` | Système |
| `admin` | Administrateur |

## ExportFormat

Format d’un export ou d’un rapport.

| Valeur API | Libellé |
|---|---|
| `csv` | CSV |
| `xlsx` | Excel |
| `pdf` | PDF |

## FinancialExceptionStatus

Valeurs de `status` pour une dérogation financière.

| Valeur API | Libellé |
|---|---|
| `demandee` | Demandée |
| `approuvee` | Approuvée |
| `refusee` | Refusée |
| `expiree` | Expirée |

## FinancialPeriodClosingStatus

Valeurs de `status` pour une clôture mensuelle.

| Valeur API | Libellé |
|---|---|
| `ouverte` | Ouverte |
| `en_cloture` | En clôture |
| `cloturee` | Clôturée |
| `reouverte` | Réouverte |

## Gender

Valeurs de `gender` pour un ouvrier.

| Valeur API | Libellé |
|---|---|
| `M` | Homme |
| `F` | Femme |

## IntegrationEnvironment

Valeurs de `environment` pour une intégration.

| Valeur API | Libellé |
|---|---|
| `bac_a_sable` | Bac à sable |
| `production` | Production |

## IntegrationServiceType

Valeurs de `service_type` pour une intégration.

| Valeur API | Libellé |
|---|---|
| `paiement` | Paiement |
| `sms` | SMS |
| `push` | Notification push |
| `email` | E-mail |
| `cartographie` | Cartographie |
| `stockage` | Stockage |
| `autre` | Autre |

## IntegrationStatus

Valeurs de `status` pour une intégration.

| Valeur API | Libellé |
|---|---|
| `active` | Active |
| `inactive` | Inactive |
| `en_erreur` | En erreur |

## MatchingCycleScope

Valeurs de `scope` pour un cycle de matching.

| Valeur API | Libellé |
|---|---|
| `global` | Global |
| `zone` | Zone |
| `metier` | Métier |
| `offre` | Offre |

## MatchingCycleStatus

Valeurs de `status` pour un cycle de matching.

| Valeur API | Libellé |
|---|---|
| `en_cours` | En cours |
| `termine` | Terminé |
| `echoue` | Échoué |
| `annule` | Annulé |

## MatchingExclusionNature

Valeurs de `nature` pour une exclusion de matching.

| Valeur API | Libellé |
|---|---|
| `reglementaire` | Réglementaire |
| `operationnelle` | Opérationnelle |
| `manuelle` | Manuelle |

## MatchingRunType

Valeurs de `run_type` pour une exécution de matching.

| Valeur API | Libellé |
|---|---|
| `initial` | Initial |
| `relance` | Relance |
| `remplacement` | Remplacement |

## MessageChannel

Canal d’envoi d’un code ou d’une invitation.

| Valeur API | Libellé |
|---|---|
| `sms` | SMS |
| `email` | E-mail |

## MissionStatus

Valeurs de `status` pour une mission.

| Valeur API | Libellé |
|---|---|
| `en_attente_acompte` | En attente d’acompte |
| `confirmee` | Confirmée |
| `en_cours` | En cours |
| `suspendue` | Suspendue |
| `terminee` | Terminée |
| `annulee` | Annulée |

## NeedStatus

Valeurs de `status` pour un besoin.

| Valeur API | Libellé |
|---|---|
| `brouillon` | Brouillon |
| `soumis` | Soumis |
| `en_controle` | En contrôle |
| `a_corriger` | À corriger |
| `matching` | Matching |
| `contact_ouvriers` | Contact des ouvriers |
| `proposition_promoteur` | Proposition promoteur |
| `selection` | Sélection |
| `paiement_en_attente` | Paiement en attente |
| `confirme` | Confirmé |
| `en_cours` | En cours |
| `suspendu` | Suspendu |
| `termine` | Terminé |
| `annule` | Annulé |

## NotificationChannel

Canal d’envoi d’une notification.

| Valeur API | Libellé |
|---|---|
| `push` | Notification push |
| `sms` | SMS |
| `email` | E-mail |
| `in_app` | Dans l’application |

## NotificationStatus

Valeurs de `status` pour une notification.

| Valeur API | Libellé |
|---|---|
| `en_attente` | En attente |
| `envoyee` | Envoyée |
| `livree` | Livrée |
| `echouee` | Échouée |

## OfferMilestoneStatus

Valeurs de `status` pour un jalon d’offre.

| Valeur API | Libellé |
|---|---|
| `prevu` | Prévu |
| `atteint` | Atteint |
| `valide` | Validé |
| `paye` | Payé |
| `annule` | Annulé |

## OfferStatus

Valeurs de `status` pour une offre.

| Valeur API | Libellé |
|---|---|
| `brouillon` | Brouillon |
| `soumise` | Soumise |
| `en_controle` | En contrôle |
| `a_corriger` | À corriger |
| `publiee` | Publiée |
| `suspendue` | Suspendue |
| `pourvue` | Pourvue |
| `cloturee` | Clôturée |
| `annulee` | Annulée |

## OfferType

Valeurs de `offer_type` pour une offre.

| Valeur API | Libellé |
|---|---|
| `ponctuelle` | Ponctuelle |
| `temporaire` | Temporaire |
| `contractuelle` | Contractuelle |

## OfferVisibility

Valeurs de `visibility` pour une offre.

| Valeur API | Libellé |
|---|---|
| `ciblee` | Ciblée |
| `publique` | Publique |

## OrganizationMemberStatus

Valeurs de `status` pour un membre d’organisation.

| Valeur API | Libellé |
|---|---|
| `invite` | Invité |
| `actif` | Actif |
| `suspendu` | Suspendu |
| `revoque` | Révoqué |

## OrganizationRole

Valeurs de `role` pour un membre d’organisation.

| Valeur API | Libellé |
|---|---|
| `administrateur` | Administrateur |
| `chef_projet` | Chef de projet |
| `responsable_chantier` | Responsable de chantier |
| `responsable_financier` | Responsable financier |
| `lecteur` | Lecteur |

## OtpPurpose

Valeurs de `purpose` pour la table `otp_codes`.

| Valeur API | Libellé |
|---|---|
| `activation` | Activation |
| `login` | Connexion |
| `password_reset` | Réinitialisation du mot de passe |
| `sensitive_action` | Action sensible |
| `phone_change` | Changement de numéro |
| `worker_phone_check` | Vérification du numéro de l’ouvrier |

## ParameterStatus

Statut d’une valeur de paramètre soumise à approbation.

| Valeur API | Libellé |
|---|---|
| `en_attente_approbation` | En attente d’approbation |
| `actif` | Actif |
| `expire` | Expiré |
| `refuse` | Refusé |

## PaymentFrequency

Valeurs de `payment_frequency` pour un besoin.

| Valeur API | Libellé |
|---|---|
| `journalier` | Journalier |
| `hebdomadaire` | Hebdomadaire |
| `mensuel` | Mensuel |
| `jalon` | Jalon |

## PaymentInstallmentStatus

Valeurs de `status` pour un versement d’un paiement flexible.

| Valeur API | Libellé |
|---|---|
| `a_payer` | À payer |
| `payee` | Payé |
| `annulee` | Annulé |

## PaymentMethodStatus

Valeurs de `status` pour un moyen de paiement.

| Valeur API | Libellé |
|---|---|
| `actif` | Actif |
| `desactive` | Désactivé |

## PaymentMethodType

Valeurs de `method_type` pour un moyen de paiement.

| Valeur API | Libellé |
|---|---|
| `mobile_money` | Mobile Money |
| `virement` | Virement |
| `carte` | Carte |

## PaymentScheduleStatus

Valeurs de `status` pour une échéance.

| Valeur API | Libellé |
|---|---|
| `prevue` | Prévue |
| `exigible` | Exigible |
| `partiellement_payee` | Partiellement payée |
| `payee` | Payée |
| `en_retard` | En retard |
| `contestee` | Contestée |
| `annulee` | Annulée |
| `remboursee` | Remboursée |

## PaymentScheduleType

Valeurs de `schedule_type` pour une échéance.

| Valeur API | Libellé |
|---|---|
| `acompte` | Acompte |
| `journaliere` | Journalière |
| `hebdomadaire` | Hebdomadaire |
| `mensuelle` | Mensuelle |
| `jalon` | Jalon |
| `seuil_r` | Seuil R |
| `solde` | Solde |
| `regularisation` | Régularisation |

## PaymentTransactionDirection

Valeurs de `direction` pour une transaction de paiement.

| Valeur API | Libellé |
|---|---|
| `encaissement` | Encaissement |
| `decaissement` | Décaissement |
| `remboursement` | Remboursement |

## PaymentTransactionPurpose

Valeurs de `purpose` pour une transaction de paiement.

| Valeur API | Libellé |
|---|---|
| `acompte` | Acompte |
| `echeance` | Échéance |
| `reversement_ouvrier` | Reversement ouvrier |
| `gain_commercial` | Gain commercial |
| `remboursement` | Remboursement |
| `abonnement` | Abonnement |

## PaymentTransactionStatus

Valeurs de `status` pour une transaction de paiement.

| Valeur API | Libellé |
|---|---|
| `initiee` | Initiée |
| `en_attente` | En attente |
| `reussie` | Réussie |
| `echouee` | Échouée |
| `expiree` | Expirée |
| `annulee` | Annulée |

## PayoutAdjustmentKind

Valeurs de `kind` pour un ajustement de reversement.

| Valeur API | Libellé |
|---|---|
| `retenue` | Retenue |
| `bonus` | Bonus |

## PayoutAdjustmentStatus

Valeurs de `status` pour un ajustement de reversement.

| Valeur API | Libellé |
|---|---|
| `proposee` | Proposée |
| `approuvee` | Approuvée |
| `refusee` | Refusée |
| `annulee` | Annulée |

## PermissionAction

Valeurs de `action` pour la table `admin_permissions`.

| Valeur API | Libellé |
|---|---|
| `read` | Consulter |
| `create` | Créer |
| `update` | Modifier |
| `validate` | Valider |
| `approve` | Approuver |
| `export` | Exporter |
| `delete` | Supprimer |

## PreferenceChannel

Valeurs de `channel` pour la table `notification_preferences`.

| Valeur API | Libellé |
|---|---|
| `push` | Notification push |
| `sms` | SMS |
| `email` | E-mail |

## PriceOrigin

Valeurs de `price_origin` pour une offre.

| Valeur API | Libellé |
|---|---|
| `ouvrier` | Ouvrier |
| `promoteur` | Promoteur |

## PricingParameterScope

Valeurs de `scope` pour un paramètre tarifaire.

| Valeur API | Libellé |
|---|---|
| `global` | Global |
| `offer_type` | Type d’offre |
| `subscription_plan` | Formule d’abonnement |
| `organization` | Organisation |
| `trade` | Métier |
| `site` | Chantier |
| `need` | Besoin |

## PricingSnapshotKind

Valeurs de `kind` pour un instantané tarifaire.

| Valeur API | Libellé |
|---|---|
| `simulation` | Simulation |
| `devis` | Devis |
| `confirme` | Confirmé |

## PricingVariable

Variable financière : acompte, commissions et seuil de progression.

| Valeur API | Libellé |
|---|---|
| `X` | Acompte (X) |
| `Y` | Commission promoteur (Y) |
| `Z` | Commission ouvrier (Z) |
| `R` | Seuil de progression (R) |

## Priority

Niveau de priorité ou d’urgence.

| Valeur API | Libellé |
|---|---|
| `basse` | Basse |
| `normale` | Normale |
| `haute` | Haute |
| `critique` | Critique |

## ProgressDeclarationStatus

Valeurs de `status` pour une déclaration de progression.

| Valeur API | Libellé |
|---|---|
| `declaree` | Déclarée |
| `verifiee` | Vérifiée |
| `rejetee` | Rejetée |

## PromoterProfileKind

Valeurs de `profile_kind` pour un profil promoteur.

| Valeur API | Libellé |
|---|---|
| `particulier` | Particulier |
| `technicien` | Technicien |
| `entreprise` | Entreprise |

## PromoterVerificationStatus

Statut de contrôle du dossier d’un promoteur ou d’une organisation.

| Valeur API | Libellé |
|---|---|
| `brouillon` | Brouillon |
| `soumis` | Soumis |
| `en_controle` | En contrôle |
| `a_corriger` | À corriger |
| `valide` | Validé |
| `refuse` | Refusé |
| `suspendu` | Suspendu |

## ProposalStatus

Proposition d’équipe présentée au promoteur.

| Valeur API | Libellé |
|---|---|
| `a_valider` | À valider |
| `acceptee` | Acceptée |
| `refusee` | Refusée |
| `expiree` | Expirée |
| `annulee` | Annulée |

## ProspectFollowupChannel

Valeurs de `channel` pour une relance de prospect.

| Valeur API | Libellé |
|---|---|
| `appel` | Appel |
| `sms` | SMS |
| `whatsapp` | WhatsApp |
| `visite` | Visite |

## ProspectInterestLevel

Valeurs de `interest_level` pour un prospect.

| Valeur API | Libellé |
|---|---|
| `faible` | Faible |
| `moyen` | Moyen |
| `fort` | Fort |

## ProspectStatus

Valeurs de `status` pour un prospect.

| Valeur API | Libellé |
|---|---|
| `nouveau` | Nouveau |
| `a_contacter` | À contacter |
| `rendez_vous` | Rendez-vous |
| `a_completer` | À compléter |
| `converti` | Converti |
| `abandonne` | Abandonné |

## RatingModerationStatus

Valeurs de `moderation_status` pour une évaluation.

| Valeur API | Libellé |
|---|---|
| `signale` | Signalé |
| `maintenu` | Maintenu |
| `masque` | Masqué |

## RatingScope

Valeurs de `rating_scope` pour une évaluation.

| Valeur API | Libellé |
|---|---|
| `individuel` | Individuel |
| `equipe` | Équipe |

## RatingStatus

Valeurs de `status` pour une évaluation.

| Valeur API | Libellé |
|---|---|
| `brouillon` | Brouillon |
| `publie` | Publié |
| `masque` | Masqué |

## RatingVerificationStatus

Valeurs de `verification_status` pour une évaluation.

| Valeur API | Libellé |
|---|---|
| `non_verifie` | Non vérifié |
| `verifie` | Vérifié |
| `suspect` | Suspect |

## ReceiptType

Valeurs de `receipt_type` pour un reçu.

| Valeur API | Libellé |
|---|---|
| `recu` | Reçu |
| `facture` | Facture |
| `releve_gains` | Relevé de gains |
| `avoir` | Avoir |

## ReconciliationDiscrepancy

Valeurs de `discrepancy_type` pour un écart de rapprochement.

| Valeur API | Libellé |
|---|---|
| `ecart_montant` | Écart de montant |
| `absent_plateforme` | Absent de la plateforme |
| `absent_prestataire` | Absent chez le prestataire |
| `doublon` | Doublon |
| `statut_different` | Statut différent |

## ReconciliationItemStatus

Valeurs de `status` pour un écart de rapprochement.

| Valeur API | Libellé |
|---|---|
| `ouvert` | Ouvert |
| `resolu` | Résolu |
| `accepte` | Accepté |

## ReconciliationRunStatus

Valeurs de `status` pour un rapprochement.

| Valeur API | Libellé |
|---|---|
| `en_cours` | En cours |
| `termine` | Terminé |
| `echoue` | Échoué |

## ReferralStatus

Valeurs de `status` pour une invitation de parrainage.

| Valeur API | Libellé |
|---|---|
| `envoyee` | Envoyée |
| `inscrite` | Inscrit |

## RefundStatus

Valeurs de `status` pour un remboursement.

| Valeur API | Libellé |
|---|---|
| `demande` | Demandé |
| `approuve` | Approuvé |
| `refuse` | Refusé |
| `execute` | Exécuté |
| `echoue` | Échoué |

## ReplacementPromoterDecision

Valeurs de `promoter_decision` pour un remplacement.

| Valeur API | Libellé |
|---|---|
| `en_attente` | En attente |
| `accepte` | Accepté |
| `refuse` | Refusé |

## ReplacementStatus

Valeurs de `status` pour un remplacement.

| Valeur API | Libellé |
|---|---|
| `signale` | Signalé |
| `recherche` | Recherche |
| `propose` | Proposé |
| `valide` | Validé |
| `prise_de_poste` | Prise de poste |
| `termine` | Terminé |
| `annule` | Annulé |

## ReportFrequency

Valeurs de `frequency` pour un rapport planifié.

| Valeur API | Libellé |
|---|---|
| `quotidien` | Quotidien |
| `hebdomadaire` | Hebdomadaire |
| `mensuel` | Mensuel |

## ReportScheduleStatus

Valeurs de `status` pour un rapport planifié.

| Valeur API | Libellé |
|---|---|
| `actif` | Actif |
| `suspendu` | Suspendu |

## ResponseRecorder

Valeurs de `recorded_by_type` pour une réponse d’ouvrier.

| Valeur API | Libellé |
|---|---|
| `commercial` | Commercial |
| `admin` | Administrateur |
| `worker` | Ouvrier |

## RetentionAction

Valeurs de `action` pour la table `data_retention_policies`.

| Valeur API | Libellé |
|---|---|
| `supprimer` | Supprimer |
| `anonymiser` | Anonymiser |
| `archiver` | Archiver |

## SalaryPeriod

Période de référence d’un salaire contractuel.

| Valeur API | Libellé |
|---|---|
| `jour` | Jour |
| `semaine` | Semaine |
| `mois` | Mois |
| `forfait` | Forfait |

## SanctionAppealStatus

Valeurs de `appeal_status` pour une sanction d’ouvrier.

| Valeur API | Libellé |
|---|---|
| `aucun` | Aucun |
| `demande` | Demandé |
| `accepte` | Accepté |
| `rejete` | Rejeté |

## SelectionStatus

Valeurs de `status` pour une sélection du promoteur.

| Valeur API | Libellé |
|---|---|
| `selectionne` | Sélectionné |
| `reserve` | Réservé |
| `confirme` | Confirmé |
| `desiste` | Désisté |
| `remplace` | Remplacé |
| `expire` | Expiré |

## SiteIncidentSeverity

Valeurs de `severity` pour un incident de chantier.

| Valeur API | Libellé |
|---|---|
| `mineure` | Mineure |
| `moderee` | Modérée |
| `grave` | Grave |
| `critique` | Critique |

## SiteIncidentStatus

Valeurs de `status` pour un incident de chantier.

| Valeur API | Libellé |
|---|---|
| `ouvert` | Ouvert |
| `en_traitement` | En traitement |
| `resolu` | Résolu |
| `clos` | Clos |

## SiteIncidentType

Valeurs de `incident_type` pour un incident de chantier.

| Valeur API | Libellé |
|---|---|
| `accident` | Accident |
| `securite` | Sécurité |
| `materiel` | Matériel |
| `retard` | Retard |
| `conflit` | Conflit |
| `qualite` | Qualité |
| `autre` | Autre |

## SiteStatus

Valeurs de `status` pour un chantier.

| Valeur API | Libellé |
|---|---|
| `brouillon` | Brouillon |
| `soumis` | Soumis |
| `recherche` | Recherche |
| `paiement` | Paiement |
| `confirme` | Confirmé |
| `en_cours` | En cours |
| `suspendu` | Suspendu |
| `termine` | Terminé |
| `annule` | Annulé |

## SiteStepStatus

Valeurs de `status` pour une étape de la chronologie d’un chantier.

| Valeur API | Libellé |
|---|---|
| `a_faire` | À démarrer |
| `en_cours` | En cours |
| `validee` | Validée |

## SiteWorkType

Nature des travaux d’un chantier (« Type de travaux »).

| Valeur API | Libellé |
|---|---|
| `construction_neuve` | Construction neuve |
| `renovation` | Rénovation |
| `extension` | Extension |
| `finitions` | Finitions |

## SkillLevel

Niveau de maîtrise d’un métier ou d’une compétence.

| Valeur API | Libellé |
|---|---|
| `debutant` | Débutant |
| `intermediaire` | Intermédiaire |
| `confirme` | Confirmé |
| `expert` | Expert |

## SubscriptionInvoiceStatus

Valeurs de `status` pour une facture d’abonnement.

| Valeur API | Libellé |
|---|---|
| `a_payer` | À payer |
| `payee` | Payée |
| `en_retard` | En retard |
| `annulee` | Annulée |
| `remboursee` | Remboursée |

## SubscriptionStatus

Valeurs de `status` pour un abonnement.

| Valeur API | Libellé |
|---|---|
| `essai` | Essai |
| `active` | Active |
| `en_retard` | En retard |
| `suspendue` | Suspendue |
| `resiliee` | Résiliée |
| `expiree` | Expirée |

## SuggestionStatus

Valeurs de `status` pour une suggestion de matching.

| Valeur API | Libellé |
|---|---|
| `suggere` | Suggéré |
| `contacte` | Contacté |
| `accepte` | Accepté |
| `refuse` | Refusé |
| `indisponible` | Indisponible |
| `expire` | Expiré |
| `propose_promoteur` | Proposé au promoteur |
| `selectionne` | Sélectionné |
| `ecarte` | Écarté |

## TicketStatus

Valeurs de `status` pour un ticket de support.

| Valeur API | Libellé |
|---|---|
| `ouvert` | Ouvert |
| `en_cours` | En cours |
| `en_attente_utilisateur` | En attente de l’utilisateur |
| `escalade` | Escaladé |
| `resolu` | Résolu |
| `ferme` | Fermé |

## TransactionStatusSource

Valeurs de `source` pour la table `payment_transaction_status_histories`.

| Valeur API | Libellé |
|---|---|
| `api` | API |
| `webhook` | Webhook |
| `rapprochement` | Rapprochement |
| `admin` | Administrateur |
| `systeme` | Système |

## UnavailabilityDeclarant

Valeurs de `declared_by_type` pour une indisponibilité.

| Valeur API | Libellé |
|---|---|
| `promoteur` | Promoteur |
| `commercial` | Commercial |
| `admin` | Administrateur |
| `ouvrier` | Ouvrier |

## UnavailabilityRequestPhase

Valeurs de `phase` pour une indisponibilité.

| Valeur API | Libellé |
|---|---|
| `avant_demarrage` | Avant démarrage |
| `en_execution` | En exécution |

## UnavailabilityRequestStatus

Valeurs de `status` pour une indisponibilité.

| Valeur API | Libellé |
|---|---|
| `declaree` | Déclarée |
| `en_analyse` | En analyse |
| `remplacement_recherche` | Remplacement recherché |
| `remplacant_propose` | Remplaçant proposé |
| `validee` | Validée |
| `refusee` | Refusée |
| `cloturee` | Clôturée |
| `annulee` | Annulée |

## UserStatus

Valeurs de `status` pour un utilisateur des applications.

| Valeur API | Libellé |
|---|---|
| `en_attente_activation` | En attente d’activation |
| `actif` | Actif |
| `suspendu` | Suspendu |
| `desactive` | Désactivé |

## UserType

Valeurs de `user_type` pour un utilisateur des applications.

| Valeur API | Libellé |
|---|---|
| `promoter` | Promoteur |
| `commercial` | Commercial |
| `worker` | Ouvrier |

## VerificationCorrectionPriority

Valeurs de `priority` pour une correction demandée.

| Valeur API | Libellé |
|---|---|
| `basse` | Basse |
| `normale` | Normale |
| `haute` | Haute |

## VerificationReviewStatus

Valeurs de `status` pour un contrôle de dossier.

| Valeur API | Libellé |
|---|---|
| `soumis` | Soumis |
| `en_controle` | En contrôle |
| `a_corriger` | À corriger |
| `valide` | Validé |
| `refuse` | Refusé |
| `annule` | Annulé |

## WebhookProcessingStatus

Valeurs de `processing_status` pour la table `payment_webhook_events`.

| Valeur API | Libellé |
|---|---|
| `recu` | Reçu |
| `traite` | Traité |
| `ignore` | Ignoré |
| `erreur` | Erreur |

## WorkDayStatus

Valeurs de `status` pour une journée travaillée.

| Valeur API | Libellé |
|---|---|
| `en_cours` | En cours |
| `a_valider` | À valider |
| `valide` | Validé |
| `rejete` | Rejeté |
| `absent` | Absent |
| `annule` | Annulé |

## WorkerAvailabilitySource

Valeurs de `source` pour une disponibilité d’ouvrier.

| Valeur API | Libellé |
|---|---|
| `commercial` | Commercial |
| `back_office` | Back Office |
| `ouvrier` | Ouvrier |
| `systeme` | Système |

## WorkerPayoutBatchStatus

Valeurs de `status` pour un lot de reversements.

| Valeur API | Libellé |
|---|---|
| `brouillon` | Brouillon |
| `a_valider` | À valider |
| `en_execution` | En exécution |
| `termine` | Terminé |
| `partiel` | Partiel |
| `echoue` | Échoué |
| `annule` | Annulé |

## WorkerPayoutStatus

Valeurs de `status` pour un reversement d’ouvrier.

| Valeur API | Libellé |
|---|---|
| `a_calculer` | À calculer |
| `provisionne` | Provisionné |
| `autorise` | Autorisé |
| `envoye` | Envoyé |
| `confirme` | Confirmé |
| `rejete` | Rejeté |
| `annule` | Annulé |

## WorkerReservationStatus

Valeurs de `status` pour une réservation d’ouvrier.

| Valeur API | Libellé |
|---|---|
| `active` | Active |
| `convertie` | Convertie |
| `expiree` | Expirée |
| `liberee` | Libérée |

## WorkerResponseDecision

Valeurs de `decision` pour une réponse d’ouvrier.

| Valeur API | Libellé |
|---|---|
| `accepte` | Accepté |
| `refuse` | Refusé |
| `indisponible` | Indisponible |

## WorkerSanctionType

Valeurs de `sanction_type` pour une sanction d’ouvrier.

| Valeur API | Libellé |
|---|---|
| `avertissement` | Avertissement |
| `suspension` | Suspension |
| `exclusion` | Exclusion |

## WorkerVerificationStatus

Valeurs de `verification_status` pour un ouvrier.

| Valeur API | Libellé |
|---|---|
| `brouillon` | Brouillon |
| `soumis` | Soumis |
| `en_controle` | En contrôle |
| `a_corriger` | À corriger |
| `valide` | Validé |
| `refuse` | Refusé |
| `suspendu` | Suspendu |
| `exclu` | Exclu |

## ZoneRequestStatus

Valeurs de `status` pour une demande de changement de zone d’un commercial.

| Valeur API | Libellé |
|---|---|
| `en_attente` | En attente de validation |
| `acceptee` | Acceptée |
| `refusee` | Refusée |
| `annulee` | Annulée |

