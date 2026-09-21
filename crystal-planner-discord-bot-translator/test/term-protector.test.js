import test from 'node:test';
import assert from 'node:assert/strict';
import { TermProtector } from '../src/translator/TermProtector.js';

const protector = new TermProtector({
  protectedTerms: ['FFXIV', 'Party Finder'],
  dictionary: {
    'fr-en': { 'Sadique': 'Savage', 'Extrême': 'Extreme' },
    'en-fr': { 'Savage': 'Sadique', 'Extreme': 'Extrême' }
  }
});

test('dictionary replacements survive translation placeholders', () => {
  const protectedValue = protector.protect('On fait le Sadique et un Extrême sur FFXIV.', 'fr', 'en');
  assert.ok(!protectedValue.text.includes('Sadique'));
  assert.ok(!protectedValue.text.includes('Extrême'));
  assert.equal(
    protector.restore(protectedValue.text, protectedValue.replacements),
    'On fait le Savage et un Extreme sur FFXIV.'
  );
});

test('Discord mentions, URLs and code are protected', () => {
  const input = '<@123456789012345678> regarde https://example.com et `npm start` dans Party Finder';
  const protectedValue = protector.protect(input, 'fr', 'en');
  const restored = protector.restore(protectedValue.text, protectedValue.replacements);
  assert.equal(restored, input);
});


test('all Discord-style @ mentions are hard-split and reconstructed unchanged', () => {
  const input = '<@123456789012345678> @everyone @here @Seije @akira.yume @raid-leader bonjour';
  const segments = protector.splitMentions(input);
  assert.equal(segments.map((segment) => segment.value).join(''), input);

  const mentions = segments.filter((segment) => segment.type === 'mention').map((segment) => segment.value);
  assert.deepEqual(mentions, [
    '<@123456789012345678>',
    '@everyone',
    '@here',
    '@Seije',
    '@akira.yume',
    '@raid-leader'
  ]);
});

test('mentions are split out before translation instead of becoming placeholders', () => {
  const input = 'Salut <@123456789012345678>, viens avec @Seije et @everyone ce soir.';
  const segments = protector.splitMentions(input);
  const mentions = segments.filter((segment) => segment.type === 'mention').map((segment) => segment.value);
  assert.deepEqual(mentions, ['<@123456789012345678>', '@Seije', '@everyone']);
  assert.equal(segments.map((segment) => segment.value).join(''), input);
});

test('email addresses are not mistaken for literal Discord @ mentions', () => {
  const input = 'Contacte test@example.com puis @Seije.';
  const segments = protector.splitMentions(input);
  const mentions = segments.filter((segment) => segment.type === 'mention').map((segment) => segment.value);
  assert.deepEqual(mentions, ['@Seije']);
  assert.equal(segments.map((segment) => segment.value).join(''), input);
});


test('Discord channel references and literal channel names are hard-split unchanged', () => {
  const input = 'Va dans <#123456789012345678>, #general-fr, #raid-planning et #✒️-questions-and-issues.';
  const segments = protector.splitDiscordReferences(input);
  assert.equal(segments.map((segment) => segment.value).join(''), input);

  const refs = segments
    .filter((segment) => segment.type === 'discord-reference')
    .map((segment) => segment.value);
  assert.deepEqual(refs, [
    '<#123456789012345678>',
    '#general-fr',
    '#raid-planning',
    '#✒️-questions-and-issues'
  ]);
});

test('channel names never enter the translation segments', () => {
  const input = 'Écris dans #general-en puis réponds à @Seije dans <#987654321098765432>.';
  const segments = protector.splitDiscordReferences(input);
  const translatable = segments
    .filter((segment) => segment.type === 'text')
    .map((segment) => segment.value)
    .join('');

  assert.ok(!translatable.includes('#general-en'));
  assert.ok(!translatable.includes('@Seije'));
  assert.ok(!translatable.includes('<#987654321098765432>'));
  assert.equal(segments.map((segment) => segment.value).join(''), input);
});

test('Discord custom emotes are hard-split and never enter translation segments', () => {
  const input = 'Salut <:mogdance:123456789012345678> puis <a:party_parrot:987654321098765432> ce soir.';
  const segments = protector.splitDiscordReferences(input);
  const refs = segments
    .filter((segment) => segment.type === 'discord-reference')
    .map((segment) => segment.value);
  assert.deepEqual(refs, [
    '<:mogdance:123456789012345678>',
    '<a:party_parrot:987654321098765432>'
  ]);

  const translatable = segments
    .filter((segment) => segment.type === 'text')
    .map((segment) => segment.value)
    .join('');
  assert.ok(!translatable.includes('mogdance'));
  assert.ok(!translatable.includes('party_parrot'));
  assert.equal(segments.map((segment) => segment.value).join(''), input);
});

test('Unicode emojis are hard-split and reconstructed unchanged', () => {
  const input = 'Salut 😂 ❤️ 👍🏽 👨‍👩‍👧‍👦 🇫🇷 on raid ce soir ?';
  const segments = protector.splitDiscordReferences(input);
  assert.equal(segments.map((segment) => segment.value).join(''), input);

  const translatable = segments
    .filter((segment) => segment.type === 'text')
    .map((segment) => segment.value)
    .join('');
  for (const emoji of ['😂', '❤️', '👍🏽', '👨‍👩‍👧‍👦', '🇫🇷']) {
    assert.ok(!translatable.includes(emoji), `emoji should not enter Bergamot: ${emoji}`);
  }
});
