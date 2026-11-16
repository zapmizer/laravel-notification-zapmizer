/**
 * As rotas da lib zapmizer no front. Com o Ziggy no app (o `route()` global
 * do `@routes`), usa ele, e um prefixo trocado em `zapmizer.routes.prefix`
 * vem junto. Sem o Ziggy, monta o caminho com o prefixo padrão: troque
 * `PREFIX` aqui se o app mudou o prefixo.
 */
const PREFIX = '/zapmizer';

const PATHS: Record<string, string> = {
  'zapmizer.connect.show': 'connect',
  'zapmizer.connect.start': 'connect/start',
  'zapmizer.connect.destroy': 'connect',
  'zapmizer.connect.reconnect': 'connect/reconnect',
  'zapmizer.inbox.session': 'inbox/session',
  'zapmizer.inbox.keepalive': 'inbox/keepalive',
};

type ZiggyRoute = (name: string, params?: Record<string, unknown>) => string;

export function zapmizerRoute(name: string, params: Record<string, string | number> = {}): string {
  const ziggy = (globalThis as { route?: ZiggyRoute }).route;

  if (typeof ziggy === 'function') {
    return ziggy(name, params);
  }

  const path = PATHS[name];

  if (path === undefined) {
    throw new Error(`zapmizerRoute: rota desconhecida "${name}".`);
  }

  const query = new URLSearchParams(Object.entries(params).map(([key, value]) => [key, String(value)])).toString();

  return `${PREFIX}/${path}${query === '' ? '' : `?${query}`}`;
}
