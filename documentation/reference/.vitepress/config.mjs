import { defineConfig } from 'vitepress';

export default defineConfig({
  title: 'MoLik Studio Docs',
  description: 'Tham chiếu kỹ thuật và hướng dẫn debug MoLik Shop',
  lang: 'vi',
  base: '/SHOPCLONE7_PAYOS/reference/',
  outDir: '../dist/reference',
  // Use .html links: GitHub Pages does not provide clean-URL rewrites.
  cleanUrls: false,
  // Index the article, not navigation, in the shared Pagefind search.
  transformHtml: (html) => html.replace(/(<div[^>]*class="vp-doc[^"]*")/, '$1 data-pagefind-body'),
  head: [['link', { rel: 'icon', href: '/SHOPCLONE7_PAYOS/favicon.svg' }]],
  themeConfig: {
    logo: '/favicon.svg',
    nav: [
      { text: 'Tham chiếu', link: '/' },
      { text: 'Debug', link: '/debug' },
    ],
    sidebar: [
      { text: 'Tham chiếu kỹ thuật', items: [
        { text: 'Tổng quan', link: '/' },
        { text: 'API & an toàn', link: '/api' },
        { text: 'Debug theo triệu chứng', link: '/debug' },
        { text: 'Kiểm tra GitHub Pages', link: '/pages' },
      ] },
    ],
    search: { provider: 'local', options: { translations: {
      button: { buttonText: 'Tìm trong tham chiếu', buttonAriaLabel: 'Tìm trong tham chiếu' },
      modal: { noResultsText: 'Không tìm thấy kết quả', resetButtonTitle: 'Xóa tìm kiếm',
        footer: { selectText: 'chọn', navigateText: 'di chuyển', closeText: 'đóng' } },
    } } },
    outline: { label: 'Trên trang này' },
    docFooter: { prev: 'Trước', next: 'Tiếp' },
    darkModeSwitchLabel: 'Giao diện',
    sidebarMenuLabel: 'Menu',
    skipToContentLabel: 'Bỏ qua để đến nội dung',
    returnToTopLabel: 'Về đầu trang',
    socialLinks: [{ icon: 'github', link: 'https://github.com/MoLik-Labs/SHOPCLONE7_PAYOS' }],
    footer: { message: 'Tài liệu từ MoLik Studio.', copyright: '' },
  },
});
