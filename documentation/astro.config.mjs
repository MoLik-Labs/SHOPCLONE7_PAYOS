import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

// GitHub project pages live below /SHOPCLONE7_PAYOS/. Keep this independent of
// the PHP application's routing and do not use deployment secrets in this site.
export default defineConfig({
  site: 'https://molik-labs.github.io',
  base: '/SHOPCLONE7_PAYOS',
  // The sandbox preview is proxied under this domain; production is static Pages.
  vite: { server: { allowedHosts: ['.e2b.app'] }, preview: { allowedHosts: ['.e2b.app'] } },
  integrations: [starlight({
    title: 'MoLik Studio Docs',
    description: 'Tài liệu cài đặt, vận hành và tích hợp MoLik Shop.',
    locales: { root: { label: 'Tiếng Việt', lang: 'vi' } },
    logo: { src: './src/assets/molik.svg', alt: 'MoLik Studio' },
    favicon: '/favicon.svg',
    social: [{ icon: 'github', label: 'GitHub MoLik Labs', href: 'https://github.com/MoLik-Labs/SHOPCLONE7_PAYOS' }],
    customCss: ['./src/styles/custom.css'],
    sidebar: [
      { label: 'Bắt đầu', items: [
        { label: 'Tổng quan', slug: 'bat-dau/tong-quan' },
        { label: 'Cài đặt an toàn', slug: 'bat-dau/cai-dat' },
        { label: 'Cấu hình', slug: 'bat-dau/cau-hinh' }
      ] },
      { label: 'Vận hành', items: [
        { label: 'Danh sách trước khi chạy', slug: 'van-hanh/checklist' },
        { label: 'Giao diện & nhận diện', slug: 'van-hanh/giao-dien' },
        { label: 'Thanh toán & đối soát', slug: 'van-hanh/thanh-toan' },
        { label: 'Xử lý sự cố', slug: 'van-hanh/su-co' }
      ] },
      { label: 'API & tích hợp', items: [
        { label: 'Xác thực', slug: 'api/xac-thuc' },
        { label: 'Đọc dữ liệu', slug: 'api/du-lieu' },
        { label: 'Nhập kho', slug: 'api/nhap-kho' },
        { label: 'Tạo & tra cứu đơn', slug: 'api/don-hang' },
        { label: 'Tham chiếu kỹ thuật & debug', link: '/reference/' }
      ] },
      { label: 'Phát triển', items: [
        { label: 'Kiểm thử', slug: 'phat-trien/kiem-thu' },
        { label: 'Bảo mật & giới hạn', slug: 'phat-trien/bao-mat' },
        { label: 'Trang docs & GitHub Pages', slug: 'phat-trien/docs' }
      ] }
    ]
  })],
});
