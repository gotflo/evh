// Règles de validation du formulaire Eden, côté navigateur.
// Le serveur applique les mêmes règles (public/api/_lib/Eden/InscriptionValidator.php) :
// ce fichier sert uniquement à prévenir la personne avant l'envoi.

export const MIN_AGE = 18;
export const MAX_AGE = 100;
export const DURATION_MAX = 99;
export const PHOTO_MAX_BYTES = 10 * 1024 * 1024;
export const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

const NANP = /^1([2-9]\d{2})([2-9]\d{2})(\d{4})$/;

/** Numéro au format international (+14184900186), ou null s'il n'est pas valide. */
export function normalizePhone(input) {
  let value = String(input ?? '').trim().replace(/[\s().\- ]/g, '');
  if (value.startsWith('00')) value = '+' + value.slice(2);

  if (value.startsWith('+')) {
    const digits = value.slice(1);
    if (!/^[1-9]\d{7,14}$/.test(digits)) return null;
    if (digits[0] === '1' && !NANP.test(digits)) return null;
    return '+' + digits;
  }

  if (!/^\d+$/.test(value)) return null;
  if (value.length === 10) value = '1' + value;
  return NANP.test(value) ? '+' + value : null;
}

/** Affichage lisible : +1 (418) 490-0186 pour le Canada et les États-Unis. */
export function formatPhone(e164) {
  const m = /^\+1(\d{3})(\d{3})(\d{4})$/.exec(e164);
  return m ? `+1 (${m[1]}) ${m[2]}-${m[3]}` : e164;
}

export function checkPhone(value) {
  if (!String(value ?? '').trim()) return 'Indiquez un numéro de téléphone.';
  if (!normalizePhone(value)) {
    return "Ce numéro ne semble pas valide. Hors Canada et États-Unis, commencez par l'indicatif du pays (ex. +225).";
  }
  return '';
}

export function checkEmail(value) {
  const v = String(value ?? '').trim();
  if (!v) return 'Indiquez votre adresse courriel.';
  if (v.length > 254 || !/^[^\s@<>()"',;:]+@[^\s@<>()"',;:]+\.[a-z]{2,}$/i.test(v)) {
    return 'Cette adresse courriel ne semble pas valide.';
  }
  return '';
}

export function checkText(value, { max = 150, isName = false } = {}) {
  const v = String(value ?? '').trim().replace(/\s+/g, ' ');
  if (!v) return 'Ce champ est obligatoire.';
  if (v.length > max) return `Ce texte est trop long (${max} caractères au maximum).`;
  if (isName && !/^[\p{L}\p{M}][\p{L}\p{M}' .\-’]+$/u.test(v)) {
    return "Utilisez uniquement des lettres, espaces, traits d'union ou apostrophes.";
  }
  if (!isName && /[<>{}\\]/.test(v)) return 'Certains caractères spéciaux ne sont pas acceptés.';
  return '';
}

/** Date du jour au format AAAA-MM-JJ, à l'heure locale. */
export function todayIso(now = new Date()) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function ageOn(birthIso, todayIsoValue) {
  const [by, bm, bd] = birthIso.split('-').map(Number);
  const [ty, tm, td] = todayIsoValue.split('-').map(Number);
  return ty - by - (tm < bm || (tm === bm && td < bd) ? 1 : 0);
}

export function checkBirthDate(value, now = new Date()) {
  const v = String(value ?? '').trim();
  if (!v) return 'Indiquez la date de naissance.';

  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
  const date = m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null;
  if (!date || date.getUTCMonth() !== +m[2] - 1 || date.getUTCDate() !== +m[3]) {
    return "Cette date n'est pas valide.";
  }

  const today = todayIso(now);
  if (v > today) return 'La date ne peut pas être dans le futur.';
  const age = ageOn(v, today);
  if (age < MIN_AGE) return `Les cours Eden sont réservés aux personnes de ${MIN_AGE} ans et plus.`;
  if (age > MAX_AGE) return "Vérifiez l'année de naissance.";
  return '';
}

export function checkDuration(value) {
  const v = String(value ?? '').trim();
  if (!/^\d{1,2}$/.test(v) || +v < 1 || +v > DURATION_MAX) {
    return `Indiquez un nombre entier entre 1 et ${DURATION_MAX}.`;
  }
  return '';
}

export function checkPhoto(file) {
  if (!file) return 'Ajoutez une photo.';
  if (!PHOTO_TYPES.includes(file.type)) return 'Format non accepté. Choisissez une photo JPG, PNG ou WebP.';
  if (file.size > PHOTO_MAX_BYTES) return 'Cette photo dépasse 10 Mo. Choisissez une image plus légère.';
  return '';
}
