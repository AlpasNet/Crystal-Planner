import { assertHttps, sleep } from "./utils.js";

const USER_AGENT = "Crystal-Planner-Server/1.1.3 (+https://github.com/AlpasNet/Crystal-Planner)";

export async function fetchResponse(url, {
  method = "GET",
  headers = {},
  body = undefined,
  timeoutMs = 35000,
  attempts = 3
} = {}) {
  assertHttps(url);
  let lastError;

  for (let attempt = 1; attempt <= Math.max(1, attempts); attempt++) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const response = await fetch(url, {
        method,
        headers: {
          "User-Agent": USER_AGENT,
          "Accept": "application/json,text/html,text/plain,*/*",
          "Accept-Encoding": "identity",
          ...headers
        },
        body,
        signal: controller.signal,
        redirect: "follow"
      });
      clearTimeout(timeout);

      if ((response.status === 429 || response.status >= 500) && attempt < attempts) {
        const retryAfter = Number(response.headers.get("retry-after") || 0);
        await response.arrayBuffer().catch(() => {});
        await sleep(Math.min(Math.max(retryAfter * 1000 || attempt * 1000, 500), 30000));
        continue;
      }
      return response;
    } catch (error) {
      clearTimeout(timeout);
      lastError = error;
      if (attempt >= attempts) break;
      await sleep(Math.min(attempt * 1000, 5000));
    }
  }
  throw new Error(`HTTP request failed for ${url}: ${lastError?.message || "unknown error"}`);
}

export async function getText(url, options = {}) {
  const response = await fetchResponse(url, options);
  const text = await response.text();
  if (!response.ok) {
    throw new Error(`HTTP ${response.status} on ${url}: ${text.slice(0, 240)}`);
  }
  return text;
}

export async function getJson(url, options = {}) {
  const text = await getText(url, options);
  try {
    return JSON.parse(text);
  } catch (error) {
    throw new Error(`Invalid JSON returned by ${url}: ${error.message}`);
  }
}
