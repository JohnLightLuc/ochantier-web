'use strict';
// Espace de noms global de l’application.
//
// La logique du back-office est une seule classe `Component` (état + méthodes lues par les
// templates). Pour l’organiser par module, chaque fichier de src/js/ ajoute une « couche » :
//
//   OCH.extend(Base => class extends Base { ...champs et méthodes du module... });
//
// Les couches sont empilées dans l’ordre de chargement des <script> (voir index.html) :
// cet ordre fixe l’ordre d’initialisation des champs (ex. `P = this.buildPromos()`),
// il ne faut donc pas le modifier sans vérifier les dépendances entre champs.
window.OCH = {
  layers: [],
  components: {},
  rootProps: {},

  extend(layer) {
    this.layers.push(layer);
  },

  component(name, def) {
    this.components[name] = def;
  },

  buildApp(DCLogic) {
    return this.layers.reduce((Base, layer) => layer(Base), DCLogic);
  }
};
