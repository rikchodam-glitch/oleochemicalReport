// Bundel JavaScript utama: Alpine.js dan Chart.js dimuat dari node_modules
// lewat Vite supaya halaman tetap berjalan di jaringan tanpa akses CDN.
import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

// Dipakai oleh komponen dan script inline di Blade (window.Alpine, window.Chart).
window.Alpine = Alpine;
window.Chart = Chart;

Alpine.start();
