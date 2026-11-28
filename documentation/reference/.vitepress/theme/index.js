import DefaultTheme from 'vitepress/theme';
import { h } from 'vue';
import './style.css';

const backLink = () => h('a', { href: '/SHOPCLONE7_PAYOS/', class: 'main-docs-link' }, 'Tài liệu chính');
export default {
  extends: DefaultTheme,
  Layout: () => h(DefaultTheme.Layout, null, {
    'nav-bar-content-after': backLink,
    'nav-screen-content-after': backLink,
  }),
};
