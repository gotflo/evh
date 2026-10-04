// Formulaire d'inscription Eden : étapes, validation, photos et envoi.
import {
  MIN_AGE,
  MAX_AGE,
  normalizePhone,
  formatPhone,
  checkPhone,
  checkEmail,
  checkText,
  checkBirthDate,
  checkDuration,
  checkPhoto,
  todayIso,
} from './eden-validation.js';

const REQUEST_TIMEOUT_MS = 120000;
// Au-delà, la photo est réduite dans le navigateur avant l'envoi (plus rapide sur cellulaire).
const RESIZE_ABOVE_BYTES = 1.5 * 1024 * 1024;
const RESIZE_MAX_SIDE = 2000;

const TEXT_RULES = {
  repondant_nom: { max: 120, isName: true },
  repondant_nationalite: { max: 80 },
  repondant_profession: { max: 100 },
  congregation: { max: 150 },
  cheminant_nom: { max: 120, isName: true },
  cheminant_nationalite: { max: 80 },
  cheminant_profession: { max: 100 },
  cheminant_pays: { max: 80 },
  cheminant_ville: { max: 80 },
  cheminant_eglise: { max: 150 },
};

const CHOICES = ['membre_evh', 'baptise_immersion', 'relation_precedente', 'deja_marie', 'bloquants', 'pret_classes'];
const PHOTOS = ['photo_repondant', 'photo_cheminant'];

const SERVER_MESSAGES = {
  403: "Cette demande n'a pas été acceptée. Rechargez la page puis réessayez.",
  413: 'Les photos sont trop lourdes. Choisissez des images plus légères (10 Mo au maximum chacune).',
  429: "Trop de tentatives depuis votre connexion. Patientez une heure avant de réessayer, ou appelez l'infoline.",
};
const GENERIC_ERROR = "Votre inscription n'a pas pu être enregistrée à cause d'un problème technique. Vos réponses sont toujours là : réessayez dans quelques minutes.";
const NETWORK_ERROR = "La connexion a été interrompue avant la fin de l'envoi. Vérifiez votre connexion internet puis réessayez : vos réponses sont toujours là.";

function uuid() {
  if (window.crypto?.randomUUID) return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** Réduit une grande photo en JPEG. En cas d'échec, renvoie le fichier d'origine. */
async function shrinkPhoto(file) {
  if (file.size <= RESIZE_ABOVE_BYTES) return file;
  try {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.src = url;
    await img.decode();
    const ratio = Math.min(1, RESIZE_MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(img.naturalWidth * ratio);
    canvas.height = Math.round(img.naturalHeight * ratio);
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    URL.revokeObjectURL(url);
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.86));
    return blob && blob.size < file.size ? blob : file;
  } catch {
    return file;
  }
}

export function mountEdenForm() {
  const form = document.getElementById('eden-form');
  if (!form) return;

  const steps = [...form.querySelectorAll('.eden-step')];
  const titleEl = form.querySelector('[data-step-title]');
  const currentEl = form.querySelector('[data-step-current]');
  const progressEl = form.querySelector('[data-progress]');
  const prevBtn = form.querySelector('[data-prev]');
  const nextBtn = form.querySelector('[data-next]');
  const submitBtn = form.querySelector('[data-submit]');
  const submitText = form.querySelector('[data-submit-text]');
  const alertEl = form.querySelector('[data-alert]');
  const uploadEl = form.querySelector('[data-upload]');
  const uploadBar = form.querySelector('[data-upload-bar]');
  const uploadText = form.querySelector('[data-upload-text]');
  const consent = form.elements.namedItem('confirmation_frais');
  const success = document.getElementById('eden-success');

  const photos = {};
  const photoErrors = {};
  const touched = new Set();
  let current = 0;
  let sending = false;
  let dirty = false;
  let done = false;

  form.elements.namedItem('submission_id').value = uuid();

  // Dates : pas de date future, âge entre 18 et 100 ans.
  const now = new Date();
  const maxBirth = new Date(now.getFullYear() - MIN_AGE, now.getMonth(), now.getDate());
  form.querySelectorAll('input[type="date"]').forEach((input) => {
    input.max = todayIso(maxBirth);
    input.min = `${now.getFullYear() - MAX_AGE - 1}-01-01`;
  });

  /* ---------- Règles ---------- */

  const value = (name) => {
    const el = form.elements.namedItem(name);
    if (!el) return '';
    if (el instanceof RadioNodeList) return el.value;
    return el.type === 'checkbox' ? (el.checked ? el.value : '') : el.value;
  };

  const congregationVisible = () => value('membre_evh') === 'non';

  const rules = {
    repondant_email: () => checkEmail(value('repondant_email')),
    repondant_date_naissance: () => checkBirthDate(value('repondant_date_naissance')),
    cheminant_date_naissance: () => checkBirthDate(value('cheminant_date_naissance')),
    repondant_telephone: () => checkPhone(value('repondant_telephone')),
    cheminant_telephone: () => checkPhone(value('cheminant_telephone')),
    duree_valeur: () => checkDuration(value('duree_valeur')) || (value('duree_unite') ? '' : 'Choisissez mois ou années.'),
    confirmation_frais: () => (consent.checked ? '' : "Cochez la case pour confirmer avoir pris connaissance des frais d'inscription."),
  };
  Object.entries(TEXT_RULES).forEach(([name, opts]) => {
    rules[name] = name === 'congregation'
      ? () => (congregationVisible() ? checkText(value(name), opts) : '')
      : () => checkText(value(name), opts);
  });
  CHOICES.forEach((name) => { rules[name] = () => (value(name) ? '' : 'Choisissez une réponse.'); });
  PHOTOS.forEach((name) => {
    rules[name] = () => (photos[name] ? '' : photoErrors[name] || 'Ajoutez une photo.');
  });

  const fieldBox = (name) => form.querySelector(`[data-field="${name}"]`);

  const showError = (name, message) => {
    const box = fieldBox(name);
    if (!box) return;
    const errorEl = box.querySelector('.eden-error');
    box.classList.toggle('has-error', Boolean(message));
    if (errorEl) errorEl.textContent = message;
    box.querySelectorAll('input, select').forEach((el) => {
      if (el.type !== 'hidden') el.setAttribute('aria-invalid', message ? 'true' : 'false');
    });
  };

  const validateField = (name) => {
    const message = rules[name] ? rules[name]() : '';
    showError(name, message);
    if (!message && alertEl.dataset.fieldErrors && !steps[current].querySelector('.has-error')) setAlert('');
    return !message;
  };

  // Champs d'une étape, sauf ceux d'un bloc conditionnel masqué.
  const fieldsOfStep = (step) => [...new Set(
    [...step.querySelectorAll('[data-field]')]
      .filter((box) => !box.closest('.eden-conditional[hidden]'))
      .map((box) => box.dataset.field)
  )];

  const validateStep = (index) => fieldsOfStep(steps[index]).filter((name) => !validateField(name));

  const focusField = (name) => {
    const box = fieldBox(name);
    const target = box?.querySelector('input:not([type="hidden"]), select');
    if (target) {
      target.focus({ preventScroll: true });
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  };

  /* ---------- Étapes ---------- */

  // fieldErrors : l'alerte résume des champs en erreur et disparaît quand ils sont corrigés.
  const setAlert = (message, { fieldErrors = false } = {}) => {
    alertEl.hidden = !message;
    alertEl.textContent = message || '';
    alertEl.dataset.fieldErrors = fieldErrors ? '1' : '';
  };

  const goTo = (index, { focus = true } = {}) => {
    current = Math.max(0, Math.min(index, steps.length - 1));
    steps.forEach((step, i) => {
      step.hidden = i !== current;
      step.classList.toggle('is-active', i === current);
    });
    const last = current === steps.length - 1;
    titleEl.textContent = steps[current].dataset.title;
    currentEl.textContent = String(current + 1);
    progressEl.style.width = `${((current + 1) / steps.length) * 100}%`;
    prevBtn.hidden = current === 0;
    nextBtn.hidden = last;
    submitBtn.hidden = !last;
    setAlert('');
    if (focus) {
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      titleEl.focus({ preventScroll: true });
    }
  };

  nextBtn.addEventListener('click', () => {
    const invalid = validateStep(current);
    invalid.forEach((name) => touched.add(name));
    if (invalid.length) {
      setAlert(invalid.length === 1 ? 'Une réponse est à compléter ou à corriger.' : `${invalid.length} réponses sont à compléter ou à corriger.`, { fieldErrors: true });
      focusField(invalid[0]);
      return;
    }
    goTo(current + 1);
  });

  prevBtn.addEventListener('click', () => goTo(current - 1));

  // La touche Entrée dans un champ fait avancer d'une étape au lieu d'envoyer.
  form.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target instanceof HTMLInputElement && e.target.type !== 'checkbox' && current < steps.length - 1) {
      e.preventDefault();
      nextBtn.click();
    }
  });

  /* ---------- Validation au fil de la saisie ---------- */

  const nameOf = (el) => el.closest('[data-field]')?.dataset.field;

  form.addEventListener('focusout', (e) => {
    const name = nameOf(e.target);
    if (!name || e.target.type === 'radio' || e.target.type === 'file') return;
    if (e.target.type === 'tel' && e.target.value.trim()) {
      const normalized = normalizePhone(e.target.value);
      if (normalized) e.target.value = formatPhone(normalized);
    }
    if (e.target.value.trim() || touched.has(name)) {
      touched.add(name);
      validateField(name);
    }
  });

  form.addEventListener('input', (e) => {
    dirty = true;
    const name = nameOf(e.target);
    if (name && touched.has(name) && e.target.type !== 'file') validateField(name);
  });

  form.addEventListener('change', (e) => {
    dirty = true;
    const name = nameOf(e.target);
    if (e.target.type === 'radio' && name) {
      touched.add(name);
      validateField(name);
    }
  });

  // Durée : uniquement des chiffres (ni signe moins, ni lettres, ni virgule).
  const duration = form.elements.namedItem('duree_valeur');
  duration.addEventListener('input', () => {
    const digits = duration.value.replace(/\D/g, '').slice(0, 2);
    if (digits !== duration.value) duration.value = digits;
  });

  // Congrégation : demandée seulement si la personne n'est pas de Vases d'Honneur.
  const conditional = form.querySelector('[data-show-when="membre_evh=non"]');
  const congregation = form.elements.namedItem('congregation');
  form.querySelectorAll('input[name="membre_evh"]').forEach((radio) => {
    radio.addEventListener('change', () => {
      const show = congregationVisible();
      conditional.hidden = !show;
      congregation.disabled = !show;
      if (!show) {
        congregation.value = '';
        touched.delete('congregation');
        showError('congregation', '');
      }
    });
  });
  congregation.disabled = true;

  // Case de confirmation : le bouton final reste inactif tant qu'elle n'est pas cochée.
  consent.addEventListener('change', () => {
    submitBtn.disabled = !consent.checked || sending;
    validateField('confirmation_frais');
  });

  /* ---------- Photos ---------- */

  PHOTOS.forEach((name) => {
    const input = form.elements.namedItem(name);
    const box = fieldBox(name);
    const preview = box.querySelector('.eden-photo-preview');
    const empty = box.querySelector('.eden-photo-empty');
    const pickText = box.querySelector('[data-pick-text]');
    let previewUrl = null;

    input.addEventListener('change', async () => {
      const file = input.files?.[0];
      if (!file) return;
      touched.add(name);

      const error = checkPhoto(file);
      if (error) {
        input.value = '';
        photoErrors[name] = error;
        if (!photos[name]) {
          preview.hidden = true;
          empty.hidden = false;
        }
        showError(name, photos[name] ? `${error} La photo précédente est conservée.` : error);
        return;
      }

      delete photoErrors[name];
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = URL.createObjectURL(file);
      preview.src = previewUrl;
      preview.alt = 'Aperçu de la photo choisie';
      preview.hidden = false;
      empty.hidden = true;
      box.classList.add('has-photo');
      pickText.textContent = 'Remplacer la photo';
      photos[name] = await shrinkPhoto(file);
      validateField(name);
    });
  });

  /* ---------- Boutons « Copier » ---------- */

  document.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(btn.dataset.copy);
        const label = btn.textContent;
        btn.textContent = 'Copié';
        btn.classList.add('is-done');
        setTimeout(() => { btn.textContent = label; btn.classList.remove('is-done'); }, 1800);
      } catch {
        btn.hidden = true;
      }
    });
  });

  /* ---------- Envoi ---------- */

  const setSending = (state, percent = 0) => {
    sending = state;
    submitBtn.disabled = state || !consent.checked;
    prevBtn.disabled = state;
    form.classList.toggle('is-sending', state);
    submitText.textContent = state ? 'Envoi en cours' : 'Finaliser mon inscription';
    uploadEl.hidden = !state;
    uploadBar.style.width = `${percent}%`;
    uploadText.textContent = state
      ? (percent < 100 ? `Envoi de vos réponses et de vos photos : ${percent} %` : 'Enregistrement de votre inscription')
      : '';
  };

  const send = (data) => new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action);
    xhr.timeout = REQUEST_TIMEOUT_MS;
    xhr.responseType = 'json';
    xhr.upload.addEventListener('progress', (e) => {
      if (e.lengthComputable) setSending(true, Math.round((e.loaded / e.total) * 100));
    });
    xhr.addEventListener('load', () => resolve({ status: xhr.status, body: xhr.response }));
    xhr.addEventListener('error', () => resolve({ status: 0, body: null }));
    xhr.addEventListener('timeout', () => resolve({ status: 0, body: null }));
    xhr.send(data);
  });

  const showSuccess = (body) => {
    done = true;
    const ref = success.querySelector('[data-success-ref]');
    if (body.reference) {
      success.querySelector('[data-ref]').textContent = body.reference;
      ref.hidden = false;
    }
    success.querySelector('[data-email]').textContent = value('repondant_email').trim();
    success.querySelector('[data-mail-ok]').hidden = Boolean(body.courriel_candidat_echec);
    success.querySelector('[data-mail-fail]').hidden = !body.courriel_candidat_echec;
    form.hidden = true;
    success.hidden = false;
    success.scrollIntoView({ behavior: 'smooth', block: 'start' });
    success.focus({ preventScroll: true });
  };

  const showServerErrors = (errors) => {
    // L'unité de durée partage la zone d'erreur du nombre.
    const byField = {};
    Object.entries(errors).forEach(([name, message]) => {
      const target = name === 'duree_unite' ? 'duree_valeur' : name;
      byField[target] = byField[target] || message;
    });
    const names = Object.keys(byField).filter((name) => fieldBox(name));
    if (!names.length) {
      setAlert(Object.values(errors)[0] || GENERIC_ERROR);
      return;
    }
    names.forEach((name) => {
      touched.add(name);
      showError(name, byField[name]);
    });
    const firstStep = steps.findIndex((step) => names.some((n) => step.querySelector(`[data-field="${n}"]`)));
    if (firstStep >= 0 && firstStep !== current) goTo(firstStep, { focus: false });
    setAlert('Certaines réponses sont à corriger. Elles sont indiquées en rouge.', { fieldErrors: true });
    const first = names.find((n) => steps[current].querySelector(`[data-field="${n}"]`));
    if (first) focusField(first);
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (sending || done) return;

    // Contrôle complet avant l'envoi, étape par étape.
    for (let i = 0; i < steps.length; i += 1) {
      const invalid = validateStep(i);
      if (invalid.length) {
        invalid.forEach((name) => touched.add(name));
        if (i !== current) goTo(i, { focus: false });
        setAlert('Certaines réponses sont à compléter ou à corriger.', { fieldErrors: true });
        focusField(invalid[0]);
        return;
      }
    }

    const data = new FormData(form);
    PHOTOS.forEach((name) => {
      const photo = photos[name];
      data.set(name, photo, photo.name || `${name}.jpg`);
    });
    ['repondant_telephone', 'cheminant_telephone'].forEach((name) => {
      data.set(name, normalizePhone(value(name)) || value(name));
    });

    setAlert('');
    setSending(true, 0);
    const { status, body } = await send(data);
    setSending(false);

    if (status === 200 && body?.ok) {
      showSuccess(body);
    } else if (status === 422 && body?.errors) {
      showServerErrors(body.errors);
    } else if (status === 0) {
      setAlert(NETWORK_ERROR);
    } else {
      setAlert(SERVER_MESSAGES[status] || body?.message || GENERIC_ERROR);
    }
  });

  window.addEventListener('beforeunload', (e) => {
    if (dirty && !done) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  form.classList.add('is-ready');
  goTo(0, { focus: false });
}
