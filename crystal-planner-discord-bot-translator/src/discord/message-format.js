const MAX_CONTENT = 1950;

export function translationFooter(from, to, enabled = true) {
  return enabled ? `-# 🌐 ${from.toUpperCase()} → ${to.toUpperCase()}` : '';
}

export function splitTranslatedMessage(text, { prefix = '', footer = '' } = {}) {
  const clean = String(text || '').trim();
  const prefixBlock = prefix ? `${prefix}\n` : '';
  const footerBlock = footer ? `\n${footer}` : '';
  const single = `${prefixBlock}${clean}${footerBlock}`.trim();
  if (single.length <= MAX_CONTENT) return [single || footer || '🌐'];

  const reserveLast = footer ? footer.length + 2 : 0;
  const chunks = [];
  let remaining = clean;
  let first = true;

  while (remaining.length) {
    const firstPrefix = first && prefix ? `${prefix}\n` : '';
    const limit = MAX_CONTENT - firstPrefix.length - reserveLast;
    let cut = Math.min(limit, remaining.length);
    if (cut < remaining.length) {
      const candidate = remaining.slice(0, cut);
      const newline = candidate.lastIndexOf('\n');
      const space = candidate.lastIndexOf(' ');
      const natural = Math.max(newline, space);
      if (natural > Math.floor(limit * 0.6)) cut = natural;
    }
    const part = remaining.slice(0, cut).trim();
    remaining = remaining.slice(cut).trim();
    chunks.push(`${firstPrefix}${part}`.trim());
    first = false;
  }

  if (footer) chunks[chunks.length - 1] = `${chunks[chunks.length - 1]}\n${footer}`;
  return chunks;
}
