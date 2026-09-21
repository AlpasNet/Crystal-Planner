import { loadConfig } from './config.js';
import { BergamotService } from './translator/BergamotService.js';

const config = await loadConfig();
const translator = new BergamotService(config);

try {
  console.log('Downloading/caching FR → EN model...');
  console.log(await translator.translate('Bonjour tout le monde.', 'fr', 'en'));
  console.log('Downloading/caching EN → FR model...');
  console.log(await translator.translate('Hello everyone.', 'en', 'fr'));
  console.log('Bergamot FR/EN models are cached and ready.');
} finally {
  await translator.close();
}
