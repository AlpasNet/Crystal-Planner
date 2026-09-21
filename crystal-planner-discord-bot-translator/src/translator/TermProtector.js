const TOKEN_PREFIX = 'ZXQCRYSTAL';
const TOKEN_SUFFIX = 'QXZ';


const HARD_DISCORD_REFERENCE_PATTERN = /(<a?:[A-Za-z0-9_~]+:\d+>|<@&\d+>|<@!?\d+>|<#\d+>|@(everyone|here)\b|(?<![\p{L}\p{N}._%+-])@[A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_])?|(?<![\p{L}\p{N}_])#[\p{L}\p{N}\p{M}\p{S}_-]+|(?:\p{Regional_Indicator}{2}|[#*0-9]\uFE0F?\u20E3|\p{Extended_Pictographic}(?:\uFE0F|\uFE0E)?(?:\p{Emoji_Modifier})?(?:\u200D\p{Extended_Pictographic}(?:\uFE0F|\uFE0E)?(?:\p{Emoji_Modifier})?)*))/giu;

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function termRegex(term) {
  const escaped = escapeRegex(term);
  const startsWord = /^[\p{L}\p{N}_]/u.test(term);
  const endsWord = /[\p{L}\p{N}_]$/u.test(term);
  return new RegExp(`${startsWord ? '(?<![\\p{L}\\p{N}_])' : ''}${escaped}${endsWord ? '(?![\\p{L}\\p{N}_])' : ''}`, 'giu');
}

export class TermProtector {
  constructor(termsConfig) {
    this.protectedTerms = Array.isArray(termsConfig?.protectedTerms) ? termsConfig.protectedTerms : [];
    this.dictionary = termsConfig?.dictionary || {};
  }

  splitDiscordReferences(text) {
    const input = String(text ?? '');
    const segments = [];
    let lastIndex = 0;

    HARD_DISCORD_REFERENCE_PATTERN.lastIndex = 0;
    for (const match of input.matchAll(HARD_DISCORD_REFERENCE_PATTERN)) {
      const index = match.index ?? 0;
      if (index > lastIndex) {
        segments.push({ type: 'text', value: input.slice(lastIndex, index) });
      }
      segments.push({ type: 'discord-reference', value: match[0] });
      lastIndex = index + match[0].length;
    }

    if (lastIndex < input.length) {
      segments.push({ type: 'text', value: input.slice(lastIndex) });
    }

    if (!segments.length) segments.push({ type: 'text', value: input });
    return segments;
  }

  // Backward-compatible alias used by older callers/tests.
  splitMentions(text) {
    return this.splitDiscordReferences(text).map((segment) =>
      segment.type === 'discord-reference'
        ? { ...segment, type: 'mention' }
        : segment
    );
  }

  protect(text, from, to) {
    let output = String(text ?? '');
    const replacements = [];

    const reserve = (replacement) => {
      const token = `${TOKEN_PREFIX}${String(replacements.length).padStart(4, '0')}${TOKEN_SUFFIX}`;
      replacements.push({ token, replacement: String(replacement) });
      return token;
    };

    // Discord syntax and technical content should never be translated.
    const technicalPatterns = [
      /```[\s\S]*?```/g,
      /`[^`\n]+`/g,
      /<a?:[A-Za-z0-9_~]+:\d+>/g,
      /<#\d+>/g,
      /<t:\d+(?::[tTdDfFR])?>/g,
      /https?:\/\/[^\s<>]+/gi
    ];

    for (const pattern of technicalPatterns) {
      output = output.replace(pattern, (match) => reserve(match));
    }

    // Apply the directional dictionary before generic protected terms.
    const dict = this.dictionary[`${from}-${to}`] || {};
    const dictionaryEntries = Object.entries(dict).sort(([a], [b]) => b.length - a.length);
    for (const [source, target] of dictionaryEntries) {
      output = output.replace(termRegex(source), () => reserve(target));
    }

    // Preserve game/community terms exactly as typed.
    const protectedTerms = [...this.protectedTerms].sort((a, b) => b.length - a.length);
    for (const term of protectedTerms) {
      output = output.replace(termRegex(term), (match) => reserve(match));
    }

    return { text: output, replacements };
  }

  restore(text, replacements) {
    let output = String(text ?? '');
    for (const { token, replacement } of replacements) {
      // Most models copy the token exactly. The second form tolerates inserted spaces.
      const compact = new RegExp(escapeRegex(token), 'gi');
      output = output.replace(compact, replacement);

      const digits = token.slice(TOKEN_PREFIX.length, -TOKEN_SUFFIX.length).split('').join('\\s*');
      const spaced = new RegExp(`${TOKEN_PREFIX}\\s*${digits}\\s*${TOKEN_SUFFIX}`, 'gi');
      output = output.replace(spaced, replacement);
    }
    return output;
  }
}
