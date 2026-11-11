import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DetailBarList from '@/components/machines/DetailBarList.vue';
import type { DetailBar } from '@/types';

function bar(label: string, overrides: Partial<DetailBar> = {}): DetailBar {
    return { label, value: '1 GB', detail: null, share: 50, ...overrides };
}

const tree: DetailBar[] = [
    bar('AppData', {
        detail: '50% of your user folder',
        children: [
            bar('Local', {
                detail: '60% of AppData',
                share: 60,
                children: [
                    bar('Google', { share: 50 }),
                    bar('Other files and smaller folders', { share: 50 }),
                ],
            }),
            bar('Roaming', { share: 20 }),
        ],
    }),
    bar('Documents'),
];

function render(items: DetailBar[] = tree) {
    return mount(DetailBarList, { props: { items }, attachTo: document.body });
}

describe('DetailBarList', () => {
    it('draws a row without children as plain text, with no toggle', () => {
        const wrapper = render([bar('Documents')]);

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.find('[data-test="detail-children"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toContain('Documents');
    });

    it('gives a row with children a folded toggle', () => {
        const wrapper = render();
        const toggles = wrapper.findAll('[data-test="detail-toggle"]');
        const children = wrapper.get('[data-test="detail-children"]');

        expect(toggles).toHaveLength(2);
        expect(toggles[0].text()).toBe('AppData');
        expect(toggles[0].attributes('aria-expanded')).toBe('false');
        expect(toggles[0].attributes('aria-controls')).toBe(
            children.attributes('id'),
        );
        expect(children.isVisible()).toBe(false);
    });

    it('opens and folds the children, one level at a time', async () => {
        const wrapper = render();
        const top = wrapper.get('[data-test="detail-toggle"]');

        await top.trigger('click');

        const children = wrapper.get('[data-test="detail-children"]');
        expect(top.attributes('aria-expanded')).toBe('true');
        expect(children.isVisible()).toBe(true);
        expect(children.text()).toContain('60% of AppData');
        expect(children.text()).toContain('Roaming');

        const nested = children.findAll('[data-test="detail-children"]');
        expect(nested).toHaveLength(1);
        expect(nested[0].isVisible()).toBe(false);

        await children.get('[data-test="detail-toggle"]').trigger('click');
        expect(nested[0].isVisible()).toBe(true);
        expect(nested[0].text()).toContain('Other files and smaller folders');

        await top.trigger('click');
        expect(top.attributes('aria-expanded')).toBe('false');
        expect(children.isVisible()).toBe(false);
    });

    it('draws the children as a smaller list with their own bars', async () => {
        const wrapper = render();

        await wrapper.get('[data-test="detail-toggle"]').trigger('click');

        const children = wrapper.get('[data-test="detail-children"]');
        expect(children.get('ul').classes()).toContain('text-xs');
        expect(
            children.findAll('[data-test="detail-bar"]')[0].attributes('style'),
        ).toContain('width: 60%');
    });

    it('keeps each toggle pointing at its own list', () => {
        const wrapper = render([
            bar('A', { children: [bar('a1')] }),
            bar('B', { children: [bar('b1')] }),
        ]);
        const ids = wrapper
            .findAll('[data-test="detail-toggle"]')
            .map((toggle) => toggle.attributes('aria-controls'));

        expect(new Set(ids).size).toBe(2);
    });

    it('shows a folder name as text, never as markup', () => {
        const name = '<img src=x onerror=alert(1)>';
        const wrapper = render([bar(name, { children: [bar(name)] })]);

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.get('[data-test="detail-toggle"]').text()).toBe(name);
    });
});
