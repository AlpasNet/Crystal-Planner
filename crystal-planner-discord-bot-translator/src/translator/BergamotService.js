import { BatchTranslator } from '@browsermt/bergamot-translator/translator.js';
import { MozillaModelBacking } from './MozillaModelBacking.js';
import { TermProtector } from './TermProtector.js';

export class BergamotService {
  constructor(config) {
    this.protector = new TermProtector(config.terms);

    const options = {
      workers: 1,
      batchSize: 1,
      cacheSize: 0,
      pivotLanguage: null,
      downloadTimeout: config.model.downloadTimeoutMs,
      mozillaRegistryUrl: config.model.registryUrl,
      refreshMozillaRegistry: config.model.refreshMozillaRegistry,
      modelArchitecture: config.model.architecture,
      modelReleaseStatus: config.model.releaseStatus,
      modelCacheDir: config.model.cacheDir,
      onerror: (error) => console.error('[Bergamot worker]', error)
    };

    const backing = new MozillaModelBacking(options);
    this.translator = new BatchTranslator(options, backing);
  }

  async translateTextSegment(text, from, to) {
    if (!text?.trim()) return text || '';
    const protectedInput = this.protector.protect(text, from, to);
    const response = await this.translator.translate({
      from,
      to,
      text: protectedInput.text,
      html: false,
      qualityScores: false,
      priority: 0
    });
    return this.protector.restore(response.target.text, protectedInput.replacements);
  }

  async translate(text, from, to) {
    if (!text?.trim()) return '';

    // Discord references and emotes are hard-protected: mentions, channel names,
    // custom Discord emotes and Unicode emoji sequences never enter Bergamot at all.
    // This prevents the model from translating or corrupting any of them.
    const segments = this.protector.splitDiscordReferences(text);
    const output = [];

    for (const segment of segments) {
      if (segment.type === 'discord-reference') {
        output.push(segment.value);
      } else {
        output.push(await this.translateTextSegment(segment.value, from, to));
      }
    }

    return output.join('').trim();
  }

  async close() {
    await this.translator.delete();
  }
}
