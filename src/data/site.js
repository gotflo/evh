// Informations de l'église réutilisées pour le référencement (balises de partage,
// données structurées schema.org). Une seule source pour éviter les écarts.

export const site = {
  name: "Église Vases d'Honneur Chicoutimi",
  alternateName: ["Vases d'Honneur Chicoutimi", "Assemblée Plénitude d'Amour", 'EVH Chicoutimi'],
  description:
    "Église chrétienne au centre-ville de Chicoutimi (Saguenay), membre du ministère international Vases d'Honneur. Cultes le dimanche et le mercredi.",
  logo: '/img/logo/cropped-Sans-titre-3-1-300x300.png',
  image: '/img/Gallery/385744452_806068568188038_1097952417221916540_n-1536x1023.jpg',
  imageWidth: 1536,
  imageHeight: 1023,
  telephone: '+1-438-350-3229',
  email: 'contact@evhca.com',
  address: {
    streetAddress: '70 Rue Racine Est',
    addressLocality: 'Chicoutimi',
    addressRegion: 'QC',
    postalCode: 'G7H 1P6',
    addressCountry: 'CA',
  },
  mapUrl: 'https://www.google.com/maps/search/?api=1&query=70+Rue+Racine+Est,+Chicoutimi,+QC,+G7H+1P6',
  sameAs: [
    'https://www.facebook.com/vasesdhonneurchicoutimi/',
    'https://www.instagram.com/vasesdhonneurchicoutimi',
    'https://www.youtube.com/@vasesdhonneurchicoutimi',
  ],
};

/** Données structurées de l'accueil : l'église et le site lui-même. */
export function homeJsonLd(siteUrl) {
  const abs = (path) => new URL(path, siteUrl).href;
  const churchId = abs('/#eglise');

  return [
    {
      '@context': 'https://schema.org',
      '@type': 'Church',
      '@id': churchId,
      name: site.name,
      alternateName: site.alternateName,
      description: site.description,
      url: abs('/'),
      logo: abs(site.logo),
      image: abs(site.image),
      telephone: site.telephone,
      email: site.email,
      address: { '@type': 'PostalAddress', ...site.address },
      hasMap: site.mapUrl,
      areaServed: ['Chicoutimi', 'Saguenay', 'Saguenay-Lac-Saint-Jean'],
      sameAs: site.sameAs,
      parentOrganization: {
        '@type': 'Organization',
        name: "Vases d'Honneur",
        description: "Ministère international fondé à Abidjan (Côte d'Ivoire) par l'Apôtre Mohammed Sanogo.",
      },
      employee: {
        '@type': 'Person',
        name: 'Abraham Andebi',
        jobTitle: 'Pasteur principal',
      },
    },
    {
      '@context': 'https://schema.org',
      '@type': 'WebSite',
      '@id': abs('/#site'),
      name: site.name,
      alternateName: site.alternateName[0],
      url: abs('/'),
      inLanguage: 'fr-CA',
      publisher: { '@id': churchId },
    },
  ];
}
