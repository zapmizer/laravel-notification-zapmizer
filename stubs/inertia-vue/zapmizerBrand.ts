declare global {
  interface ImportMetaEnv {
    readonly VITE_ZAPMIZER_BRAND?: string;
  }

  interface ImportMeta {
    readonly env: ImportMetaEnv;
  }
}

/**
 * A marca do deploy da `ZAPMIZER_BASE_URI` (`zapmizer` em app.zapmizer.com,
 * `parli` em app.parlichat.com). Vem do `VITE_ZAPMIZER_BRAND` do `.env`, que
 * o `zapmizer:install` grava e o `zapmizer:check` confere; mudou, rebuilde o
 * front. A caixa de entrada usa a mesma no embed (o iframe só
 * fala com a marca do deploy), e os textos do connect, o nome dela.
 */
export const ZAPMIZER_BRAND: string = import.meta.env.VITE_ZAPMIZER_BRAND || 'zapmizer';

const KNOWN: Record<string, { name: string; feminine: boolean }> = {
  zapmizer: { name: 'Zapmizer', feminine: false },
  parli: { name: 'Parli', feminine: true },
};

const known = KNOWN[ZAPMIZER_BRAND] ?? { name: ZAPMIZER_BRAND.charAt(0).toUpperCase() + ZAPMIZER_BRAND.slice(1), feminine: false };
const article = known.feminine ? 'a' : 'o';

/**
 * O nome da marca nos textos, já com o artigo que ela pede ("o Zapmizer",
 * "a Parli"): `brand.do` é "do Zapmizer"/"da Parli", `brand.no` é
 * "no Zapmizer"/"na Parli", e assim por diante.
 */
export const brand = {
  name: known.name,
  o: `${article} ${known.name}`,
  O: `${article.toUpperCase()} ${known.name}`,
  do: `${known.feminine ? 'da' : 'do'} ${known.name}`,
  no: `${known.feminine ? 'na' : 'no'} ${known.name}`,
  com: `com ${article} ${known.name}`,
};
