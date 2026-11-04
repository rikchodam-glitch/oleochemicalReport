// PDF.js dibundel lokal lewat Vite (termasuk worker) supaya halaman denah IT
// tidak bergantung pada unpkg.com saat dibuka di jaringan tanpa internet.
import * as pdfjsLib from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.js?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

window.pdfjsLib = pdfjsLib;
