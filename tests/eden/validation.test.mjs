// Tests des règles de validation du formulaire Eden (navigateur).
// Lancer : npm test
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizePhone,
  formatPhone,
  checkPhone,
  checkEmail,
  checkText,
  checkBirthDate,
  checkDuration,
  checkPhoto,
  todayIso,
} from '../../src/scripts/eden-validation.js';

const NOW = new Date(2026, 9, 3); // 3 octobre 2026

test('téléphone : numéros canadiens écrits de différentes façons', () => {
  for (const input of ['418 490-0186', '(418) 490-0186', '4184900186', '1-418-490-0186', '+1 418 490 0186', '001 418 490 0186']) {
    assert.equal(normalizePhone(input), '+14184900186', input);
  }
  assert.equal(formatPhone('+14184900186'), '+1 (418) 490-0186');
});

test('téléphone : numéros internationaux avec indicatif', () => {
  assert.equal(normalizePhone('+225 07 07 12 34 56'), '+2250707123456');
  assert.equal(normalizePhone('+33 6 12 34 56 78'), '+33612345678');
  assert.equal(formatPhone('+2250707123456'), '+2250707123456');
});

test('téléphone : numéros invalides refusés', () => {
  for (const input of ['', '123', '07 07 12 34 56', '418-490-018', '+1 018 490 0186', 'abcdefghij', '+0 123 456 789', '418 490 0186 ext 2']) {
    assert.equal(normalizePhone(input), null, input);
  }
  assert.match(checkPhone('12345'), /indicatif/);
  assert.equal(checkPhone('418 490-0186'), '');
});

test('courriel', () => {
  assert.equal(checkEmail('marie.kouassi@gmail.com'), '');
  assert.equal(checkEmail('  jean+eden@exemple.ca '), '');
  for (const bad of ['', 'marie', 'marie@', 'marie@gmail', 'ma rie@gmail.com', '<a>@x.com', 'a@b.c']) {
    assert.notEqual(checkEmail(bad), '', bad);
  }
});

test('textes et noms', () => {
  assert.equal(checkText("N'Guessan Kouadio Jean-Marc", { isName: true }), '');
  assert.equal(checkText('Élodie Brûlé', { isName: true }), '');
  assert.notEqual(checkText('J', { isName: true }), '');
  assert.notEqual(checkText('Jean123', { isName: true }), '');
  assert.notEqual(checkText('   ', {}), '');
  assert.notEqual(checkText('<script>alert(1)</script>', {}), '');
  assert.notEqual(checkText('a'.repeat(81), { max: 80 }), '');
  assert.equal(checkText('Infirmière auxiliaire', { max: 100 }), '');
});

test('date de naissance : future, invalide, âge', () => {
  assert.equal(todayIso(NOW), '2026-10-03');
  assert.equal(checkBirthDate('1994-05-12', NOW), '');
  assert.equal(checkBirthDate('2008-10-03', NOW), '', '18 ans le jour même');
  assert.match(checkBirthDate('2008-10-04', NOW), /18 ans/);
  assert.match(checkBirthDate('2027-01-01', NOW), /futur/);
  assert.match(checkBirthDate('2026-10-04', NOW), /futur/);
  assert.match(checkBirthDate('1990-02-30', NOW), /pas valide/);
  assert.match(checkBirthDate('12/05/1994', NOW), /pas valide/);
  assert.match(checkBirthDate('1900-01-01', NOW), /année/);
  assert.match(checkBirthDate('', NOW), /Indiquez/);
});

test('durée du cheminement', () => {
  assert.equal(checkDuration('1'), '');
  assert.equal(checkDuration('18'), '');
  assert.equal(checkDuration('99'), '');
  for (const bad of ['', '0', '-3', '100', '2.5', '1e2', 'deux', '3 ans']) {
    assert.notEqual(checkDuration(bad), '', bad);
  }
});

test('photos : type et taille', () => {
  assert.equal(checkPhoto({ type: 'image/jpeg', size: 2_000_000 }), '');
  assert.equal(checkPhoto({ type: 'image/webp', size: 500 }), '');
  assert.match(checkPhoto({ type: 'image/gif', size: 500 }), /Format/);
  assert.match(checkPhoto({ type: 'application/x-php', size: 500 }), /Format/);
  assert.match(checkPhoto({ type: 'image/png', size: 11 * 1024 * 1024 }), /10 Mo/);
  assert.match(checkPhoto(null), /Ajoutez/);
});
