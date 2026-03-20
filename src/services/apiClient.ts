type ApiResponse<T> = {
  ok: boolean;
  message?: string;
  data?: T;
};

type CacheEntry = {
  expiresAt: number;
  data: unknown;
};

type FetchApiDataOptions = {
  method?: string;
  headers?: Record<string, string>;
  body?: unknown;
  credentials?: RequestCredentials;
  ttlMs?: number;
  forceRefresh?: boolean;
  cacheKey?: string;
  timeoutMs?: number;
};

const responseCache = new Map<string, CacheEntry>();
const inflight = new Map<string, Promise<unknown>>();

function normalizeMethod(value?: string): string {
  return String(value || 'GET').toUpperCase();
}

function toCacheKey(method: string, url: string, custom?: string): string {
  if (custom) return custom;
  return `${method}:${url}`;
}

function getCached<T>(key: string): T | null {
  const cached = responseCache.get(key);
  if (!cached) return null;
  if (cached.expiresAt <= Date.now()) {
    responseCache.delete(key);
    return null;
  }
  return cached.data as T;
}

function parseApiPayload<T>(text: string, statusCode: number): ApiResponse<T> {
  if (!text) return { ok: false, message: `Request failed (${statusCode})` };

  try {
    const parsed = JSON.parse(text);
    if (parsed.ok === false) {
      return { ok: false, message: parsed.message || `API error (${statusCode})` };
    }
    return { ok: true, data: parsed as T };
  } catch {
    return { ok: false, message: `Invalid response (${statusCode})` };
  }
}

async function fetchWithTimeout(
  url: string,
  options: RequestInit & { timeoutMs?: number } = {}
): Promise{ ok: boolean; status: number; text: string }> {
  const { timeoutMs = 30000, ...fetchOptions } = options;

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, {
      ...fetchOptions,
      signal: controller.signal
    });
    clearTimeout(timeoutId);

    const text = await response.text();
    return { ok: response.ok, status: response.status, text };
  } catch (error) {
    clearTimeout(timeoutId);
    if (error instanceof Error && error.name === 'AbortError') {
      return { ok: false, status: 0, text: '' };
    }
    throw error;
  }
}

export async function fetchApiData<T>(
  url: string,
  options: FetchApiDataOptions = {}
): Promise<T> {
  const method = normalizeMethod(options.method);
  const cacheKey = toCacheKey(method, url, options.cacheKey);

  if (method === 'GET' && !options.forceRefresh) {
    const cached = getCached<T>(cacheKey);
    if (cached) return cached;
  }

  if (inflight.has(cacheKey)) {
    return (await inflight.get(cacheKey)) as T;
  }

  const promise = (async () => {
    try {
      const { ok, status, text } = await fetchWithTimeout(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          ...options.headers
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
        credentials: options.credentials,
        timeoutMs: options.timeoutMs
      });

      const result = parseApiPayload<T>(text, status);

      if (result.ok && result.data !== undefined) {
        if (method === 'GET' && options.ttlMs) {
          responseCache.set(cacheKey, {
            expiresAt: Date.now() + options.ttlMs,
            data: result.data
          });
        }
        return result.data;
      }

      throw new Error(result.message || `API request failed (${status})`);
    } finally {
      inflight.delete(cacheKey);
    }
  })();

  inflight.set(cacheKey, promise);
  return promise as Promise<T>;
}

export function invalidateApiCache(filter?: string | RegExp | ((key: string) => boolean)): void {
  if (!filter) {
    responseCache.clear();
    return;
  }

  if (typeof filter === 'string') {
    responseCache.delete(filter);
    return;
  }

  if (filter instanceof RegExp) {
    for (const key of responseCache.keys()) {
      if (filter.test(key)) responseCache.delete(key);
    }
    return;
  }

  for (const key of responseCache.keys()) {
    if (filter(key)) responseCache.delete(key);
  }
}

export function clearApiCache(): void {
  responseCache.clear();
  inflight.clear();
}
