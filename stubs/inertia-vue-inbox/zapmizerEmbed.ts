import { endEmbeds, listenToLogout } from '@zapmizer/embed/logout';
import type { KeepAlive, OpenSession } from '@zapmizer/embed/state';
import { ZAPMIZER_BRAND } from './zapmizerBrand';

/**
 * A marca do embed: a mesma em `useInboxHost`, `endEmbeds` e `listenToLogout`.
 * O iframe marca cada evento com `source: "<marca>-embed"`, e cada deploy da
 * Zapmizer tem a sua (`parli` em app.parlichat.com): com a marca errada a
 * caixa nunca recebe o `ready`. Vem do `VITE_ZAPMIZER_BRAND` do `.env`
 * (`zapmizerBrand.ts`), que o `zapmizer:install` grava; mudou, rebuilde o
 * front. O `zapmizer:check` confere se bate com o deploy.
 */
export { ZAPMIZER_BRAND };

/**
 * O CSRF de uma rota web do Laravel: o `<meta name="csrf-token">` quando a
 * página tem, senão o cookie `XSRF-TOKEN` (o que o axios manda sozinho).
 * Lido a cada chamada: um login sem recarregar a página troca o token. Sem
 * ele, o POST dá 419 e a lib pede para recarregar a página.
 */
function csrfHeaders(): Record<string, string> {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    if (meta) {
        return { 'X-CSRF-TOKEN': meta };
    }

    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) } : {};
}

const JSON_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

/**
 * O `openSession` do @zapmizer/embed contra `zapmizer.inbox.session`. A
 * recusa sobe como `{ status, code, retryAfter }`, o formato que a lib lê
 * (com o axios o `Retry-After` do 429 se perderia). `payload` é lido a cada
 * abertura: um tema trocado vai na sessão seguinte.
 */
export function zapmizerOpenSession(url: string, payload: () => object = () => ({})): OpenSession {
    return async () => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { ...JSON_HEADERS, 'Content-Type': 'application/json', ...csrfHeaders() },
            body: JSON.stringify(payload()),
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw {
                status: response.status,
                code: typeof body.code === 'string' ? body.code : null,
                retryAfter: Number(response.headers.get('Retry-After')) || undefined,
            };
        }

        return body;
    };
}

/**
 * O `keepAlive` contra `zapmizer.inbox.keepalive`. O `Accept: application/json`
 * importa: sem ele, um usuário deslogado recebe o redirect para o login, o
 * fetch segue o redirect e responde 200, e a caixa ficaria aberta.
 */
export function zapmizerKeepAlive(url: string): KeepAlive {
    return async () => {
        const response = await fetch(url, { credentials: 'same-origin', headers: JSON_HEADERS });

        if (!response.ok) {
            throw { status: response.status };
        }
    };
}

/**
 * Chame no boot do app (no `app.ts`, uma vez por aba): o logout feito em
 * outra aba fecha a caixa desta e apaga a retomada. Devolve a função que
 * para de ouvir.
 */
export function listenToZapmizerLogout(): () => void {
    return listenToLogout({ brand: ZAPMIZER_BRAND });
}

/**
 * Chame ANTES do request de logout do app: fecha a caixa nesta aba, apaga a
 * retomada (`<marca>-inbox:*`) e avisa as outras abas.
 */
export function endZapmizerEmbeds(): void {
    endEmbeds({ brand: ZAPMIZER_BRAND });
}
