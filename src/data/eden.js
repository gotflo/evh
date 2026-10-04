// Contenu de l'espace Eden (cours de préparation au mariage).
// Les montants et coordonnées de paiement sont aussi rappelés dans le courriel
// envoyé au candidat : s'ils changent, mettre à jour evh_private/.env (EDEN_*).

export const eden = {
  frais: 250,
  interacEmail: 'eden1@evhca.com',
  whatsapp: '+1 (581) 574-4660',
  whatsappLien: 'https://wa.me/15815744660',
  infoline: '+1 (418) 490-0186',
  infolineLien: 'tel:+14184900186',

  // Session en cours (contenu de l'affiche).
  session: {
    debut: 'Mardi 20 octobre 2026',
    debutCourt: 'Dès le mardi 20 octobre',
    lieu: 'En ligne sur Zoom',
    lieuPhrase: 'en ligne sur Zoom',
    inscriptions: 'Les inscriptions sont ouvertes',
  },

  affiche: {
    petite: '/img/eden/affiche-cours-eden-800.webp',
    grande: '/img/eden/affiche-cours-eden-1600.webp',
    partage: '/img/eden/affiche-cours-eden-og.jpg',
    largeur: 1600,
    hauteur: 899,
    alt: "Affiche de la reprise des cours d'Eden, cheminement au mariage : début des cours à partir du mardi 20 octobre, en ligne sur Zoom. Les inscriptions ont déjà débuté. Infoline : +1 (418) 490-0186.",
  },

  // Les démarches proposées sur la page Eden. Pour ouvrir « Enregistrer mon
  // couple », créer sa page puis passer disponible à true avec son lien.
  demarches: [
    {
      id: 'inscription',
      titre: 'Inscription aux cours de mariage',
      texte: "Vous êtes en cheminement et vous souhaitez vous préparer au mariage ? Inscrivez-vous à la prochaine session des cours d'Eden.",
      lien: '/eden-inscription.html',
      action: 'Commencer mon inscription',
      disponible: true,
    },
    {
      id: 'couple',
      titre: 'Enregistrer mon couple',
      texte: "Cette démarche pourra bientôt se faire en ligne. D'ici là, l'équipe Eden reste joignable à l'infoline.",
      lien: null,
      action: 'Bientôt disponible',
      disponible: false,
    },
  ],

  // Adresse du script PHP qui reçoit le formulaire.
  endpoint: '/api/eden/inscription.php',
};
