import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { localiseNumber, normaliseLocale, translate } from '@/lib/messages';
import type { Locale, MessageKey } from '@/lib/messages';
import { scannedAgo } from '@/lib/scannedAgo';

export function useLocale() {
    const page = usePage();
    const locale = computed<Locale>(() => normaliseLocale(page.props.locale));

    function t(
        key: MessageKey,
        params: Record<string, string | number> = {},
    ): string {
        return translate(locale.value, key, params);
    }

    function ago(value: string): string {
        return scannedAgo(value, new Date(), locale.value);
    }

    function number(value: string): string {
        return localiseNumber(value, locale.value);
    }

    function deviceType(value: string | null): string | null {
        if (value === 'laptop' || value === 'desktop') {
            return t(`deviceType.${value}`);
        }

        return value;
    }

    return { locale, t, ago, number, deviceType };
}
