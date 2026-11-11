const DECIMAL = /^(-?)(\d+)(?:\.(\d+))?$/;

/**
 * Formats a decimal string such as "1520.75" as "1,520.75".
 *
 * Pure string work, never a float: every digit the API sent is kept (at least two decimals,
 * same rule as the CLI), however large or small the amount. Anything that is not a plain
 * decimal renders as "—" instead of "NaN". No currency symbol: the API does not say which
 * currency the merchant uses.
 */
export function formatCommission(amount: string): string {
    const match = DECIMAL.exec(amount);

    if (match === null) {
        return '—';
    }

    const [, sign = '', integer = '0', fraction = ''] = match;
    const grouped = integer.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${sign}${grouped}.${fraction.padEnd(2, '0')}`;
}

export function formatGeneratedAt(iso: string, locale?: string): string {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'medium' }).format(date);
}
